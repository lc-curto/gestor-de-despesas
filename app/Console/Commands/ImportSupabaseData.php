<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportSupabaseData extends Command
{
    protected $signature = 'supabase:import
        {directory=storage/app/private/supabase-export : Pasta com users.json, despesas.json e receitas.json}
        {--dry-run : Validar os ficheiros sem gravar dados}';

    protected $description = 'Importa utilizadores e movimentos exportados do Supabase, preservando UUIDs.';

    public function handle(): int
    {
        $directory = $this->argument('directory');
        $path = str_starts_with($directory, DIRECTORY_SEPARATOR)
            ? $directory
            : base_path($directory);

        try {
            $users = $this->readJson($path.'/users.json');
            $despesas = $this->readJson($path.'/despesas.json');
            $receitas = $this->readJson($path.'/receitas.json');

            $users = array_map(fn (array $row) => $this->mapUser($row), $users);
            $despesas = array_map(fn (array $row) => $this->mapMovement($row, 'expense_date'), $despesas);
            $receitas = array_map(fn (array $row) => $this->mapMovement($row, 'income_date'), $receitas);

            $ids = array_column($users, 'id');
            $allUserIds = array_unique(array_merge(
                $ids,
                array_column($despesas, 'user_id'),
                array_column($receitas, 'user_id')
            ));

            $knownIds = DB::table('users')->whereIn('id', $allUserIds)->pluck('id')->all();
            $missingIds = array_diff($allUserIds, array_merge($ids, $knownIds));

            if ($missingIds !== []) {
                throw new RuntimeException('Existem movimentos associados a utilizadores ausentes do ficheiro users.json.');
            }

            $this->info('Ficheiros validados:');
            $this->line('Utilizadores: '.count($users));
            $this->line('Despesas: '.count($despesas));
            $this->line('Receitas: '.count($receitas));

            if ($this->option('dry-run')) {
                $this->info('Dry-run concluído. Nenhum dado foi alterado.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($users, $despesas, $receitas): void {
                if ($users !== []) {
                    DB::table('users')->upsert(
                        $users,
                        ['id'],
                        ['name', 'email', 'password', 'currency', 'email_verified_at', 'created_at', 'updated_at']
                    );
                }

                if ($despesas !== []) {
                    DB::table('despesas')->upsert(
                        $despesas,
                        ['id'],
                        ['user_id', 'description', 'amount', 'currency', 'category', 'expense_date', 'created_at']
                    );
                }

                if ($receitas !== []) {
                    DB::table('receitas')->upsert(
                        $receitas,
                        ['id'],
                        ['user_id', 'description', 'amount', 'currency', 'category', 'income_date', 'created_at']
                    );
                }
            });

            $this->info('Importação concluída. Os UUIDs e as relações foram preservados.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Importação interrompida: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function readJson(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('Ficheiro em falta ou sem permissão de leitura: '.basename($file));
        }

        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException(basename($file).' deve conter um array JSON.');
        }

        foreach ($data as $row) {
            if (! is_array($row)) {
                throw new RuntimeException(basename($file).' contém um registo inválido.');
            }
        }

        return $data;
    }

    private function mapUser(array $row): array
    {
        $metadata = $row['raw_user_meta_data'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        $id = $row['id'] ?? '';
        $email = Str::lower(trim((string) ($row['email'] ?? '')));
        $hash = (string) ($row['encrypted_password'] ?? '');

        if (! Str::isUuid($id) || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Utilizador com UUID ou email inválido no export.');
        }

        // Supabase Auth usa bcrypt. Não convertemos nem voltamos a aplicar hashing.
        if ($hash !== '' && ! preg_match('/^\$2[aby]\$\d{2}\$/', $hash)) {
            throw new RuntimeException('Foi encontrado um hash de palavra-passe incompatível. A importação foi cancelada para evitar bloquear a conta.');
        }

        $password = $hash !== '' ? $hash : Hash::make(Str::random(64));
        $verifiedAt = $row['email_confirmed_at'] ?? $row['confirmed_at'] ?? null;

        return [
            'id' => $id,
            'name' => trim((string) ($metadata['full_name'] ?? $metadata['name'] ?? strstr($email, '@', true))),
            'email' => $email,
            'password' => $password,
            'currency' => in_array($metadata['currency'] ?? 'EUR', ['EUR', 'USD', 'GBP', 'BRL'], true) ? ($metadata['currency'] ?? 'EUR') : 'EUR',
            'email_verified_at' => $verifiedAt ? $this->dateTime($verifiedAt) : null,
            'created_at' => $this->dateTime($row['created_at'] ?? now()),
            'updated_at' => $this->dateTime($row['updated_at'] ?? $row['created_at'] ?? now()),
        ];
    }

    private function mapMovement(array $row, string $dateField): array
    {
        $id = $row['id'] ?? '';
        $userId = $row['user_id'] ?? '';
        $amount = $row['amount'] ?? null;
        $currency = $row['currency'] ?? 'EUR';
        $category = $row['category'] ?? '';

        if (! Str::isUuid($id) || ! Str::isUuid($userId)) {
            throw new RuntimeException('Foi encontrado um movimento com UUID inválido.');
        }
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new RuntimeException('Foi encontrado um movimento com valor inválido.');
        }
        if (! in_array($currency, ['EUR', 'USD', 'GBP', 'BRL'], true)) {
            throw new RuntimeException('Foi encontrada uma moeda não suportada: '.$currency);
        }

        $allowed = $dateField === 'expense_date'
            ? ['casa', 'alimentacao', 'transportes', 'saude', 'lazer', 'compras', 'educacao', 'outros']
            : ['salario', 'vendas', 'trabalho_extra', 'investimentos', 'reembolsos', 'outros'];

        if (! in_array($category, $allowed, true) || empty($row['description']) || empty($row[$dateField])) {
            throw new RuntimeException('Foi encontrado um movimento com descrição, categoria ou data inválida.');
        }

        return [
            'id' => $id,
            'user_id' => $userId,
            'description' => (string) $row['description'],
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => $currency,
            'category' => $category,
            $dateField => Carbon::parse($row[$dateField])->toDateString(),
            'created_at' => $this->dateTime($row['created_at'] ?? now()),
        ];
    }

    private function dateTime(mixed $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}

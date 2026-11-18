<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckFinancialCommitmentIntegrity extends Command
{
    protected $signature = 'financial:check-commitments {--user= : Limitar a verificação ao UUID de um utilizador} {--repair-safe-links : Corrigir apenas debt_id quando uma prestação válida identifica inequivocamente o compromisso}';

    protected $description = 'Audita relações entre movimentos, parcelamentos e prestações; permite corrigir apenas ligações inequívocas.';

    public function handle(): int
    {
        $query = DB::table('movimentos as m')
            ->leftJoin('dividas as d', function ($join): void {
                $join->on('d.id', '=', 'm.debt_id')->on('d.user_id', '=', 'm.user_id');
            })
            ->leftJoin('parcelas_divida as p', function ($join): void {
                $join->on('p.id', '=', 'm.installment_id')->on('p.user_id', '=', 'm.user_id');
            })
            ->leftJoin('dividas as pd', function ($join): void {
                $join->on('pd.id', '=', 'p.debt_id')->on('pd.user_id', '=', 'p.user_id');
            })
            ->where('m.status', '!=', 'void')
            ->where(function ($relations): void {
                $relations->whereNotNull('m.debt_id')->orWhereNotNull('m.installment_id');
            })
            ->select([
                'm.id',
                'm.user_id',
                'm.debt_id',
                'm.installment_id',
                'm.status as movement_status',
                'd.id as existing_debt_id',
                'd.status as debt_status',
                'd.archived_at',
                'd.deleted_at',
                'p.id as existing_installment_id',
                'p.debt_id as installment_debt_id',
                'p.status as installment_status',
                'pd.id as installment_parent_exists',
                'pd.status as installment_parent_status',
                'pd.archived_at as installment_parent_archived_at',
                'pd.deleted_at as installment_parent_deleted_at',
            ])
            ->when($this->option('user'), fn ($builder, $userId) => $builder->where('m.user_id', $userId));

        $rows = $query->get();

        $issues = [
            'Movimentos com parcelamento inexistente' => $rows->filter(fn ($row) => $row->debt_id !== null && $row->existing_debt_id === null),
            'Movimentos com prestação inexistente' => $rows->filter(fn ($row) => $row->installment_id !== null && $row->existing_installment_id === null),
            'Prestações ligadas a parcelamentos inexistentes' => $rows->filter(fn ($row) => $row->existing_installment_id !== null
                && $row->installment_parent_exists === null),
            'Relações movimento/prestação divergentes' => $rows->filter(fn ($row) => $row->installment_id !== null
                && $row->existing_installment_id !== null && $row->debt_id !== $row->installment_debt_id),
            'Movimentos ligados a parcelamentos arquivados/eliminados' => $rows->filter(fn ($row) =>
                $row->archived_at !== null || $row->deleted_at !== null
                || $row->installment_parent_archived_at !== null || $row->installment_parent_deleted_at !== null),
            'Movimentos ligados a parcelamentos/prestações cancelados' => $rows->filter(fn ($row) => $row->debt_status === 'cancelled'
                || $row->installment_status === 'cancelled' || $row->installment_parent_status === 'cancelled'),
        ];

        $repairCount = 0;
        if ($this->option('repair-safe-links')) {
            $repairCandidates = $rows->filter(fn ($row) => $row->installment_id !== null
                && $row->existing_installment_id !== null
                && $row->installment_parent_exists !== null
                && $row->debt_id !== $row->installment_debt_id);

            foreach ($repairCandidates as $candidate) {
                $repaired = DB::transaction(function () use ($candidate): bool {
                    $movement = DB::table('movimentos')
                        ->where('user_id', $candidate->user_id)
                        ->where('id', $candidate->id)
                        ->lockForUpdate()
                        ->first();
                    $installment = DB::table('parcelas_divida')
                        ->where('user_id', $candidate->user_id)
                        ->where('id', $candidate->installment_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $movement || ! $installment || $movement->status === 'void'
                        || $movement->installment_id !== $installment->id) {
                        return false;
                    }

                    $debt = DB::table('dividas')
                        ->where('user_id', $candidate->user_id)
                        ->where('id', $installment->debt_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $debt || $movement->debt_id === $debt->id) {
                        return false;
                    }

                    $before = [
                        'debt_id' => $movement->debt_id,
                        'installment_id' => $movement->installment_id,
                        'status' => $movement->status,
                    ];
                    $after = [
                        'debt_id' => $debt->id,
                        'installment_id' => $movement->installment_id,
                        'status' => $movement->status,
                    ];

                    DB::table('movimentos')
                        ->where('user_id', $candidate->user_id)
                        ->where('id', $candidate->id)
                        ->update(['debt_id' => $debt->id, 'updated_at' => now()]);

                    DB::table('financial_integrity_corrections')->insert([
                        'id' => (string) Str::uuid(),
                        'user_id' => $candidate->user_id,
                        'entity_type' => 'Movimento',
                        'entity_id' => $candidate->id,
                        'correction_type' => 'installment_parent_alignment',
                        'before_data' => json_encode($before, JSON_THROW_ON_ERROR),
                        'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);

                    return true;
                });

                if ($repaired) {
                    $repairCount++;
                }
            }
        }

        $total = 0;
        foreach ($issues as $label => $items) {
            $count = $items->count();
            $total += $count;
            $this->line(sprintf('%s: <comment>%d</comment>', $label, $count));
            if ($count > 0) {
                $this->table(
                    ['Movimento', 'Utilizador', 'Debt ID', 'Installment ID', 'Estado'],
                    $items->take(50)->map(fn ($row) => [
                        $row->id,
                        $row->user_id,
                        $row->debt_id ?? '—',
                        $row->installment_id ?? '—',
                        $row->movement_status,
                    ])->all()
                );
                if ($count > 50) {
                    $this->warn('A apresentar apenas os primeiros 50 registos desta categoria.');
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('Verificação concluída. %d ocorrências encontradas.', $total));
        if ($this->option('repair-safe-links')) {
            $this->info(sprintf('%d ligações inequívocas corrigidas e registadas em financial_integrity_corrections. Montantes, estados e pagamentos não foram alterados.', $repairCount));
        } else {
            $this->comment('Modo de leitura: nenhum dado foi alterado.');
        }

        return self::SUCCESS;
    }
}

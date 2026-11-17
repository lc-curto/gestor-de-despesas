<?php

namespace App\Services\Financial;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialDataMigrationService
{
    /** Produz o plano sem escrever dados; casos não seguros ficam em ambiguous. */
    public function plan(?string $userId = null): array
    {
        $reports = [];
        $users = User::query()->when($userId, fn ($query) => $query->whereKey($userId))->get();

        foreach ($users as $user) {
            $legacyAccounts = DB::table('contas_financeiras')->where('user_id', $user->id)->where('type', 'legacy')->get();
            $account = $legacyAccounts->count() === 1 ? $legacyAccounts->first() : null;
            $eligible = [];
            $ambiguous = [];

            foreach ([['despesas', 'despesa', 'expense', 'expense_date'], ['receitas', 'receita', 'income', 'income_date']] as [$table, $legacyType, $type, $dateColumn]) {
                $rows = DB::table($table)->where('user_id', $user->id)
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as m')->where('m.user_id', $user->id)->where('m.legacy_type', $legacyType)->whereColumn('m.legacy_id', $table.'.id'))
                    ->orderBy('id')->get();
                foreach ($rows as $row) {
                    $candidate = ['kind' => 'legacy_movement', 'legacy_type' => $legacyType, 'legacy_id' => $row->id];
                    if ($account && $user->financial_cutoff_date) {
                        $eligible[] = $candidate;
                    } else {
                        $candidate['reason'] = !$account ? 'A conta histórica não existe ou não é única.' : 'A data de corte financeira não está definida.';
                        $ambiguous[] = $candidate;
                    }
                }
            }

            $debts = DB::table('dividas as d')->where('d.user_id', $user->id)
                ->where('d.status', 'active')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('parcelas_divida as p')->whereColumn('p.debt_id', 'd.id')->whereColumn('p.user_id', 'd.user_id'))
                ->orderBy('d.id')->get();
            foreach ($debts as $debt) {
                $candidate = ['kind' => 'single_installment', 'debt_id' => $debt->id];
                $hasMovement = DB::table('movimentos')->where('user_id', $user->id)->where('debt_id', $debt->id)->exists();
                $safeCondition = in_array($debt->payment_condition, ['deferred', 'installments'], true);
                if ($safeCondition && (int) $debt->installments_count === 1 && $debt->due_date && !$hasMovement && (float) $debt->total_amount > 0) {
                    $eligible[] = $candidate;
                } else {
                    $candidate['reason'] = $hasMovement ? 'A obrigação já tem movimento financeiro associado.' : 'Faltam condições para reconstruir uma única prestação sem inferências.';
                    $ambiguous[] = $candidate;
                }
            }

            $reports[] = [
                'user_id' => $user->id,
                'deterministic' => $eligible,
                'ambiguous' => $ambiguous,
                'counts' => ['deterministic' => count($eligible), 'ambiguous' => count($ambiguous)],
            ];
        }

        return $reports;
    }

    /** Aplica apenas itens do plano revistos imediatamente antes de cada escrita. */
    public function migrate(?string $userId = null): array
    {
        $results = [];
        foreach ($this->plan($userId) as $report) {
            $user = User::query()->findOrFail($report['user_id']);
            $applied = [];
            foreach ($report['deterministic'] as $item) {
                $created = DB::transaction(function () use ($user, $item): bool {
                    if ($item['kind'] === 'legacy_movement') {
                        $table = $item['legacy_type'] === 'despesa' ? 'despesas' : 'receitas';
                        $dateColumn = $item['legacy_type'] === 'despesa' ? 'expense_date' : 'income_date';
                        $legacy = DB::table($table)->where('user_id', $user->id)->where('id', $item['legacy_id'])->lockForUpdate()->first();
                        if (!$legacy || DB::table('movimentos')->where('user_id', $user->id)->where('legacy_type', $item['legacy_type'])->where('legacy_id', $legacy->id)->exists()) return false;
                        $accounts = DB::table('contas_financeiras')->where('user_id', $user->id)->where('type', 'legacy')->get();
                        $cutoff = $user->financial_cutoff_date?->toDateString();
                        if ($accounts->count() !== 1 || !$cutoff) return false;
                        $historical = $legacy->{$dateColumn} <= $cutoff;
                        $movementId = (string) Str::uuid();
                        $now = now();
                        $movement = [
                            'id' => $movementId, 'user_id' => $user->id, 'type' => $item['legacy_type'] === 'despesa' ? 'expense' : 'income',
                            'status' => $historical ? 'settled' : 'pending', 'description' => $legacy->description, 'amount' => $legacy->amount,
                            'movement_date' => $legacy->{$dateColumn},
                            'source_account_id' => $historical && $item['legacy_type'] === 'despesa' ? $accounts->first()->id : null,
                            'destination_account_id' => $historical && $item['legacy_type'] === 'receita' ? $accounts->first()->id : null,
                            'category_id' => $legacy->category_id ?? null, 'is_historical' => $historical,
                            'requires_account_assignment' => !$historical, 'legacy_type' => $item['legacy_type'], 'legacy_id' => $legacy->id,
                            'settled_at' => $historical ? ($legacy->created_at ?? $now) : null, 'created_at' => $legacy->created_at ?? $now, 'updated_at' => $now,
                        ];
                        DB::table('movimentos')->insert($movement);
                        DB::table('historico_movimentos')->insert([
                            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'movement_id' => $movementId, 'changed_by' => null, 'action' => 'deterministic_legacy_migration',
                            'before_data' => null, 'after_data' => json_encode($movement), 'reason' => 'Recuperação não destrutiva de registo legado órfão determinístico.', 'created_at' => $now,
                        ]);
                        return true;
                    }

                    $debt = DB::table('dividas')->where('user_id', $user->id)->where('id', $item['debt_id'])->where('status', 'active')->lockForUpdate()->first();
                    if (!$debt || !in_array($debt->payment_condition, ['deferred', 'installments'], true) || (int) $debt->installments_count !== 1 || !$debt->due_date || (float) $debt->total_amount <= 0
                        || DB::table('parcelas_divida')->where('user_id', $user->id)->where('debt_id', $debt->id)->exists()
                        || DB::table('movimentos')->where('user_id', $user->id)->where('debt_id', $debt->id)->exists()) return false;
                    DB::table('parcelas_divida')->insert([
                        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'debt_id' => $debt->id, 'installment_number' => 1,
                        'due_date' => $debt->due_date, 'amount' => $debt->total_amount, 'principal_amount' => $debt->total_amount,
                        'interest_amount' => '0.00', 'paid_amount' => '0.00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    return true;
                });
                if ($created) $applied[] = $item;
            }
            $results[] = ['user_id' => $user->id, 'applied' => $applied, 'applied_count' => count($applied), 'ambiguous' => $report['ambiguous']];
        }
        return $results;
    }
}

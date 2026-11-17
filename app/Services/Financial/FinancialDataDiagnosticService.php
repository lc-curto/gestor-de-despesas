<?php

namespace App\Services\Financial;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinancialDataDiagnosticService
{
    public function report(?string $userId = null): array
    {
        $users = User::query()->when($userId, fn ($query) => $query->whereKey($userId))->get(['id']);
        $reports = [];

        foreach ($users as $user) {
            $legacyExpenses = DB::table('despesas as legacy')
                ->where('legacy.user_id', $user->id)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as linked')->whereColumn('linked.legacy_id', 'legacy.id')->where('linked.legacy_type', 'despesa')->where('linked.user_id', $user->id))
                ->count();
            $legacyIncomes = DB::table('receitas as legacy')
                ->where('legacy.user_id', $user->id)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movimentos as linked')->whereColumn('linked.legacy_id', 'legacy.id')->where('linked.legacy_type', 'receita')->where('linked.user_id', $user->id))
                ->count();

            $brokenLegacyLinks = DB::table('movimentos as movement')
                ->where('movement.user_id', $user->id)
                ->whereNotNull('movement.legacy_id')
                ->where(function ($query) {
                    $query->where(function ($nested) {
                        $nested->where('movement.legacy_type', 'despesa')->whereNotExists(fn ($exists) => $exists->selectRaw('1')->from('despesas')->whereColumn('despesas.id', 'movement.legacy_id')->whereColumn('despesas.user_id', 'movement.user_id'));
                    })->orWhere(function ($nested) {
                        $nested->where('movement.legacy_type', 'receita')->whereNotExists(fn ($exists) => $exists->selectRaw('1')->from('receitas')->whereColumn('receitas.id', 'movement.legacy_id')->whereColumn('receitas.user_id', 'movement.user_id'));
                    });
                })->count();

            $ambiguousDuplicates = DB::table('movimentos')
                ->select('type', 'description', 'amount', 'movement_date', 'source_account_id', 'destination_account_id')
                ->where('user_id', $user->id)
                ->where('status', '!=', 'void')
                ->groupBy('type', 'description', 'amount', 'movement_date', 'source_account_id', 'destination_account_id')
                ->havingRaw('COUNT(*) > 1')
                ->limit(100)
                ->get()->map(fn ($row) => (array) $row)->values()->all();

            $incompleteDebts = DB::table('dividas as debt')
                ->leftJoin('parcelas_divida as installment', 'installment.debt_id', '=', 'debt.id')
                ->where('debt.user_id', $user->id)
                ->groupBy('debt.id', 'debt.name', 'debt.total_amount')
                ->havingRaw('COUNT(installment.id) = 0 OR ABS(COALESCE(SUM(installment.amount), 0) - debt.total_amount) > 0.01')
                ->select('debt.id', 'debt.name', 'debt.total_amount')
                ->limit(100)->get()->map(fn ($row) => (array) $row)->values()->all();

            $invalidInstallments = DB::table('parcelas_divida')
                ->where('user_id', $user->id)
                ->where(function ($query) {
                    $query->where('paid_amount', '<', 0)
                        ->orWhereColumn('paid_amount', '>', 'amount')
                        ->orWhereColumn('paid_amount', 'amount')->where('status', '!=', 'paid')
                        ->orWhere(function ($nested) {
                            $nested->where('status', 'paid')->whereColumn('paid_amount', '<', 'amount');
                        });
                })->count();

            $reports[] = [
                'user_id' => $user->id,
                'counts' => [
                    'orphan_legacy_expenses' => $legacyExpenses,
                    'orphan_legacy_incomes' => $legacyIncomes,
                    'broken_legacy_links' => $brokenLegacyLinks,
                    'ambiguous_duplicate_candidates' => count($ambiguousDuplicates),
                    'incomplete_debts' => count($incompleteDebts),
                    'invalid_installments' => $invalidInstallments,
                ],
                'items' => [
                    'ambiguous_duplicate_candidates' => $ambiguousDuplicates,
                    'incomplete_debts' => $incompleteDebts,
                ],
            ];
        }

        return $reports;
    }
}

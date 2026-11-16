<?php

namespace App\Http\Controllers;

use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\DashboardMovementQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinancialRouteController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardMovementQuery $movementQuery,
        AccountBalanceService $balances
    ) {
        $validated = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
            'modo' => ['nullable', 'in:1,3'],
        ]);

        try {
            $start = Carbon::createFromFormat('!Y-m', $validated['mes'] ?? now()->format('Y-m'))->startOfMonth();
        } catch (\Throwable) {
            $start = now()->startOfMonth();
        }

        $mode = (int) ($validated['modo'] ?? 3);
        $months = collect(range(0, $mode - 1))
            ->map(fn (int $offset) => $start->copy()->addMonths($offset));

        $rangeStart = $months->first()->copy()->startOfMonth();
        $rangeEnd = $months->last()->copy()->endOfMonth();

        // O Dashboard considera apenas bancos e dinheiro no saldo disponível.
        // A Rota parte do saldo dessas mesmas contas na véspera do período.
        $accounts = $request->user()->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->whereNotNull('opening_balance')
            ->whereNotNull('opening_balance_date')
            ->get();

        $openingDate = $rangeStart->copy()->subDay()->toDateString();
        $openingBalance = $accounts
            ->filter(fn ($account) => $account->opening_balance_date->toDateString() <= $openingDate)
            ->sum(fn ($account) => (float) $balances->balanceAt($account, $openingDate));

        // Contas abertas durante o período entram no saldo apenas na data de abertura.
        $accountOpeningEvents = $accounts
            ->filter(fn ($account) => $account->opening_balance_date->betweenIncluded($rangeStart, $rangeEnd))
            ->groupBy(fn ($account) => $account->opening_balance_date->toDateString())
            ->map(fn ($items) => (float) $items->sum('opening_balance'));

        // Mesma consulta, união, associação legado/canónico e exclusão de anulados
        // utilizados pela listagem tradicional do Dashboard.
        $movements = $movementQuery->build(
            $request->user()->id,
            $rangeStart,
            $rangeEnd,
            'todos',
            'all'
        )->orderBy('data_movimento')->get();

        $days = [];
        foreach ($months as $month) {
            $monthKey = $month->format('Y-m');
            $days[$monthKey] = [];
            $cursor = $month->copy()->startOfMonth();

            while ($cursor->lte($month->copy()->endOfMonth())) {
                $key = $cursor->toDateString();
                $items = $movements->filter(
                    fn ($movement) => Carbon::parse($movement->data_movimento)->toDateString() === $key
                );

                $days[$monthKey][$key] = [
                    'income' => (float) $items->where('tipo', 'receita')->sum('amount'),
                    'variable' => (float) $items->where('tipo', 'despesa')
                        ->where('expense_behavior', 'variable')->sum('amount'),
                    'fixed' => (float) $items->where('tipo', 'despesa')
                        ->where('expense_behavior', 'fixed')->sum('amount'),
                    'items' => $items->values(),
                    'account_opening' => (float) ($accountOpeningEvents[$key] ?? 0),
                ];

                $cursor->addDay();
            }
        }

        return view('financial-route', [
            'months' => $months,
            'days' => $days,
            'mode' => $mode,
            'month' => $start->format('Y-m'),
            'currency' => $request->user()->currency,
            'openingBalance' => $openingBalance,
            'categories' => $request->user()->categorias()
                ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'accounts' => $accounts->sortBy('name')->values(),
        ]);
    }
}

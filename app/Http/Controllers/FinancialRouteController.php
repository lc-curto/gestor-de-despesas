<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinancialRouteController extends Controller
{
    public function __invoke(Request $request)
    {
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
        $months = collect(range(0, $mode - 1))->map(fn (int $offset) => $start->copy()->addMonths($offset));

        $rangeStart = $months->first()->copy()->startOfMonth();
        $rangeEnd = $months->last()->copy()->endOfMonth();

        // A tabela canónica evita somar novamente o registo legado associado.
        $openingBalance = (float) $request->user()->contasFinanceiras()
            ->where('is_operational', true)->where('status', 'active')->sum('opening_balance');
        $prior = $request->user()->movimentos()
            ->whereIn('type', ['income', 'expense'])
            ->whereIn('status', ['pending', 'settled'])
            ->where('is_historical', false)
            ->where('movement_date', '<', $rangeStart->toDateString())
            ->get(['type', 'amount']);
        $openingBalance += (float) $prior->where('type', 'income')->sum('amount')
            - (float) $prior->where('type', 'expense')->sum('amount');

        $movements = $request->user()->movimentos()
            ->whereIn('type', ['income', 'expense'])
            ->whereIn('status', ['pending', 'settled'])
            ->whereBetween('movement_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('movement_date')
            ->with('categoria:id,name')->get(['id', 'type', 'status', 'description', 'amount', 'movement_date', 'expense_behavior', 'category_id']);

        $days = [];
        foreach ($months as $month) {
            $monthKey = $month->format('Y-m');
            $days[$monthKey] = [];
            $cursor = $month->copy()->startOfMonth();
            while ($cursor->lte($month->copy()->endOfMonth())) {
                $key = $cursor->toDateString();
                $items = $movements->filter(fn ($movement) => $movement->movement_date->toDateString() === $key);
                $days[$monthKey][$key] = [
                    'income' => (float) $items->where('type', 'income')->sum('amount'),
                    'variable' => (float) $items->where('type', 'expense')->where('expense_behavior', 'variable')->sum('amount'),
                    'fixed' => (float) $items->where('type', 'expense')->where('expense_behavior', 'fixed')->sum('amount'),
                    'items' => $items->values(),
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
            'categories' => $request->user()->categorias()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'accounts' => $request->user()->contasFinanceiras()->where('is_operational', true)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }
}

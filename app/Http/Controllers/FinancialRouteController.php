<?php

namespace App\Http\Controllers;

use App\Services\Financial\FinancialProjectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinancialRouteController extends Controller
{
    public function __invoke(Request $request, FinancialProjectionService $projection)
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

        $projectionData = $projection->build($request->user(), $rangeStart, $rangeEnd);
        $openingBalance = $projectionData['openingBalance'];
        $movements = $projectionData['movements'];
        $overdueItems = $projectionData['overdue'] ?? collect();

        $days = [];
        foreach ($months as $month) {
            $monthKey = $month->format('Y-m');
            $days[$monthKey] = [];
            $cursor = $month->copy()->startOfMonth();
            while ($cursor->lte($month->copy()->endOfMonth())) {
                $key = $cursor->toDateString();
                $items = $movements->filter(fn ($movement) => $movement->movement_date->toDateString() === $key);
                $cashIncome = $items->where('type', 'income')->sum('amount');
                $cashExpenses = $items->where('type', 'expense');
                $days[$monthKey][$key] = [
                    'income' => (float) $cashIncome,
                    'variable' => (float) $cashExpenses->where('expense_behavior', 'variable')->sum('amount'),
                    'fixed' => (float) $cashExpenses->where('expense_behavior', 'fixed')->sum('amount'),
                    'cash_delta' => (float) $items->sum(fn ($item) => (float) ($item->projection_delta ?? 0)),
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
            'overdueItems' => $overdueItems,
            'categories' => $request->user()->categorias()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'accounts' => $request->user()->contasFinanceiras()->where('is_operational', true)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }
}

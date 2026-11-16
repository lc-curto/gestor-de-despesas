<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Services\Financial\AccountBalanceService;
use Illuminate\Http\Request;

class FinancialCardsPageController extends Controller
{
    public function __invoke(Request $request, AccountBalanceService $balances)
    {
        $cards = $request->user()->contasFinanceiras()
            ->where('type', 'credit_card')
            ->withCount(['movimentosOrigem', 'movimentosDestino'])
            ->orderBy('name')
            ->get();

        $cards->each(function (ContaFinanceira $card) use ($balances): void {
            $card->current_balance = ($card->is_operational && $card->opening_balance !== null && $card->opening_balance_date)
                ? $balances->balance($card)
                : (string) ($card->opening_balance ?? '0.00');
            $card->used_limit = max(0, -(float) $card->current_balance);
            $card->available_limit = max(0, (float) ($card->credit_limit ?? 0) - $card->used_limit);
            $card->can_delete = $card->movimentos_origem_count === 0
                && $card->movimentos_destino_count === 0
                && (float) ($card->opening_debt_amount ?? 0) === 0.0
                && (float) ($card->opening_debt_paid ?? 0) === 0.0;
            $card->usage_percent = (float) ($card->credit_limit ?? 0) > 0
                ? min(100, ($card->used_limit / (float) $card->credit_limit) * 100)
                : 0;
        });

        $activeCards = $cards->where('status', 'active')->where('is_operational', true);
        $inactiveCards = $cards->filter(fn (ContaFinanceira $card) => $card->status !== 'active' || !$card->is_operational)->values();
        $summary = [
            'used' => $activeCards->sum('used_limit'),
            'available' => $activeCards->sum('available_limit'),
            'count' => $activeCards->count(),
        ];

        return view('financial.cards', compact('cards', 'inactiveCards', 'summary'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Services\Financial\AccountBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialCardsPageController extends Controller
{
    public function show(Request $request, string $id, AccountBalanceService $balances)
    {
        $card = $request->user()->contasFinanceiras()
            ->where('type', 'credit_card')->whereKey($id)
            ->withCount(['movimentosOrigem', 'movimentosDestino'])->firstOrFail();

        $this->prepareCard($card, $balances);

        $hasDebt = DB::table('dividas')->where('user_id', $card->user_id)->where('account_id', $card->id)->exists();
        $hasRecurrence = DB::table('recorrencias')->where('user_id', $card->user_id)->where('account_id', $card->id)->exists();
        $card->can_delete = $card->movimentos_origem_count === 0 && $card->movimentos_destino_count === 0
            && ! $hasDebt && ! $hasRecurrence
            && (float) ($card->opening_debt_amount ?? 0) === 0.0
            && (float) ($card->opening_debt_paid ?? 0) === 0.0
            && (float) ($card->opening_balance ?? 0) === 0.0;

        $invoices = app(\App\Services\Financial\CardInvoiceService::class)->listInvoices($request->user(), $card->id);

        return view('financial.card-show', compact('card', 'invoices'));
    }

    private function prepareCard(ContaFinanceira $card, AccountBalanceService $balances): void
    {
        $card->current_balance = ($card->is_operational && $card->opening_balance !== null && $card->opening_balance_date)
            ? $balances->balance($card)
            : (string) ($card->opening_balance ?? '0.00');
        $card->used_limit = max(0, -(float) $card->current_balance);
        $card->available_limit = max(0, (float) ($card->credit_limit ?? 0) - $card->used_limit);
        $card->usage_percent = (float) ($card->credit_limit ?? 0) > 0
            ? min(100, ($card->used_limit / (float) $card->credit_limit) * 100)
            : 0;
    }

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
            $hasDebt = DB::table('dividas')->where('user_id', $card->user_id)->where('account_id', $card->id)->exists();
            $hasRecurrence = DB::table('recorrencias')->where('user_id', $card->user_id)->where('account_id', $card->id)->exists();
            $card->can_delete = $card->movimentos_origem_count === 0
                && $card->movimentos_destino_count === 0
                && ! $hasDebt
                && ! $hasRecurrence
                && (float) ($card->opening_debt_amount ?? 0) === 0.0
                && (float) ($card->opening_debt_paid ?? 0) === 0.0
                && (float) ($card->opening_balance ?? 0) === 0.0;
            $card->usage_percent = (float) ($card->credit_limit ?? 0) > 0
                ? min(100, ($card->used_limit / (float) $card->credit_limit) * 100)
                : 0;
        });

        $inactiveCards = $cards->filter(fn (ContaFinanceira $card) => $card->status !== 'active' || ! $card->is_operational)->values();

        return view('financial.cards', compact('cards', 'inactiveCards'));
    }
}

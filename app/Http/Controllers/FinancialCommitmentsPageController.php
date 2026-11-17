<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use Illuminate\Http\Request;

class FinancialCommitmentsPageController extends Controller
{
    public function __invoke(Request $request, CardInvoiceService $invoices, AccountBalanceService $balances)
    {
        $user = $request->user();

        $cards = $user->contasFinanceiras()
            ->where('type', 'credit_card')
            ->where('is_operational', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $cards->each(function (ContaFinanceira $card) use ($balances): void {
            $card->current_balance = $card->opening_balance !== null
                ? $balances->balance($card)
                : '0.00';
            $card->used_limit = number_format(max(0, -(float) $card->current_balance), 2, '.', '');
            $card->available_limit = $card->credit_limit !== null
                ? number_format(max(0, (float) $card->credit_limit - (float) $card->used_limit), 2, '.', '')
                : null;
        });

        $paymentAccounts = $user->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $invoicePage = $invoices->listInvoices($user);
        $commitments = $user->dividas()
            ->whereIn('payment_condition', ['deferred', 'installments'])
            ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card']))
            ->where('status', '!=', 'cancelled')
            ->with(['parcelas' => fn ($query) => $query->orderBy('due_date')])
            ->orderBy('due_date')
            ->paginate(20, ['*'], 'commitments');

        $commitmentOpen = (float) $user->parcelasDivida()
            ->whereHas('divida', fn ($query) => $query
                ->whereIn('payment_condition', ['deferred', 'installments'])
                ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card']))
                ->where('status', '!=', 'cancelled'))
            ->where('status', '!=', 'paid')
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as remaining')
            ->value('remaining');

        $commitmentOverdue = (float) $user->parcelasDivida()
            ->whereHas('divida', fn ($query) => $query
                ->whereIn('payment_condition', ['deferred', 'installments'])
                ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card']))
                ->where('status', '!=', 'cancelled'))
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<', today())
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as remaining')
            ->value('remaining');

        $summary = [
            'open' => $commitmentOpen,
            'overdue' => $commitmentOverdue,
            'upcoming' => max(0, $commitmentOpen - $commitmentOverdue),
        ];

        return view('financial.commitments', compact(
            'paymentAccounts',
            'invoicePage',
            'commitments',
            'summary'
        ));
    }
}

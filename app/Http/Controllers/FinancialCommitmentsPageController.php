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
            ->where('status', '!=', 'paid')
            ->with(['parcelas' => fn ($query) => $query->where('status', '!=', 'paid')->orderBy('due_date')])
            ->orderBy('due_date')
            ->paginate(20, ['*'], 'commitments');

        $invoiceOpen = (float) $invoicePage->getCollection()->sum('remaining_amount');
        $commitmentOpen = (float) $user->parcelasDivida()
            ->where('status', '!=', 'paid')
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as remaining')
            ->value('remaining');

        $invoiceOverdue = (float) $invoicePage->getCollection()
            ->filter(fn ($invoice) => $invoice->display_status === 'overdue')
            ->sum('remaining_amount');
        $commitmentOverdue = (float) $user->parcelasDivida()
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<', today())
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as remaining')
            ->value('remaining');

        $summary = [
            'open' => $invoiceOpen + $commitmentOpen,
            'overdue' => $invoiceOverdue + $commitmentOverdue,
            'upcoming' => max(0, $invoiceOpen + $commitmentOpen - $invoiceOverdue - $commitmentOverdue),
        ];

        return view('financial.commitments', compact(
            'cards',
            'paymentAccounts',
            'invoicePage',
            'commitments',
            'summary'
        ));
    }
}

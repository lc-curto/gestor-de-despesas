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
        $cards = $user->contasFinanceiras()->where('type', 'credit_card')->where('is_operational', true)->where('status', 'active')->orderBy('name')->get();
        $cards->each(function (ContaFinanceira $card) use ($balances): void {
            $card->current_balance = $card->opening_balance !== null ? $balances->balance($card) : '0.00';
        });
        $paymentAccounts = $user->contasFinanceiras()->whereIn('type', ['bank', 'cash'])->where('is_operational', true)->where('status', 'active')->orderBy('name')->get();
        $commitmentPaymentAccounts = $user->contasFinanceiras()->whereIn('type', ['bank', 'cash', 'credit_card'])->where('is_operational', true)->where('status', 'active')->orderBy('name')->get();
        $invoicePage = $invoices->listInvoices($user);
        $commitments = $user->dividas()->with('parcelas')->latest()->paginate(30, ['*'], 'commitments');

        return view('financial.commitments', compact('cards', 'paymentAccounts', 'commitmentPaymentAccounts', 'invoicePage', 'commitments'));
    }
}

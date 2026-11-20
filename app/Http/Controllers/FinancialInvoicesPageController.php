<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Services\Financial\CardInvoiceService;
use Illuminate\Http\Request;

class FinancialInvoicesPageController extends Controller
{
    public function __invoke(Request $request, CardInvoiceService $invoices)
    {
        $cardId = $request->query('account_id');
        if (! $cardId) {
            return redirect()->route('financial.cards.page');
        }

        $card = $request->user()->contasFinanceiras()
            ->where('type', 'credit_card')->whereKey($cardId)->firstOrFail();
        $items = $invoices->listInvoices($request->user(), $card->id);

        return view('financial.invoices', compact('card', 'items'));
    }

    public function show(Request $request, string $id, CardInvoiceService $invoices)
    {
        $invoice = $invoices->findInvoice($request->user(), $id);
        $card = $request->user()->contasFinanceiras()
            ->where('type', 'credit_card')->whereKey($invoice->account_id)->firstOrFail();
        $accounts = $request->user()->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('status', 'active')->where('is_operational', true)
            ->orderBy('name')->get(['id', 'name', 'type', 'currency']);

        return view('financial.invoice-show', compact('invoice', 'card', 'accounts'));
    }
}

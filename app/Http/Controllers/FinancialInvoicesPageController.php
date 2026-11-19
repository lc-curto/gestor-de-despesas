<?php

namespace App\Http\Controllers;

use App\Services\Financial\CardInvoiceService;
use Illuminate\Http\Request;

class FinancialInvoicesPageController extends Controller
{
    public function __invoke(Request $request, CardInvoiceService $invoices)
    {
        $items = $invoices->listInvoices($request->user(), $request->query('account_id'));
        $accounts = $request->user()->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('status', 'active')
            ->where('is_operational', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency']);

        return view('financial.invoices', compact('items', 'accounts'));
    }
}

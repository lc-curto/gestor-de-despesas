<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Services\Financial\AccountBalanceService;
use Illuminate\Http\Request;

class FinancialAccountsPageController extends Controller
{
    public function __invoke(Request $request, AccountBalanceService $balances)
    {
        $accounts = $request->user()->contasFinanceiras()
            ->where('type', '!=', 'legacy')
            ->orderBy('created_at')
            ->get();

        $accounts->each(function (ContaFinanceira $account) use ($balances): void {
            $account->current_balance = null;

            if ($account->is_operational && $account->opening_balance !== null && $account->type !== 'credit_card') {
                $account->current_balance = $balances->balance($account);
            }
        });

        return view('financial.accounts', compact('accounts'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialAccountsPageController extends Controller
{
    public function __invoke(Request $request, AccountBalanceService $balances)
    {
        $accounts = $request->user()->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->withCount(['movimentosOrigem', 'movimentosDestino'])
            ->orderBy('created_at')
            ->get();
        $transferTargets = $request->user()->contasFinanceiras()
            ->whereIn('type', ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('status', 'active')
            ->whereNotNull('opening_balance')
            ->orderBy('name')
            ->get();
        $accounts->each(function (ContaFinanceira $account) use ($balances): void {
            $account->current_balance = null;
            if ($account->is_operational && $account->opening_balance !== null) {
                $account->current_balance = $balances->balance($account);
            }
        });

        return view('financial.accounts', compact('accounts', 'transferTargets'));
    }

    public function show(Request $request, string $id, AccountBalanceService $balances)
    {
        $account = $request->user()->contasFinanceiras()
            ->where('type', '!=', 'legacy')
            ->whereKey($id)
            ->firstOrFail();

        $account->current_balance = $account->is_operational
            ? ($account->opening_balance !== null ? $balances->balance($account) : null)
            : $balances->balanceForHistory($account);
        $paymentAccounts = $request->user()->contasFinanceiras()->whereIn('type', ['bank', 'cash'])->where('is_operational', true)->where('status', 'active')->whereNotNull('opening_balance')->orderBy('name')->get();
        $openingDebtRemaining = max(0, (float) ($account->opening_debt_amount ?? 0) - (float) ($account->opening_debt_paid ?? 0));

        $movements = $request->user()->movimentos()
            ->with(['contaOrigem:id,name', 'contaDestino:id,name', 'categoria:id,name,icon,color'])
            ->where(function ($query) use ($account): void {
                $query->where('source_account_id', $account->id)
                    ->orWhere('destination_account_id', $account->id);
            })
            ->whereIn('status', ['pending', 'partial', 'settled'])
            ->orderByDesc('movement_date')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('financial.account-show', compact('account', 'movements', 'paymentAccounts', 'openingDebtRemaining'));
    }

    public function destroyMovement(Request $request, string $id, FinancialMovementService $service)
    {
        $movement = $request->user()->movimentos()->whereKey($id)->firstOrFail();
        $service->deleteAsCorrection(
            $request->user(),
            $movement,
            'Movimento eliminado a partir do detalhe da conta.'
        );

        return back()->with('success', 'Movimento eliminado. Os saldos foram atualizados.');
    }


}

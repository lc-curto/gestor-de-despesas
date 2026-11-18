<?php

namespace App\Http\Controllers;

use App\Models\ContaFinanceira;
use App\Models\Divida;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $card->current_balance = $card->opening_balance !== null ? $balances->balance($card) : '0.00';
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

        $eligibleDebts = fn () => $user->dividas()->withTrashed()
            ->where('payment_condition', 'installments')
            ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card']))
            ->where('status', '!=', 'cancelled');

        $visibleDebts = fn () => $eligibleDebts()->whereNull('archived_at')->whereNull('deleted_at');

        $openInstallments = $user->parcelasDivida()
            ->whereHas('divida', fn ($query) => $query
                ->whereNull('archived_at')
                ->where('payment_condition', 'installments')
                ->where(fn ($query) => $query->whereNull('payment_method')->orWhereNotIn('payment_method', ['credit', 'card']))
                ->where('status', '!=', 'cancelled'))
            ->whereColumn('amount', '>', 'paid_amount')
            ->where('status', '!=', 'cancelled');

        $commitmentOpen = (float) (clone $openInstallments)->sum(DB::raw('amount - paid_amount'));
        $commitmentOverdue = (float) (clone $openInstallments)
            ->whereDate('due_date', '<', today())
            ->sum(DB::raw('amount - paid_amount'));

        $overdueCommitments = (clone $openInstallments)
            ->whereDate('due_date', '<', today())
            ->distinct()
            ->count('debt_id');

        $nextInstallment = (clone $openInstallments)
            ->with('divida')
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->first();

        $eligibleQuery = $visibleDebts();
        $activeCount = (clone $eligibleQuery)
            ->whereHas('parcelas', fn ($query) => $query
                ->whereColumn('amount', '>', 'paid_amount')
                ->where('status', '!=', 'cancelled'))
            ->count();

        $completedCount = (clone $eligibleQuery)
            ->whereDoesntHave('parcelas', fn ($query) => $query
                ->whereColumn('amount', '>', 'paid_amount')
                ->where('status', '!=', 'cancelled'))
            ->where('status', '!=', 'cancelled')
            ->count();

        $archivedCount = $eligibleDebts()->where(fn ($query) => $query->whereNotNull('archived_at')->orWhereNotNull('deleted_at'))->count();

        $summary = [
            'open' => $commitmentOpen,
            'overdue' => $commitmentOverdue,
            'overdue_commitments' => $overdueCommitments,
            'upcoming' => max(0, $commitmentOpen - $commitmentOverdue),
            'active_count' => $activeCount,
            'completed_count' => $completedCount,
            'archived_count' => $archivedCount,
            'total_count' => $activeCount + $completedCount + $archivedCount,
            'next_installment' => $nextInstallment,
        ];

        $status = $request->query('status', 'active');
        if (! in_array($status, ['active', 'completed', 'archived', 'all'], true)) {
            $status = 'active';
        }

        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [6, 10], true)) {
            $perPage = 10;
        }

        $query = $eligibleDebts()
            ->when($status === 'active' || $status === 'completed', fn ($builder) => $builder->whereNull('archived_at')->whereNull('deleted_at'))
            ->when($status === 'archived', fn ($builder) => $builder->where(fn ($archived) => $archived->whereNotNull('archived_at')->orWhereNotNull('deleted_at')))
            ->withCount(['parcelas as paid_installments_count' => fn ($parts) => $parts->where('status', 'paid')])
            ->withSum('parcelas as paid_amount_total', 'paid_amount')
            ->withSum('parcelas as installment_amount_total', 'amount');

        if ($status === 'active') {
            $query->whereHas('parcelas', fn ($parts) => $parts
                ->whereColumn('amount', '>', 'paid_amount')
                ->where('status', '!=', 'cancelled'));
        } elseif ($status === 'completed') {
            $query->whereDoesntHave('parcelas', fn ($parts) => $parts
                ->whereColumn('amount', '>', 'paid_amount')
                ->where('status', '!=', 'cancelled'));
        }

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $commitments = $query
            ->orderByRaw("CASE WHEN status = 'paid' THEN 1 ELSE 0 END")
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->paginate($perPage, ['*'], 'purchases')
            ->withQueryString();

        $commitments->getCollection()->each(function (Divida $commitment): void {
            $commitment->setRelation('installmentsPage', $commitment->parcelas()
                ->orderBy('installment_number')
                ->paginate(6, ['*'], 'installments_'.$commitment->id)
                ->withQueryString());

            $next = $commitment->parcelas()
                ->whereColumn('amount', '>', 'paid_amount')
                ->where('status', '!=', 'cancelled')
                ->orderBy('due_date')
                ->orderBy('installment_number')
                ->first();

            $hasOverdue = $commitment->parcelas()->whereColumn('amount', '>', 'paid_amount')
                ->where('status', '!=', 'cancelled')->whereDate('due_date', '<', today())->exists();

            $commitment->setAttribute('next_installment', $next);
            $commitment->setAttribute('has_overdue_installment', $hasOverdue);
            $commitment->setAttribute('remaining_amount', max(0, (float) $commitment->total_amount - (float) $commitment->paid_amount_total));
            $commitment->setAttribute('progress_percent', (float) $commitment->total_amount > 0
                ? min(100, round(((float) $commitment->paid_amount_total / (float) $commitment->total_amount) * 100, 1))
                : 0);
        });

        return view('financial.commitments', compact(
            'paymentAccounts',
            'cards',
            'commitments',
            'summary',
            'status',
            'search',
            'perPage'
        ));
    }
}

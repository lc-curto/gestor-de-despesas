<?php

namespace App\Services\Financial;

use App\Models\Fatura;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardInvoiceService
{
    public function __construct(private readonly FinancialMovementService $movements) {}

    public function purchase(User $user, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $data): Movimento {
            $card = $user->contasFinanceiras()->whereKey($data['account_id'])->lockForUpdate()->firstOrFail();
            if ($card->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Seleciona um cartão de crédito ativo.']);
            }
            if (!$card->closing_day || !$card->due_day) {
                throw ValidationException::withMessages(['account_id' => 'Configura primeiro os dias de fecho e vencimento do cartão.']);
            }
            if ($card->credit_limit !== null) {
                $balances = app(AccountBalanceService::class);
                $balance = $balances->toCents($balances->balance($card));
                $purchase = $balances->toCents((string) $data['amount']);
                if (max(0, -$balance) + $purchase > $balances->toCents((string) $card->credit_limit)) {
                    throw ValidationException::withMessages(['amount' => 'A compra excede o limite disponível do cartão.']);
                }
            }

            $date = Carbon::parse($data['movement_date'])->startOfDay();
            if ($date->greaterThan(today())) {
                throw ValidationException::withMessages(['movement_date' => 'A data da compra no cartão não pode ser futura.']);
            }

            $reference = $date->day > $card->closing_day ? $date->copy()->addMonthNoOverflow() : $date->copy();
            $reference->day = 1;
            $closing = $reference->copy()->day(min($card->closing_day, $reference->daysInMonth));
            $dueMonth = $reference->copy()->addMonthNoOverflow();
            $due = $dueMonth->copy()->day(min($card->due_day, $dueMonth->daysInMonth));
            $invoice = Fatura::query()->firstOrCreate(
                ['user_id' => $user->id, 'account_id' => $card->id, 'reference_month' => $reference->toDateString()],
                ['closing_date' => $closing->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'open']
            );
            $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'paid' || ($invoice->closing_date && $invoice->closing_date->lt(today()))) {
                throw ValidationException::withMessages(['invoice' => 'A fatura deste ciclo já foi fechada. A compra deve entrar no ciclo seguinte.']);
            }

            return $this->movements->create($user, [
                'type' => 'expense',
                'expense_behavior' => $data['expense_behavior'] ?? 'variable',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'movement_date' => $date->toDateString(),
                'source_account_id' => $card->id,
                'category_id' => $data['category_id'] ?? null,
                'invoice_id' => $invoice->id,
                'debt_id' => $data['debt_id'] ?? null,
                'installment_id' => $data['installment_id'] ?? null,
                'legacy_type' => $data['legacy_type'] ?? null,
                'legacy_id' => $data['legacy_id'] ?? null,
                'is_historical' => (bool) ($data['is_historical'] ?? false),
            ]);
        });
    }

    public function settlePendingPurchase(User $user, Movimento $movement, string $cardId): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $cardId): Movimento {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || $locked->type !== 'expense') {
                throw ValidationException::withMessages(['movement' => 'Só previsões de despesa pendentes podem ser liquidadas com cartão.']);
            }

            $card = $user->contasFinanceiras()->whereKey($cardId)->lockForUpdate()->firstOrFail();
            if ($card->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active' || !$card->closing_day || !$card->due_day) {
                throw ValidationException::withMessages(['account_id' => 'Seleciona um cartão ativo e configurado.']);
            }
            $date = Carbon::parse($locked->movement_date)->startOfDay();
            if ($date->greaterThan(today())) {
                throw ValidationException::withMessages(['movement_date' => 'Uma previsão futura pode ser confirmada, mas só liquidada na data prevista.']);
            }

            if ($card->credit_limit !== null) {
                $balances = app(AccountBalanceService::class);
                $used = max(0, -$balances->toCents($balances->balance($card)));
                if ($used + $balances->toCents((string) $locked->amount) > $balances->toCents((string) $card->credit_limit)) {
                    throw ValidationException::withMessages(['amount' => 'A compra excede o limite disponível do cartão.']);
                }
            }

            $reference = $date->day > $card->closing_day ? $date->copy()->addMonthNoOverflow() : $date->copy();
            $reference->day = 1;
            $closing = $reference->copy()->day(min($card->closing_day, $reference->daysInMonth));
            $dueMonth = $reference->copy()->addMonthNoOverflow();
            $due = $dueMonth->copy()->day(min($card->due_day, $dueMonth->daysInMonth));
            $invoice = Fatura::query()->firstOrCreate(
                ['user_id' => $user->id, 'account_id' => $card->id, 'reference_month' => $reference->toDateString()],
                ['closing_date' => $closing->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'open']
            );
            $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'paid' || ($invoice->closing_date && $invoice->closing_date->lt(today()))) {
                throw ValidationException::withMessages(['invoice' => 'A fatura deste ciclo já foi fechada. A compra deve entrar no ciclo seguinte.']);
            }

            return $this->movements->settle($user, $locked, [
                'source_account_id' => $card->id,
                'invoice_id' => $invoice->id,
            ]);
        });
    }

    public function payOpeningDebt(User $user, string $cardId, string $sourceAccountId, string $date, string $requestedAmount): Movimento
    {
        return DB::transaction(function () use ($user, $cardId, $sourceAccountId, $date, $requestedAmount): Movimento {
            $card = $user->contasFinanceiras()->whereKey($cardId)->lockForUpdate()->firstOrFail();
            $source = $user->contasFinanceiras()->whereKey($sourceAccountId)->lockForUpdate()->firstOrFail();
            if ($card->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['card' => 'Cartão não disponível.']);
            }
            if ($source->id === $card->id || !in_array($source->type, ['bank', 'cash'], true) || !$source->is_operational || $source->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Seleciona uma conta bancária ou de dinheiro ativa.']);
            }
            $balances = app(AccountBalanceService::class);
            $remaining = $balances->toCents((string) ($card->opening_debt_amount ?? '0.00')) - $balances->toCents((string) ($card->opening_debt_paid ?? '0.00'));
            $payment = $balances->toCents($requestedAmount);
            if ($remaining <= 0 || $payment <= 0 || $payment > $remaining) {
                throw ValidationException::withMessages(['amount' => 'O pagamento não pode exceder a dívida inicial ainda por pagar.']);
            }
            $movement = $this->movements->transfer($user, [
                'description' => 'Pagamento de dívida inicial · ' . $card->name,
                'amount' => number_format($payment / 100, 2, '.', ''),
                'movement_date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $card->id,
                'opening_debt_card_id' => $card->id,
            ]);
            $card->update(['opening_debt_paid' => number_format(($balances->toCents((string) ($card->opening_debt_paid ?? '0.00')) + $payment) / 100, 2, '.', '')]);
            return $movement;
        });
    }

    public function listInvoices(User $user)
    {
        return Fatura::query()->where('user_id', $user->id)
            ->whereHas('movimentos', fn ($q) => $q->where('type', 'expense')->where('status', 'settled'))
            ->with('conta:id,name,type,currency,credit_limit')
            ->with(['movimentos' => fn ($q) => $q->where('type', 'expense')->where('status', 'settled')->select('id', 'invoice_id', 'description', 'amount', 'movement_date')])
            ->withSum(['movimentos as total_amount' => fn ($q) => $q->where('type', 'expense')->where('status', 'settled')], 'amount')
            ->withSum(['movimentos as paid_amount' => fn ($q) => $q->where('type', 'transfer')->where('status', 'settled')], 'amount')
            ->withCount(['movimentos' => fn ($q) => $q->where('type', 'expense')->where('status', 'settled')])
            ->orderByDesc('reference_month')->paginate(30)
            ->through(function (Fatura $invoice): Fatura {
                $total = (float) $invoice->total_amount;
                $paid = (float) ($invoice->paid_amount ?? 0);
                $remaining = max(0, round($total - $paid, 2));
                $invoice->setAttribute('remaining_amount', number_format($remaining, 2, '.', ''));
                $invoice->setAttribute('display_status', $remaining <= 0 ? 'paid'
                    : ($invoice->due_date->lt(today()) ? 'overdue'
                        : ($paid > 0 ? 'partial'
                            : ($invoice->closing_date && $invoice->closing_date->lt(today()) ? 'closed' : 'open'))));
                return $invoice;
            });
    }

    public function pay(User $user, Fatura $invoice, string $sourceAccountId, string $date, ?string $requestedAmount = null): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $sourceAccountId, $date, $requestedAmount): Movimento {
            $locked = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($locked->account_id)->lockForUpdate()->firstOrFail();
            $source = $user->contasFinanceiras()->whereKey($sourceAccountId)->lockForUpdate()->firstOrFail();

            if ($source->id === $card->id || !in_array($source->type, ['bank', 'cash'], true) || !$source->is_operational || $source->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'A fatura deve ser paga a partir de uma conta bancária ou de dinheiro ativa.']);
            }
            if (!$card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado a esta fatura não está ativo.']);
            }

            $balances = app(AccountBalanceService::class);
            $totalCents = $balances->toCents((string) $locked->movimentos()->where('type', 'expense')->where('status', 'settled')->sum('amount'));
            $paidCents = $balances->toCents((string) $locked->movimentos()->where('type', 'transfer')->where('status', 'settled')->sum('amount'));
            $remainingCents = $totalCents - $paidCents;
            if ($remainingCents <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já está totalmente paga.']);
            }

            $paymentCents = $requestedAmount !== null ? $balances->toCents($requestedAmount) : $remainingCents;
            if ($paymentCents <= 0 || $paymentCents > $remainingCents) {
                throw ValidationException::withMessages(['amount' => 'O pagamento deve ser superior a zero e não pode exceder o valor pendente.']);
            }

            $movement = $this->movements->transfer($user, [
                'description' => 'Pagamento da fatura ' . $card->name . ' · ' . $locked->reference_month->format('m/Y'),
                'amount' => number_format($paymentCents / 100, 2, '.', ''),
                'movement_date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $card->id,
                'invoice_id' => $locked->id,
            ]);

            if ($paymentCents === $remainingCents) {
                $locked->update(['status' => 'paid']);
            }
            return $movement;
        });
    }
}

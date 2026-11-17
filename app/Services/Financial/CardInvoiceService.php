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
            // SQLite pode guardar a data com hora; procura a fatura pelo dia.
            $invoice = Fatura::query()->where('user_id', $user->id)->where('account_id', $card->id)
                ->whereDate('reference_month', $reference->toDateString())->first() ?? Fatura::query()->firstOrCreate(
                ['user_id' => $user->id, 'account_id' => $card->id, 'reference_month' => $reference->toDateString()],
                ['closing_date' => $closing->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'open']
            );
            $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->movements->assertInvoiceReconciled($user, $invoice);
            if ($invoice->status === 'paid' || ($invoice->closing_date && $invoice->closing_date->lt(today()))) {
                throw ValidationException::withMessages(['invoice' => 'A fatura deste ciclo já foi fechada. A compra deve entrar no ciclo seguinte.']);
            }

            $purchase = $this->movements->create($user, [
                'type' => 'expense',
                'expense_behavior' => $data['expense_behavior'] ?? 'variable',
                'description' => $data['description'],
                'payment_method' => 'credit',
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
            $this->movements->syncInvoiceSettlementParent($user, $invoice);
            return $purchase;
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
            // SQLite pode guardar a data com hora; procura a fatura pelo dia.
            $invoice = Fatura::query()->where('user_id', $user->id)->where('account_id', $card->id)
                ->whereDate('reference_month', $reference->toDateString())->first() ?? Fatura::query()->firstOrCreate(
                ['user_id' => $user->id, 'account_id' => $card->id, 'reference_month' => $reference->toDateString()],
                ['closing_date' => $closing->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'open']
            );
            $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->movements->assertInvoiceReconciled($user, $invoice);
            if ($invoice->status === 'paid' || ($invoice->closing_date && $invoice->closing_date->lt(today()))) {
                throw ValidationException::withMessages(['invoice' => 'A fatura deste ciclo já foi fechada. A compra deve entrar no ciclo seguinte.']);
            }

            $settled = $this->movements->settle($user, $locked, [
                'source_account_id' => $card->id,
                'invoice_id' => $invoice->id,
            ]);
            $this->movements->syncInvoiceSettlementParent($user, $invoice);
            return $settled;
        });
    }

    public function payOpeningDebt(User $user, string $cardId, string $sourceAccountId, string $date, string $requestedAmount): Movimento
    {
        return $this->movements->payCardOpeningDebt($user, $cardId, $sourceAccountId, $date, $requestedAmount);
    }

    public function listInvoices(User $user)
    {
        return Fatura::query()->where('user_id', $user->id)
            ->whereHas('movimentos', fn ($q) => $q->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false))
            ->with('conta:id,name,type,currency,credit_limit')
            ->with(['movimentos' => fn ($q) => $q->whereIn('type', ['expense', 'transfer'])->where('status', 'settled')->where('is_settlement_parent', false)
                ->select('id', 'invoice_id', 'description', 'amount', 'movement_date', 'type', 'is_settlement_parent', 'reversal_of_id', 'settles_movement_id')])
            ->withCount(['movimentos' => fn ($q) => $q->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false)])
            ->orderByDesc('reference_month')->paginate(30)
            ->through(function (Fatura $invoice): Fatura {
                $totalCents = 0;
                $paidCents = 0;
                foreach ($invoice->movimentos as $movement) {
                    $cents = (int) round((float) $movement->amount * 100);
                    if ($movement->type === 'expense' && !$movement->is_settlement_parent) $totalCents += $cents;
                    if ($movement->type === 'transfer') $paidCents += $movement->reversal_of_id ? -$cents : $cents;
                }
                $total = $totalCents / 100;
                $paid = $paidCents / 100;
                $remaining = max(0, round($total - $paid, 2));
                $invoice->setAttribute('total_amount', number_format($total, 2, '.', ''));
                $invoice->setAttribute('paid_amount', number_format($paid, 2, '.', ''));
                $invoice->setAttribute('remaining_amount', number_format($remaining, 2, '.', ''));
                $invoice->setAttribute('display_status', $invoice->status === 'carried' ? 'carried'
                    : ($remaining <= 0 ? 'paid'
                        : ($invoice->due_date->lt(today()) ? 'overdue'
                            : ($paid > 0 ? 'partial'
                                : ($invoice->closing_date && $invoice->closing_date->lt(today()) ? 'closed' : 'open')))));
                return $invoice;
            });
    }

    public function addCharge(User $user, Fatura $invoice, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $data): Movimento {
            $locked = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid' || $locked->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'Não é possível adicionar encargos a uma fatura paga ou já transportada.']);
            }
            $card = $user->contasFinanceiras()->whereKey($locked->account_id)->lockForUpdate()->firstOrFail();
            if ($card->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado a esta fatura não está ativo.']);
            }

            $charge = $this->movements->create($user, [
                'type' => 'expense',
                'expense_behavior' => 'fixed',
                'description' => $data['description'],
                'notes' => 'Encargo de fatura: '.$data['charge_type'],
                'payment_method' => 'credit',
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'source_account_id' => $card->id,
                'category_id' => $data['category_id'] ?? null,
                'invoice_id' => $locked->id,
            ]);
            $this->movements->syncInvoiceSettlementParent($user, $locked);
            return $charge;
        });
    }

    public function pay(User $user, Fatura $invoice, string $sourceAccountId, string $date, ?string $requestedAmount = null): Movimento
    {
        return $this->movements->payCardInvoice($user, $invoice, $sourceAccountId, $date, $requestedAmount);
    }
}

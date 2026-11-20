<?php

namespace App\Services\Financial;

use App\Models\Fatura;
use App\Models\FaturaSaldoTransportado;
use App\Models\HistoricoMovimento;
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
            if ($card->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Seleciona um cartão de crédito ativo.']);
            }
            if (! $card->closing_day || ! $card->due_day) {
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
            $invoice = $this->invoiceForCycle($user, $card->id, $reference, $closing, $due);
            $this->movements->assertInvoiceReconciled($user, $invoice);
            $this->carryOverdueInvoices($user, $invoice);
            if ($invoice->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'O saldo deste ciclo já foi transportado. Regista a compra no ciclo atual.']);
            }

            $purchase = $this->movements->createCardPurchase($user, [
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
            if ($card->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active' || ! $card->closing_day || ! $card->due_day) {
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
            $invoice = $this->invoiceForCycle($user, $card->id, $reference, $closing, $due);
            $this->movements->assertInvoiceReconciled($user, $invoice);
            $this->carryOverdueInvoices($user, $invoice);
            if ($invoice->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'O saldo deste ciclo já foi transportado. Regista a compra no ciclo atual.']);
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

    private function invoiceQuery(User $user)
    {
        return Fatura::query()->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereHas('movimentos', fn ($q) => $q->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false))
                    ->orWhereHas('saldosTransportados');
            })
            ->with('conta:id,name,type,currency,credit_limit')
            ->with([
                'movimentos' => fn ($q) => $q->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false)
                    ->select('id', 'invoice_id', 'description', 'amount', 'movement_date', 'type', 'invoice_charge_type', 'is_settlement_parent'),
                'pagamentos' => fn ($q) => $q->where('status', 'settled')
                    ->with(['contaOrigem:id,name,type', 'contaDestino:id,name,type'])
                    ->select('id', 'invoice_id', 'description', 'amount', 'reversal_of_id', 'type', 'movement_date', 'source_account_id', 'destination_account_id'),
                'creditos' => fn ($q) => $q->where('status', 'settled')
                    ->select('id', 'invoice_id', 'description', 'amount', 'movement_date', 'invoice_credit_for_movement_id', 'invoice_charge_type'),
                'saldosTransportados' => fn ($q) => $q->with('sourceInvoice:id,reference_month,closing_date,due_date,status')
                    ->select('id', 'user_id', 'source_invoice_id', 'target_invoice_id', 'amount'),
            ])
            ->withCount(['movimentos' => fn ($q) => $q->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false)]);
    }

    public function listInvoices(User $user, ?string $accountId = null)
    {
        return $this->invoiceQuery($user)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->orderByDesc('reference_month')->paginate(30)
            ->through(fn (Fatura $invoice): Fatura => $this->presentInvoice($invoice));
    }

    public function findInvoice(User $user, string $invoiceId): Fatura
    {
        $invoice = $this->invoiceQuery($user)->whereKey($invoiceId)->firstOrFail();

        return $this->presentInvoice($invoice);
    }

    private function presentInvoice(Fatura $invoice): Fatura
    {
        $totalCents = 0;
        $paidCents = 0;
        foreach ($invoice->movimentos as $movement) {
            if ($movement->type === 'expense' && ! $movement->is_settlement_parent) {
                $totalCents += (int) round((float) $movement->amount * 100);
            }
        }
        $carriedCents = 0;
        foreach ($invoice->saldosTransportados as $carried) {
            $carriedCents += (int) round((float) $carried->amount * 100);
        }
        foreach ($invoice->creditos as $credit) {
            $totalCents -= (int) round((float) $credit->amount * 100);
        }
        foreach ($invoice->pagamentos as $payment) {
            $cents = (int) round((float) $payment->amount * 100);
            $paidCents += $payment->reversal_of_id ? -$cents : $cents;
        }
        $totalCents += $carriedCents;
        $total = $totalCents / 100;
        $paid = $paidCents / 100;
        $remaining = $invoice->status === 'carried' ? 0 : max(0, round($total - $paid, 2));
        $invoice->setAttribute('carried_balance', number_format($carriedCents / 100, 2, '.', ''));
        $invoice->setAttribute('total_amount', number_format($total, 2, '.', ''));
        $invoice->setAttribute('paid_amount', number_format($paid, 2, '.', ''));
        $invoice->setAttribute('remaining_amount', number_format($remaining, 2, '.', ''));
        $cycleStatus = $invoice->status === 'carried'
            || $invoice->status === 'closed'
            || $invoice->closed_at !== null
            || ($invoice->closing_date && $invoice->closing_date->lt(today()))
                ? 'closed'
                : 'open';
        $paymentStatus = $invoice->status === 'carried' ? 'carried'
            : ($remaining <= 0 ? 'paid'
                : ($invoice->due_date->lt(today()) ? 'overdue'
                    : ($paid > 0 ? 'partial' : 'unpaid')));
        $invoice->setAttribute('cycle_status', $cycleStatus);
        $invoice->setAttribute('payment_status', $paymentStatus);
        // Mantém o campo legado para consumidores existentes da API.
        $invoice->setAttribute('display_status', $invoice->status === 'carried' ? 'carried'
            : ($remaining <= 0 ? 'paid'
                : ($invoice->due_date->lt(today()) ? 'overdue'
                    : ($paid > 0 ? 'partial' : ($cycleStatus === 'closed' ? 'closed' : 'open')))));

        return $invoice;
    }

    public function addCharge(User $user, Fatura $invoice, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $data): Movimento {
            // A ordem global é cartão -> fatura, igual à compra normal.
            $invoiceSnapshot = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($invoiceSnapshot->account_id)->lockForUpdate()->firstOrFail();
            $locked = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->account_id !== $card->id) {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado à fatura foi alterado. Atualiza a página e tenta novamente.']);
            }
            if ($locked->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'O saldo desta fatura já foi transportado. Regista o encargo na fatura atual.']);
            }
            $this->carryOverdueInvoices($user, $locked);
            $this->movements->assertInvoiceReconciled($user, $locked);
            if ($card->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado a esta fatura não está ativo.']);
            }
            $originInvoice = null;
            if (! empty($data['origin_invoice_id'])) {
                $originInvoice = Fatura::query()->where('user_id', $user->id)
                    ->whereKey($data['origin_invoice_id'])->lockForUpdate()->firstOrFail();
                if ($originInvoice->account_id !== $locked->account_id
                    || ! $originInvoice->reference_month->lt($locked->reference_month)) {
                    throw ValidationException::withMessages(['origin_invoice_id' => 'A fatura de origem deve pertencer ao mesmo cartão e a um ciclo anterior.']);
                }
            }

            $charge = $this->movements->createCardPurchase($user, [
                'type' => 'expense',
                'expense_behavior' => 'fixed',
                'description' => $data['description'],
                'notes' => 'Encargo de fatura: '.$data['charge_type'],
                'invoice_charge_type' => $data['charge_type'],
                'origin_invoice_id' => $originInvoice?->id,
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

    /**
     * Transporta apenas saldos de faturas cujo vencimento já passou.
     * O transporte é um registo entre faturas, nunca um movimento de caixa ou despesa.
     */
    private function invoiceForCycle(User $user, string $cardId, Carbon $reference, Carbon $closing, Carbon $due): Fatura
    {
        $invoice = Fatura::withTrashed()
            ->where('user_id', $user->id)
            ->where('account_id', $cardId)
            ->whereDate('reference_month', $reference->toDateString())
            ->lockForUpdate()
            ->first();

        if ($invoice?->trashed()) {
            $invoice->restore();
            $invoice->update([
                'closing_date' => $closing->toDateString(),
                'due_date' => $due->toDateString(),
                'status' => 'open',
                'statement_amount' => null,
                'closed_at' => null,
            ]);
        }

        if (! $invoice) {
            $invoice = Fatura::query()->create([
                'user_id' => $user->id,
                'account_id' => $cardId,
                'reference_month' => $reference->toDateString(),
                'closing_date' => $closing->toDateString(),
                'due_date' => $due->toDateString(),
                'status' => 'open',
            ]);
        }

        return Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)
            ->lockForUpdate()->firstOrFail();
    }

    private function carryOverdueInvoices(User $user, Fatura $target): void
    {
        $sources = Fatura::query()->where('user_id', $user->id)
            ->where('account_id', $target->account_id)
            ->whereDate('reference_month', '<', $target->reference_month)
            ->whereDate('due_date', '<', today())
            ->whereNotIn('status', ['paid', 'carried'])
            ->orderBy('reference_month')
            ->lockForUpdate()->get();

        foreach ($sources as $source) {
            if (FaturaSaldoTransportado::query()->where('user_id', $user->id)
                ->where('source_invoice_id', $source->id)->exists()) {
                continue;
            }
            $this->movements->assertInvoiceReconciled($user, $source);

            $remainingCents = $this->invoiceTotalCents($source) - $this->invoicePaidCents($source);
            if ($remainingCents <= 0) {
                $source->update(['status' => 'paid']);

                continue;
            }

            FaturaSaldoTransportado::query()->create([
                'user_id' => $user->id,
                'source_invoice_id' => $source->id,
                'target_invoice_id' => $target->id,
                'amount' => number_format($remainingCents / 100, 2, '.', ''),
            ]);
            $source->update(['status' => 'carried']);
        }
    }

    private function invoiceTotalCents(Fatura $invoice): int
    {
        $total = 0;
        foreach ($invoice->movimentos()->where('type', 'expense')->where('status', 'settled')
            ->where('is_settlement_parent', false)->get(['amount']) as $expense) {
            $total += (int) round((float) $expense->amount * 100);
        }
        $total += (int) round((float) DB::table('fatura_saldos_transportados')
            ->where('user_id', $invoice->user_id)->where('target_invoice_id', $invoice->id)->sum('amount') * 100);
        foreach ($invoice->creditos()->where('status', 'settled')->get(['amount']) as $credit) {
            $total -= (int) round((float) $credit->amount * 100);
        }

        return $total;
    }

    private function invoicePaidCents(Fatura $invoice): int
    {
        $paid = 0;
        foreach ($invoice->pagamentos()->where('status', 'settled')->get(['amount', 'reversal_of_id']) as $payment) {
            $cents = (int) round((float) $payment->amount * 100);
            $paid += $payment->reversal_of_id ? -$cents : $cents;
        }

        return $paid;
    }

    public function addCredit(User $user, Fatura $invoice, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $data): Movimento {
            // Obtém referências sem lock para descobrir o cartão e as duas faturas;
            // depois bloqueia cartão e faturas por ordem cronológica determinística.
            $invoiceSnapshot = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->firstOrFail();
            $purchaseSnapshot = Movimento::query()->where('user_id', $user->id)->whereKey($data['purchase_movement_id'])
                ->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false)->firstOrFail();
            if (! $purchaseSnapshot->invoice_id) {
                throw ValidationException::withMessages(['purchase_movement_id' => 'A compra selecionada não está associada a uma fatura.']);
            }
            $originSnapshot = Fatura::query()->where('user_id', $user->id)->whereKey($purchaseSnapshot->invoice_id)->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($invoiceSnapshot->account_id)->lockForUpdate()->firstOrFail();
            $invoiceIds = array_values(array_unique([$invoice->id, $originSnapshot->id]));
            $lockedInvoices = Fatura::query()->where('user_id', $user->id)->whereIn('id', $invoiceIds)
                ->orderBy('reference_month')->orderBy('id')->lockForUpdate()->get();
            $locked = $lockedInvoices->firstWhere('id', $invoice->id);
            $originInvoice = $lockedInvoices->firstWhere('id', $originSnapshot->id);
            if (! $locked || ! $originInvoice || $locked->account_id !== $card->id || $originInvoice->account_id !== $card->id) {
                throw ValidationException::withMessages(['invoice' => 'As faturas selecionadas não pertencem ao mesmo cartão. Atualiza a página e tenta novamente.']);
            }
            $purchase = Movimento::query()->where('user_id', $user->id)->whereKey($data['purchase_movement_id'])
                ->where('type', 'expense')->where('status', 'settled')->where('is_settlement_parent', false)->firstOrFail();
            if ($purchase->invoice_id !== $originInvoice->id) {
                throw ValidationException::withMessages(['purchase_movement_id' => 'A associação da compra foi alterada. Atualiza a página e tenta novamente.']);
            }
            if ($locked->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'O saldo desta fatura já foi transportado. Regista o crédito na fatura atual.']);
            }
            if ($card->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado a esta fatura não está ativo.']);
            }
            $this->carryOverdueInvoices($user, $locked);
            $this->movements->assertInvoiceReconciled($user, $locked);
            if ($originInvoice->account_id !== $locked->account_id
                || $originInvoice->reference_month->gt($locked->reference_month)
                || (! empty($data['origin_invoice_id']) && $data['origin_invoice_id'] !== $originInvoice->id)) {
                throw ValidationException::withMessages(['purchase_movement_id' => 'A compra deve pertencer a este cartão e a um ciclo igual ou anterior ao da fatura que recebe o crédito.']);
            }

            $alreadyCreditedCents = 0;
            foreach (Movimento::query()->where('user_id', $user->id)->where('invoice_credit_for_movement_id', $purchase->id)
                ->where('status', 'settled')->get(['amount']) as $credit) {
                $alreadyCreditedCents += (int) round((float) $credit->amount * 100);
            }
            $creditCents = (int) round((float) $data['amount'] * 100);
            $purchaseCents = (int) round((float) $purchase->amount * 100);
            if ($creditCents <= 0 || $creditCents > $purchaseCents - $alreadyCreditedCents) {
                throw ValidationException::withMessages(['amount' => 'O crédito excede o valor ainda não creditado desta compra.']);
            }
            $remainingCents = $this->invoiceTotalCents($locked) - $this->invoicePaidCents($locked);
            if ($creditCents > $remainingCents) {
                throw ValidationException::withMessages(['amount' => 'O crédito não pode exceder o saldo ainda em dívida nesta fatura.']);
            }

            $credit = $this->movements->createInvoiceCredit($user, $locked, $card, $purchase, [
                'description' => $data['description'],
                'amount' => number_format($creditCents / 100, 2, '.', ''),
                'movement_date' => $data['movement_date'],
            ]);
            $this->movements->syncInvoiceSettlementParent($user, $locked);

            return $credit;
        });
    }


    /**
     * Corrige uma compra, encargo ou crédito individual da fatura.
     * Pagamentos, saldo transportado e agregados técnicos não são editáveis aqui.
     */
    public function updateInvoiceComponent(User $user, Fatura $invoice, string $movementId, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $movementId, $data): Movimento {
            $snapshot = Movimento::query()->where('user_id', $user->id)
                ->whereKey($movementId)->where('invoice_id', $invoice->id)->firstOrFail();
            $invoiceSnapshot = Fatura::query()->where('user_id', $user->id)
                ->whereKey($invoice->id)->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($invoiceSnapshot->account_id)
                ->where('type', 'credit_card')->lockForUpdate()->firstOrFail();
            $lockedInvoice = Fatura::query()->where('user_id', $user->id)
                ->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($lockedInvoice->status === 'carried') {
                throw ValidationException::withMessages(['movement' => 'Esta fatura já foi transportada e não pode ser alterada.']);
            }

            $movement = Movimento::query()->where('user_id', $user->id)
                ->whereKey($snapshot->id)->where('invoice_id', $lockedInvoice->id)
                ->lockForUpdate()->firstOrFail();

            $isPurchaseOrCharge = $movement->type === 'expense'
                && $movement->status === 'settled'
                && ! $movement->is_settlement_parent
                && ! $movement->debt_id
                && ! $movement->installment_id
                && ! $movement->reversal_of_id;
            $isCredit = $movement->type === 'adjustment'
                && $movement->status === 'settled'
                && $movement->invoice_charge_type === 'credit'
                && $movement->invoice_credit_for_movement_id
                && ! $movement->reversal_of_id;

            if (! $isPurchaseOrCharge && ! $isCredit) {
                throw ValidationException::withMessages(['movement' => 'Este registo não pode ser editado nesta área.']);
            }

            if ($isPurchaseOrCharge && array_key_exists('movement_date', $data)) {
                $date = Carbon::parse($data['movement_date'])->startOfDay();
                if ($date->greaterThan(today())) {
                    throw ValidationException::withMessages(['movement_date' => 'A data do movimento não pode ser futura.']);
                }
                $reference = $date->day > $card->closing_day ? $date->copy()->addMonthNoOverflow() : $date->copy();
                $reference->day = 1;
                if (! $reference->equalTo($lockedInvoice->reference_month->copy()->startOfMonth())) {
                    throw ValidationException::withMessages(['movement_date' => 'A data deve permanecer no ciclo de faturação original. Para mudar de ciclo, regista o movimento no ciclo correto.']);
                }
            }
            if ($isPurchaseOrCharge && Movimento::query()->where('user_id', $user->id)
                ->where('invoice_credit_for_movement_id', $movement->id)
                ->where('status', 'settled')->exists()) {
                throw ValidationException::withMessages(['movement' => 'Esta compra já tem créditos associados. Corrige primeiro os créditos existentes.']);
            }

            $before = $movement->getAttributes();
            $newAmount = array_key_exists('amount', $data)
                ? app(AccountBalanceService::class)->toCents((string) $data['amount'])
                : app(AccountBalanceService::class)->toCents((string) $movement->amount);
            if ($newAmount <= 0) {
                throw ValidationException::withMessages(['amount' => 'O valor deve ser superior a zero.']);
            }

            if ($isCredit) {
                $purchase = Movimento::query()->where('user_id', $user->id)
                    ->whereKey($movement->invoice_credit_for_movement_id)
                    ->where('type', 'expense')->where('status', 'settled')->firstOrFail();
                $otherCredits = Movimento::query()->where('user_id', $user->id)
                    ->where('invoice_credit_for_movement_id', $purchase->id)
                    ->where('status', 'settled')->where('id', '!=', $movement->id)
                    ->get(['amount'])->sum(fn (Movimento $item) => (int) round((float) $item->amount * 100));
                $purchaseCents = (int) round((float) $purchase->amount * 100);
                if ($newAmount + $otherCredits > $purchaseCents) {
                    throw ValidationException::withMessages(['amount' => 'O crédito não pode ultrapassar o valor ainda não creditado da compra.']);
                }
            }

            $movement->update([
                'description' => $data['description'] ?? $movement->description,
                'amount' => number_format($newAmount / 100, 2, '.', ''),
                'movement_date' => $data['movement_date'] ?? $movement->movement_date->toDateString(),
            ]);

            HistoricoMovimento::query()->create([
                'user_id' => $user->id,
                'movement_id' => $movement->id,
                'changed_by' => $user->id,
                'action' => 'invoice_component_updated',
                'before_data' => $before,
                'after_data' => $movement->fresh()->getAttributes(),
                'reason' => 'Movimento da fatura corrigido pelo utilizador.',
            ]);

            $this->movements->syncInvoiceSettlementParent($user, $lockedInvoice);

            return $movement->refresh();
        });
    }

    public function pay(User $user, Fatura $invoice, string $sourceAccountId, string $date, ?string $requestedAmount = null, ?array $allocation = null): Movimento
    {
        return $this->movements->payCardInvoice($user, $invoice, $sourceAccountId, $date, $requestedAmount, $allocation);
    }
}

<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use App\Models\Divida;
use App\Models\Fatura;
use App\Models\HistoricoMovimento;
use App\Models\Movimento;
use App\Models\ParcelaDivida;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialMovementService
{
    public function __construct(private readonly AccountBalanceService $balances) {}

    public function create(User $user, array $data): Movimento
    {
        return $this->createMovement($user, $data, false);
    }

    /**
     * Cria uma compra em cartão apenas quando a fatura pertence ao utilizador
     * e ao próprio cartão. O fluxo de domínio continua responsável pelo limite
     * e pelo ciclo da fatura.
     */
    public function createCardPurchase(User $user, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $data): Movimento {
            $card = $this->account($user, $data['source_account_id'] ?? null);
            $invoice = Fatura::query()
                ->where('user_id', $user->id)
                ->whereKey($data['invoice_id'] ?? null)
                ->lockForUpdate()
                ->firstOrFail();

            if ($data['type'] !== 'expense' || $card?->type !== 'credit_card' || $invoice->account_id !== $card->id) {
                throw ValidationException::withMessages([
                    'account_id' => 'A compra deve usar um cartão e uma fatura pertencentes ao mesmo utilizador.',
                ]);
            }

            return $this->createMovement($user, $data, true);
        });
    }

    /** A via interna permite operações de cartão já validadas pelos serviços de domínio. */
    private function createMovement(User $user, array $data, bool $allowCardTransfer): Movimento
    {
        return DB::transaction(function () use ($user, $data, $allowCardTransfer) {
            $type = $data['type'];
            [$source, $destination] = $this->accountsInLockOrder(
                $user,
                $data['source_account_id'] ?? null,
                $data['destination_account_id'] ?? null
            );
            $this->assertShape($type, $source, $destination, $data['adjustment_direction'] ?? null);
            if (! $allowCardTransfer) {
                $this->assertNoGenericCardMovement($type, $source, $destination);
            }
            $isHistorical = (bool) ($data['is_historical'] ?? false);

            if ($source && ! $isHistorical) {
                $this->balances->assertCanDebit($source, (string) $data['amount']);
            }

            $movement = Movimento::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => $type,
                'expense_behavior' => $type === 'expense' ? ($data['expense_behavior'] ?? 'variable') : 'variable',
                'status' => 'settled',
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'category_id' => $data['category_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'invoice_charge_type' => $data['invoice_charge_type'] ?? null,
                'origin_invoice_id' => $data['origin_invoice_id'] ?? null,
                'invoice_credit_for_movement_id' => $data['invoice_credit_for_movement_id'] ?? null,
                'invoice_payment_allocation' => $data['invoice_payment_allocation'] ?? null,
                'opening_debt_card_id' => $data['opening_debt_card_id'] ?? null,
                'adjustment_direction' => $data['adjustment_direction'] ?? null,
                'is_historical' => $isHistorical,
                'legacy_type' => $data['legacy_type'] ?? null,
                'legacy_id' => $data['legacy_id'] ?? null,
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
                'debt_id' => $data['debt_id'] ?? null,
                'installment_id' => $data['installment_id'] ?? null,
                'settles_movement_id' => $data['settles_movement_id'] ?? null,
                'is_settlement_parent' => (bool) ($data['is_settlement_parent'] ?? false),
                'settled_at' => now(),
            ]);

            $this->audit($user, $movement, 'created', null, $movement->getAttributes());

            return $movement;
        });
    }

    public function createPending(User $user, array $data): Movimento
    {
        if (! in_array($data['type'], ['income', 'expense'], true)) {
            throw ValidationException::withMessages(['type' => 'Só receitas e despesas podem ser criadas como compromisso pendente.']);
        }

        return DB::transaction(function () use ($user, $data) {
            if ($data['type'] === 'income' && ! empty($data['destination_account_id'])
                && $user->contasFinanceiras()->whereKey($data['destination_account_id'])->where('type', 'credit_card')->exists()) {
                throw ValidationException::withMessages(['type' => 'Não é possível registar uma receita diretamente num cartão de crédito. Usa o pagamento da fatura ou da dívida inicial.']);
            }
            $movement = Movimento::query()->create([
                'user_id' => $user->id,
                'type' => $data['type'],
                'expense_behavior' => $data['type'] === 'expense' ? ($data['expense_behavior'] ?? 'variable') : 'variable',
                'status' => 'pending',
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'source_account_id' => $data['source_account_id'] ?? null,
                'destination_account_id' => $data['destination_account_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'recurrence_id' => $data['recurrence_id'] ?? null,
                'recurrence_occurrence_date' => $data['recurrence_occurrence_date'] ?? null,
                'debt_id' => $data['debt_id'] ?? null,
                'installment_id' => $data['installment_id'] ?? null,
                'settles_movement_id' => null,
                'is_settlement_parent' => false,
                'legacy_type' => $data['legacy_type'] ?? null,
                'legacy_id' => $data['legacy_id'] ?? null,
                'is_historical' => false,
                'requires_account_assignment' => empty($data['source_account_id']) && empty($data['destination_account_id']),
            ]);

            $this->audit($user, $movement, 'created_pending', null, $movement->getAttributes());

            return $movement;
        });
    }

    public function transfer(User $user, array $data): Movimento
    {
        $data['type'] = 'transfer';

        return $this->create($user, $data);
    }

    /** A única entrada para transferir para uma fatura também atualiza o seu estado. */
    public function createInvoiceCredit(User $user, Fatura $invoice, ContaFinanceira $card, Movimento $purchase, array $data): Movimento
    {
        return $this->createMovement($user, [
            'type' => 'adjustment',
            'adjustment_direction' => 'increase',
            'description' => $data['description'],
            'amount' => $data['amount'],
            'movement_date' => $data['movement_date'],
            'destination_account_id' => $card->id,
            'invoice_id' => $invoice->id,
            'invoice_charge_type' => 'credit',
            'origin_invoice_id' => $purchase->invoice_id,
            'invoice_credit_for_movement_id' => $purchase->id,
        ], true);
    }

    public function payCardInvoice(User $user, Fatura $invoice, string $sourceAccountId, string $date, ?string $requestedAmount = null, ?array $allocation = null): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $sourceAccountId, $date, $requestedAmount, $allocation): Movimento {
            $locked = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($locked->account_id)->lockForUpdate()->firstOrFail();
            $source = $user->contasFinanceiras()->whereKey($sourceAccountId)->lockForUpdate()->firstOrFail();

            if ($source->id === $card->id || ! in_array($source->type, ['bank', 'cash'], true) || ! $source->is_operational || $source->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'A fatura deve ser paga a partir de uma conta bancária ou de dinheiro ativa.']);
            }
            if ($card->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado a esta fatura não está ativo.']);
            }
            if ($locked->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já foi transportada para um ciclo seguinte. Paga o saldo na fatura de destino.']);
            }

            $this->assertInvoiceReconciled($user, $locked);
            $parent = $this->syncInvoiceSettlementParent($user, $locked);
            if (! $parent) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura não possui valores reconhecidos para pagamento.']);
            }

            $alreadySettled = $this->netSettledCents($user, $parent);
            $totalCents = $this->balances->toCents((string) $parent->amount);
            $remainingCents = $totalCents - $alreadySettled;
            if ($remainingCents <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já está totalmente paga.']);
            }

            $paymentCents = $requestedAmount !== null ? $this->balances->toCents($requestedAmount) : $remainingCents;
            if ($paymentCents <= 0 || $paymentCents > $remainingCents) {
                throw ValidationException::withMessages(['amount' => 'O pagamento deve ser superior a zero e não pode exceder o valor pendente.']);
            }
            if ($allocation !== null) {
                $allocationCents = 0;
                foreach ($allocation as $componentAmount) {
                    if ($componentAmount !== null && $componentAmount !== '') {
                        $allocationCents += $this->balances->toCents((string) $componentAmount);
                    }
                }
                if ($allocationCents !== $paymentCents) {
                    throw ValidationException::withMessages(['allocation' => 'A decomposição fornecida pelo banco deve somar exatamente o valor do pagamento.']);
                }
            }

            $event = $this->createMovement($user, [
                'type' => 'transfer',
                'description' => 'Pagamento da fatura '.$card->name.' · '.$locked->reference_month->format('m/Y'),
                'amount' => number_format($paymentCents / 100, 2, '.', ''),
                'movement_date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $card->id,
                'invoice_id' => $locked->id,
                'invoice_payment_allocation' => $allocation,
                'settles_movement_id' => $parent->id,
            ], true);

            $this->refreshSettlementParentAfterDeletion($user, $parent->id);

            return $event->refresh();
        });
    }

    /**
     * Mantém uma obrigação técnica por fatura. As compras e encargos continuam
     * a ser as despesas económicas; este agregado serve apenas para liquidação.
     */
    public function syncInvoiceSettlementParent(User $user, Fatura $invoice): ?Movimento
    {
        return DB::transaction(function () use ($user, $invoice): ?Movimento {
            $lockedInvoice = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($lockedInvoice->account_id)->firstOrFail();
            $totalCents = 0;
            $expenses = $lockedInvoice->movimentos()
                ->where('type', 'expense')->where('status', 'settled')
                ->where('is_settlement_parent', false)->get(['amount']);
            foreach ($expenses as $expense) {
                $totalCents += $this->balances->toCents((string) $expense->amount);
            }
            $carriedCents = $this->balances->toCents(number_format((float) DB::table('fatura_saldos_transportados')
                ->where('user_id', $user->id)->where('target_invoice_id', $lockedInvoice->id)->sum('amount'), 2, '.', ''));
            $creditCents = 0;
            foreach ($lockedInvoice->creditos()->where('status', 'settled')->get(['amount']) as $credit) {
                $creditCents += $this->balances->toCents((string) $credit->amount);
            }
            $totalCents += $carriedCents - $creditCents;

            $parent = Movimento::query()->where('user_id', $user->id)
                ->where('invoice_id', $lockedInvoice->id)
                ->where('is_settlement_parent', true)
                ->where('type', 'expense')
                ->lockForUpdate()->first();
            if ($totalCents <= 0) {
                if ($parent) {
                    $before = $parent->getAttributes();
                    $parent->update(['status' => 'void', 'is_settlement_parent' => true, 'settled_at' => null]);
                    $this->audit($user, $parent, 'invoice_cancelled', $before, $parent->getAttributes());
                }

                $hasActiveComponents = $lockedInvoice->movimentos()
                    ->where('is_settlement_parent', false)
                    ->whereIn('status', ['pending', 'partial', 'settled'])
                    ->exists();
                $hasCarry = DB::table('fatura_saldos_transportados')
                    ->where('user_id', $user->id)
                    ->where(function ($query) use ($lockedInvoice): void {
                        $query->where('source_invoice_id', $lockedInvoice->id)
                            ->orWhere('target_invoice_id', $lockedInvoice->id);
                    })->exists();

                if (! $hasActiveComponents && ! $hasCarry) {
                    // A fatura é uma agregação derivada: sem movimentos de origem,
                    // deixa de existir como fatura ativa, mantendo o tombstone.
                    $lockedInvoice->delete();
                } else {
                    $lockedInvoice->update(['status' => 'paid']);
                }

                return null;
            }

            $paidCents = 0;
            $payments = $lockedInvoice->movimentos()->where('type', 'transfer')->where('status', 'settled')
                ->get(['amount', 'reversal_of_id']);
            foreach ($payments as $payment) {
                $cents = $this->balances->toCents((string) $payment->amount);
                $paidCents += $payment->reversal_of_id ? -$cents : $cents;
            }
            if ($paidCents > $totalCents) {
                throw ValidationException::withMessages(['invoice' => 'O total da fatura ficou abaixo dos pagamentos já registados. É necessária uma correção explícita antes de continuar.']);
            }

            $status = $paidCents <= 0 ? 'pending' : ($paidCents >= $totalCents ? 'settled' : 'partial');
            $amount = number_format($totalCents / 100, 2, '.', '');
            if (! $parent) {
                $parent = $this->createPending($user, [
                    'type' => 'expense',
                    'description' => 'Fatura '.$card->name.' · '.$lockedInvoice->reference_month->format('m/Y'),
                    'amount' => $amount,
                    'movement_date' => $lockedInvoice->due_date->toDateString(),
                    'source_account_id' => $card->id,
                ]);
                $before = $parent->getAttributes();
                $parent->update([
                    'invoice_id' => $lockedInvoice->id,
                    'is_settlement_parent' => true,
                    'status' => $status,
                    'settled_at' => $status === 'settled' ? now() : null,
                ]);
                $this->audit($user, $parent, 'invoice_obligation_created', $before, $parent->getAttributes());
            } else {
                $before = $parent->getAttributes();
                $parent->update([
                    'amount' => $amount,
                    'movement_date' => $lockedInvoice->due_date->toDateString(),
                    'status' => $status,
                    'is_settlement_parent' => true,
                    'settled_at' => $status === 'settled' ? now() : null,
                ]);
                if ($before !== $parent->getAttributes()) {
                    $this->audit($user, $parent, 'invoice_obligation_updated', $before, $parent->getAttributes());
                }
            }
            $invoiceStatus = $status === 'settled' ? 'paid'
                : ($lockedInvoice->closed_at ? 'closed' : ($status === 'partial' ? 'partial' : 'open'));
            $lockedInvoice->update(['status' => $invoiceStatus]);

            return $parent->refresh();
        });
    }

    /** A fatura deve estar bloqueada pela transação que vai alterá-la. */
    public function assertInvoiceReconciled(User $user, Fatura $invoice): void
    {
        // Movimentos históricos ligados a correções antigas exigem reconciliação antes de alterar a fatura.
        if ($invoice->movimentos()->where('user_id', $user->id)->whereIn('id', DB::table('movimentos')
            ->select('reversal_of_id')->where('user_id', $user->id)->whereNotNull('reversal_of_id')
            ->whereNull('settles_movement_id'))->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Esta fatura contém uma referência histórica de correção que exige reconciliação antes de novos lançamentos ou pagamentos.',
            ]);
        }
    }

    /** A única entrada para pagar dívida inicial também atualiza o respetivo contador. */
    public function payCardOpeningDebt(User $user, string $cardId, string $sourceAccountId, string $date, string $requestedAmount): Movimento
    {
        return DB::transaction(function () use ($user, $cardId, $sourceAccountId, $date, $requestedAmount): Movimento {
            $card = $user->contasFinanceiras()->whereKey($cardId)->lockForUpdate()->firstOrFail();
            $source = $user->contasFinanceiras()->whereKey($sourceAccountId)->lockForUpdate()->firstOrFail();
            if ($card->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['card' => 'Cartão não disponível.']);
            }
            if ($source->id === $card->id || ! in_array($source->type, ['bank', 'cash'], true) || ! $source->is_operational || $source->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Seleciona uma conta bancária ou de dinheiro ativa.']);
            }
            $paidCents = $this->balances->toCents((string) ($card->opening_debt_paid ?? '0.00'));
            $remaining = $this->balances->toCents((string) ($card->opening_debt_amount ?? '0.00')) - $paidCents;
            $payment = $this->balances->toCents($requestedAmount);
            if ($remaining <= 0 || $payment <= 0 || $payment > $remaining) {
                throw ValidationException::withMessages(['amount' => 'O pagamento não pode exceder a dívida inicial ainda por pagar.']);
            }
            $movement = $this->createMovement($user, [
                'type' => 'transfer',
                'description' => 'Pagamento de dívida inicial · '.$card->name,
                'amount' => number_format($payment / 100, 2, '.', ''),
                'movement_date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $card->id,
                'opening_debt_card_id' => $card->id,
            ], true);
            $card->update(['opening_debt_paid' => number_format(($paidCents + $payment) / 100, 2, '.', '')]);

            return $movement;
        });
    }

    /**
     * Regista cada liquidação como um movimento efetivo associado à obrigação original.
     * O original conserva o valor contratado; os eventos associados determinam o saldo.
     */
    public function settle(User $user, Movimento $movement, array $accounts): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $accounts): Movimento {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            // Compra em cartão é reconhecimento da despesa na fatura, não liquidação de caixa.
            if ($locked->type === 'expense' && ! empty($accounts['invoice_id'])) {
                $card = $this->account($user, $accounts['source_account_id'] ?? $locked->source_account_id);
                $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($accounts['invoice_id'])->lockForUpdate()->firstOrFail();
                if ($card?->type !== 'credit_card' || ! $card->is_operational || $card->status !== 'active'
                    || $invoice->account_id !== $card->id || $locked->is_settlement_parent) {
                    throw ValidationException::withMessages(['type' => 'A compra deve estar associada a uma fatura do cartão selecionado.']);
                }
                if ($locked->status !== 'pending') {
                    throw ValidationException::withMessages(['movement' => 'Só previsões pendentes podem ser reconhecidas numa fatura.']);
                }
                $before = $locked->getAttributes();
                $locked->update([
                    'source_account_id' => $card->id,
                    'invoice_id' => $invoice->id,
                    'requires_account_assignment' => false,
                    'status' => 'settled',
                    'settled_at' => now(),
                ]);
                $this->audit($user, $locked, 'settled', $before, $locked->getAttributes(), 'Compra reconhecida na fatura do cartão.');

                return $locked->refresh();
            }

            if ($locked->is_settlement_parent) {
                if ($locked->status !== 'partial') {
                    throw ValidationException::withMessages(['movement' => 'Esta obrigação já está totalmente liquidada ou encerrada.']);
                }
            } elseif ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['movement' => 'Só é possível liquidar movimentos pendentes ou parcialmente liquidados.']);
            }
            if (! in_array($locked->type, ['expense', 'income'], true)) {
                throw ValidationException::withMessages(['type' => 'Transferências e ajustes não podem usar o fluxo de liquidação de despesas/receitas; operações com cartão devem passar pela fatura.']);
            }

            $source = $locked->type === 'expense'
                ? $this->account($user, $accounts['source_account_id'] ?? $locked->source_account_id)
                : null;
            $destination = $locked->type === 'income'
                ? $this->account($user, $accounts['destination_account_id'] ?? $locked->destination_account_id)
                : null;
            $this->assertShape($locked->type, $source, $destination, null);
            $this->assertNoGenericCardMovement($locked->type, $source, $destination);
            $account = $locked->type === 'expense' ? $source : $destination;
            if ($account?->type === 'credit_card') {
                throw ValidationException::withMessages(['type' => 'Compras em cartão devem ser reconhecidas através da fatura do cartão.']);
            }
            if (! $account || ! in_array($account->type, ['bank', 'cash'], true) || ! $account->is_operational || $account->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'Seleciona uma conta bancária ou de dinheiro ativa para registar a liquidação.']);
            }

            $alreadySettled = $locked->is_settlement_parent ? $this->netSettledCents($user, $locked) : 0;
            $originalCents = $this->balances->toCents((string) $locked->amount);
            $remainingCents = $originalCents - $alreadySettled;
            $paymentCents = isset($accounts['amount'])
                ? $this->balances->toCents((string) $accounts['amount'])
                : $remainingCents;
            if ($remainingCents <= 0 || $paymentCents <= 0 || $paymentCents > $remainingCents) {
                throw ValidationException::withMessages(['amount' => 'O valor da liquidação deve ser positivo e não pode exceder o saldo pendente.']);
            }

            $event = $this->createMovement($user, [
                'type' => $locked->type,
                'description' => 'Liquidação: '.$locked->description,
                'amount' => number_format($paymentCents / 100, 2, '.', ''),
                'movement_date' => $accounts['movement_date'] ?? now()->toDateString(),
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'category_id' => $locked->category_id,
                'expense_behavior' => $locked->expense_behavior,
                'settles_movement_id' => $locked->id,
            ], false);

            $before = $locked->getAttributes();
            $newSettledCents = $alreadySettled + $paymentCents;
            $locked->update([
                'status' => $newSettledCents >= $originalCents ? 'settled' : 'partial',
                'source_account_id' => $locked->type === 'expense' ? ($locked->source_account_id ?? $source->id) : null,
                'destination_account_id' => $locked->type === 'income' ? ($locked->destination_account_id ?? $destination->id) : null,
                'is_settlement_parent' => true,
                'requires_account_assignment' => false,
                'settled_at' => $newSettledCents >= $originalCents ? now() : null,
            ]);
            $this->audit(
                $user,
                $locked,
                $newSettledCents >= $originalCents ? 'settled' : 'partially_settled',
                $before,
                $locked->getAttributes(),
                'Liquidação '.$event->id.' registada por '.number_format($paymentCents / 100, 2, '.', '').'.'
            );

            $this->syncInstallmentFromSettlementParent($user, $locked);

            $result = $locked->refresh();
            $result->setAttribute('last_settlement', $event);

            return $result;
        });
    }

    /** Total líquido liquidado, considerando também referências históricas de correção. */
    private function netSettledCents(User $user, Movimento $original): int
    {
        $events = Movimento::query()
            ->where('user_id', $user->id)
            ->where('settles_movement_id', $original->id)
            ->where('status', 'settled')
            ->get(['amount', 'reversal_of_id']);

        $net = 0;
        foreach ($events as $event) {
            $cents = $this->balances->toCents((string) $event->amount);
            $net += $event->reversal_of_id ? -$cents : $cents;
        }

        // Pagamentos anteriores à associação ao motor central permanecem válidos.
        if ($original->invoice_id) {
            $legacyPayments = Movimento::query()->where('user_id', $user->id)
                ->where('invoice_id', $original->invoice_id)->where('type', 'transfer')
                ->where('status', 'settled')->whereNull('settles_movement_id')
                ->whereNull('reversal_of_id')->get(['amount']);
            foreach ($legacyPayments as $payment) {
                $net += $this->balances->toCents((string) $payment->amount);
            }
        }

        return $net;
    }

    /** Recalcula a obrigação depois da eliminação de um evento de liquidação associado. */
    public function refreshSettlementParentAfterDeletion(User $user, string $originalId): void
    {
        $parent = Movimento::query()->where('user_id', $user->id)->whereKey($originalId)->lockForUpdate()->first();
        if (! $parent || ! $parent->is_settlement_parent) {
            return;
        }

        $net = $this->netSettledCents($user, $parent);
        $total = $this->balances->toCents((string) $parent->amount);
        $isInvoiceParent = $parent->invoice_id !== null;
        $parentStatus = $net <= 0 ? 'pending' : ($net >= $total ? 'settled' : 'partial');
        $parent->update([
            'status' => $parentStatus,
            'is_settlement_parent' => $isInvoiceParent || $net > 0,
            'settled_at' => $parentStatus === 'settled' ? now() : null,
        ]);
        if ($isInvoiceParent) {
            $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($parent->invoice_id)->first();
            if ($invoice) {
                $invoiceStatus = $parentStatus === 'settled' ? 'paid'
                    : ($invoice->closed_at ? 'closed' : ($parentStatus === 'partial' ? 'partial' : 'open'));
                $invoice->update(['status' => $invoiceStatus]);
            }
        }
        $this->audit($user, $parent, 'settlement_recalculated', null, $parent->getAttributes(), 'Estado recalculado após eliminação de um evento de pagamento.');
        $this->syncInstallmentFromSettlementParent($user, $parent);
    }



    public function updatePending(User $user, Movimento $movement, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $data) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['movement' => 'Só movimentos pendentes podem ser editados. Um movimento liquidado não pode ser alterado; elimina-o pela ação de domínio, se a propagação for permitida, e regista o movimento correto.']);
            }
            $this->assertNotCommitmentForecast($locked);
            $before = $locked->getAttributes();
            $allowed = ['description', 'notes', 'payment_method', 'amount', 'movement_date', 'category_id', 'expense_behavior'];
            $locked->fill(array_intersect_key($data, array_flip($allowed)));
            $locked->save();
            $this->audit($user, $locked, 'updated', $before, $locked->getAttributes());

            return $locked->refresh();
        });
    }

    /**
     * Atualiza os termos do movimento original sem alterar os eventos de liquidação.
     * O novo valor nunca pode ficar abaixo do total líquido já pago/recebido.
     */
    public function updateSettlementParent(User $user, Movimento $movement, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $data): Movimento {
            $locked = Movimento::query()
                ->where('user_id', $user->id)
                ->whereKey($movement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->is_settlement_parent
                || ! in_array($locked->status, ['partial', 'settled'], true)
                || $locked->installment_id
                || $locked->debt_id
                || $locked->invoice_id
                || $locked->recurrence_id
                || ! in_array($locked->type, ['expense', 'income'], true)) {
                throw ValidationException::withMessages([
                    'movement' => 'Este movimento não pode ser ajustado por esta operação. Revê a origem da obrigação.',
                ]);
            }

            $paidCents = $this->netSettledCents($user, $locked);
            $newAmountCents = array_key_exists('amount', $data)
                ? $this->balances->toCents((string) $data['amount'])
                : $this->balances->toCents((string) $locked->amount);

            if ($newAmountCents < $paidCents) {
                throw ValidationException::withMessages([
                    'amount' => 'O novo valor não pode ser inferior ao total já liquidado. Regista primeiro um ajuste explícito para tratar o excedente.',
                ]);
            }

            $changes = array_intersect_key($data, array_flip([
                'description',
                'notes',
                'amount',
                'movement_date',
                'category_id',
                'expense_behavior',
                'payment_method',
            ]));

            $accountKey = $locked->type === 'expense' ? 'source_account_id' : 'destination_account_id';
            if (! empty($data[$accountKey]) && $data[$accountKey] !== $locked->{$accountKey}) {
                $account = $this->account($user, $data[$accountKey]);
                $this->assertNoGenericCardMovement(
                    $locked->type,
                    $locked->type === 'expense' ? $account : null,
                    $locked->type === 'income' ? $account : null
                );
                if (! in_array($account?->type, ['bank', 'cash'], true)
                    || ! $account->is_operational
                    || $account->status !== 'active') {
                    throw ValidationException::withMessages([
                        'account_id' => 'Seleciona uma conta bancária ou de dinheiro ativa.',
                    ]);
                }
                $changes[$accountKey] = $account->id;
            }

            $status = $newAmountCents === $paidCents ? 'settled' : 'partial';
            $changes['status'] = $status;
            $changes['is_settlement_parent'] = true;
            $changes['settled_at'] = $status === 'settled' ? ($locked->settled_at ?? now()) : null;

            $before = $locked->getAttributes();
            $locked->update($changes);
            $this->audit(
                $user,
                $locked,
                'settlement_parent_updated',
                $before,
                $locked->getAttributes(),
                'Termos do movimento original ajustados sem alterar o histórico de liquidações.'
            );

            return $locked->refresh();
        });
    }

    /** Uso interno pelo parcelamento: sincroniza apenas o nome e o vencimento. */
    public function updateCommitmentForecast(User $user, Divida $debt, ParcelaDivida $part, Movimento $movement): Movimento
    {
        return DB::transaction(function () use ($user, $debt, $part, $movement): Movimento {
            $lockedDebt = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $lockedPart = ParcelaDivida::query()->where('user_id', $user->id)->where('debt_id', $lockedDebt->id)->whereKey($part->id)->lockForUpdate()->firstOrFail();
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $validForecastState = $locked->status === 'pending'
                || ($locked->status === 'partial' && $locked->is_settlement_parent);
            if (in_array($lockedDebt->status, ['cancelled', 'paid'], true) || ! in_array($lockedPart->status, ['pending', 'partial'], true)
                || ! $validForecastState || $locked->debt_id !== $lockedDebt->id || $locked->installment_id !== $lockedPart->id) {
                throw ValidationException::withMessages(['movement' => 'Não é possível sincronizar esta previsão. Revê o parcelamento em Parcelamentos.']);
            }
            $before = $locked->getAttributes();
            $legacyPaidCents = Movimento::query()->where('user_id', $user->id)
                ->where('installment_id', $lockedPart->id)->whereNull('settles_movement_id')
                ->whereNull('reversal_of_id')->where('status', 'settled')->get(['amount'])
                ->sum(fn (Movimento $item) => $this->balances->toCents((string) $item->amount));
            $remainingCents = max(0, $this->balances->toCents((string) $lockedPart->amount) - $this->balances->toCents((string) $lockedPart->paid_amount));
            $forecastAmountCents = $locked->is_settlement_parent
                ? max(0, $this->balances->toCents((string) $lockedPart->amount) - (int) $legacyPaidCents)
                : $remainingCents;
            $changes = [
                'description' => $lockedDebt->name.' · Parcela '.$lockedPart->installment_number.'/'.$lockedDebt->installments_count,
                'movement_date' => $lockedPart->due_date->toDateString(),
            ];
            if (! $locked->is_settlement_parent || $forecastAmountCents !== $this->balances->toCents((string) $locked->amount)) {
                $changes['amount'] = number_format($forecastAmountCents / 100, 2, '.', '');
            }
            $locked->update($changes);
            $this->audit($user, $locked, 'updated', $before, $locked->getAttributes());

            return $locked->refresh();
        });
    }

    /** Anula previsões antigas substituídas por uma renegociação, sem tocar em pagamentos liquidados. */
    public function deleteAsCorrection(
        User $user,
        Movimento $movement,
        ?string $reason = null,
        bool $deleteLegacy = true,
        bool $detachRecurrence = false
    ): Movimento {
        return app(FinancialDeletionService::class)->deleteMovement(
            $user,
            $movement,
            $reason ?? 'Movimento eliminado pelo utilizador.',
            $deleteLegacy,
            $detachRecurrence
        );
    }



    private function account(User $user, ?string $id): ?ContaFinanceira
    {
        if (! $id) {
            return null;
        }
        $account = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->first();
        if (! $account || ! $account->is_operational || $account->status !== 'active') {
            throw ValidationException::withMessages(['account' => 'Conta inexistente, encerrada ou ainda não configurada.']);
        }
        if ($account->currency !== $user->currency) {
            throw ValidationException::withMessages(['account' => 'A moeda da conta não corresponde à moeda do utilizador.']);
        }

        return $account;
    }

    /**
     * Bloqueia contas envolvidas na mesma ordem em qualquer transferência,
     * evitando ciclos de lock quando duas transferências ocorrem em sentidos opostos.
     *
     * @return array{0: ?ContaFinanceira, 1: ?ContaFinanceira}
     */
    private function accountsInLockOrder(User $user, ?string $sourceId, ?string $destinationId): array
    {
        $ids = array_values(array_unique(array_filter([$sourceId, $destinationId])));
        sort($ids, SORT_STRING);

        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = $this->account($user, $id);
        }

        return [
            $sourceId ? $locked[$sourceId] : null,
            $destinationId ? $locked[$destinationId] : null,
        ];
    }

    private function assertShape(string $type, ?ContaFinanceira $source, ?ContaFinanceira $destination, ?string $direction): void
    {
        $valid = match ($type) {
            'income' => ! $source && (bool) $destination,
            'expense' => (bool) $source && ! $destination,
            'transfer' => $source && $destination && $source->id !== $destination->id,
            'adjustment' => ($direction === 'increase' && ! $source && $destination) || ($direction === 'decrease' && $source && ! $destination),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['type' => 'A combinação de tipo e contas do movimento é inválida.']);
        }
    }

    private function assertNotCommitmentForecast(Movimento $movement): void
    {
        if ($movement->installment_id || $movement->debt_id) {
            throw ValidationException::withMessages(['movement' => 'Esta previsão está ligada a um compromisso. Para editá-la ou cancelá-la, usa Parcelamentos.']);
        }
    }

    private function assertNoGenericCardMovement(string $type, ?ContaFinanceira $source, ?ContaFinanceira $destination): void
    {
        if ($type === 'transfer' && ($source?->type === 'credit_card' || $destination?->type === 'credit_card')) {
            throw ValidationException::withMessages([
                'type' => 'Não é possível transferir genericamente de ou para um cartão. Para pagar, usa o pagamento da fatura ou da dívida inicial; para compras, regista uma compra no cartão.',
            ]);
        }
        if (($type === 'expense' && $source?->type === 'credit_card')
            || ($type === 'income' && $destination?->type === 'credit_card')
            || ($type === 'adjustment' && ($source?->type === 'credit_card' || $destination?->type === 'credit_card'))) {
            throw ValidationException::withMessages([
                'type' => 'Não é possível registar despesas, receitas ou ajustes genéricos num cartão de crédito. Para compras, usa o registo de compra na fatura; para pagar, usa o pagamento da fatura ou da dívida inicial.',
            ]);
        }
    }

    private function audit(User $user, Movimento $movement, string $action, ?array $before, ?array $after, ?string $reason = null): void
    {
        HistoricoMovimento::query()->create([
            'user_id' => $user->id,
            'movement_id' => $movement->id,
            'changed_by' => $user->id,
            'action' => $action,
            'before_data' => $before,
            'after_data' => $after,
            'reason' => $reason,
        ]);
    }
    private function syncInstallmentFromSettlementParent(User $user, Movimento $parent): void
    {
        if (! $parent->installment_id) {
            return;
        }

        $installment = ParcelaDivida::query()
            ->where('user_id', $user->id)
            ->whereKey($parent->installment_id)
            ->lockForUpdate()
            ->first();
        if (! $installment) {
            return;
        }

        $legacyPaidCents = Movimento::query()
            ->where('user_id', $user->id)
            ->where('installment_id', $installment->id)
            ->whereNull('settles_movement_id')
            ->whereNull('reversal_of_id')
            ->where('status', 'settled')
            ->get(['amount'])
            ->sum(fn (Movimento $item) => $this->balances->toCents((string) $item->amount));

        $parentIds = Movimento::query()
            ->where('user_id', $user->id)
            ->where('installment_id', $installment->id)
            ->whereNull('settles_movement_id')
            ->pluck('id');

        $eventCents = Movimento::query()
            ->where('user_id', $user->id)
            ->whereIn('settles_movement_id', $parentIds)
            ->where('status', 'settled')
            ->get(['amount', 'reversal_of_id'])
            ->sum(fn (Movimento $item) => ($item->reversal_of_id ? -1 : 1) * $this->balances->toCents((string) $item->amount));

        $paidCents = max(0, min(
            $this->balances->toCents((string) $installment->amount),
            (int) $legacyPaidCents + (int) $eventCents
        ));
        $totalCents = $this->balances->toCents((string) $installment->amount);
        $newStatus = $installment->status === 'cancelled'
            ? 'cancelled'
            : ($paidCents <= 0 ? 'pending' : ($paidCents >= $totalCents ? 'paid' : 'partial'));

        $installment->update([
            'paid_amount' => number_format($paidCents / 100, 2, '.', ''),
            'status' => $newStatus,
        ]);

        $debt = Divida::query()->where('user_id', $user->id)->whereKey($installment->debt_id)->lockForUpdate()->first();
        if ($debt && $debt->status !== 'cancelled') {
            $allPaid = ! $debt->parcelas()->where('status', '!=', 'paid')->exists();
            $debt->update(['status' => $allPaid ? 'paid' : 'open']);
        }
    }

    public function confirmPending(User $user, Movimento $movement, array $accounts): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $accounts) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['movement' => 'Só é possível confirmar previsões pendentes.']);
            }
            $this->assertNotCommitmentForecast($locked);

            [$source, $destination] = $this->accountsInLockOrder(
                $user,
                $accounts['source_account_id'] ?? null,
                $accounts['destination_account_id'] ?? null
            );
            $this->assertShape($locked->type, $source, $destination, $locked->adjustment_direction);
            $this->assertNoGenericCardMovement($locked->type, $source, $destination);

            $before = $locked->getAttributes();
            $locked->update([
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'requires_account_assignment' => false,
            ]);
            $this->audit($user, $locked, 'confirmed', $before, $locked->getAttributes());

            return $locked->refresh();
        });
    }

    public function assignLegacyAccount(User $user, Movimento $movement, string $accountId): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $accountId) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'settled' || ! $locked->legacy_type || ! $locked->legacy_id) {
                throw ValidationException::withMessages(['account_id' => 'Só é possível associar movimentos existentes importados.']);
            }
            if (! in_array($locked->type, ['expense', 'income'], true)) {
                throw ValidationException::withMessages(['account_id' => 'Este tipo de movimento não pode ser associado por este formulário.']);
            }

            $account = $this->account($user, $accountId);
            $currentId = $locked->type === 'expense' ? $locked->source_account_id : $locked->destination_account_id;
            if ($currentId === $account->id) {
                return $locked;
            }
            $this->assertNoGenericCardMovement(
                $locked->type,
                $locked->type === 'expense' ? $account : null,
                $locked->type === 'income' ? $account : null
            );

            if (! $locked->is_historical && $locked->type === 'expense') {
                $this->balances->assertCanDebit($account, (string) $locked->amount);
            }

            $before = $locked->getAttributes();
            $locked->update($locked->type === 'expense'
                ? ['source_account_id' => $account->id, 'destination_account_id' => null, 'requires_account_assignment' => false]
                : ['source_account_id' => null, 'destination_account_id' => $account->id, 'requires_account_assignment' => false]);
            $this->audit(
                $user,
                $locked,
                $locked->is_historical ? 'historical_account_assigned' : 'account_reassigned',
                $before,
                $locked->getAttributes()
            );

            return $locked->refresh();
        });
    }
}

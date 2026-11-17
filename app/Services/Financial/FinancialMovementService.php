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

    /** Apenas pagamentos de cartão validados e estornos comprovados usam a via interna. */
    private function createMovement(User $user, array $data, bool $allowCardTransfer): Movimento
    {
        return DB::transaction(function () use ($user, $data, $allowCardTransfer) {
            $type = $data['type'];
            $source = $this->account($user, $data['source_account_id'] ?? null);
            $destination = $this->account($user, $data['destination_account_id'] ?? null);
            $this->assertShape($type, $source, $destination, $data['adjustment_direction'] ?? null);
            if (!$allowCardTransfer) $this->assertNoGenericCardMovement($type, $source, $destination);
            $isHistorical = (bool) ($data['is_historical'] ?? false);

            if ($source && !$isHistorical) {
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
        if (!in_array($data['type'], ['income', 'expense'], true)) {
            throw ValidationException::withMessages(['type' => 'Só receitas e despesas podem ser criadas como compromisso pendente.']);
        }

        return DB::transaction(function () use ($user, $data) {
            if ($data['type'] === 'income' && !empty($data['destination_account_id'])
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
    public function payCardInvoice(User $user, Fatura $invoice, string $sourceAccountId, string $date, ?string $requestedAmount = null): Movimento
    {
        return DB::transaction(function () use ($user, $invoice, $sourceAccountId, $date, $requestedAmount): Movimento {
            $locked = Fatura::query()->where('user_id', $user->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $card = $user->contasFinanceiras()->whereKey($locked->account_id)->lockForUpdate()->firstOrFail();
            $source = $user->contasFinanceiras()->whereKey($sourceAccountId)->lockForUpdate()->firstOrFail();

            if ($source->id === $card->id || !in_array($source->type, ['bank', 'cash'], true) || !$source->is_operational || $source->status !== 'active') {
                throw ValidationException::withMessages(['account_id' => 'A fatura deve ser paga a partir de uma conta bancária ou de dinheiro ativa.']);
            }
            if ($card->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active') {
                throw ValidationException::withMessages(['invoice' => 'O cartão associado a esta fatura não está ativo.']);
            }
            if ($locked->status === 'carried') {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já foi transportada para um ciclo seguinte. Paga o saldo na fatura de destino.']);
            }

            $this->assertInvoiceReconciled($user, $locked);
            $parent = $this->syncInvoiceSettlementParent($user, $locked);
            if (!$parent) {
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

            $event = $this->createMovement($user, [
                'type' => 'transfer',
                'description' => 'Pagamento da fatura ' . $card->name . ' · ' . $locked->reference_month->format('m/Y'),
                'amount' => number_format($paymentCents / 100, 2, '.', ''),
                'movement_date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $card->id,
                'invoice_id' => $locked->id,
                'settles_movement_id' => $parent->id,
            ], true);

            $this->refreshSettlementParent($user, $parent->id);
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
            foreach ($expenses as $expense) $totalCents += $this->balances->toCents((string) $expense->amount);
            if ($totalCents <= 0) return null;

            $parent = Movimento::query()->where('user_id', $user->id)
                ->where('invoice_id', $lockedInvoice->id)
                ->where('is_settlement_parent', true)
                ->where('type', 'expense')
                ->lockForUpdate()->first();

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
            if (!$parent) {
                $parent = $this->createPending($user, [
                    'type' => 'expense',
                    'description' => 'Fatura ' . $card->name . ' · ' . $lockedInvoice->reference_month->format('m/Y'),
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
            $lockedInvoice->update(['status' => $status === 'settled' ? 'paid' : ($status === 'partial' ? 'partial' : 'open')]);
            return $parent->refresh();
        });
    }

    /** A fatura deve estar bloqueada pela transação que vai alterá-la. */
    public function assertInvoiceReconciled(User $user, Fatura $invoice): void
    {
        // Estornos de versões anteriores não alteravam os agregados da fatura.
        if ($invoice->movimentos()->where('user_id', $user->id)->whereIn('id', DB::table('movimentos')
            ->select('reversal_of_id')->where('user_id', $user->id)->whereNotNull('reversal_of_id')
            ->whereNull('settles_movement_id'))->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Esta fatura tem um estorno anterior que exige reconciliação antes de novos lançamentos ou pagamentos.',
            ]);
        }
    }

    /** A única entrada para pagar dívida inicial também atualiza o respetivo contador. */
    public function payCardOpeningDebt(User $user, string $cardId, string $sourceAccountId, string $date, string $requestedAmount): Movimento
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
            $paidCents = $this->balances->toCents((string) ($card->opening_debt_paid ?? '0.00'));
            $remaining = $this->balances->toCents((string) ($card->opening_debt_amount ?? '0.00')) - $paidCents;
            $payment = $this->balances->toCents($requestedAmount);
            if ($remaining <= 0 || $payment <= 0 || $payment > $remaining) {
                throw ValidationException::withMessages(['amount' => 'O pagamento não pode exceder a dívida inicial ainda por pagar.']);
            }
            $movement = $this->createMovement($user, [
                'type' => 'transfer',
                'description' => 'Pagamento de dívida inicial · ' . $card->name,
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
            if ($locked->type === 'expense' && !empty($accounts['invoice_id'])) {
                $card = $this->account($user, $accounts['source_account_id'] ?? $locked->source_account_id);
                $invoice = Fatura::query()->where('user_id', $user->id)->whereKey($accounts['invoice_id'])->lockForUpdate()->firstOrFail();
                if ($card?->type !== 'credit_card' || !$card->is_operational || $card->status !== 'active'
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
            if (!in_array($locked->type, ['expense', 'income'], true)) {
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
            if (!$account || !in_array($account->type, ['bank', 'cash'], true) || !$account->is_operational || $account->status !== 'active') {
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

    /** Total líquido liquidado, descontando os eventos de estorno correspondentes. */
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

    /** Recalcula a obrigação quando uma liquidação associada é estornada. */
    private function refreshSettlementParent(User $user, string $originalId): void
    {
        $parent = Movimento::query()->where('user_id', $user->id)->whereKey($originalId)->lockForUpdate()->first();
        if (!$parent || !$parent->is_settlement_parent) return;

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
            Fatura::query()->where('user_id', $user->id)->whereKey($parent->invoice_id)
                ->update(['status' => $parentStatus === 'settled' ? 'paid' : ($parentStatus === 'partial' ? 'partial' : 'open')]);
        }
        $this->audit($user, $parent, 'settlement_recalculated', null, $parent->getAttributes(), 'Estado recalculado após estorno de liquidação.');
        $this->syncInstallmentFromSettlementParent($user, $parent);
    }

    /**
     * Mantém o agregado da prestação sincronizado com as liquidações comuns.
     * Pagamentos antigos sem settles_movement_id continuam a contar como histórico.
     */
    private function syncInstallmentFromSettlementParent(User $user, Movimento $parent): void
    {
        if (!$parent->installment_id) return;

        $installment = ParcelaDivida::query()
            ->where('user_id', $user->id)
            ->whereKey($parent->installment_id)
            ->lockForUpdate()
            ->first();
        if (!$installment) return;

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
            $allPaid = !$debt->parcelas()->where('status', '!=', 'paid')->exists();
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

            $source = $this->account($user, $accounts['source_account_id'] ?? null);
            $destination = $this->account($user, $accounts['destination_account_id'] ?? null);
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
            if ($locked->status !== 'settled' || !$locked->legacy_type || !$locked->legacy_id) {
                throw ValidationException::withMessages(['account_id' => 'Só é possível associar movimentos existentes importados.']);
            }
            if (!in_array($locked->type, ['expense', 'income'], true)) {
                throw ValidationException::withMessages(['account_id' => 'Este tipo de movimento não pode ser associado por este formulário.']);
            }

            $account = $this->account($user, $accountId);
            $currentId = $locked->type === 'expense' ? $locked->source_account_id : $locked->destination_account_id;
            if ($currentId === $account->id) return $locked;
            if ($locked->type === 'income') $this->assertNoGenericCardMovement('income', null, $account);

            if (!$locked->is_historical && $locked->type === 'expense') {
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

    public function voidPending(User $user, Movimento $movement, ?string $reason = null): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $reason) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['movement' => 'Movimentos liquidados não podem ser anulados diretamente.']);
            }
            $this->assertNotCommitmentForecast($locked);
            $before = $locked->getAttributes();
            $locked->update(['status' => 'void']);
            $this->audit($user, $locked, 'voided', $before, $locked->getAttributes(), $reason);
            return $locked->refresh();
        });
    }

    public function updatePending(User $user, Movimento $movement, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $data) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['movement' => 'Só movimentos pendentes podem ser editados. Para corrigir um movimento liquidado, usa o estorno e regista o movimento correto.']);
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

    /** Uso interno pelo parcelamento: sincroniza apenas o nome e o vencimento. */
    public function updateCommitmentForecast(User $user, Divida $debt, ParcelaDivida $part, Movimento $movement): Movimento
    {
        return DB::transaction(function () use ($user, $debt, $part, $movement): Movimento {
            $lockedDebt = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $lockedPart = ParcelaDivida::query()->where('user_id', $user->id)->where('debt_id', $lockedDebt->id)->whereKey($part->id)->lockForUpdate()->firstOrFail();
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $validForecastState = $locked->status === 'pending'
                || ($locked->status === 'partial' && $locked->is_settlement_parent);
            if (in_array($lockedDebt->status, ['cancelled', 'paid'], true) || !in_array($lockedPart->status, ['pending', 'partial'], true)
                || !$validForecastState || $locked->debt_id !== $lockedDebt->id || $locked->installment_id !== $lockedPart->id) {
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
            if (!$locked->is_settlement_parent || $forecastAmountCents !== $this->balances->toCents((string) $locked->amount)) {
                $changes['amount'] = number_format($forecastAmountCents / 100, 2, '.', '');
            }
            $locked->update($changes);
            $this->audit($user, $locked, 'updated', $before, $locked->getAttributes());
            return $locked->refresh();
        });
    }

    /** Anula previsões antigas substituídas por uma renegociação, sem tocar em pagamentos liquidados. */
    public function voidRenegotiatedCommitmentForecast(User $user, Divida $debt, Movimento $movement): Movimento
    {
        return DB::transaction(function () use ($user, $debt, $movement): Movimento {
            $lockedDebt = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedDebt->status, ['cancelled', 'paid'], true) || $locked->status !== 'pending' || $locked->debt_id !== $lockedDebt->id) {
                throw ValidationException::withMessages(['movement' => 'Não é possível substituir esta previsão. Atualiza o parcelamento.']);
            }
            $before = $locked->getAttributes();
            $locked->update(['status' => 'void']);
            $this->audit($user, $locked, 'voided', $before, $locked->getAttributes(), 'Previsão substituída por renegociação do parcelamento.');
            return $locked->refresh();
        });
    }

    /** Uso interno: só anula previsões de um parcelamento cancelado na transação. */
    public function voidCancelledCommitmentForecast(User $user, Divida $debt, Movimento $movement): Movimento
    {
        return DB::transaction(function () use ($user, $debt, $movement): Movimento {
            $lockedDebt = Divida::query()->where('user_id', $user->id)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($lockedDebt->status !== 'cancelled' || $locked->status !== 'pending' || $locked->debt_id !== $lockedDebt->id) {
                throw ValidationException::withMessages(['movement' => 'Não é possível anular esta previsão. Revê o parcelamento em Parcelamentos.']);
            }
            $before = $locked->getAttributes();
            $locked->update(['status' => 'void']);
            $this->audit($user, $locked, 'voided', $before, $locked->getAttributes(), 'Previsão anulada ao encerrar o parcelamento.');
            return $locked->refresh();
        });
    }

    /**
     * Trata o comando "eliminar" como correção do lançamento.
     * O movimento canónico permanece auditável, mas deixa de produzir impacto financeiro.
     */
    public function deleteAsCorrection(User $user, Movimento $movement, ?string $reason = null): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $reason) {
            $locked = Movimento::query()
                ->where('user_id', $user->id)
                ->whereKey($movement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->settles_movement_id || $locked->is_settlement_parent || $locked->installment_id || ($locked->status === 'pending' && $locked->debt_id)) {
                throw ValidationException::withMessages(['movement' => 'Este movimento está ligado a uma prestação. Gere ou cancela o compromisso em Parcelamentos; não o elimines isoladamente.']);
            }
            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['movement' => 'Este movimento já foi removido ou estornado.']);
            }
            if ($locked->reversal_of_id || Movimento::query()->where('reversal_of_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimento faz parte de um estorno. Mantém o par original e a reversão para preservar o histórico e os saldos.']);
            }

            if ($this->appearsToBeOpeningDebtPayment($user, $locked)) {
                throw ValidationException::withMessages([
                    'movement' => 'Um pagamento de dívida inicial não pode ser eliminado como correção. Usa o estorno para repor a dívida e os saldos; se o pagamento antigo não puder ser identificado, pede a reconciliação do cartão.',
                ]);
            }

            // Uma compra de cartão ligada a uma fatura parcialmente paga ou paga
            // não pode ser removida isoladamente sem redistribuir pagamentos.
            if ($locked->invoice_id) {
                $invoice = $user->faturas()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
                $this->assertInvoiceReconciled($user, $invoice);
                $hasPayments = $invoice->movimentos()
                    ->where('type', 'transfer')
                    ->where('status', 'settled')
                    ->exists();

                if ($invoice->status === 'paid' || $hasPayments) {
                    throw ValidationException::withMessages([
                        'movement' => 'Esta compra pertence a uma fatura com pagamentos registados. Corrige primeiro a fatura para manter os saldos coerentes.',
                    ]);
                }
            }

            $before = $locked->getAttributes();
            $locked->update(['status' => 'void']);
            $this->audit(
                $user,
                $locked,
                'deleted_as_correction',
                $before,
                $locked->getAttributes(),
                $reason ?? 'Removido pelo utilizador como correção de lançamento.'
            );

            return $locked->refresh();
        });
    }

    public function reverse(User $user, Movimento $movement, ?string $reason = null): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $reason) {
            // Mantém a mesma ordem de bloqueio usada nas liquidações: obrigação antes do evento.
            if ($movement->settles_movement_id) {
                Movimento::query()->where('user_id', $user->id)->whereKey($movement->settles_movement_id)->lockForUpdate()->firstOrFail();
            }
            $original = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($original->is_settlement_parent) {
                $message = $original->installment_id
                    ? 'Esta previsão pertence a uma prestação e não pode ser estornada enquanto obrigação. Estorna os pagamentos associados em Parcelamentos.'
                    : 'O movimento original não pode ser estornado enquanto obrigação. Estorna os eventos de liquidação associados.';
                throw ValidationException::withMessages(['movement' => $message]);
            }
            if ($original->installment_id) {
                throw ValidationException::withMessages(['movement' => 'Este movimento está ligado a uma prestação e não pode ser estornado isoladamente. Revê o compromisso em Parcelamentos.']);
            }
            if ($original->status !== 'settled') {
                throw ValidationException::withMessages(['movement' => 'Só é possível estornar movimentos liquidados.']);
            }
            if ($original->reversal_of_id || Movimento::query()->where('reversal_of_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimento já foi estornado.']);
            }
            // Os agregados de fatura e as projeções somam os tipos originais; um
            // movimento inverso isolado não anula a compra ou o pagamento neles.
            if ($original->invoice_id) {
                $invoicePayment = $original->type === 'transfer' && $original->settles_movement_id
                    && Movimento::query()->where('user_id', $user->id)->whereKey($original->settles_movement_id)
                        ->where('invoice_id', $original->invoice_id)->where('is_settlement_parent', true)->exists();
                if (!$invoicePayment) {
                    throw ValidationException::withMessages([
                        'movement' => 'Não é possível estornar uma compra diretamente na fatura. Regista um crédito identificado ou corrige a fatura antes de continuar.',
                    ]);
                }
            }
            $openingDebtCard = $this->openingDebtCardForReversal($user, $original);
            if (!$openingDebtCard && $this->appearsToBeOpeningDebtPayment($user, $original)) {
                throw ValidationException::withMessages([
                    'movement' => 'Não foi possível identificar com segurança este pagamento antigo de dívida inicial. Pede a reconciliação do cartão antes de o estornar.',
                ]);
            }
            if ($openingDebtCard) {
                $openingDebtCard = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($openingDebtCard->id)->lockForUpdate()->firstOrFail();
                $paidCents = $this->balances->toCents((string) ($openingDebtCard->opening_debt_paid ?? '0.00'));
                $reversedCents = $this->balances->toCents((string) $original->amount);
                if ($reversedCents > $paidCents) {
                    throw ValidationException::withMessages(['movement' => 'O pagamento não pode ser estornado porque o saldo de dívida inicial já foi alterado. Revê a dívida do cartão antes de continuar.']);
                }
            }
            $payload = match ($original->type) {
                'expense' => ['type' => 'income', 'source_account_id' => null, 'destination_account_id' => $original->source_account_id],
                'income' => ['type' => 'expense', 'source_account_id' => $original->destination_account_id, 'destination_account_id' => null],
                'transfer' => ['type' => 'transfer', 'source_account_id' => $original->destination_account_id, 'destination_account_id' => $original->source_account_id],
                'adjustment' => $original->adjustment_direction === 'increase'
                    ? ['type' => 'adjustment', 'source_account_id' => $original->destination_account_id, 'destination_account_id' => null, 'adjustment_direction' => 'decrease']
                    : ['type' => 'adjustment', 'source_account_id' => null, 'destination_account_id' => $original->source_account_id, 'adjustment_direction' => 'increase'],
                default => throw ValidationException::withMessages(['movement' => 'Este tipo de movimento não pode ser estornado.']),
            };
            $reversal = $this->createMovement($user, array_merge($payload, [
                'description' => 'Estorno: ' . $original->description,
                'amount' => $original->amount,
                'movement_date' => now()->toDateString(),
                'reversal_of_id' => $original->id,
                'invoice_id' => $original->invoice_id,
                'settles_movement_id' => $original->settles_movement_id,
                'is_historical' => (bool) $original->is_historical,
            ]), true);
            $before = $original->getAttributes();
            if ($openingDebtCard) {
                $paidCents -= $this->balances->toCents((string) $original->amount);
                $openingDebtCard->update(['opening_debt_paid' => number_format($paidCents / 100, 2, '.', '')]);
                if (!$original->opening_debt_card_id) {
                    $original->opening_debt_card_id = $openingDebtCard->id;
                    $original->save();
                }
            }
            if ($original->settles_movement_id) {
                $this->refreshSettlementParent($user, $original->settles_movement_id);
            }
            // Ambos os lançamentos ficam liquidados: os seus efeitos opostos cancelam-se
            // no saldo e a origem mantém-se auditável. O vínculo único impede novo estorno.
            $this->audit($user, $original->refresh(), 'reversed', $before, $original->getAttributes(), $reason);
            return $reversal->refresh();
        });
    }

    private function account(User $user, ?string $id): ?ContaFinanceira
    {
        if (!$id) return null;
        $account = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->first();
        if (!$account || !$account->is_operational || $account->status !== 'active') {
            throw ValidationException::withMessages(['account' => 'Conta inexistente, encerrada ou ainda não configurada.']);
        }
        if ($account->currency !== $user->currency) {
            throw ValidationException::withMessages(['account' => 'A moeda da conta não corresponde à moeda do utilizador.']);
        }
        return $account;
    }

    private function openingDebtCardForReversal(User $user, Movimento $movement): ?ContaFinanceira
    {
        if ($movement->opening_debt_card_id) {
            if ($movement->type !== 'transfer' || $movement->invoice_id
                || $movement->destination_account_id !== $movement->opening_debt_card_id) {
                return null;
            }
            return ContaFinanceira::query()->where('user_id', $user->id)->where('type', 'credit_card')->whereKey($movement->opening_debt_card_id)->first();
        }

        // Compatibilidade com pagamentos existentes anteriores à coluna tipada.
        // Só inferir quando a descrição gerada e a conta de destino coincidirem exatamente.
        if ($movement->type !== 'transfer' || $movement->invoice_id || !$movement->destination_account_id
            || !str_starts_with((string) $movement->description, 'Pagamento de dívida inicial · ')) {
            return null;
        }

        $card = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($movement->destination_account_id)->where('type', 'credit_card')->first();
        if (!$card || $movement->description !== 'Pagamento de dívida inicial · '.$card->name) {
            return null;
        }

        return $card;
    }

    private function appearsToBeOpeningDebtPayment(User $user, Movimento $movement): bool
    {
        if ($movement->opening_debt_card_id) return true;

        // Mesmo depois de renomear o cartão, um pagamento legado ambíguo não
        // deve ser apagado ou estornado sem reconciliação do contador da dívida.
        return $movement->type === 'transfer' && !$movement->invoice_id && $movement->destination_account_id
            && str_starts_with((string) $movement->description, 'Pagamento de dívida inicial · ')
            && $user->contasFinanceiras()->whereKey($movement->destination_account_id)->where('type', 'credit_card')->exists();
    }

    private function assertShape(string $type, ?ContaFinanceira $source, ?ContaFinanceira $destination, ?string $direction): void
    {
        $valid = match ($type) {
            'income' => !$source && (bool) $destination,
            'expense' => (bool) $source && !$destination,
            'transfer' => $source && $destination && $source->id !== $destination->id,
            'adjustment' => ($direction === 'increase' && !$source && $destination) || ($direction === 'decrease' && $source && !$destination),
            default => false,
        };
        if (!$valid) {
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
        if (($type === 'income' && $destination?->type === 'credit_card')
            || ($type === 'adjustment' && ($source?->type === 'credit_card' || $destination?->type === 'credit_card'))) {
            throw ValidationException::withMessages([
                'type' => 'Não é possível registar receitas ou ajustes genéricos num cartão de crédito. Para pagar, usa o pagamento da fatura ou da dívida inicial.',
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
}

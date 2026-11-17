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
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'category_id' => $data['category_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'opening_debt_card_id' => $data['opening_debt_card_id'] ?? null,
                'adjustment_direction' => $data['adjustment_direction'] ?? null,
                'is_historical' => $isHistorical,
                'legacy_type' => $data['legacy_type'] ?? null,
                'legacy_id' => $data['legacy_id'] ?? null,
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
                'debt_id' => $data['debt_id'] ?? null,
                'installment_id' => $data['installment_id'] ?? null,
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
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'source_account_id' => $data['source_account_id'] ?? null,
                'destination_account_id' => $data['destination_account_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'recurrence_id' => $data['recurrence_id'] ?? null,
                'recurrence_occurrence_date' => $data['recurrence_occurrence_date'] ?? null,
                'debt_id' => $data['debt_id'] ?? null,
                'installment_id' => $data['installment_id'] ?? null,
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

            $this->assertInvoiceReconciled($user, $locked);
            $totalCents = $this->balances->toCents((string) $locked->movimentos()->where('type', 'expense')->where('status', 'settled')->sum('amount'));
            $paidCents = $this->balances->toCents((string) $locked->movimentos()->where('type', 'transfer')->where('status', 'settled')->sum('amount'));
            $remainingCents = $totalCents - $paidCents;
            if ($remainingCents <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já está totalmente paga.']);
            }

            $paymentCents = $requestedAmount !== null ? $this->balances->toCents($requestedAmount) : $remainingCents;
            if ($paymentCents <= 0 || $paymentCents > $remainingCents) {
                throw ValidationException::withMessages(['amount' => 'O pagamento deve ser superior a zero e não pode exceder o valor pendente.']);
            }

            $movement = $this->createMovement($user, [
                'type' => 'transfer',
                'description' => 'Pagamento da fatura ' . $card->name . ' · ' . $locked->reference_month->format('m/Y'),
                'amount' => number_format($paymentCents / 100, 2, '.', ''),
                'movement_date' => $date,
                'source_account_id' => $source->id,
                'destination_account_id' => $card->id,
                'invoice_id' => $locked->id,
            ], true);
            if ($paymentCents === $remainingCents) {
                $locked->update(['status' => 'paid']);
            }
            return $movement;
        });
    }

    /** A fatura deve estar bloqueada pela transação que vai alterá-la. */
    public function assertInvoiceReconciled(User $user, Fatura $invoice): void
    {
        // Estornos de versões anteriores não alteravam os agregados da fatura.
        if ($invoice->movimentos()->where('user_id', $user->id)->whereIn('id', DB::table('movimentos')
            ->select('reversal_of_id')->where('user_id', $user->id)->whereNotNull('reversal_of_id'))->exists()) {
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

    public function settle(User $user, Movimento $movement, array $accounts): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $accounts) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['movement' => 'Só é possível liquidar movimentos pendentes.']);
            }

            $source = $this->account($user, $accounts['source_account_id'] ?? $locked->source_account_id);
            $destination = $this->account($user, $accounts['destination_account_id'] ?? $locked->destination_account_id);
            $this->assertShape($locked->type, $source, $destination, $locked->adjustment_direction);
            $this->assertNoGenericCardMovement($locked->type, $source, $destination);

            if ($source) {
                $this->balances->assertCanDebit($source, (string) $locked->amount);
            }

            $before = $locked->getAttributes();
            $locked->update([
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'invoice_id' => $accounts['invoice_id'] ?? $locked->invoice_id,
                'movement_date' => $accounts['movement_date'] ?? $locked->movement_date,
                'requires_account_assignment' => false,
                'status' => 'settled',
                'settled_at' => now(),
            ]);
            $this->audit($user, $locked, 'settled', $before, $locked->getAttributes());
            return $locked->refresh();
        });
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
            $allowed = ['description', 'notes', 'amount', 'movement_date', 'category_id', 'expense_behavior'];
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
            if (in_array($lockedDebt->status, ['cancelled', 'paid'], true) || !in_array($lockedPart->status, ['pending', 'partial'], true)
                || $locked->status !== 'pending' || $locked->debt_id !== $lockedDebt->id || $locked->installment_id !== $lockedPart->id) {
                throw ValidationException::withMessages(['movement' => 'Não é possível sincronizar esta previsão. Revê o parcelamento em Parcelamentos.']);
            }
            $before = $locked->getAttributes();
            $locked->update([
                'description' => $lockedDebt->name.' · Parcela '.$lockedPart->installment_number.'/'.$lockedDebt->installments_count,
                'movement_date' => $lockedPart->due_date->toDateString(),
            ]);
            $this->audit($user, $locked, 'updated', $before, $locked->getAttributes());
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

            if ($locked->installment_id || ($locked->status === 'pending' && $locked->debt_id)) {
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
            $original = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
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
                throw ValidationException::withMessages([
                    'movement' => 'Não é possível estornar com segurança um movimento de fatura. Para uma compra sem pagamentos, elimina-a como correção; se houver pagamentos, pede a reconciliação da fatura. Não uses uma transferência para compensar.',
                ]);
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

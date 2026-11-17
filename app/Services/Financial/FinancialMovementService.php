<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use App\Models\HistoricoMovimento;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialMovementService
{
    public function __construct(private readonly AccountBalanceService $balances) {}

    public function create(User $user, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $data) {
            $type = $data['type'];
            $source = $this->account($user, $data['source_account_id'] ?? null);
            $destination = $this->account($user, $data['destination_account_id'] ?? null);
            $this->assertShape($type, $source, $destination, $data['adjustment_direction'] ?? null);
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

            if ($source) {
                $this->balances->assertCanDebit($source, (string) $locked->amount);
            }

            $before = $locked->getAttributes();
            $locked->update([
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'invoice_id' => $accounts['invoice_id'] ?? $locked->invoice_id,
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

            $source = $this->account($user, $accounts['source_account_id'] ?? null);
            $destination = $this->account($user, $accounts['destination_account_id'] ?? null);
            $this->assertShape($locked->type, $source, $destination, $locked->adjustment_direction);

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
            $before = $locked->getAttributes();
            $allowed = ['description', 'notes', 'amount', 'movement_date', 'category_id', 'expense_behavior'];
            $locked->fill(array_intersect_key($data, array_flip($allowed)));
            $locked->save();
            $this->audit($user, $locked, 'updated', $before, $locked->getAttributes());
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

            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['movement' => 'Este movimento já foi removido ou estornado.']);
            }
            if ($locked->reversal_of_id || Movimento::query()->where('reversal_of_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimento faz parte de um estorno. Mantém o par original e a reversão para preservar o histórico e os saldos.']);
            }

            // Uma compra de cartão ligada a uma fatura parcialmente paga ou paga
            // não pode ser removida isoladamente sem redistribuir pagamentos.
            if ($locked->invoice_id) {
                $invoice = $user->faturas()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
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
            if ($original->status !== 'settled') {
                throw ValidationException::withMessages(['movement' => 'Só é possível estornar movimentos liquidados.']);
            }
            if ($original->reversal_of_id || Movimento::query()->where('reversal_of_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimento já foi estornado.']);
            }
            $openingDebtCard = $this->openingDebtCardForReversal($user, $original);
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
            $reversal = $this->create($user, array_merge($payload, [
                'description' => 'Estorno: ' . $original->description,
                'amount' => $original->amount,
                'movement_date' => now()->toDateString(),
                'reversal_of_id' => $original->id,
                'is_historical' => (bool) $original->is_historical,
            ]));
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

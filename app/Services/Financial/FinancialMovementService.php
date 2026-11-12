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

            if ($source) {
                $this->balances->assertCanDebit($source, (string) $data['amount']);
            }

            $movement = Movimento::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => $type,
                'status' => 'settled',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'category_id' => $data['category_id'] ?? null,
                'adjustment_direction' => $data['adjustment_direction'] ?? null,
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
                'status' => 'pending',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'category_id' => $data['category_id'] ?? null,
                'is_historical' => false,
                'requires_account_assignment' => true,
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

            $source = $this->account($user, $accounts['source_account_id'] ?? null);
            $destination = $this->account($user, $accounts['destination_account_id'] ?? null);
            $this->assertShape($locked->type, $source, $destination, $locked->adjustment_direction);

            if ($source) {
                $this->balances->assertCanDebit($source, (string) $locked->amount);
            }

            $before = $locked->getAttributes();
            $locked->update([
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'requires_account_assignment' => false,
                'status' => 'settled',
                'settled_at' => now(),
            ]);
            $this->audit($user, $locked, 'settled', $before, $locked->getAttributes());
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
            $locked->update(['status' => 'void', 'requires_account_assignment' => false]);
            $this->audit($user, $locked, 'voided', $before, $locked->getAttributes(), $reason);
            return $locked->refresh();
        });
    }

    public function updatePending(User $user, Movimento $movement, array $data): Movimento
    {
        return DB::transaction(function () use ($user, $movement, $data) {
            $locked = Movimento::query()->where('user_id', $user->id)->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $before = $locked->getAttributes();

            if ($locked->status === 'settled') {
                if (array_intersect(['amount', 'movement_date', 'type', 'source_account_id', 'destination_account_id'], array_keys($data))) {
                    throw ValidationException::withMessages(['movement' => 'Alterações financeiras em movimentos liquidados exigem um ajuste ou estorno.']);
                }
                $allowed = ['description', 'category_id'];
                $action = 'description_corrected';
            } elseif ($locked->status === 'pending') {
                $allowed = ['description', 'amount', 'movement_date', 'category_id'];
                $action = 'updated';
            } else {
                throw ValidationException::withMessages(['movement' => 'Movimentos anulados não podem ser editados.']);
            }

            $locked->fill(array_intersect_key($data, array_flip($allowed)));
            $locked->save();
            $this->audit($user, $locked, $action, $before, $locked->getAttributes());
            return $locked->refresh();
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

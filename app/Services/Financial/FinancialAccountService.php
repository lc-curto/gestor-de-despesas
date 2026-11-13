<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FinancialAccountService
{
    public function __construct(private readonly AccountBalanceService $balances) {}

    public function create(User $user, array $data): ContaFinanceira
    {
        return DB::transaction(function () use ($user, $data) {
            // Toda conta começa utilizável com saldo zero. O saldo real pode
            // ser corrigido mais tarde, inclusive depois de lançamentos.
            $openingBalance = $data['opening_balance'] ?? '0.00';
            $allowNegative = true;
            if (($data['opening_balance_date'] ?? now()->toDateString()) > now()->toDateString()) {
                throw ValidationException::withMessages(['opening_balance_date' => 'A data do saldo inicial não pode ser futura.']);
            }

            return ContaFinanceira::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $user->currency,
                'opening_balance' => $openingBalance,
                'opening_balance_date' => $data['opening_balance_date'] ?? now()->toDateString(),
                'is_operational' => true,
                'allow_negative_balance' => $allowNegative,
                'closing_day' => $data['type'] === 'credit_card' ? ($data['closing_day'] ?? null) : null,
                'due_day' => $data['type'] === 'credit_card' ? ($data['due_day'] ?? null) : null,
                'credit_limit' => $data['type'] === 'credit_card' ? ($data['credit_limit'] ?? null) : null,
                'status' => 'active',
            ]);
        });
    }

    public function setOpeningBalance(User $user, ContaFinanceira $account, string $amount, string $date, bool $allowNegative): ContaFinanceira
    {
        return DB::transaction(function () use ($user, $account, $amount, $date, $allowNegative) {
            $locked = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (!$locked->is_operational || $locked->type === 'legacy') {
                throw ValidationException::withMessages(['account' => 'Esta conta não aceita saldo inicial operacional.']);
            }
            if ($date > now()->toDateString()) {
                throw ValidationException::withMessages(['opening_balance_date' => 'A data do saldo inicial não pode ser futura.']);
            }
            $locked->update([
                'opening_balance' => $amount,
                'opening_balance_date' => $date,
                'allow_negative_balance' => $allowNegative || $this->balances->toCents($amount) < 0,
                'status' => 'active',
            ]);

            return $locked->refresh();
        });
    }

    public function rename(User $user, ContaFinanceira $account, string $name): ContaFinanceira
    {
        return DB::transaction(function () use ($user, $account, $name) {
            $locked = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (!$locked->is_operational || $locked->type === 'legacy' || $locked->status === 'closed') {
                throw ValidationException::withMessages(['account' => 'Esta conta não pode ser editada.']);
            }

            $locked->update(['name' => $name]);
            return $locked->refresh();
        });
    }

    public function setNegativeBalancePermission(User $user, ContaFinanceira $account, bool $allowed): ContaFinanceira
    {
        return DB::transaction(function () use ($user, $account, $allowed) {
            $locked = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (!$locked->is_operational || $locked->status === 'closed' || !in_array($locked->type, ['bank', 'cash'], true)) {
                throw ValidationException::withMessages(['account' => 'Esta configuração só se aplica a contas bancárias e de dinheiro ativas.']);
            }
            $locked->update([
                'allow_negative_balance' => $allowed || ($locked->opening_balance !== null && $this->balances->toCents((string) $locked->opening_balance) < 0),
            ]);
            return $locked->refresh();
        });
    }

    public function close(User $user, ContaFinanceira $account, ?string $transferToAccountId = null): void
    {
        DB::transaction(function () use ($user, $account, $transferToAccountId) {
            $locked = ContaFinanceira::query()->where('user_id', $user->id)->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (!$locked->is_operational || $locked->type === 'legacy') {
                throw ValidationException::withMessages(['account' => 'A conta já está encerrada ou é histórica.']);
            }
            $balance = $this->balances->balance($locked);
            $cents = $this->balances->toCents($balance);

            if ($cents < 0) {
                throw ValidationException::withMessages(['account' => 'Reconcilia o saldo negativo antes de encerrar esta conta.']);
            }

            if ($cents > 0) {
                if (!$transferToAccountId || $transferToAccountId === $locked->id) {
                    throw ValidationException::withMessages(['transfer_to_account_id' => 'Transfira o saldo positivo para outra conta antes de encerrar.']);
                }
                app(FinancialMovementService::class)->transfer($user, [
                    'description' => 'Transferência de encerramento: ' . $locked->name,
                    'amount' => $balance,
                    'movement_date' => now()->toDateString(),
                    'source_account_id' => $locked->id,
                    'destination_account_id' => $transferToAccountId,
                ]);
            }

            $locked->update(['status' => 'closed', 'is_operational' => false]);
        });
    }
}

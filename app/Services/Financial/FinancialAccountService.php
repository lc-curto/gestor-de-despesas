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
            $openingBalance = $data['opening_balance'] ?? null;
            $allowNegative = (bool) ($data['allow_negative_balance'] ?? false);

            if ($openingBalance !== null && $this->balances->toCents((string) $openingBalance) < 0 && !$allowNegative) {
                throw ValidationException::withMessages(['opening_balance' => 'Saldo inicial negativo exige autorização explícita.']);
            }

            return ContaFinanceira::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $user->currency,
                'opening_balance' => $openingBalance,
                'opening_balance_date' => $openingBalance !== null ? ($data['opening_balance_date'] ?? now()->toDateString()) : null,
                'is_operational' => true,
                'allow_negative_balance' => $allowNegative,
                'status' => $openingBalance !== null ? 'active' : 'setup_pending',
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
            if ($this->balances->toCents($amount) < 0 && !$allowNegative) {
                throw ValidationException::withMessages(['opening_balance' => 'Saldo inicial negativo exige autorização explícita.']);
            }
            if (DB::table('movimentos')->where('user_id', $user->id)->where(function ($q) use ($locked) {
                $q->where('source_account_id', $locked->id)->orWhere('destination_account_id', $locked->id);
            })->exists()) {
                throw ValidationException::withMessages(['opening_balance' => 'Não é possível alterar o saldo inicial depois de existirem movimentos na conta.']);
            }

            $locked->update([
                'opening_balance' => $amount,
                'opening_balance_date' => $date,
                'allow_negative_balance' => $allowNegative,
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

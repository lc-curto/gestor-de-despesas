<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountBalanceService
{
    /**
     * Saldo = saldo inicial + todos os movimentos operacionais liquidados.
     * A data do saldo inicial é uma referência, não um bloqueio de lançamentos.
     */
    public function balance(ContaFinanceira $account): string
    {
        if (!$account->is_operational || $account->opening_balance === null || !$account->opening_balance_date) {
            throw new RuntimeException('A conta ainda não está configurada para cálculo de saldo.');
        }

        $movementDelta = DB::table('movimentos')
            ->where('user_id', $account->user_id)
            ->where('status', 'settled')
            ->where('is_historical', false)
            ->where(function ($query) use ($account) {
                $query->where('source_account_id', $account->id)
                    ->orWhere('destination_account_id', $account->id);
            })
            ->selectRaw(
                "COALESCE(SUM(CASE
                    WHEN destination_account_id = ? AND type IN ('income', 'transfer') THEN amount
                    WHEN destination_account_id = ? AND type = 'adjustment' AND adjustment_direction = 'increase' THEN amount
                    WHEN source_account_id = ? AND type IN ('expense', 'transfer') THEN -amount
                    WHEN source_account_id = ? AND type = 'adjustment' AND adjustment_direction = 'decrease' THEN -amount
                    ELSE 0 END), 0) AS delta",
                [$account->id, $account->id, $account->id, $account->id]
            )->first()->delta;

        $cents = $this->toCents($account->opening_balance) + $this->toCents((string) $movementDelta);

        return $this->fromCents($cents);
    }

    public function assertCanDebit(ContaFinanceira $account, string $amount): void
    {
        // O saldo é informativo e pode ficar negativo: bancos permitem
        // descoberto, dinheiro pode ser corrigido depois e cartões são crédito.
        // A aplicação não bloqueia um lançamento por falta de saldo calculado.
    }

    public function toCents(string $amount): int
    {
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new RuntimeException('Valor monetário inválido.');
        }

        $negative = str_starts_with($amount, '-');
        $digits = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}

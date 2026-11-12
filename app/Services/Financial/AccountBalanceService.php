<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountBalanceService
{
    /**
     * Saldo = saldo inicial + movimentos liquidados desde a data de abertura.
     * A conta legacy nunca é considerada operacional.
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
            // O saldo inicial é a fotografia do corte; movimentos contam a partir do dia seguinte.
            ->whereDate('movement_date', '>', $account->opening_balance_date->toDateString())
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
        if ($account->type === 'credit_card' || $account->allow_negative_balance) {
            return;
        }

        $balance = $this->toCents($this->balance($account));
        $debit = $this->toCents($amount);

        if ($balance - $debit < 0) {
            throw new RuntimeException('Saldo insuficiente. Ative a permissão de saldo negativo se pretender continuar.');
        }
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

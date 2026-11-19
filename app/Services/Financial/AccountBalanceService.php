<?php

namespace App\Services\Financial;

use App\Models\ContaFinanceira;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountBalanceService
{
    /**
     * Saldo = saldo inicial + todos os movimentos operacionais liquidados.
     * A data do saldo inicial é uma referência, não um bloqueio de lançamentos.
     */
    public function balance(ContaFinanceira $account): string
    {
        if (! $account->is_operational || $account->opening_balance === null || ! $account->opening_balance_date) {
            throw new RuntimeException('A conta ainda não está configurada para cálculo de saldo.');
        }

        $movementDelta = DB::table('movimentos')
            ->where('user_id', $account->user_id)
            ->where('status', 'settled')
            ->where('is_historical', false)
            ->where('is_settlement_parent', false)
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
        // Cartões seguem as regras de limite e faturação. A política de descoberto
        // aplica-se apenas a contas bancárias e de dinheiro.
        if (! in_array($account->type, ['bank', 'cash'], true) || $account->allow_negative_balance) {
            return;
        }

        $debitCents = $this->toCents($amount);
        if ($debitCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'O valor do débito deve ser superior a zero.']);
        }

        $availableCents = $this->toCents($this->balance($account));
        if ($debitCents > $availableCents) {
            throw ValidationException::withMessages([
                'amount' => 'Saldo insuficiente. Faltam '.number_format(($debitCents - $availableCents) / 100, 2, ',', '.').' '.$account->currency.'. Ativa a permissão de saldo negativo nas definições da conta ou reduz o valor.',
            ]);
        }
    }

    public function toCents(string $amount): int
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
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
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}

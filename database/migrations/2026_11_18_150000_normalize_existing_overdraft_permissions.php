<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Só migramos contas operacionais bancárias/dinheiro ainda autorizadas.
        // Cartões e contas legadas não fazem parte desta política.
        $toCents = static function (string $amount): int {
            $negative = str_starts_with($amount, '-');
            $digits = ltrim($amount, '-');
            [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '0');
            $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);

            return $negative ? -$cents : $cents;
        };

        DB::table('contas_financeiras')
            ->whereIn('type', ['bank', 'cash'])
            ->where('is_operational', true)
            ->where('allow_negative_balance', true)
            ->orderBy('id')
            ->chunkById(200, function ($accounts): void {
                foreach ($accounts as $account) {
                    // Um saldo inicial negativo mantém a autorização por causa
                    // da constraint contas_opening_balance_valid e da regra de
                    // negócio que impede desativá-la enquanto ele for negativo.
                    if ($account->opening_balance === null
                        || (float) $account->opening_balance < 0) {
                        continue;
                    }

                    $delta = DB::table('movimentos')
                        ->where('user_id', $account->user_id)
                        ->where('status', 'settled')
                        ->where('is_historical', false)
                        ->where('is_settlement_parent', false)
                        ->where(function ($query) use ($account): void {
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
                        )
                        ->value('delta');

                    // Calcula em cêntimos inteiros para evitar dependência de
                    // BCMath e qualquer imprecisão de ponto flutuante.
                    $operationalBalanceCents = $toCents((string) $account->opening_balance)
                        + $toCents((string) $delta);

                    if ($operationalBalanceCents >= 0) {
                        DB::table('contas_financeiras')
                            ->where('id', $account->id)
                            ->where('user_id', $account->user_id)
                            ->update(['allow_negative_balance' => false]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        // Migração de dados deliberadamente irreversível: não reativar
        // descoberto indiscriminadamente ao fazer rollback de código.
    }
};

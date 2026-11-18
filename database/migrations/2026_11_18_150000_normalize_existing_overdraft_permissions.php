<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Só migramos contas operacionais bancárias/dinheiro ainda autorizadas.
        // Cartões e contas legadas não fazem parte desta política.
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

                    // O saldo é calculado em decimal pela base de dados, sem
                    // conversões float em PHP. Só removemos a permissão quando
                    // o saldo operacional atual é zero ou positivo.
                    $operationalBalance = bcadd((string) $account->opening_balance, (string) $delta, 2);

                    if (bccomp($operationalBalance, '0.00', 2) >= 0) {
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

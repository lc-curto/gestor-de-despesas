<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Contas operacionais sem saldo configurado passam a começar em zero.
        DB::table('contas_financeiras')
            ->where('type', '!=', 'legacy')
            ->whereNull('opening_balance')
            ->update([
                'opening_balance' => '0.00',
                'opening_balance_date' => now()->toDateString(),
                'is_operational' => true,
                'status' => 'active',
            ]);
    }

    public function down(): void
    {
        // Não desfazer dados financeiros já utilizados.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contas_financeiras DROP CONSTRAINT IF EXISTS contas_opening_balance_valid');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE contas_financeiras ADD CONSTRAINT contas_opening_balance_valid CHECK (opening_balance IS NULL OR opening_balance >= 0 OR allow_negative_balance = 1)");
        }
    }
};

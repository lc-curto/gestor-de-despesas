<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('financial_cutoff_date')->nullable()->after('currency');
        });

        // A data de corte inicial é a data desta migração. Pode ser ajustada
        // por utilizador antes da reconciliação das contas reais.
        DB::table('users')->whereNull('financial_cutoff_date')->update([
            'financial_cutoff_date' => '2026-11-12',
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('financial_cutoff_date');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table): void {
            $table->decimal('opening_debt_amount', 12, 2)->default(0);
            $table->decimal('opening_debt_paid', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table): void {
            $table->dropColumn(['opening_debt_amount', 'opening_debt_paid']);
        });
    }
};

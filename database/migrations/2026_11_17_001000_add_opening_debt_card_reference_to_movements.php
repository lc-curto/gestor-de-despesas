<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->uuid('opening_debt_card_id')->nullable()->after('invoice_id');
            $table->foreign(['user_id', 'opening_debt_card_id'], 'movimentos_user_opening_debt_card_fk')
                ->references(['user_id', 'id'])->on('contas_financeiras')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropForeign('movimentos_user_opening_debt_card_fk');
            $table->dropColumn('opening_debt_card_id');
        });
    }
};

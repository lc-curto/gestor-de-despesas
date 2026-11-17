<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table): void {
            $table->decimal('statement_amount', 12, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('movimentos', function (Blueprint $table): void {
            $table->uuid('invoice_credit_for_movement_id')->nullable();
            $table->foreign(['user_id', 'invoice_credit_for_movement_id'], 'movimentos_user_invoice_credit_purchase_fk')
                ->references(['user_id', 'id'])->on('movimentos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropForeign('movimentos_user_invoice_credit_purchase_fk');
            $table->dropColumn('invoice_credit_for_movement_id');
        });
        Schema::table('faturas', function (Blueprint $table): void {
            $table->dropColumn(['statement_amount', 'closed_at']);
        });
    }
};

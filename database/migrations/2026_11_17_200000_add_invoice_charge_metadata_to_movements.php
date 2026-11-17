<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->string('invoice_charge_type', 20)->nullable();
            $table->uuid('origin_invoice_id')->nullable();
            $table->foreign(['user_id', 'origin_invoice_id'], 'movimentos_user_origin_invoice_fk')
                ->references(['user_id', 'id'])->on('faturas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropForeign('movimentos_user_origin_invoice_fk');
            $table->dropColumn(['invoice_charge_type', 'origin_invoice_id']);
        });
    }
};

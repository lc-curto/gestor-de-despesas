<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fatura_saldos_transportados', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('source_invoice_id');
            $table->uuid('target_invoice_id');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['user_id', 'source_invoice_id'], 'fatura_transport_source_unique');
            $table->index(['user_id', 'target_invoice_id'], 'fatura_transport_target_index');
            $table->foreign(['user_id', 'source_invoice_id'], 'fatura_transport_source_fk')
                ->references(['user_id', 'id'])->on('faturas')->restrictOnDelete();
            $table->foreign(['user_id', 'target_invoice_id'], 'fatura_transport_target_fk')
                ->references(['user_id', 'id'])->on('faturas')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fatura_saldos_transportados');
    }
};

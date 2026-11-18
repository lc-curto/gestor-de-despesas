<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_integrity_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type', 64);
            $table->uuid('entity_id');
            $table->string('correction_type', 100);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'entity_type', 'entity_id'], 'financial_integrity_corrections_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_integrity_corrections');
    }
};

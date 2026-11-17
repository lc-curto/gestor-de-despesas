<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('requested_by')->nullable();
            $table->string('root_entity_type', 80);
            $table->uuid('root_entity_id');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'deletion_operations_user_id_id_unique');
            $table->index(['user_id', 'root_entity_type', 'root_entity_id'], 'deletion_operations_root_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('deletion_operation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('deletion_operation_id');
            $table->string('entity_type', 80);
            $table->uuid('entity_id');
            $table->string('action', 40);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->timestamps();

            $table->foreign(['user_id', 'deletion_operation_id'], 'deletion_items_user_operation_fk')
                ->references(['user_id', 'id'])->on('deletion_operations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'entity_type', 'entity_id'], 'deletion_items_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_operation_items');
        Schema::dropIfExists('deletion_operations');
    }
};

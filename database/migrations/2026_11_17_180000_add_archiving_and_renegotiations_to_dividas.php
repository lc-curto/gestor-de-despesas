<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividas', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
            $table->softDeletes();
        });

        Schema::create('dividas_renegociacoes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('debt_id')->constrained('dividas')->cascadeOnDelete();
            $table->json('previous_terms');
            $table->json('new_terms');
            $table->timestamps();
            $table->index(['user_id', 'debt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividas_renegociacoes');
        Schema::table('dividas', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'deleted_at']);
        });
    }
};

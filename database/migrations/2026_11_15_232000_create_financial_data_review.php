<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('financial_review_required')->default(false);
        });

        // Apenas contas que já existiam no momento desta migração precisam da revisão.
        DB::table('users')->update(['financial_review_required' => true]);

        Schema::create('financial_data_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('review_version')->default(1);
            $table->json('completed_steps')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_data_reviews');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('financial_review_required');
        });
    }
};

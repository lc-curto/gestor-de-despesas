<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_data_reviews', function (Blueprint $table): void {
            $table->json('step_evidence')->nullable()->after('completed_steps');
        });
    }

    public function down(): void
    {
        Schema::table('financial_data_reviews', function (Blueprint $table): void {
            $table->dropColumn('step_evidence');
        });
    }
};

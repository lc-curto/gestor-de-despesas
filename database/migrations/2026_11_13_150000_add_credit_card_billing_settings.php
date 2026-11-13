<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table): void {
            $table->unsignedTinyInteger('closing_day')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table): void {
            $table->dropColumn(['closing_day', 'due_day', 'credit_limit']);
        });
    }
};

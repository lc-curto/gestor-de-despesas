<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->string('expense_behavior', 12)->default('variable')->after('type');
        });

        Schema::table('recorrencias', function (Blueprint $table): void {
            $table->string('expense_behavior', 12)->default('variable')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('recorrencias', function (Blueprint $table): void {
            $table->dropColumn('expense_behavior');
        });

        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropColumn('expense_behavior');
        });
    }
};

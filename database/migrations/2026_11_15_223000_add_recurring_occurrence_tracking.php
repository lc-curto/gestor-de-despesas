<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recorrencias', function (Blueprint $table): void {
            $table->date('anchor_date')->nullable()->after('next_occurrence');
        });
        DB::table('recorrencias')->whereNull('anchor_date')->update(['anchor_date' => DB::raw('next_occurrence')]);

        Schema::table('movimentos', function (Blueprint $table): void {
            $table->date('recurrence_occurrence_date')->nullable()->after('recurrence_id');
            $table->unique(
                ['user_id', 'recurrence_id', 'recurrence_occurrence_date'],
                'movements_recurrence_occurrence_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropUnique('movements_recurrence_occurrence_unique');
            $table->dropColumn('recurrence_occurrence_date');
        });
        Schema::table('recorrencias', function (Blueprint $table): void {
            $table->dropColumn('anchor_date');
        });
    }
};

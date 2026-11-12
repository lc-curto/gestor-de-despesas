<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->uuid('reversal_of_id')->nullable()->after('legacy_id');
            $table->unique(['user_id', 'reversal_of_id'], 'movimentos_one_reversal_per_original');
            $table->foreign(['user_id', 'reversal_of_id'], 'movimentos_user_reversal_fk')
                ->references(['user_id', 'id'])->on('movimentos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropForeign('movimentos_user_reversal_fk');
            $table->dropUnique('movimentos_one_reversal_per_original');
            $table->dropColumn('reversal_of_id');
        });
    }
};

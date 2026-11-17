<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->boolean('is_settlement_parent')->default(false)->after('settled_at');
            $table->uuid('settles_movement_id')->nullable()->after('is_settlement_parent');
            $table->index(['user_id', 'settles_movement_id', 'status'], 'movimentos_settlement_events_index');
            $table->foreign(['user_id', 'settles_movement_id'], 'movimentos_user_settlement_parent_fk')
                ->references(['user_id', 'id'])->on('movimentos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos', function (Blueprint $table): void {
            $table->dropForeign('movimentos_user_settlement_parent_fk');
            $table->dropIndex('movimentos_settlement_events_index');
            $table->dropColumn(['settles_movement_id', 'is_settlement_parent']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividas', function (Blueprint $table): void {
            $table->string('payment_condition', 20)->default('cash')->after('type');
            $table->string('payment_method', 30)->nullable()->after('payment_condition');
            $table->unsignedInteger('installments_count')->default(1)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('dividas', function (Blueprint $table): void {
            $table->dropColumn(['payment_condition', 'payment_method', 'installments_count']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividas', function (Blueprint $table): void {
            $table->string('installment_frequency', 12)->default('monthly')->after('installments_count');
        });
    }

    public function down(): void
    {
        Schema::table('dividas', function (Blueprint $table): void {
            $table->dropColumn('installment_frequency');
        });
    }
};

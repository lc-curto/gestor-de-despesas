<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historico_movimentos', function (Blueprint $table): void {
            // As ações de auditoria são identificadores descritivos; 30 caracteres
            // não são suficientes para os novos eventos de edição financeira.
            $table->string('action', 80)->change();
        });
    }

    public function down(): void
    {
        Schema::table('historico_movimentos', function (Blueprint $table): void {
            $table->string('action', 30)->change();
        });
    }
};

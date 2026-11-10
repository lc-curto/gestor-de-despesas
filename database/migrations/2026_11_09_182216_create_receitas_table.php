<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela de receitas.
     */
    public function up(): void
    {
        Schema::create('receitas', function (Blueprint $table) {

            // Identificador único da receita.
            $table->uuid('id')->primary();

            // Utilizador proprietário da receita.
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Descrição da receita.
            $table->text('description');

            // Valor monetário com duas casas decimais.
            $table->decimal('amount', 12, 2);

            // Moeda utilizada, com EUR como valor padrão.
            $table->string('currency', 3)->default('EUR');

            // Categoria da receita.
            $table->text('category');

            // Data em que a receita ocorreu.
            $table->date('income_date')->useCurrent();

            // Data de criação do registo.
            $table->timestamp('created_at')->useCurrent();

            // Índice para consultas por utilizador e data.
            $table->index(
                ['user_id', 'income_date'],
                'receitas_user_income_date_index'
            );
        });

        // Impede valores iguais ou inferiores a zero.
        DB::statement("
            ALTER TABLE receitas
            ADD CONSTRAINT receitas_amount_positive
            CHECK (amount > 0)
        ");

        // Restringe as moedas permitidas.
        DB::statement("
            ALTER TABLE receitas
            ADD CONSTRAINT receitas_currency_valid
            CHECK (currency IN ('EUR', 'USD', 'GBP', 'BRL'))
        ");

        // Restringe as categorias permitidas.
        DB::statement("
            ALTER TABLE receitas
            ADD CONSTRAINT receitas_category_valid
            CHECK (category IN (
                'salario',
                'vendas',
                'trabalho_extra',
                'investimentos',
                'reembolsos',
                'outros'
            ))
        ");
    }

    /**
     * Remove a tabela de receitas.
     */
    public function down(): void
    {
        Schema::dropIfExists('receitas');
    }
};

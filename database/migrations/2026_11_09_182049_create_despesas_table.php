<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela de despesas.
     */
    public function up(): void
    {
        Schema::create('despesas', function (Blueprint $table) {

            // Identificador único da despesa.
            $table->uuid('id')->primary();

            // Utilizador proprietário da despesa.
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Descrição da despesa.
            $table->text('description');

            // Valor monetário com duas casas decimais.
            $table->decimal('amount', 12, 2);

            // Moeda utilizada.
            $table->string('currency', 3);

            // Categoria da despesa.
            $table->text('category');

            // Data em que a despesa ocorreu.
            $table->date('expense_date')->useCurrent();

            // Data de criação do registo.
            $table->timestamp('created_at')->useCurrent();

            // Índice para pesquisas por utilizador e data.
            $table->index(
                ['user_id', 'expense_date'],
                'despesas_user_expense_date_index'
            );
        });

        // Impede valores iguais ou inferiores a zero.
        DB::statement("
            ALTER TABLE despesas
            ADD CONSTRAINT despesas_amount_positive
            CHECK (amount > 0)
        ");

        // Restringe as moedas permitidas.
        DB::statement("
            ALTER TABLE despesas
            ADD CONSTRAINT despesas_currency_valid
            CHECK (currency IN ('EUR', 'USD', 'GBP', 'BRL'))
        ");

        // Restringe as categorias permitidas.
        DB::statement("
            ALTER TABLE despesas
            ADD CONSTRAINT despesas_category_valid
            CHECK (category IN (
                'casa',
                'alimentacao',
                'transportes',
                'saude',
                'lazer',
                'compras',
                'educacao',
                'outros'
            ))
        ");
    }

    /**
     * Remove a tabela de despesas.
     */
    public function down(): void
    {
        Schema::dropIfExists('despesas');
    }
};

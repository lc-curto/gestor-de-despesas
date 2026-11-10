<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Despesa;
use App\Models\Receita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Database\QueryException;

class MovimentoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifica se uma despesa recebe UUID automaticamente
     * e fica associada ao utilizador correto.
     */
    public function test_despesa_recebe_uuid_e_pertence_ao_utilizador(): void
    {
        $user = User::factory()->create();

        $despesa = Despesa::create([
            'user_id' => $user->id,
            'description' => 'Renda da casa',
            'amount' => 850.00,
            'currency' => 'EUR',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
        ]);

        $this->assertNotEmpty($despesa->id);

        $this->assertTrue(
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $despesa->id
            ) === 1
        );

        $this->assertEquals($user->id, $despesa->user_id);
        $this->assertTrue($despesa->user->is($user));
    }

    /**
     * Verifica se uma receita recebe UUID automaticamente
     * e fica associada ao utilizador correto.
     */
    public function test_receita_recebe_uuid_e_pertence_ao_utilizador(): void
    {
        $user = User::factory()->create();

        $receita = Receita::create([
            'user_id' => $user->id,
            'description' => 'Salário',
            'amount' => 2500.00,
            'currency' => 'EUR',
            'category' => 'salario',
            'income_date' => '2026-11-09',
        ]);

        $this->assertNotEmpty($receita->id);

        $this->assertTrue(
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $receita->id
            ) === 1
        );

        $this->assertEquals($user->id, $receita->user_id);
        $this->assertTrue($receita->user->is($user));
    }

    /**
     * Verifica se o utilizador consegue consultar
     * as suas despesas através do relacionamento.
     */
    public function test_utilizador_pode_ter_varias_despesas(): void
    {
        $user = User::factory()->create();

        $user->despesas()->create([
            'description' => 'Supermercado',
            'amount' => 75.50,
            'currency' => 'EUR',
            'category' => 'alimentacao',
            'expense_date' => '2026-11-09',
        ]);

        $user->despesas()->create([
            'description' => 'Combustível',
            'amount' => 60.00,
            'currency' => 'EUR',
            'category' => 'transportes',
            'expense_date' => '2026-11-09',
        ]);

        $this->assertCount(2, $user->despesas);
    }

    /**
     * Verifica se o utilizador consegue consultar
     * as suas receitas através do relacionamento.
     */
    public function test_utilizador_pode_ter_varias_receitas(): void
    {
        $user = User::factory()->create();

        $user->receitas()->create([
            'description' => 'Salário',
            'amount' => 2500.00,
            'currency' => 'EUR',
            'category' => 'salario',
            'income_date' => '2026-11-09',
        ]);

        $user->receitas()->create([
            'description' => 'Trabalho extra',
            'amount' => 300.00,
            'currency' => 'EUR',
            'category' => 'trabalho_extra',
            'income_date' => '2026-11-09',
        ]);

        $this->assertCount(2, $user->receitas);
    }

    /**
     * Verifica se eliminar um utilizador elimina
     * automaticamente as suas despesas e receitas.
     */
    public function test_eliminar_utilizador_elimina_os_seus_movimentos(): void
    {
        $user = User::factory()->create();

        $despesa = $user->despesas()->create([
            'description' => 'Internet',
            'amount' => 40.00,
            'currency' => 'EUR',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
        ]);

        $receita = $user->receitas()->create([
            'description' => 'Reembolso',
            'amount' => 50.00,
            'currency' => 'EUR',
            'category' => 'reembolsos',
            'income_date' => '2026-11-09',
        ]);

        $user->delete();

        $this->assertDatabaseMissing('despesas', [
            'id' => $despesa->id,
        ]);

        $this->assertDatabaseMissing('receitas', [
            'id' => $receita->id,
        ]);
    }
    /**
     * Verifica se o MySQL rejeita despesas com valores negativos.
     */
    public function test_banco_rejeita_despesa_com_valor_negativo(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->despesas()->create([
            'description' => 'Despesa inválida',
            'amount' => -10.00,
            'currency' => 'EUR',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
        ]);
    }

    /**
     * Verifica se o MySQL rejeita receitas com valor zero.
     */
    public function test_banco_rejeita_receita_com_valor_zero(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->receitas()->create([
            'description' => 'Receita inválida',
            'amount' => 0,
            'currency' => 'EUR',
            'category' => 'salario',
            'income_date' => '2026-11-09',
        ]);
    }

    /**
     * Verifica se o MySQL rejeita moedas inválidas.
     */
    public function test_banco_rejeita_moeda_invalida(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->despesas()->create([
            'description' => 'Despesa com moeda inválida',
            'amount' => 100.00,
            'currency' => 'JPY',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
        ]);
    }

    /**
     * Verifica se o MySQL rejeita categorias inválidas.
     */
    public function test_banco_rejeita_categoria_invalida(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->receitas()->create([
            'description' => 'Receita com categoria inválida',
            'amount' => 100.00,
            'currency' => 'EUR',
            'category' => 'categoria_inexistente',
            'income_date' => '2026-11-09',
        ]);
    }
}

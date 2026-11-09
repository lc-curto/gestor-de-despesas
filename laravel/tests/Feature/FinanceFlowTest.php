<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class FinanceFlowTest extends TestCase
{
    use RefreshDatabase;
    public function test_authenticated_user_can_create_an_expense(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->post(route('despesas.store'),[
            'description'=>'Supermercado','amount'=>'45.90','currency'=>'EUR',
            'category'=>'alimentacao','expense_date'=>'2026-11-09',
        ])->assertRedirect(route('despesas.index'));
        $this->assertDatabaseHas('despesas',[
            'user_id'=>$user->id,'description'=>'Supermercado','amount'=>'45.90',
        ]);
    }
    public function test_authenticated_user_can_create_an_income(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->post(route('receitas.store'),[
            'description'=>'Salário','amount'=>'2200.00','currency'=>'EUR',
            'category'=>'salario','income_date'=>'2026-11-09',
        ])->assertRedirect(route('receitas.index'));
        $this->assertDatabaseHas('receitas',['user_id'=>$user->id,'description'=>'Salário']);
    }
    public function test_invalid_expense_is_rejected_by_application_validation(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->from(route('despesas.create'))->post(route('despesas.store'),[
            'description'=>'Valor inválido','amount'=>'-1','currency'=>'EUR',
            'category'=>'casa','expense_date'=>'2026-11-09',
        ])->assertRedirect(route('despesas.create'))->assertSessionHasErrors('amount');
    }
    public function test_user_cannot_access_another_users_expense(): void
    {
        $owner=User::factory()->create();
        $other=User::factory()->create();
        $expense=$owner->despesas()->create([
            'description'=>'Privada','amount'=>20,'currency'=>'EUR',
            'category'=>'casa','expense_date'=>'2026-11-09',
        ]);
        $this->actingAs($other)->get(route('despesas.edit',$expense->id))->assertNotFound();
        $this->actingAs($other)->delete(route('despesas.destroy',$expense->id))->assertNotFound();
        $this->assertDatabaseHas('despesas',['id'=>$expense->id,'user_id'=>$owner->id]);
    }
    public function test_guest_cannot_create_financial_movements(): void
    {
        $this->post(route('despesas.store'),[])->assertRedirect(route('login'));
        $this->post(route('receitas.store'),[])->assertRedirect(route('login'));
    }

    public function test_movement_currency_always_uses_the_account_currency(): void
    {
        $user = User::factory()->create(['currency' => 'USD']);

        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Compra em dólares',
            'amount' => '12.50',
            'currency' => 'EUR',
            'category' => 'compras',
            'expense_date' => '2026-11-09',
        ])->assertRedirect(route('despesas.index'));

        $this->assertDatabaseHas('despesas', [
            'user_id' => $user->id,
            'description' => 'Compra em dólares',
            'currency' => 'USD',
        ]);
    }

    public function test_dashboard_movements_endpoint_paginates_and_filters_by_user_and_month(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= 8; $i++) {
            $user->despesas()->create([
                'description' => 'Despesa ' . $i,
                'amount' => $i,
                'currency' => 'EUR',
                'category' => 'casa',
                'expense_date' => '2026-11-09',
            ]);
        }

        $user->receitas()->create([
            'description' => 'Salário',
            'amount' => 1000,
            'currency' => 'EUR',
            'category' => 'salario',
            'income_date' => '2026-11-10',
        ]);

        $other->despesas()->create([
            'description' => 'Movimento privado',
            'amount' => 999,
            'currency' => 'EUR',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
        ]);

        $response = $this->actingAs($user)->getJson(route('dashboard.movimentos', [
            'mes' => '2026-11',
            'limit' => 6,
            'page' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('per_page', 6)
            ->assertJsonPath('total', 9)
            ->assertJsonPath('data.0.description', 'Salário')
            ->assertJsonPath('has_more', true)
            ->assertJsonCount(6, 'data');

        $this->assertNotContains('Movimento privado', collect($response->json('data'))->pluck('description'));
        $this->assertSame('private, no-store', $response->headers->get('Cache-Control'));
    }

    public function test_dashboard_totals_include_all_month_movements_not_only_first_page(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);

        for ($i = 1; $i <= 12; $i++) {
            $user->despesas()->create([
                'description' => 'Despesa ' . $i,
                'amount' => 10,
                'currency' => 'EUR',
                'category' => 'casa',
                'expense_date' => '2026-11-09',
            ]);
        }

        $this->actingAs($user)->get(route('dashboard', ['mes' => '2026-11']))
            ->assertOk()
            ->assertSee('120,00');
    }

    public function test_dashboard_inline_update_returns_to_dashboard_and_keeps_account_currency(): void
    {
        $user = User::factory()->create(['currency' => 'GBP']);
        $expense = $user->despesas()->create([
            'description' => 'Original',
            'amount' => 10,
            'currency' => 'GBP',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
        ]);

        $this->actingAs($user)->put(route('despesas.update', $expense->id), [
            'return_to_dashboard' => '1',
            'description' => 'Atualizada',
            'amount' => '25.50',
            'currency' => 'EUR',
            'category' => 'compras',
            'expense_date' => '2026-11-10',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('despesas', [
            'id' => $expense->id,
            'description' => 'Atualizada',
            'amount' => '25.50',
            'currency' => 'GBP',
        ]);
    }

}

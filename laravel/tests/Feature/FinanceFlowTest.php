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
}
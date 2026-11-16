<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\ContaFinanceira;
use App\Services\Financial\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class FinanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, string $openingBalance = '10000.00'): string
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta de teste',
            'type' => 'bank',
            'opening_balance' => $openingBalance,
            'opening_balance_date' => '2026-11-01',
        ])->id;
    }

    public function test_authenticated_user_can_create_an_expense(): void
    {
        $user=User::factory()->create();
        $accountId = $this->account($user);
        $this->actingAs($user)->post(route('despesas.store'),[
            'description'=>'Supermercado','amount'=>'45.90','currency'=>'EUR',
            'category'=>'alimentacao','expense_date'=>'2026-11-09','account_id'=>$accountId,
        ])->assertRedirect(route('despesas.index'));
        $this->assertDatabaseHas('despesas',[
            'user_id'=>$user->id,'description'=>'Supermercado','amount'=>'45.90',
        ]);
        $legacyId = $user->despesas()->where('description', 'Supermercado')->value('id');
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'expense', 'source_account_id' => $accountId,
            'legacy_type' => 'despesa', 'legacy_id' => $legacyId,
        ]);
    }

    public function test_expense_form_with_credit_card_creates_the_invoice_and_keeps_legacy_link(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '1000.00',
        ]);

        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Compra no supermercado', 'amount' => '35.50', 'currency' => 'EUR',
            'category' => 'alimentacao', 'expense_date' => '2026-11-09', 'account_id' => $card->id,
        ])->assertRedirect(route('despesas.index'));

        $legacyExpense = $user->despesas()->where('description', 'Compra no supermercado')->firstOrFail();
        $invoice = $user->faturas()->firstOrFail();
        $this->assertSame('2026-11-01', $invoice->reference_month->toDateString());
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'legacy_type' => 'despesa', 'legacy_id' => $legacyExpense->id,
            'source_account_id' => $card->id, 'invoice_id' => $invoice->id, 'amount' => '35.50',
        ]);
        $this->assertDatabaseCount('despesas', 1);
        $this->assertDatabaseCount('faturas', 1);
    }

    public function test_authenticated_user_can_create_an_income(): void
    {
        $user=User::factory()->create();
        $accountId = $this->account($user);
        $this->actingAs($user)->post(route('receitas.store'),[
            'description'=>'Salário','amount'=>'2200.00','currency'=>'EUR',
            'category'=>'salario','income_date'=>'2026-11-09','account_id'=>$accountId,
        ])->assertRedirect(route('receitas.index'));
        $this->assertDatabaseHas('receitas',['user_id'=>$user->id,'description'=>'Salário']);
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'income', 'destination_account_id' => $accountId,
            'legacy_type' => 'receita',
        ]);
    }
    public function test_invalid_expense_is_rejected_by_application_validation(): void
    {
        $user=User::factory()->create();
        $accountId = $this->account($user);
        $this->actingAs($user)->from(route('despesas.create'))->post(route('despesas.store'),[
            'description'=>'Valor inválido','amount'=>'-1','currency'=>'EUR',
            'category'=>'casa','expense_date'=>'2026-11-09','account_id'=>$accountId,
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
        $accountId = $this->account($user);

        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Compra em dólares',
            'amount' => '12.50',
            'currency' => 'EUR',
            'category' => 'compras',
            'expense_date' => '2026-11-09',
            'account_id' => $accountId,
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
            ->assertJsonPath('data.0.data_movimento', '2026-11-10')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('total_despesas_filtradas', 8)
            ->assertJsonPath('total_receitas_filtradas', 1)
            ->assertJsonCount(6, 'data');

        // A segunda página continua a ordenação global sem repetir nem saltar movimentos.
        $secondPage = $this->getJson(route('dashboard.movimentos', [
            'mes' => '2026-11',
            'limit' => 6,
            'page' => 2,
        ]));

        $secondPage->assertOk()
            ->assertJsonPath('page', 2)
            ->assertJsonPath('per_page', 6)
            ->assertJsonPath('total', 9)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('has_more', false);

        $firstIds = collect($response->json('data'))->pluck('id');
        $secondIds = collect($secondPage->json('data'))->pluck('id');
        $this->assertSame([], $firstIds->intersect($secondIds)->values()->all());

        $this->assertNotContains('Movimento privado', collect($response->json('data'))->pluck('description'));
        $cacheDirectives = array_map(
            'trim',
            explode(',', strtolower($response->headers->get('Cache-Control')))
        );
        sort($cacheDirectives);
        $this->assertSame(['no-store', 'private'], $cacheDirectives);
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
        $accountId = $this->account($user);
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
            'account_id' => $accountId,
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('despesas', [
            'id' => $expense->id,
            'description' => 'Atualizada',
            'amount' => '25.50',
            'currency' => 'GBP',
        ]);
    }

    public function test_dashboard_movement_crud_returns_json_for_no_reload_interactions(): void
    {
        $user = User::factory()->create();
        $accountId = $this->account($user);

        $created = $this->actingAs($user)->postJson(route('despesas.store'), [
            'description' => 'Compra online',
            'amount' => '35.90',
            'currency' => 'EUR',
            'category' => 'compras',
            'expense_date' => '2026-11-09',
            'account_id' => $accountId,
        ])->assertOk()->assertJsonPath('ok', true);

        $expense = $user->despesas()->where('description', 'Compra online')->firstOrFail();

        $this->putJson(route('despesas.update', $expense->id), [
            'description' => 'Compra atualizada',
            'amount' => '35.90',
            'currency' => 'EUR',
            'category' => 'compras',
            'expense_date' => '2026-11-09',
            'account_id' => $accountId,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->deleteJson(route('despesas.destroy', $expense->id))
            ->assertUnprocessable();

        $this->assertDatabaseHas('despesas', ['id' => $expense->id]);
    }

    public function test_existing_pending_legacy_movement_can_be_associated_through_edit_form(): void
    {
        $user = User::factory()->create();
        $accountId = $this->account($user, '100.00');
        $categoryId = $user->categorias()->where('slug', 'casa')->value('id');
        $expense = $user->despesas()->create([
            'description' => 'Renda importada', 'amount' => '25.00', 'currency' => 'EUR',
            'category' => 'casa', 'category_id' => $categoryId, 'expense_date' => '2026-11-15',
        ]);
        $movement = $user->movimentos()->create([
            'type' => 'expense', 'status' => 'pending', 'description' => $expense->description,
            'amount' => '25.00', 'movement_date' => '2026-11-15', 'category_id' => $categoryId,
            'legacy_type' => 'despesa', 'legacy_id' => $expense->id,
            'requires_account_assignment' => true, 'is_historical' => false,
        ]);

        $this->actingAs($user)->put(route('despesas.update', $expense->id), [
            'description' => 'Renda importada', 'amount' => '25.00', 'category_id' => $categoryId,
            'expense_date' => '2026-11-15', 'account_id' => $accountId,
        ])->assertRedirect(route('despesas.index'));

        $this->assertDatabaseHas('movimentos', [
            'id' => $movement->id, 'status' => 'settled', 'source_account_id' => $accountId,
            'requires_account_assignment' => false,
        ]);
        $this->assertSame('75.00', app(\App\Services\Financial\AccountBalanceService::class)->balance(
            ContaFinanceira::query()->findOrFail($accountId)
        ));
    }

    public function test_historical_movement_can_be_associated_without_changing_operational_balance(): void
    {
        $user = User::factory()->create(['financial_cutoff_date' => '2026-11-12']);
        $accountId = $this->account($user, '100.00');
        $legacyAccount = $user->contasFinanceiras()->create([
            'name' => 'Histórico', 'type' => 'legacy', 'currency' => 'EUR', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-12', 'is_operational' => false,
            'allow_negative_balance' => false, 'status' => 'historical',
        ]);
        $categoryId = $user->categorias()->where('slug', 'casa')->value('id');
        $expense = $user->despesas()->create([
            'description' => 'Despesa antiga', 'amount' => '20.00', 'currency' => 'EUR',
            'category' => 'casa', 'category_id' => $categoryId, 'expense_date' => '2026-11-10',
        ]);
        $movement = $user->movimentos()->create([
            'type' => 'expense', 'status' => 'settled', 'description' => $expense->description,
            'amount' => '20.00', 'movement_date' => '2026-11-10', 'source_account_id' => $legacyAccount->id,
            'category_id' => $categoryId, 'legacy_type' => 'despesa', 'legacy_id' => $expense->id,
            'is_historical' => true, 'requires_account_assignment' => false, 'settled_at' => now(),
        ]);

        $this->actingAs($user)->put(route('despesas.update', $expense->id), [
            'description' => 'Despesa antiga', 'amount' => '20.00', 'category_id' => $categoryId,
            'expense_date' => '2026-11-10', 'account_id' => $accountId,
        ])->assertRedirect(route('despesas.index'));

        $this->assertDatabaseHas('movimentos', [
            'id' => $movement->id, 'source_account_id' => $accountId, 'is_historical' => true,
        ]);
        $this->assertSame('100.00', app(\App\Services\Financial\AccountBalanceService::class)->balance(
            ContaFinanceira::query()->findOrFail($accountId)
        ));
    }

    public function test_new_movement_can_change_account_through_edit_form_with_audit(): void
    {
        $user = User::factory()->create();
        $firstAccountId = $this->account($user, '500.00');
        $secondAccountId = $this->account($user, '100.00');
        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Compra', 'amount' => '50.00', 'category' => 'compras',
            'expense_date' => '2026-11-15', 'account_id' => $firstAccountId,
        ])->assertRedirect(route('despesas.index'));
        $expense = $user->despesas()->where('description', 'Compra')->firstOrFail();

        $this->put(route('despesas.update', $expense->id), [
            'description' => 'Compra', 'amount' => '50.00', 'category' => 'compras',
            'expense_date' => '2026-11-15', 'account_id' => $secondAccountId,
        ])->assertRedirect(route('despesas.index'));

        $this->assertSame('500.00', app(\App\Services\Financial\AccountBalanceService::class)->balance(ContaFinanceira::query()->findOrFail($firstAccountId)));
        $this->assertSame('50.00', app(\App\Services\Financial\AccountBalanceService::class)->balance(ContaFinanceira::query()->findOrFail($secondAccountId)));
        $this->assertDatabaseHas('historico_movimentos', ['action' => 'account_reassigned']);
    }

}

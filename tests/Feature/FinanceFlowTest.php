<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFinancialDataReviewed;
use App\Models\ContaFinanceira;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\RecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $user = User::factory()->create();
        $accountId = $this->account($user);
        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Supermercado', 'amount' => '45.90', 'currency' => 'EUR',
            'category' => 'alimentacao', 'expense_date' => '2026-11-09', 'account_id' => $accountId,
        ])->assertRedirect(route('despesas.index'));
        $this->assertDatabaseHas('despesas', [
            'user_id' => $user->id, 'description' => 'Supermercado', 'amount' => '45.90',
        ]);
        $legacyId = $user->despesas()->where('description', 'Supermercado')->value('id');
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'expense', 'source_account_id' => $accountId,
            'legacy_type' => 'despesa', 'legacy_id' => $legacyId,
        ]);
    }

    public function test_credit_card_opening_debt_reduces_available_limit_and_can_be_paid_without_historical_purchases(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $bank = app(FinancialAccountService::class)->create($user, ['name' => 'Conta à ordem', 'type' => 'bank', 'opening_balance' => '1000.00', 'opening_balance_date' => '2026-11-01']);
        $card = app(FinancialAccountService::class)->create($user, ['name' => 'Cartão', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'opening_balance_date' => '2026-11-16', 'closing_day' => 10, 'due_day' => 1, 'credit_limit' => '1000.00']);
        $balances = app(AccountBalanceService::class);
        $this->assertSame('-350.00', $balances->balance($card));
        $this->actingAs($user)->postJson(route('financial.cards.pay-opening-debt', $card->id), ['account_id' => $bank->id, 'amount' => '200.00', 'payment_date' => '2026-11-16'])->assertOk();
        $card->refresh();
        $bank->refresh();
        $this->assertSame('200.00', (string) $card->opening_debt_paid);
        $this->assertSame('-150.00', $balances->balance($card));
        $this->assertSame('800.00', $balances->balance($bank));
        $this->assertDatabaseCount('despesas', 0);
        $this->assertDatabaseCount('movimentos', 1);
    }

    public function test_credit_card_creation_accepts_zero_padded_billing_days(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('financial.accounts.store'), [
            'name' => 'Novo Banco Crédito',
            'type' => 'credit_card',
            'closing_day' => '10',
            'due_day' => '01',
            'credit_limit' => '1000',
        ])->assertCreated();

        $this->assertDatabaseHas('contas_financeiras', [
            'user_id' => $user->id,
            'name' => 'Novo Banco Crédito',
            'type' => 'credit_card',
            'closing_day' => 10,
            'due_day' => 1,
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
        $user = User::factory()->create();
        $accountId = $this->account($user);
        $this->actingAs($user)->post(route('receitas.store'), [
            'description' => 'Salário', 'amount' => '2200.00', 'currency' => 'EUR',
            'category' => 'salario', 'income_date' => '2026-11-09', 'account_id' => $accountId,
        ])->assertRedirect(route('receitas.index'));
        $this->assertDatabaseHas('receitas', ['user_id' => $user->id, 'description' => 'Salário']);
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'income', 'destination_account_id' => $accountId,
            'legacy_type' => 'receita',
        ]);
    }

    public function test_invalid_expense_is_rejected_by_application_validation(): void
    {
        $user = User::factory()->create();
        $accountId = $this->account($user);
        $this->actingAs($user)->from(route('despesas.create'))->post(route('despesas.store'), [
            'description' => 'Valor inválido', 'amount' => '-1', 'currency' => 'EUR',
            'category' => 'casa', 'expense_date' => '2026-11-09', 'account_id' => $accountId,
        ])->assertRedirect(route('despesas.create'))->assertSessionHasErrors('amount');
    }

    public function test_user_cannot_access_another_users_expense(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expense = $owner->despesas()->create([
            'description' => 'Privada', 'amount' => 20, 'currency' => 'EUR',
            'category' => 'casa', 'expense_date' => '2026-11-09',
        ]);
        $this->actingAs($other)->get(route('despesas.edit', $expense->id))->assertNotFound();
        $this->actingAs($other)->delete(route('despesas.destroy', $expense->id))->assertNotFound();
        $this->assertDatabaseHas('despesas', ['id' => $expense->id, 'user_id' => $owner->id]);
    }

    public function test_guest_cannot_create_financial_movements(): void
    {
        $this->post(route('despesas.store'), [])->assertRedirect(route('login'));
        $this->post(route('receitas.store'), [])->assertRedirect(route('login'));
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
                'description' => 'Despesa '.$i,
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
                'description' => 'Despesa '.$i,
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
        ])->assertUnprocessable();

        $movement = $user->movimentos()->where('legacy_type', 'despesa')->where('legacy_id', $expense->id)->firstOrFail();

        $this->deleteJson(route('despesas.destroy', $expense->id))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('despesas', ['id' => $expense->id]);
        $this->assertDatabaseHas('movimentos', ['id' => $movement->id, 'status' => 'void']);
    }

    public function test_deleting_a_settled_legacy_expense_automatically_removes_its_balance_impact_and_keeps_audit(): void
    {
        $user = User::factory()->create();
        $accountId = $this->account($user, '100.00');

        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Compra lançada por engano',
            'amount' => '15.00',
            'category' => 'compras',
            'expense_date' => '2026-11-15',
            'account_id' => $accountId,
        ])->assertRedirect(route('despesas.index'));

        $legacy = $user->despesas()->where('description', 'Compra lançada por engano')->firstOrFail();
        $movement = $user->movimentos()->where('legacy_type', 'despesa')->where('legacy_id', $legacy->id)->firstOrFail();
        $account = ContaFinanceira::query()->findOrFail($accountId);

        $this->assertSame('85.00', app(AccountBalanceService::class)->balance($account));

        $this->delete(route('despesas.destroy', $legacy->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('despesas', ['id' => $legacy->id]);
        $this->assertDatabaseHas('movimentos', ['id' => $movement->id, 'status' => 'void']);
        $this->assertDatabaseHas('historico_movimentos', [
            'movement_id' => $movement->id,
            'action' => 'deleted_as_correction',
        ]);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_recurring_credit_card_expense_is_forecast_and_settled_into_the_correct_invoice(): void
    {
        Carbon::setTestNow('2026-11-16 10:00:00');

        try {
            $user = User::factory()->create(['currency' => 'EUR']);
            $card = app(FinancialAccountService::class)->create($user, [
                'name' => 'Visa recorrente',
                'type' => 'credit_card',
                'opening_balance' => '0.00',
                'opening_balance_date' => '2026-11-01',
                'closing_day' => 25,
                'due_day' => 5,
                'credit_limit' => '1000.00',
            ]);
            $categoryId = $user->categorias()->where('slug', 'compras')->value('id');

            $rule = app(RecurrenceService::class)->create($user, [
                'type' => 'expense',
                'description' => 'Subscrição mensal',
                'amount' => '30.00',
                'category_id' => $categoryId,
                'account_id' => $card->id,
                'frequency' => 'monthly',
                'interval_value' => 1,
                'start_date' => '2026-11-16',
            ]);

            $movement = $rule->movimentos()->whereDate('recurrence_occurrence_date', '2026-11-16')->firstOrFail();

            $this->assertSame('pending', $movement->status);
            $this->assertSame($card->id, $movement->source_account_id);
            $this->assertNull($movement->invoice_id);
            $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card));

            $this->actingAs($user)->putJson(route('financial.movements.settle', $movement->id), [
                'source_account_id' => $card->id,
            ])->assertOk();

            $movement->refresh();
            $this->assertSame('settled', $movement->status);
            $this->assertNotNull($movement->invoice_id);
            $this->assertDatabaseHas('faturas', [
                'id' => $movement->invoice_id,
                'account_id' => $card->id,
            ]);
            $referenceMonth = Carbon::parse(
                DB::table('faturas')->where('id', $movement->invoice_id)->value('reference_month')
            )->toDateString();
            $this->assertSame('2026-11-01', $referenceMonth);
        } finally {
            Carbon::setTestNow();
        }
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
        $this->assertSame('75.00', app(AccountBalanceService::class)->balance(
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
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance(
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

        $this->assertSame('500.00', app(AccountBalanceService::class)->balance(ContaFinanceira::query()->findOrFail($firstAccountId)));
        $this->assertSame('50.00', app(AccountBalanceService::class)->balance(ContaFinanceira::query()->findOrFail($secondAccountId)));
        $this->assertDatabaseHas('historico_movimentos', ['action' => 'account_reassigned']);
    }

    public function test_credit_cards_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);

        $this->actingAs($user)
            ->get(route('financial.cards.page'))
            ->assertOk()
            ->assertSee('Cartões');
    }

    public function test_commitments_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);

        $this->actingAs($user)
            ->get(route('financial.commitments.page'))
            ->assertOk()
            ->assertSee('Parcelamentos')
            ->assertSee('Próxima prestação');
    }

    public function test_commitments_page_excludes_single_deferred_expenses_and_shows_installment_plans(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialCommitmentService::class);
        $service->create($user, [
            'name' => 'Fatura de eletricidade',
            'total_amount' => '90.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);
        $service->create($user, [
            'name' => 'Compra parcelada',
            'total_amount' => '120.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'installments',
            'installments_count' => 3,
        ]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);

        $this->actingAs($user)
            ->get(route('financial.commitments.page'))
            ->assertOk()
            ->assertSee('Compra parcelada')
            ->assertDontSee('Fatura de eletricidade');
    }

    public function test_account_creation_form_has_only_account_fields_and_creates_opening_balance(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $this->actingAs($user)->get(route('financial.accounts.page'))
            ->assertOk()
            ->assertSee('accountCreateForm')
            ->assertSee('opening_balance')
            ->assertDontSee('id="transferAmount"', false);

        $response = $this->actingAs($user)->postJson(route('financial.accounts.store'), [
            'name' => 'Conta principal',
            'type' => 'bank',
            'opening_balance' => '1250.50',
            'opening_balance_date' => '2026-11-16',
        ]);
        $response->assertCreated();
        $account = ContaFinanceira::query()->where('name', 'Conta principal')->firstOrFail();
        $this->assertSame('1250.50', app(AccountBalanceService::class)->balance($account));
    }

    public function test_transfer_form_uses_transfer_movement_and_moves_balance_between_accounts(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $sourceId = $this->account($user, '500.00');
        $destinationId = $this->account($user, '100.00');

        $this->actingAs($user)->get(route('financial.accounts.page'))
            ->assertOk()
            ->assertSee('accountTransferForm')
            ->assertSee('Transferir entre contas');

        $this->actingAs($user)->postJson(route('financial.movements.store'), [
            'type' => 'transfer',
            'description' => 'Transferência entre contas',
            'amount' => '75.00',
            'movement_date' => '2026-11-16',
            'source_account_id' => $sourceId,
            'destination_account_id' => $destinationId,
        ])->assertCreated();

        $balances = app(AccountBalanceService::class);
        $this->assertSame('425.00', $balances->balance(ContaFinanceira::query()->findOrFail($sourceId)));
        $this->assertSame('175.00', $balances->balance(ContaFinanceira::query()->findOrFail($destinationId)));
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id,
            'type' => 'transfer',
            'source_account_id' => $sourceId,
            'destination_account_id' => $destinationId,
            'amount' => '75.00',
        ]);
    }

    public function test_credit_cards_page_handles_closed_cards_without_calling_operational_balance_service(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão encerrado',
            'type' => 'credit_card',
            'opening_debt_amount' => '125.00',
            'opening_balance_date' => '2026-11-16',
            'closing_day' => 10,
            'due_day' => 1,
            'credit_limit' => '1000.00',
        ]);
        $card->update(['status' => 'closed', 'is_operational' => false]);

        $this->actingAs($user)
            ->get(route('financial.cards.page'))
            ->assertOk()
            ->assertSee('Cartão encerrado')
            ->assertSee('125,00');
    }

    public function test_editing_partially_settled_original_preserves_payments_and_rejects_amount_below_paid_total(): void
    {
        $user = User::factory()->create();
        $accountId = $this->account($user, '10000.00');

        $this->actingAs($user)->postJson(route('despesas.store'), [
            'description' => 'Despesa original',
            'amount' => '100.00',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
            'paid_now' => '0',
        ])->assertOk();

        $legacy = $user->despesas()->where('description', 'Despesa original')->firstOrFail();
        $movement = $user->movimentos()
            ->where('legacy_type', 'despesa')
            ->where('legacy_id', $legacy->id)
            ->firstOrFail();

        app(\App\Services\Financial\FinancialMovementService::class)->settle($user, $movement, [
            'source_account_id' => $accountId,
            'amount' => '40.00',
            'movement_date' => '2026-11-16',
        ]);

        $payment = $movement->settlements()->where('status', 'settled')->firstOrFail();

        $payload = [
            'description' => 'Despesa revista',
            'amount' => '120.00',
            'category' => 'casa',
            'expense_date' => '2026-11-09',
            'account_id' => $accountId,
            'paid_now' => '0',
        ];

        $this->actingAs($user)->putJson(route('despesas.update', $legacy->id), $payload)->assertOk();

        $updated = $movement->fresh();
        $this->assertSame($movement->id, $updated->id);
        $this->assertSame('120.00', $updated->amount);
        $this->assertSame('partial', $updated->status);
        $this->assertSame('Despesa revista', $updated->description);
        $this->assertSame('40.00', $payment->fresh()->amount);
        $this->assertSame(1, $updated->settlements()->where('status', 'settled')->count());
        $this->assertSame('120.00', (string) $legacy->fresh()->amount);
        $this->assertSame('9960.00', app(AccountBalanceService::class)->balance(
            $user->contasFinanceiras()->whereKey($accountId)->firstOrFail()
        ));

        $this->actingAs($user)->putJson(route('despesas.update', $legacy->id), array_merge($payload, [
            'amount' => '30.00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame('120.00', $movement->fresh()->amount);
        $this->assertSame('120.00', (string) $legacy->fresh()->amount);
        $this->assertSame('40.00', $payment->fresh()->amount);
        $this->assertSame(1, $movement->settlements()->where('status', 'settled')->count());
    }

}

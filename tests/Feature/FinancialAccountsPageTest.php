<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_accounts_page(): void
    {
        $this->get('/contas')->assertRedirect('/login');
    }

    public function test_user_sees_only_their_own_financial_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(FinancialAccountService::class);

        $service->create($owner, [
            'name' => 'Conta da Ana',
            'type' => 'bank',
            'opening_balance' => '120.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $service->create($other, [
            'name' => 'Conta privada de outro utilizador',
            'type' => 'bank',
            'opening_balance' => '50.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($owner)
            ->get('/contas')
            ->assertOk()
            ->assertSee('Conta da Ana')
            ->assertSee('Permitir saldo negativo nesta conta')
            ->assertDontSee('Conta privada de outro utilizador');
    }

    public function test_owner_can_rename_and_close_an_account_without_deleting_it(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);
        $account = $service->create($user, [
            'name' => 'Conta antiga', 'type' => 'bank', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id, [
            'name' => 'Conta principal',
        ])->assertOk()->assertJsonPath('data.name', 'Conta principal');

        $this->postJson('/api/financial/accounts/'.$account->id.'/close')
            ->assertOk();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $account->id, 'user_id' => $user->id, 'name' => 'Conta principal',
            'status' => 'closed', 'is_operational' => false,
        ]);
    }

    public function test_owner_can_change_negative_balance_permission_after_creation(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta à ordem', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $this->assertFalse((bool) $account->allow_negative_balance);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => true,
        ])->assertOk();
        $this->assertDatabaseHas('contas_financeiras', ['id' => $account->id, 'allow_negative_balance' => true]);

        $this->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertOk();
        $this->assertDatabaseHas('contas_financeiras', ['id' => $account->id, 'allow_negative_balance' => false]);
    }

    public function test_negative_balance_permission_cannot_be_disabled_when_operational_balance_is_negative(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta em descoberto', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01', 'allow_negative_balance' => true,
        ]);
        app(\App\Services\Financial\FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Débito em descoberto', 'amount' => '125.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $account->id, 'allow_negative_balance' => true,
        ]);
    }

    public function test_negative_balance_permission_cannot_be_disabled_for_negative_opening_balance(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta negativa', 'type' => 'bank', 'opening_balance' => '-25.00',
            'opening_balance_date' => '2026-11-01', 'allow_negative_balance' => true,
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('contas_financeiras', ['id' => $account->id, 'allow_negative_balance' => true]);
    }

    public function test_closing_positive_balance_transfers_it_and_keeps_both_accounts(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);
        $source = $service->create($user, [
            'name' => 'Conta a encerrar', 'type' => 'bank', 'opening_balance' => '75.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $target = $service->create($user, [
            'name' => 'Conta de destino', 'type' => 'bank', 'opening_balance' => '10.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->postJson('/api/financial/accounts/'.$source->id.'/close', [
            'transfer_to_account_id' => $target->id,
        ])->assertOk();

        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'transfer',
            'source_account_id' => $source->id, 'destination_account_id' => $target->id,
            'amount' => '75.00',
        ]);
        $this->assertDatabaseHas('contas_financeiras', ['id' => $source->id, 'status' => 'closed']);
        $this->assertDatabaseHas('contas_financeiras', ['id' => $target->id, 'status' => 'active']);
    }

    public function test_credit_cards_are_not_displayed_on_accounts_page(): void
    {
        $user = User::factory()->create();
        app(FinancialAccountService::class)->create($user, ['name' => 'Cartão principal', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1]);
        $this->actingAs($user)->get('/contas')->assertOk()->assertDontSee('Os meus cartões')->assertDontSee('Cartão principal');
    }

    public function test_credit_cards_are_displayed_on_their_own_page(): void
    {
        $user = User::factory()->create();
        app(FinancialAccountService::class)->create($user, ['name' => 'Cartão principal', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1]);
        $this->actingAs($user)->get('/cartoes')->assertOk()->assertSee('Cartões')->assertSee('Cartão principal')->assertSee('Utilizado')->assertSee('Disponível');
    }

    public function test_card_limit_cannot_be_reduced_below_current_utilization(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, ['name' => 'Cartão principal', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1]);
        $this->actingAs($user)->putJson('/api/financial/accounts/'.$card->id.'/card-settings', ['credit_limit' => '300.00', 'closing_day' => 10, 'due_day' => 1])->assertUnprocessable();
    }

    public function test_initial_card_debt_can_be_corrected_before_any_movement(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão corrigível', 'type' => 'credit_card', 'opening_debt_amount' => '350.00',
            'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1,
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$card->id.'/card-settings', [
            'credit_limit' => '1000.00', 'opening_debt_amount' => '275.50', 'closing_day' => 30, 'due_day' => 31,
        ])->assertOk();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $card->id, 'opening_debt_amount' => '275.50', 'opening_balance' => '-275.50', 'closing_day' => 30, 'due_day' => 31,
        ]);
    }

    public function test_initial_card_debt_cannot_be_changed_after_a_card_movement(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão usado', 'type' => 'credit_card', 'opening_debt_amount' => '100.00',
            'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra posterior', 'amount' => '20.00', 'movement_date' => '2026-11-16',
        ])->assertCreated();

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$card->id.'/card-settings', [
            'credit_limit' => '1000.00', 'opening_debt_amount' => '200.00', 'closing_day' => 10, 'due_day' => 1,
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'A dívida inicial só pode ser corrigida antes de existirem movimentos no cartão.']);
    }

    public function test_accounts_page_shows_bank_to_cash_transfer_form(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);
        $service->create($user, [
            'name' => 'Banco', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $service->create($user, [
            'name' => 'Carteira', 'type' => 'cash', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->get('/contas')
            ->assertOk()
            ->assertSee('Transferir')
            ->assertSee('Banco')
            ->assertSee('Carteira');
    }
}

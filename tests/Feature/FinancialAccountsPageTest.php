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

        $this->actingAs($user)->putJson('/api/financial/accounts/' . $account->id, [
            'name' => 'Conta principal',
        ])->assertOk()->assertJsonPath('data.name', 'Conta principal');

        $this->postJson('/api/financial/accounts/' . $account->id . '/close')
            ->assertOk();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $account->id, 'user_id' => $user->id, 'name' => 'Conta principal',
            'status' => 'closed', 'is_operational' => false,
        ]);
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

        $this->actingAs($user)->postJson('/api/financial/accounts/' . $source->id . '/close', [
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
            ->assertSee('Registar transferência')
            ->assertSee('Banco')
            ->assertSee('Carteira');
    }
}

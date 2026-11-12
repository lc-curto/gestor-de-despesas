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
}

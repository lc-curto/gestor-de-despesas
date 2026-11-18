<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizeExistingOverdraftPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_disables_overdraft_only_for_operational_accounts_with_nonnegative_balances(): void
    {
        $user = User::factory()->create();
        $accounts = app(FinancialAccountService::class);
        $movements = app(FinancialMovementService::class);

        $positive = $accounts->create($user, [
            'name' => 'Conta positiva',
            'type' => 'bank',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
            'allow_negative_balance' => true,
        ]);
        $movements->create($user, [
            'type' => 'expense',
            'description' => 'Despesa dentro do saldo',
            'amount' => '40.00',
            'movement_date' => '2026-11-12',
            'source_account_id' => $positive->id,
        ]);

        $negative = $accounts->create($user, [
            'name' => 'Conta operacional negativa',
            'type' => 'cash',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
            'allow_negative_balance' => true,
        ]);
        $movements->create($user, [
            'type' => 'expense',
            'description' => 'Despesa acima do saldo',
            'amount' => '120.00',
            'movement_date' => '2026-11-12',
            'source_account_id' => $negative->id,
        ]);

        $recoveredNegativeOpening = $accounts->create($user, [
            'name' => 'Saldo inicial negativo recuperado',
            'type' => 'bank',
            'opening_balance' => '-25.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $movements->create($user, [
            'type' => 'income',
            'description' => 'Reposição',
            'amount' => '30.00',
            'movement_date' => '2026-11-12',
            'destination_account_id' => $recoveredNegativeOpening->id,
        ]);

        $card = $accounts->create($user, [
            'name' => 'Cartão',
            'type' => 'credit_card',
            'opening_debt_amount' => '0.00',
            'credit_limit' => '1000.00',
            'closing_day' => 20,
            'due_day' => 5,
        ]);

        $migration = require database_path('migrations/2026_11_18_150000_normalize_existing_overdraft_permissions.php');
        $migration->up();

        $this->assertFalse((bool) $positive->fresh()->allow_negative_balance);
        $this->assertSame('60.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($positive->fresh()));

        $this->assertTrue((bool) $negative->fresh()->allow_negative_balance);
        $this->assertSame('-20.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($negative->fresh()));

        // O saldo atual é positivo, mas o saldo inicial negativo mantém a
        // permissão por causa da constraint e da regra de negócio existente.
        $this->assertTrue((bool) $recoveredNegativeOpening->fresh()->allow_negative_balance);
        $this->assertSame('5.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($recoveredNegativeOpening->fresh()));

        // Cartões não pertencem ao âmbito desta migração.
        $this->assertTrue((bool) $card->fresh()->allow_negative_balance);
    }

    public function test_migration_is_idempotent(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta positiva',
            'type' => 'bank',
            'opening_balance' => '10.00',
            'opening_balance_date' => '2026-11-01',
            'allow_negative_balance' => true,
        ]);

        $migration = require database_path('migrations/2026_11_18_150000_normalize_existing_overdraft_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertFalse((bool) $account->fresh()->allow_negative_balance);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialCoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, string $name, string $balance = '100.00', bool $allowNegative = false): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => $name,
            'type' => 'bank',
            'opening_balance' => $balance,
            'opening_balance_date' => '2026-11-01',
            'allow_negative_balance' => $allowNegative,
        ]);
    }

    public function test_account_balance_reflects_settled_income_expense_and_transfers_without_double_counting(): void
    {
        $user = User::factory()->create();
        $first = $this->account($user, 'Conta principal');
        $second = $this->account($user, 'Poupança', '0.00');
        $service = app(FinancialMovementService::class);

        $service->create($user, [
            'type' => 'income', 'description' => 'Salário', 'amount' => '50.00',
            'movement_date' => '2026-11-12', 'destination_account_id' => $first->id,
        ]);
        $service->create($user, [
            'type' => 'expense', 'description' => 'Compras', 'amount' => '20.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $first->id,
        ]);
        $service->transfer($user, [
            'description' => 'Poupança', 'amount' => '30.00', 'movement_date' => '2026-11-12',
            'source_account_id' => $first->id, 'destination_account_id' => $second->id,
        ]);

        $balances = app(AccountBalanceService::class);
        $this->assertSame('100.00', $balances->balance($first));
        $this->assertSame('30.00', $balances->balance($second));
        $this->assertDatabaseCount('historico_movimentos', 3);
    }

    public function test_expense_cannot_overdraw_account_without_permission(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta', '10.00');

        try {
            app(FinancialMovementService::class)->create($user, [
                'type' => 'expense', 'description' => 'Despesa acima do saldo', 'amount' => '11.00',
                'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
            ]);
            $this->fail('Era esperado bloquear saldo insuficiente.');
        } catch (ValidationException|\RuntimeException) {
            $this->assertDatabaseCount('movimentos', 0);
        }
    }

    public function test_account_cannot_be_used_by_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner, 'Privada');

        $this->expectException(ValidationException::class);
        app(FinancialMovementService::class)->create($other, [
            'type' => 'expense', 'description' => 'Acesso indevido', 'amount' => '1.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);
    }

    public function test_pending_legacy_movement_can_be_settled_once_and_audited(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta');
        $movement = $user->movimentos()->create([
            'type' => 'expense',
            'status' => 'pending',
            'description' => 'Legado por associar',
            'amount' => '25.00',
            'movement_date' => '2026-11-13',
            'requires_account_assignment' => true,
            'is_historical' => false,
        ]);

        $service = app(FinancialMovementService::class);
        $settled = $service->settle($user, $movement, ['source_account_id' => $account->id]);

        $this->assertSame('settled', $settled->status);
        $this->assertFalse($settled->requires_account_assignment);
        $this->assertSame('75.00', app(AccountBalanceService::class)->balance($account));
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $movement->id, 'action' => 'settled']);
    }

    public function test_settled_movements_cannot_be_financially_edited(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta');
        $movement = app(FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Original', 'amount' => '5.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $this->expectException(ValidationException::class);
        app(FinancialMovementService::class)->updatePending($user, $movement, ['amount' => '9.00']);
    }
    public function test_settled_movement_allows_descriptive_correction_with_audit(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta');
        $movement = app(FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Descrição inicial', 'amount' => '5.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $updated = app(FinancialMovementService::class)->updatePending($user, $movement, [
            'description' => 'Descrição corrigida',
        ]);

        $this->assertSame('Descrição corrigida', $updated->description);
        $this->assertDatabaseHas('historico_movimentos', [
            'movement_id' => $movement->id,
            'action' => 'description_corrected',
        ]);
        $this->assertSame('95.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_authenticated_api_can_create_account_and_record_a_settled_expense(): void
    {
        $user = User::factory()->create();

        $accountResponse = $this->actingAs($user)->postJson('/api/financial/accounts', [
            'name' => 'Conta API',
            'type' => 'bank',
            'opening_balance' => '80.00',
            'opening_balance_date' => '2026-11-01',
        ])->assertCreated();

        $accountId = $accountResponse->json('data.id');

        $this->postJson('/api/financial/movements', [
            'type' => 'expense',
            'description' => 'Despesa via API',
            'amount' => '12.50',
            'movement_date' => '2026-11-12',
            'source_account_id' => $accountId,
        ])->assertCreated()->assertJsonPath('data.status', 'settled');

        $this->getJson('/api/financial/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.current_balance', '67.50');
    }

    public function test_user_can_transfer_between_bank_and_cash_without_creating_income_or_expense(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Banco', '100.00');
        $cash = app(FinancialAccountService::class)->create($user, [
            'name' => 'Carteira', 'type' => 'cash', 'opening_balance' => '5.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->postJson('/api/financial/movements', [
            'type' => 'transfer', 'description' => 'Levantamento', 'amount' => '30.00',
            'movement_date' => '2026-11-15', 'source_account_id' => $bank->id,
            'destination_account_id' => $cash->id,
        ])->assertCreated()->assertJsonPath('data.type', 'transfer');

        $balances = app(AccountBalanceService::class);
        $this->assertSame('70.00', $balances->balance($bank));
        $this->assertSame('35.00', $balances->balance($cash));
        $this->assertDatabaseCount('despesas', 0);
        $this->assertDatabaseCount('receitas', 0);
    }

    public function test_pending_commitment_does_not_change_account_balance_until_settled(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta');

        $pending = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Conta de água',
            'amount' => '15.00',
            'movement_date' => '2026-11-20',
        ]);

        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($account));

        app(FinancialMovementService::class)->settle($user, $pending, [
            'source_account_id' => $account->id,
        ]);

        $this->assertSame('85.00', app(AccountBalanceService::class)->balance($account));
    }

}

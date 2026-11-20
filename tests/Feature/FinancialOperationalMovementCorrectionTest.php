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

class FinancialOperationalMovementCorrectionTest extends TestCase
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

    public function test_settled_transfer_can_be_corrected_and_both_account_balances_are_reprojected(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $source = $this->account($user, 'Origem', '100.00');
        $destination = $this->account($user, 'Destino', '20.00');
        $other = $this->account($user, 'Outra', '50.00');
        $service = app(FinancialMovementService::class);
        $movement = $service->transfer($user, [
            'description' => 'Transferência', 'amount' => '30.00', 'movement_date' => '2026-11-10',
            'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
        ]);

        $updated = $service->updateOperationalMovement($user, $movement, [
            'amount' => '40.00', 'movement_date' => '2026-11-11',
            'source_account_id' => $source->id, 'destination_account_id' => $other->id,
        ]);

        $this->assertSame('40.00', (string) $updated->amount);
        $this->assertSame($other->id, $updated->destination_account_id);
        $balances = app(AccountBalanceService::class);
        $this->assertSame('60.00', $balances->balance($source->fresh()));
        $this->assertSame('20.00', $balances->balance($destination->fresh()));
        $this->assertSame('90.00', $balances->balance($other->fresh()));
        $this->assertDatabaseHas('historico_movimentos', [
            'movement_id' => $movement->id,
            'action' => 'operational_movement_corrected',
        ]);
    }

    public function test_settled_adjustment_direction_and_account_can_be_corrected(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = $this->account($user, 'Conta', '100.00');
        $service = app(FinancialMovementService::class);
        $movement = $service->create($user, [
            'type' => 'adjustment', 'adjustment_direction' => 'increase',
            'description' => 'Acerto', 'amount' => '15.00', 'movement_date' => '2026-11-10',
            'destination_account_id' => $account->id,
        ]);

        $updated = $service->updateOperationalMovement($user, $movement, [
            'adjustment_direction' => 'decrease', 'amount' => '10.00',
            'source_account_id' => $account->id,
        ]);

        $this->assertSame('decrease', $updated->adjustment_direction);
        $this->assertNull($updated->destination_account_id);
        $this->assertSame('90.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_transfer_correction_cannot_overdraw_source_account(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $source = $this->account($user, 'Origem', '100.00');
        $destination = $this->account($user, 'Destino', '0.00');
        $service = app(FinancialMovementService::class);
        $movement = $service->transfer($user, [
            'description' => 'Transferência', 'amount' => '30.00', 'movement_date' => '2026-11-10',
            'source_account_id' => $source->id, 'destination_account_id' => $destination->id,
        ]);

        try {
            $service->updateOperationalMovement($user, $movement, ['amount' => '101.00']);
            $this->fail('A correção deveria respeitar o saldo disponível.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame('70.00', app(AccountBalanceService::class)->balance($source->fresh()));
        $this->assertSame('30.00', app(AccountBalanceService::class)->balance($destination->fresh()));
    }
}

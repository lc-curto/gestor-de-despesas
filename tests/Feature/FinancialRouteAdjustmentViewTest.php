<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFinancialDataReviewed;
use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialRouteAdjustmentViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_day_data_exposes_signed_adjustments_and_the_cash_account_without_changing_income_or_expense_totals(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $accounts = app(FinancialAccountService::class);
        $bank = $accounts->create($user, [
            'name' => 'Conta principal', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $cash = $accounts->create($user, [
            'name' => 'Carteira', 'type' => 'cash', 'opening_balance' => '50.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $card = $accounts->create($user, [
            'name' => 'Cartão', 'type' => 'credit_card', 'opening_debt_amount' => '50.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $service = app(FinancialMovementService::class);
        $increase = $service->create($user, [
            'type' => 'adjustment', 'adjustment_direction' => 'increase',
            'description' => 'Ajuste de aumento', 'notes' => 'Recontagem', 'amount' => '20.50',
            'movement_date' => '2026-12-05', 'destination_account_id' => $bank->id,
        ]);
        $decrease = $service->create($user, [
            'type' => 'adjustment', 'adjustment_direction' => 'decrease',
            'description' => 'Ajuste de diminuição', 'amount' => '7.25',
            'movement_date' => '2026-12-05', 'source_account_id' => $cash->id,
        ]);
        $income = $service->createPending($user, [
            'type' => 'income', 'description' => 'Entrada pendente', 'amount' => '30.10',
            'movement_date' => '2026-12-05', 'destination_account_id' => $bank->id,
        ]);
        $fixed = $service->createPending($user, [
            'type' => 'expense', 'expense_behavior' => 'fixed', 'description' => 'Gasto fixo', 'amount' => '12.40',
            'movement_date' => '2026-12-05', 'source_account_id' => $bank->id,
        ]);
        $variable = $service->createPending($user, [
            'type' => 'expense', 'expense_behavior' => 'variable', 'description' => 'Gasto variável', 'amount' => '6.30',
            'movement_date' => '2026-12-05', 'source_account_id' => $cash->id,
        ]);
        $payment = $service->payCardOpeningDebt($user, $card->id, $bank->id, '2026-12-05', '10.00');
        $reversal = $service->reverse($user, $payment);

        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.route', ['mes' => '2026-12', 'modo' => 1]));
        $response->assertOk();

        $day = $response->viewData('days')['2026-12']['2026-12-05'];
        $this->assertEqualsWithDelta(30.10, $day['income'], 0.001);
        $this->assertEqualsWithDelta(12.40, $day['fixed'], 0.001);
        $this->assertEqualsWithDelta(6.30, $day['variable'], 0.001);
        $this->assertEqualsWithDelta(20.50 - 7.25 + 30.10 - 12.40 - 6.30, $day['cash_delta'], 0.001);

        $this->assertSame(1, preg_match('~<script type="application/json" id="route-day-2026-12-05">(.*?)</script>~s', $response->getContent(), $matches));
        $data = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $items = collect($data['items'])->keyBy('id');
        $this->assertCount(7, $items);
        $this->assertEqualsWithDelta(150 + $day['cash_delta'], $data['balance'], 0.001);

        $this->assertSame('adjustment', $items[$increase->id]['type']);
        $this->assertSame('settled', $items[$increase->id]['status']);
        $this->assertEqualsWithDelta(20.50, $items[$increase->id]['projection_delta'], 0.001);
        $this->assertSame($bank->id, $items[$increase->id]['account_id']);
        $this->assertSame('Conta principal', $items[$increase->id]['account_name']);
        $this->assertSame('Recontagem', $items[$increase->id]['notes']);

        $this->assertSame('adjustment', $items[$decrease->id]['type']);
        $this->assertEqualsWithDelta(-7.25, $items[$decrease->id]['projection_delta'], 0.001);
        $this->assertSame($cash->id, $items[$decrease->id]['account_id']);
        $this->assertSame('Carteira', $items[$decrease->id]['account_name']);

        $this->assertSame('transfer', $items[$payment->id]['type']);
        $this->assertEqualsWithDelta(-10.00, $items[$payment->id]['projection_delta'], 0.001);
        $this->assertSame($bank->id, $items[$payment->id]['account_id']);
        $this->assertSame('Conta principal', $items[$payment->id]['account_name']);
        $this->assertSame('transfer', $items[$reversal->id]['type']);
        $this->assertEqualsWithDelta(10.00, $items[$reversal->id]['projection_delta'], 0.001);
        $this->assertSame($bank->id, $items[$reversal->id]['account_id']);
        $this->assertSame('Conta principal', $items[$reversal->id]['account_name']);

        $this->assertSame('income', $items[$income->id]['type']);
        $this->assertEqualsWithDelta(30.10, $items[$income->id]['projection_delta'], 0.001);
        $this->assertSame($bank->id, $items[$income->id]['account_id']);
        $this->assertSame('fixed', $items[$fixed->id]['expense_behavior']);
        $this->assertSame($bank->id, $items[$fixed->id]['account_id']);
        $this->assertSame('variable', $items[$variable->id]['expense_behavior']);
        $this->assertSame($cash->id, $items[$variable->id]['account_id']);
    }
}

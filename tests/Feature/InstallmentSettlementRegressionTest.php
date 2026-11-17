<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Divida;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InstallmentSettlementRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function setupDebt(string $balance = '200.00'): array
    {
        $user = User::factory()->create();
        $bank = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta local', 'type' => 'bank', 'opening_balance' => $balance, 'opening_balance_date' => '2026-11-01',
        ]);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Compra', 'total_amount' => '90.00', 'start_date' => '2026-11-17', 'due_date' => '2026-12-17',
            'payment_condition' => 'installments', 'payment_method' => 'transfer', 'installments_count' => 3, 'installment_frequency' => 'monthly',
            'category_id' => $user->categorias()->where('slug', 'casa')->firstOrFail()->id,
        ]);
        return [$user, $bank, $debt];
    }

    private function settle(User $user, ContaFinanceira $account, string $id, string $amount, ?string $expected): \Illuminate\Testing\TestResponse
    {
        $payload = ['amount' => $amount, 'movement_date' => '2026-11-17', 'source_account_id' => $account->id];
        if ($expected !== null) $payload['expected_amount'] = $expected;
        return $this->actingAs($user)->putJson(route('financial.movements.settle', $id), $payload);
    }

    private function openAmount(Divida $debt): string
    {
        return number_format((float) $debt->parcelas()->whereIn('status', ['pending', 'partial'])->get()->sum(fn ($p) => (float) $p->amount - (float) $p->paid_amount), 2, '.', '');
    }

    private function projectedAmount(User $user, string $start, string $end): float
    {
        return app(FinancialProjectionService::class)->build($user, Carbon::parse($start), Carbon::parse($end))['movements']->sum('projection_delta');
    }

    public function test_full_payment_updates_one_existing_forecast_balance_and_installment_and_rejects_duplicates(): void
    {
        [$user, $bank, $debt] = $this->setupDebt();
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $this->settle($user, $bank, $forecast->id, '30.00', '30.00')
            ->assertOk()->assertJsonPath('data.id', $forecast->id)->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('installment.status', 'paid')->assertJsonPath('installment.paid_amount', '30.00');
        $this->assertSame('paid', $part->fresh()->status);
        $this->assertSame('60.00', $this->openAmount($debt));
        $this->assertSame('170.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debt->id)->count());
        $this->assertSame(-30.0, $this->projectedAmount($user, '2026-11-01', '2026-11-30'));
        $this->assertSame(0.0, $this->projectedAmount($user, '2026-12-01', '2026-12-31'));
        $this->assertSame(-60.0, $this->projectedAmount($user, '2027-01-01', '2027-02-28'));
        $this->settle($user, $bank, $forecast->id, '30.00', '30.00')->assertUnprocessable();
        $this->postJson(route('financial.commitments.installments.pay', ['id' => $debt->id, 'installmentId' => $part->id]), [
            'account_id' => $bank->id, 'amount' => '30.00', 'payment_date' => '2026-11-17',
        ])->assertUnprocessable();
        $this->assertSame('170.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debt->id)->count());

        foreach ($debt->parcelas->where('id', '!=', $part->id) as $remainingPart) {
            $movement = $user->movimentos()->where('installment_id', $remainingPart->id)->firstOrFail();
            $this->settle($user, $bank, $movement->id, '30.00', '30.00')->assertOk();
        }
        $this->assertSame('paid', $debt->fresh()->status);
        $this->assertSame('0.00', $this->openAmount($debt));
        $this->assertSame('110.00', app(AccountBalanceService::class)->balance($bank->fresh()));
    }

    public function test_partial_payment_preserves_forecast_and_rejects_stale_repeat_without_double_debit(): void
    {
        [$user, $bank, $debt] = $this->setupDebt();
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $this->settle($user, $bank, $forecast->id, '12.00', '30.00')
            ->assertOk()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.amount', '30.00')
            ->assertJsonPath('installment.status', 'partial')->assertJsonPath('installment.paid_amount', '12.00');
        $this->assertSame('30.00', $forecast->fresh()->amount);
        $this->assertSame('partial', $forecast->fresh()->status);
        $this->assertSame('78.00', $this->openAmount($debt));
        $this->assertSame('188.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(-12.0, $this->projectedAmount($user, '2026-11-01', '2026-11-30'));
        $this->assertSame(-18.0, $this->projectedAmount($user, '2026-12-01', '2026-12-31'));
        $this->assertSame(-60.0, $this->projectedAmount($user, '2027-01-01', '2027-02-28'));
        $this->settle($user, $bank, $forecast->id, '12.00', '30.00')->assertUnprocessable();
        $this->settle($user, $bank, $forecast->id, '12.00', null)->assertUnprocessable();
        $this->assertSame('188.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debt->id)->count());
        $this->settle($user, $bank, $forecast->id, '18.00', '18.00')->assertOk()->assertJsonPath('data.id', $forecast->id)->assertJsonPath('installment.status', 'paid');
        $this->assertSame('170.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('60.00', $this->openAmount($debt));
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debt->id)->count());
    }

    public function test_overpayment_and_credit_card_leave_everything_unchanged(): void
    {
        [$user, $bank, $debt] = $this->setupDebt('10.00');
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão local', 'type' => 'credit_card', 'opening_balance' => '0.00', 'opening_balance_date' => '2026-11-01',
            'closing_day' => 25, 'due_day' => 5, 'credit_limit' => '500.00',
        ]);
        $this->settle($user, $bank, $forecast->id, '31.00', '30.00')->assertUnprocessable();
        $this->settle($user, $card, $forecast->id, '30.00', '30.00')->assertUnprocessable();
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('30.00', $forecast->fresh()->amount);
        $this->assertSame('0.00', $part->fresh()->paid_amount);
        $this->assertSame('90.00', $this->openAmount($debt));
        $this->assertSame('10.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debt->id)->count());
    }

    public function test_partial_payment_respects_existing_negative_balance_policy(): void
    {
        [$user, $bank, $debt] = $this->setupDebt('10.00');
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $this->settle($user, $bank, $forecast->id, '20.00', '30.00')->assertOk();
        $this->assertSame('-10.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('30.00', $forecast->fresh()->amount);
        $this->assertSame('70.00', $this->openAmount($debt));
    }

    public function test_editing_commitment_updates_only_pending_forecast_names_and_months(): void
    {
        [$user, $bank, $debt] = $this->setupDebt();
        $parts = $debt->parcelas->sortBy('installment_number')->values();
        $first = $user->movimentos()->where('installment_id', $parts[0]->id)->firstOrFail();
        $second = $user->movimentos()->where('installment_id', $parts[1]->id)->firstOrFail();
        $third = $user->movimentos()->where('installment_id', $parts[2]->id)->firstOrFail();
        $this->settle($user, $bank, $first->id, '30.00', '30.00')->assertOk();
        $this->settle($user, $bank, $second->id, '10.00', '30.00')->assertOk();
        $paidPartial = $second->settlements()->where('status', 'settled')->firstOrFail();

        $this->actingAs($user)->putJson(route('financial.commitments.update', $debt->id), [
            'name' => 'Compra editada', 'due_date' => '2027-03-10',
        ])->assertOk();
        $this->assertSame('Compra · Parcela 1/3', $first->fresh()->description);
        $this->assertSame('2026-11-17', $first->fresh()->movement_date->toDateString());
        $this->assertSame('Liquidação: Compra · Parcela 2/3', $paidPartial->fresh()->description);
        $this->assertSame('2026-11-17', $paidPartial->fresh()->movement_date->toDateString());
        $this->assertSame('Compra editada · Parcela 2/3', $second->fresh()->description);
        $this->assertSame('2027-03-10', $second->fresh()->movement_date->toDateString());
        $this->assertSame('30.00', $second->fresh()->amount);
        $this->assertSame('2027-03-10', $parts[1]->fresh()->due_date->toDateString());
        $this->assertSame('Compra editada · Parcela 3/3', $third->fresh()->description);
        $this->assertSame('2027-04-10', $third->fresh()->movement_date->toDateString());
        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2027-01']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2027-03']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Compra editada · Parcela 2/3');
        $this->assertSame(-20.0, $this->projectedAmount($user, '2027-03-01', '2027-03-31'));
        $this->assertSame(-30.0, $this->projectedAmount($user, '2027-04-01', '2027-04-30'));
    }

    public function test_legacy_installment_without_forecast_projects_only_unpaid_remainder(): void
    {
        [$user, $bank] = $this->setupDebt();
        $debt = Divida::query()->create([
            'user_id' => $user->id, 'name' => 'Legado', 'type' => 'purchase', 'payment_condition' => 'deferred',
            'payment_method' => 'transfer', 'installments_count' => 1, 'total_amount' => '30.00',
            'start_date' => '2026-11-17', 'due_date' => '2026-12-22', 'status' => 'open',
        ]);
        $part = $debt->parcelas()->create([
            'user_id' => $user->id, 'installment_number' => 1, 'due_date' => '2026-12-22',
            'amount' => '30.00', 'principal_amount' => '30.00', 'interest_amount' => '0.00', 'paid_amount' => '0.00', 'status' => 'pending',
        ]);
        $endpoint = route('financial.commitments.installments.pay', ['id' => $debt->id, 'installmentId' => $part->id]);
        $this->actingAs($user)->postJson($endpoint, ['account_id' => $bank->id, 'amount' => '12.00', 'payment_date' => '2026-11-17'])->assertOk();
        $this->assertSame(0, $user->movimentos()->where('installment_id', $part->id)->where('status', 'pending')->count());
        $this->assertSame(-48.0, $this->projectedAmount($user, '2026-12-01', '2026-12-31')); // 30 nova + 18 legado
        $this->postJson($endpoint, ['account_id' => $bank->id, 'amount' => '18.00', 'payment_date' => '2026-11-18'])->assertOk();
        $this->assertSame(-30.0, $this->projectedAmount($user, '2026-12-01', '2026-12-31'));
    }
}

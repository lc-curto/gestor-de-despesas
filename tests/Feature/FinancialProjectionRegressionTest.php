<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Divida;
use App\Models\Movimento;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\FinancialProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialProjectionRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(User $user, string $type, string $balance = '0.00'): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => ucfirst($type), 'type' => $type, 'opening_balance' => $balance,
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '1000.00',
        ]);
    }

    private function project(User $user, string $start = '2026-12-01', string $end = '2026-12-31'): array
    {
        return app(FinancialProjectionService::class)->build($user, Carbon::parse($start), Carbon::parse($end));
    }

    private function adjust(User $user, ContaFinanceira $account, string $direction, string $amount, string $date, bool $historical = false): Movimento
    {
        return app(FinancialMovementService::class)->create($user, [
            'type' => 'adjustment', 'adjustment_direction' => $direction,
            'description' => 'Ajuste '.$direction.' '.$date, 'amount' => $amount, 'movement_date' => $date,
            'source_account_id' => $direction === 'decrease' ? $account->id : null,
            'destination_account_id' => $direction === 'increase' ? $account->id : null,
            'is_historical' => $historical,
        ]);
    }

    public function test_cash_adjustments_before_and_inside_period_match_balances_and_ignore_history_and_cards(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '100.00');
        $cash = $this->account($user, 'cash', '20.00');
        $card = $this->account($user, 'credit_card');
        $service = app(FinancialMovementService::class);

        $this->adjust($user, $bank, 'increase', '50.00', '2026-11-28');
        $this->adjust($user, $cash, 'decrease', '10.00', '2026-11-29');
        $this->adjust($user, $bank, 'increase', '500.00', '2026-11-30', true);
        $increase = $this->adjust($user, $bank, 'increase', '25.00', '2026-12-01');
        $decrease = $this->adjust($user, $cash, 'decrease', '7.00', '2026-12-31');
        $historicalAdjustment = $this->adjust($user, $bank, 'increase', '100.00', '2026-12-02', true);
        $historicalExpense = $service->create($user, [
            'type' => 'expense', 'description' => 'Histórico', 'amount' => '30.00',
            'movement_date' => '2026-12-03', 'source_account_id' => $bank->id, 'is_historical' => true,
        ]);
        $historicalIncome = $service->create($user, [
            'type' => 'income', 'description' => 'Receita histórica', 'amount' => '40.00',
            'movement_date' => '2026-12-04', 'destination_account_id' => $bank->id, 'is_historical' => true,
        ]);
        // Ajustes antigos de cartão podem existir apesar de novos ajustes genéricos serem agora bloqueados.
        foreach ([['increase', '200.00', '2026-12-05'], ['decrease', '50.00', '2026-12-06']] as [$direction, $amount, $date]) {
            $user->movimentos()->create([
                'type' => 'adjustment', 'status' => 'settled', 'adjustment_direction' => $direction,
                'description' => 'Ajuste antigo de cartão', 'amount' => $amount, 'movement_date' => $date,
                'source_account_id' => $direction === 'decrease' ? $card->id : null,
                'destination_account_id' => $direction === 'increase' ? $card->id : null,
                'is_historical' => false, 'settled_at' => now(),
            ]);
        }
        $service->create($user, [
            'type' => 'income', 'description' => 'Entrada', 'amount' => '13.00',
            'movement_date' => '2026-12-07', 'destination_account_id' => $bank->id,
        ]);
        $service->create($user, [
            'type' => 'expense', 'description' => 'Saída', 'amount' => '9.00',
            'movement_date' => '2026-12-08', 'source_account_id' => $bank->id,
        ]);
        $transfer = $service->transfer($user, [
            'description' => 'Entre contas de caixa', 'amount' => '6.00', 'movement_date' => '2026-12-09',
            'source_account_id' => $bank->id, 'destination_account_id' => $cash->id,
        ]);
        $this->adjust($user, $bank, 'increase', '5.00', '2027-01-01');

        $result = $this->project($user);
        $this->assertSame(160.0, $result['openingBalance']);
        $this->assertSame(22.0, $result['movements']->sum('projection_delta'));
        $this->assertSame(25.0, $result['movements']->firstWhere('id', $increase->id)->projection_delta);
        $this->assertSame(-7.0, $result['movements']->firstWhere('id', $decrease->id)->projection_delta);
        $this->assertSame('increase', $result['movements']->firstWhere('id', $increase->id)->adjustment_direction);
        foreach ([$historicalAdjustment, $historicalExpense, $historicalIncome, $transfer] as $excluded) {
            $this->assertNull($result['movements']->firstWhere('id', $excluded->id));
        }
        $this->assertCount(4, $result['movements']);

        $next = $this->project($user, '2027-01-01', '2027-01-31');
        $this->assertSame(182.0, $next['openingBalance']);
        $this->assertSame(5.0, $next['movements']->sum('projection_delta'));
        $balances = app(AccountBalanceService::class);
        $this->assertSame(187.0, (float) $balances->balance($bank) + (float) $balances->balance($cash));
        $this->assertSame(187.0, $next['openingBalance'] + $next['movements']->sum('projection_delta'));
    }

    public function test_void_pending_and_inactive_account_adjustments_do_not_change_projection(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '100.00');
        $inactive = $this->account($user, 'cash', '25.00');
        $inactiveAdjustment = $this->adjust($user, $inactive, 'increase', '40.00', '2026-12-10');
        $inactive->update(['status' => 'closed']);
        $void = $this->adjust($user, $bank, 'decrease', '20.00', '2026-12-11');
        $void->update(['status' => 'void']);
        $pending = $user->movimentos()->create([
            'type' => 'adjustment', 'status' => 'pending', 'description' => 'Ajuste não liquidado',
            'amount' => '15.00', 'movement_date' => '2026-12-12', 'adjustment_direction' => 'increase',
            'destination_account_id' => $bank->id, 'is_historical' => false,
        ]);
        $other = User::factory()->create();
        $otherAccount = $this->account($other, 'bank', '999.00');
        $this->adjust($other, $otherAccount, 'increase', '99.00', '2026-12-12');

        $result = $this->project($user);
        $this->assertSame(100.0, $result['openingBalance']);
        $this->assertCount(0, $result['movements']);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($bank));
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('closed', $inactiveAdjustment->contaDestino->status);
    }

    public function test_reversed_adjustment_cancels_in_the_period_instead_of_disappearing_from_opening_balance(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '100.00');
        $original = $this->adjust($user, $bank, 'increase', '23.45', '2026-11-19');
        Carbon::setTestNow('2026-12-02 12:00:00');
        $reversal = app(FinancialMovementService::class)->reverse($user, $original);

        $result = $this->project($user);
        $this->assertSame(123.45, $result['openingBalance']);
        $this->assertSame(-23.45, $result['movements']->firstWhere('id', $reversal->id)->projection_delta);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($bank));
        $this->assertEqualsWithDelta(100.0, $result['openingBalance'] + $result['movements']->sum('projection_delta'), 0.001);
    }

    public function test_route_day_cash_delta_includes_signed_adjustments_without_counting_them_as_income_or_expense(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '100.00');
        $this->adjust($user, $bank, 'increase', '20.00', '2026-11-30');
        $plus = $this->adjust($user, $bank, 'increase', '7.00', '2026-12-05');
        $minus = $this->adjust($user, $bank, 'decrease', '2.00', '2026-12-05');
        $this->adjust($user, $bank, 'increase', '90.00', '2026-12-05', true);

        $this->withoutMiddleware(\App\Http\Middleware\EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.route', ['mes' => '2026-12', 'modo' => 1]));
        $response->assertOk();
        $this->assertSame(120.0, $response->viewData('openingBalance'));
        $day = $response->viewData('days')['2026-12']['2026-12-05'];
        $this->assertSame(5.0, $day['cash_delta']);
        $this->assertSame(0.0, $day['income']);
        $this->assertSame(0.0, $day['fixed']);
        $this->assertSame(0.0, $day['variable']);
        $this->assertEqualsCanonicalizing([$plus->id, $minus->id], $day['items']->pluck('id')->all());
        $response->assertSee('125,00');
    }

    public function test_partial_legacy_installment_projects_only_remaining_amount_even_with_settled_payment(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '100.00');
        $debt = Divida::query()->create([
            'user_id' => $user->id, 'name' => 'Legado', 'type' => 'purchase',
            'payment_condition' => 'deferred', 'payment_method' => 'transfer', 'installments_count' => 1,
            'total_amount' => '30.00', 'start_date' => '2026-11-01', 'due_date' => '2026-12-10', 'status' => 'open',
        ]);
        $part = $debt->parcelas()->create([
            'user_id' => $user->id, 'installment_number' => 1, 'due_date' => '2026-12-10',
            'amount' => '30.00', 'principal_amount' => '30.00', 'interest_amount' => '0.00',
            'paid_amount' => '0.00', 'status' => 'pending',
        ]);
        $commitments = app(FinancialCommitmentService::class);
        $commitments->payInstallment($user, $debt, $part, [
            'account_id' => $bank->id, 'amount' => '12.00', 'payment_date' => '2026-11-30',
        ]);
        $this->assertSame('partial', $part->fresh()->status);
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $this->assertSame('partial', $forecast->status);
        $this->assertSame('12.00', number_format((float) $forecast->settlements()->where('status', 'settled')->sum('amount'), 2, '.', ''));
        $this->assertSame(0, $user->movimentos()->where('installment_id', $part->id)->where('status', 'pending')->count());

        $result = $this->project($user);
        $this->assertSame(88.0, $result['openingBalance']);
        $this->assertCount(1, $result['movements']);
        $this->assertSame('debt-'.$part->id, $result['movements']->first()->id);
        $this->assertSame(-18.0, $result['movements']->first()->projection_delta);

        $commitments->payInstallment($user, $debt, $part->fresh(), [
            'account_id' => $bank->id, 'amount' => '18.00', 'payment_date' => '2026-12-02',
        ]);
        $paid = $this->project($user);
        $this->assertSame(88.0, $paid['openingBalance']);
        $this->assertCount(1, $paid['movements']);
        $this->assertSame('settled', $paid['movements']->first()->status);
        $this->assertSame(-18.0, $paid['movements']->first()->projection_delta);
        $this->assertSame('70.00', app(AccountBalanceService::class)->balance($bank));
    }

    public function test_partial_installment_with_pending_forecast_counts_payment_and_remainder_once(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '100.00');
        $commitments = app(FinancialCommitmentService::class);
        $debt = $commitments->create($user, [
            'name' => 'Com previsão', 'total_amount' => '30.00', 'start_date' => '2026-11-01',
            'due_date' => '2026-12-10', 'payment_condition' => 'deferred', 'payment_method' => 'transfer',
        ]);
        $part = $debt->parcelas->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->where('status', 'pending')->firstOrFail();
        $commitments->payInstallment($user, $debt, $part, [
            'account_id' => $bank->id, 'amount' => '12.00', 'payment_date' => '2026-12-02',
        ]);

        $result = $this->project($user);
        $this->assertSame(100.0, $result['openingBalance']);
        $this->assertCount(2, $result['movements']);
        $this->assertSame(-30.0, $result['movements']->sum('projection_delta'));
        $effective = $result['movements']->firstWhere('settles_movement_id', $forecast->id);
        $remaining = $result['movements']->firstWhere('id', 'debt-'.$part->id);
        $this->assertNotNull($effective);
        $this->assertNotNull($remaining);
        $this->assertSame(-12.0, $effective->projection_delta);
        $this->assertSame(-18.0, $remaining->projection_delta);
        $this->assertSame('88.00', app(AccountBalanceService::class)->balance($bank));
        $this->assertSame(88.0, $this->project($user, '2026-12-03', '2026-12-31')['openingBalance']);
    }

    public function test_historical_or_void_linked_forecasts_do_not_hide_legacy_remainder(): void
    {
        $user = User::factory()->create();
        $debt = Divida::query()->create([
            'user_id' => $user->id, 'name' => 'Legado histórico', 'type' => 'purchase',
            'payment_condition' => 'deferred', 'payment_method' => 'transfer', 'installments_count' => 1,
            'total_amount' => '30.00', 'start_date' => '2026-11-01', 'due_date' => '2026-12-10', 'status' => 'open',
        ]);
        $part = $debt->parcelas()->create([
            'user_id' => $user->id, 'installment_number' => 1, 'due_date' => '2026-12-10',
            'amount' => '30.00', 'paid_amount' => '12.00', 'status' => 'partial',
        ]);
        foreach ([['pending', true], ['void', false]] as [$status, $historical]) {
            $user->movimentos()->create([
                'type' => 'expense', 'status' => $status, 'description' => 'Previsão desconsiderada',
                'amount' => '18.00', 'movement_date' => '2026-12-10', 'debt_id' => $debt->id,
                'installment_id' => $part->id, 'is_historical' => $historical, 'requires_account_assignment' => true,
            ]);
        }
        $result = $this->project($user);
        $this->assertCount(1, $result['movements']);
        $this->assertSame('debt-'.$part->id, $result['movements']->first()->id);
        $this->assertSame(-18.0, $result['movements']->first()->projection_delta);
    }

    public function test_existing_forecast_outside_period_does_not_create_a_duplicate_fallback(): void
    {
        $user = User::factory()->create();
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Data da previsão alterada', 'total_amount' => '30.00', 'start_date' => '2026-11-01',
            'due_date' => '2026-12-10', 'payment_condition' => 'deferred', 'payment_method' => 'transfer',
        ]);
        $part = $debt->parcelas->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->where('status', 'pending')->firstOrFail();
        $forecast->update(['movement_date' => '2027-01-10']);

        $this->assertCount(0, $this->project($user)['movements']);
        $next = $this->project($user, '2027-01-01', '2027-01-31');
        $this->assertCount(1, $next['movements']);
        $this->assertSame($forecast->id, $next['movements']->first()->id);
        $this->assertSame(-30.0, $next['movements']->sum('projection_delta'));
    }

    public function test_last_day_includes_installments_and_invoices_but_excludes_next_day(): void
    {
        $user = User::factory()->create();
        $card = $this->account($user, 'credit_card');
        $installments = [];
        $invoices = [];
        foreach ([['2026-12-31', '9.00', '27.00', '2026-11-01'], ['2027-01-01', '11.00', '33.00', '2026-12-01']] as [$due, $installmentAmount, $invoiceAmount, $reference]) {
            $debt = Divida::query()->create([
                'user_id' => $user->id, 'name' => 'Legado '.$due, 'type' => 'purchase',
                'payment_condition' => 'deferred', 'payment_method' => 'transfer', 'installments_count' => 1,
                'total_amount' => $installmentAmount, 'start_date' => '2026-11-01', 'due_date' => $due, 'status' => 'open',
            ]);
            $installments[] = $debt->parcelas()->create([
                'user_id' => $user->id, 'installment_number' => 1, 'due_date' => $due,
                'amount' => $installmentAmount, 'paid_amount' => '0.00', 'status' => 'pending',
            ]);
            $invoice = $user->faturas()->create([
                'account_id' => $card->id, 'reference_month' => $reference, 'due_date' => $due, 'status' => 'open',
            ]);
            $invoices[] = $invoice;
            $user->movimentos()->create([
                'type' => 'expense', 'status' => 'settled', 'description' => 'Compra', 'amount' => $invoiceAmount,
                'movement_date' => '2026-11-12', 'source_account_id' => $card->id, 'invoice_id' => $invoice->id,
                'is_historical' => false,
            ]);
        }
        $start = Carbon::parse('2026-12-01');
        $end = Carbon::parse('2026-12-31');
        $december = app(FinancialProjectionService::class)->build($user, $start, $end);
        $this->assertSame('2026-12-31', $end->toDateString());
        $this->assertCount(2, $december['movements']);
        $this->assertEqualsCanonicalizing(['debt-'.$installments[0]->id, 'invoice-'.$invoices[0]->id], $december['movements']->pluck('id')->all());
        $this->assertSame(-36.0, $december['movements']->sum('projection_delta'));

        $january = $this->project($user, '2027-01-01', '2027-01-31');
        $this->assertCount(2, $january['movements']);
        $this->assertEqualsCanonicalizing(['debt-'.$installments[1]->id, 'invoice-'.$invoices[1]->id], $january['movements']->pluck('id')->all());
        $this->assertSame(-44.0, $january['movements']->sum('projection_delta'));
    }

    public function test_invoice_purchase_partial_payments_and_full_payment_are_not_double_counted(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '200.00');
        $card = $this->account($user, 'credit_card');
        $invoices = app(CardInvoiceService::class);
        $purchase = $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra no cartão', 'amount' => '100.00',
            'movement_date' => '2026-11-12',
        ]);
        $invoice = $user->faturas()->firstOrFail();
        $this->assertSame('2026-12-05', $invoice->due_date->toDateString());
        $november = $this->project($user, '2026-11-01', '2026-11-30');
        $this->assertSame(200.0, $november['openingBalance']);
        $this->assertNull($november['movements']->firstWhere('id', $purchase->id));
        $unpaid = $this->project($user);
        $this->assertCount(1, $unpaid['movements']);
        $this->assertSame('invoice-'.$invoice->id, $unpaid['movements']->first()->id);
        $this->assertSame(-100.0, $unpaid['movements']->first()->projection_delta);

        $invoices->pay($user, $invoice, $bank->id, '2026-11-30', '25.00');
        $secondPayment = $invoices->pay($user, $invoice, $bank->id, '2026-12-02', '15.00');
        $partial = $this->project($user);
        $this->assertSame(175.0, $partial['openingBalance']);
        $this->assertCount(3, $partial['movements']);
        $this->assertSame(-15.0, $partial['movements']->firstWhere('id', $secondPayment->id)->projection_delta);
        $this->assertSame(-60.0, $partial['movements']->firstWhere('id', 'invoice-'.$invoice->id)->projection_delta);
        $this->assertSame(100.0, $partial['openingBalance'] + $partial['movements']->sum('projection_delta'));

        $thirdPayment = $invoices->pay($user, $invoice, $bank->id, '2026-12-03', '60.00');
        $paid = $this->project($user);
        $this->assertSame(175.0, $paid['openingBalance']);
        $this->assertCount(3, $paid['movements']);
        $this->assertNull($paid['movements']->firstWhere('id', 'invoice-'.$invoice->id));
        $this->assertSame(-60.0, $paid['movements']->firstWhere('id', $thirdPayment->id)->projection_delta);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($bank));
        $this->assertSame(100.0, $paid['openingBalance'] + $paid['movements']->sum('projection_delta'));
    }

    public function test_historical_invoice_payment_reduces_invoice_remainder_but_is_not_a_new_cash_event(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '200.00');
        $card = $this->account($user, 'credit_card');
        $invoices = app(CardInvoiceService::class);
        $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra no cartão', 'amount' => '100.00',
            'movement_date' => '2026-11-12',
        ]);
        $invoice = $user->faturas()->firstOrFail();
        // Registo de importação anterior à validação atual das transferências de cartão.
        $payment = $user->movimentos()->create([
            'type' => 'transfer', 'status' => 'settled', 'description' => 'Pagamento histórico',
            'amount' => '25.00', 'movement_date' => '2026-12-02',
            'source_account_id' => $bank->id, 'destination_account_id' => $card->id,
            'invoice_id' => $invoice->id, 'is_historical' => true,
        ]);

        $result = $this->project($user);
        $this->assertSame(200.0, $result['openingBalance']);
        $this->assertNull($result['movements']->firstWhere('id', $payment->id));
        $this->assertCount(1, $result['movements']);
        $this->assertSame(-75.0, $result['movements']->firstWhere('id', 'invoice-'.$invoice->id)->projection_delta);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank));
    }
}

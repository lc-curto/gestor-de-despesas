<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CardIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function bank(User $user, string $name = 'Banco', string $balance = '500.00'): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => $name, 'type' => 'bank', 'opening_balance' => $balance,
            'opening_balance_date' => '2026-11-01',
        ]);
    }

    private function card(User $user, string $name = 'Visa', string $debt = '0.00', string $limit = '500.00'): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => $name, 'type' => 'credit_card', 'opening_debt_amount' => $debt,
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => $limit,
        ]);
    }

    private function purchase(User $user, ContaFinanceira $card, string $amount = '100.00'): Movimento
    {
        return app(CardInvoiceService::class)->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => $amount,
            'movement_date' => '2026-11-17',
        ]);
    }

    private function assertRejected(callable $action, string $field, string $messagePart): void
    {
        try {
            $action();
            $this->fail('A operação deveria ter sido rejeitada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertStringContainsString($messagePart, $exception->errors()[$field][0]);
        }
    }

    private function assertBalance(ContaFinanceira $account, string $balance): void
    {
        $this->assertSame($balance, app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_unpaid_invoice_purchase_cannot_be_reversed_but_can_be_deleted_as_audited_correction(): void
    {
        $user = User::factory()->create();
        $card = $this->card($user);
        $purchase = $this->purchase($user, $card);
        $invoice = $user->faturas()->firstOrFail();
        $auditCount = DB::table('historico_movimentos')->count();

        $response = $this->actingAs($user)->postJson('/api/financial/movements/'.$purchase->id.'/reverse');
        $response->assertUnprocessable()->assertJsonValidationErrors('movement');
        $this->assertStringContainsString('fatura', $response->json('errors.movement.0'));
        $this->assertSame($auditCount, DB::table('historico_movimentos')->count());
        $this->assertSame(2, $user->movimentos()->count());
        $this->assertSame('settled', $purchase->fresh()->status);
        $this->assertSame('open', $invoice->fresh()->status);
        $listed = app(CardInvoiceService::class)->listInvoices($user)->items()[0];
        $this->assertEquals(100, $listed->total_amount);
        $this->assertSame('100.00', $listed->remaining_amount);
        $this->assertBalance($card, '-100.00');

        $this->deleteJson('/api/financial/movements/'.$purchase->id)->assertOk();
        $this->assertSame('void', $purchase->fresh()->status);
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $purchase->id, 'action' => 'deleted_as_correction']);
        $this->assertCount(0, app(CardInvoiceService::class)->listInvoices($user)->items());
        $this->assertBalance($card, '0.00');
        $this->deleteJson('/api/financial/movements/'.$purchase->id)->assertUnprocessable();

        $replacement = $this->purchase($user, $card, '25.00');
        $this->assertSame($invoice->id, $replacement->invoice_id);
        $this->assertBalance($card, '-25.00');
    }

    public function test_invoice_purchase_and_partial_or_full_payments_cannot_be_reversed_or_deleted_in_isolation(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $purchase = $this->purchase($user, $card, '100.00');
        $invoice = $user->faturas()->firstOrFail();
        $invoices = app(CardInvoiceService::class);
        $partial = $invoices->pay($user, $invoice, $bank->id, '2026-11-17', '35.25');
        $this->assertSame($invoice->id, $partial->invoice_id);
        $this->assertSame('partial', $invoice->fresh()->status);

        $this->actingAs($user)->postJson('/api/financial/movements/'.$purchase->id.'/reverse')->assertUnprocessable();
        $this->postJson('/api/financial/movements/'.$partial->id.'/reverse')->assertUnprocessable();
        $this->deleteJson('/api/financial/movements/'.$purchase->id)->assertUnprocessable();
        $this->deleteJson('/api/financial/movements/'.$partial->id)->assertUnprocessable();
        $listed = $invoices->listInvoices($user)->items()[0];
        $this->assertEquals(100, $listed->total_amount);
        $this->assertEquals(35.25, $listed->paid_amount);
        $this->assertSame('64.75', $listed->remaining_amount);
        $this->assertSame('partial', $listed->display_status);
        $this->assertBalance($card, '-64.75');
        $this->assertBalance($bank, '464.75');
        $this->assertSame(2, $user->movimentos()->count());

        $this->assertRejected(fn () => $invoices->pay($user, $invoice, $bank->id, '2026-11-17', '64.76'), 'amount', 'pendente');
        $final = $invoices->pay($user, $invoice, $bank->id, '2026-11-17');
        $this->assertSame('64.75', (string) $final->amount);
        $this->assertSame($invoice->id, $final->invoice_id);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->postJson('/api/financial/movements/'.$final->id.'/reverse')->assertUnprocessable();
        $this->postJson('/api/financial/movements/'.$partial->id.'/reverse')->assertUnprocessable();
        $this->deleteJson('/api/financial/movements/'.$final->id)->assertUnprocessable();
        $this->assertRejected(fn () => $invoices->pay($user, $invoice, $bank->id, '2026-11-17'), 'invoice', 'totalmente paga');
        $listed = $invoices->listInvoices($user)->items()[0];
        $this->assertEquals(100, $listed->total_amount);
        $this->assertEquals(100, $listed->paid_amount);
        $this->assertSame('0.00', $listed->remaining_amount);
        $this->assertSame('paid', $listed->display_status);
        $this->assertBalance($bank, '400.00');
        $this->assertBalance($card, '0.00');
        $this->assertSame(4, $user->movimentos()->count());
        $this->assertSame(0, $user->movimentos()->whereNotNull('reversal_of_id')->count());
    }

    public function test_generic_transfers_to_and_from_cards_are_rejected_even_with_forged_payment_metadata(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user, limit: '100.00');
        $otherCard = $this->card($user, 'Outro', limit: '100.00');
        $purchase = $this->purchase($user, $card, '80.00');
        $invoice = $user->faturas()->firstOrFail();
        $this->actingAs($user);

        foreach ([[$bank, $card, '80.00'], [$card, $bank, '1.00'], [$card, $bank, '1000.00'], [$card, $otherCard, '10.00']] as [$source, $destination, $amount]) {
            $response = $this->postJson('/api/financial/movements', [
                'type' => 'transfer', 'description' => 'Transferência genérica', 'amount' => $amount,
                'movement_date' => '2026-11-17', 'source_account_id' => $source->id,
                'destination_account_id' => $destination->id,
                'invoice_id' => $invoice->id, 'opening_debt_card_id' => $card->id,
            ]);
            $response->assertUnprocessable()->assertJsonValidationErrors('type');
            $this->assertStringContainsString('fatura', $response->json('errors.type.0'));
        }

        $movements = app(FinancialMovementService::class);
        foreach ([['invoice_id' => $invoice->id], ['opening_debt_card_id' => $card->id], ['reversal_of_id' => $purchase->id]] as $metadata) {
            $this->assertRejected(fn () => $movements->create($user, array_merge([
                'type' => 'transfer', 'description' => 'Tentativa', 'amount' => '80.00',
                'movement_date' => '2026-11-17', 'source_account_id' => $bank->id,
                'destination_account_id' => $card->id,
            ], $metadata)), 'type', 'cartão');
        }
        $this->assertSame(2, $user->movimentos()->count());
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '-80.00');
        $this->assertBalance($otherCard, '0.00');

        app(CardInvoiceService::class)->pay($user, $invoice, $bank->id, '2026-11-17');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertBalance($bank, '420.00');
        $this->assertBalance($card, '0.00');
        $this->assertRejected(fn () => app(CardInvoiceService::class)->pay($user, $invoice, $bank->id, '2026-11-17'), 'invoice', 'totalmente paga');
    }

    public function test_card_purchase_limit_accounts_for_opening_debt_and_partial_invoice_payment(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user, debt: '30.00', limit: '100.00');
        $this->purchase($user, $card, '60.00');
        $invoice = $user->faturas()->firstOrFail();
        $this->assertRejected(fn () => $this->purchase($user, $card, '10.01'), 'amount', 'limite');
        $this->assertBalance($card, '-90.00');

        app(CardInvoiceService::class)->pay($user, $invoice, $bank->id, '2026-11-17', '20.00');
        $this->assertBalance($card, '-70.00');
        $this->purchase($user, $card, '30.00');
        $this->assertBalance($card, '-100.00');
        $this->assertRejected(fn () => $this->purchase($user, $card, '0.01'), 'amount', 'limite');
        $this->assertSame(4, $user->movimentos()->count());
        $listed = app(CardInvoiceService::class)->listInvoices($user)->items()[0];
        $this->assertEquals(90, $listed->total_amount);
        $this->assertEquals(20, $listed->paid_amount);
        $this->assertSame('70.00', $listed->remaining_amount);
    }

    public function test_opening_debt_delete_is_blocked_and_reversal_restores_counters_balances_and_audit_once(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user, debt: '300.00');
        $invoices = app(CardInvoiceService::class);
        $payment = $invoices->payOpeningDebt($user, $card->id, $bank->id, '2026-11-17', '100.00');
        $this->assertSame($card->id, $payment->opening_debt_card_id);
        $this->assertSame('100.00', (string) $card->fresh()->opening_debt_paid);

        $response = $this->actingAs($user)->deleteJson('/api/financial/movements/'.$payment->id);
        $response->assertUnprocessable()->assertJsonValidationErrors('movement');
        $this->assertStringContainsString('estorno', $response->json('errors.movement.0'));
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertSame('100.00', (string) $card->fresh()->opening_debt_paid);
        $this->assertBalance($bank, '400.00');
        $this->assertBalance($card, '-200.00');
        $this->assertRejected(fn () => $invoices->payOpeningDebt($user, $card->id, $bank->id, '2026-11-17', '200.01'), 'amount', 'dívida inicial');
        $this->assertRejected(fn () => $invoices->payOpeningDebt($user, $card->id, $card->id, '2026-11-17', '1.00'), 'account_id', 'bancária');
        $this->assertSame('100.00', (string) $card->fresh()->opening_debt_paid);

        $reversal = app(FinancialMovementService::class)->reverse($user, $payment, 'Correção');
        $this->assertSame($payment->id, $reversal->reversal_of_id);
        $this->assertSame($card->id, $reversal->source_account_id);
        $this->assertSame('0.00', (string) $card->fresh()->opening_debt_paid);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '-300.00');
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $payment->id, 'action' => 'reversed', 'reason' => 'Correção']);
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $reversal->id, 'action' => 'created']);
        $this->assertRejected(fn () => app(FinancialMovementService::class)->reverse($user, $payment), 'movement', 'já foi estornado');
        $this->deleteJson('/api/financial/movements/'.$payment->id)->assertUnprocessable();
        $this->deleteJson('/api/financial/movements/'.$reversal->id)->assertUnprocessable();

        $invoices->payOpeningDebt($user, $card->id, $bank->id, '2026-11-17', '300.00');
        $this->assertSame('300.00', (string) $card->fresh()->opening_debt_paid);
        $this->assertBalance($card, '0.00');
        $this->assertBalance($bank, '200.00');
        $this->assertRejected(fn () => $invoices->payOpeningDebt($user, $card->id, $bank->id, '2026-11-17', '0.01'), 'amount', 'dívida inicial');
    }

    public function test_legacy_opening_debt_payment_is_protected_and_renaming_ambiguous_payment_requires_reconciliation(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user, debt: '200.00');
        $payment = app(CardInvoiceService::class)->payOpeningDebt($user, $card->id, $bank->id, '2026-11-17', '50.00');
        DB::table('movimentos')->where('id', $payment->id)->update(['opening_debt_card_id' => null]);
        $payment->refresh();
        $this->assertRejected(fn () => app(FinancialMovementService::class)->deleteAsCorrection($user, $payment), 'movement', 'estorno');
        app(FinancialAccountService::class)->rename($user, $card, 'Novo nome');
        $this->assertRejected(fn () => app(FinancialMovementService::class)->deleteAsCorrection($user, $payment), 'movement', 'estorno');
        $this->assertRejected(fn () => app(FinancialMovementService::class)->reverse($user, $payment), 'movement', 'reconciliação');
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertSame('50.00', (string) $card->fresh()->opening_debt_paid);
        $this->assertBalance($bank, '450.00');
        $this->assertBalance($card, '-150.00');
        $this->assertSame(1, $user->movimentos()->count());
    }

    public function test_invoice_with_existing_legacy_reversal_is_not_used_to_calculate_another_payment(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $purchase = $this->purchase($user, $card);
        $invoice = $user->faturas()->firstOrFail();
        // Reproduz o formato criado pela versão antiga: receita sem invoice_id.
        $legacyReversal = $user->movimentos()->create([
            'type' => 'income', 'status' => 'settled', 'description' => 'Estorno antigo',
            'amount' => '100.00', 'movement_date' => '2026-11-17',
            'destination_account_id' => $card->id, 'reversal_of_id' => $purchase->id,
            'is_historical' => false,
        ]);
        $this->assertNull($legacyReversal->invoice_id);
        $this->assertBalance($card, '0.00');
        $this->assertRejected(fn () => app(CardInvoiceService::class)->pay($user, $invoice, $bank->id, '2026-11-17'), 'invoice', 'reconciliação');
        $this->assertRejected(fn () => $this->purchase($user, $card, '1.00'), 'invoice', 'reconciliação');
        $pending = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense', 'description' => 'Previsão', 'amount' => '1.00', 'movement_date' => '2026-11-17',
        ]);
        $this->assertRejected(fn () => app(CardInvoiceService::class)->settlePendingPurchase($user, $pending, $card->id), 'invoice', 'reconciliação');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(3, $user->movimentos()->count());
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '0.00');
    }

    public function test_invoice_with_old_payment_reversal_requires_reconciliation_before_further_payment(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $this->purchase($user, $card);
        $invoice = $user->faturas()->firstOrFail();
        $payment = app(CardInvoiceService::class)->pay($user, $invoice, $bank->id, '2026-11-17', '30.00');
        $user->movimentos()->create([
            'type' => 'transfer', 'status' => 'settled', 'description' => 'Estorno antigo',
            'amount' => '30.00', 'movement_date' => '2026-11-17',
            'source_account_id' => $card->id, 'destination_account_id' => $bank->id,
            'reversal_of_id' => $payment->id, 'is_historical' => false,
        ]);
        $this->assertRejected(fn () => app(CardInvoiceService::class)->pay($user, $invoice, $bank->id, '2026-11-17'), 'invoice', 'reconciliação');
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame(4, $user->movimentos()->count());
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '-100.00');
    }

    public function test_pending_legacy_card_transfer_cannot_bypass_generic_transfer_guard_by_settlement(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $pending = $user->movimentos()->create([
            'type' => 'transfer', 'status' => 'pending', 'description' => 'Transferência antiga pendente',
            'amount' => '40.00', 'movement_date' => '2026-11-17',
            'source_account_id' => $bank->id, 'destination_account_id' => $card->id,
            'is_historical' => false,
        ]);
        $this->assertRejected(fn () => app(FinancialMovementService::class)->settle($user, $pending, []), 'type', 'cartão');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '0.00');
        $this->assertSame(0, DB::table('historico_movimentos')->count());
    }

    public function test_pending_card_purchase_reuses_cycle_and_respects_limit_and_one_time_settlement(): void
    {
        $user = User::factory()->create();
        $card = $this->card($user, limit: '100.00');
        $first = $this->purchase($user, $card, '60.00');
        $service = app(FinancialMovementService::class);
        $pending = $service->createPending($user, [
            'type' => 'expense', 'description' => 'Previsão', 'amount' => '40.00',
            'movement_date' => '2026-11-17',
        ]);
        $settled = app(CardInvoiceService::class)->settlePendingPurchase($user, $pending, $card->id);
        $this->assertSame($first->invoice_id, $settled->invoice_id);
        $this->assertSame('settled', $settled->status);
        $this->assertBalance($card, '-100.00');
        $this->assertRejected(fn () => app(CardInvoiceService::class)->settlePendingPurchase($user, $pending, $card->id), 'movement', 'pendentes');
        $tooMuch = $service->createPending($user, [
            'type' => 'expense', 'description' => 'Acima do limite', 'amount' => '0.01',
            'movement_date' => '2026-11-17',
        ]);
        $this->assertRejected(fn () => app(CardInvoiceService::class)->settlePendingPurchase($user, $tooMuch, $card->id), 'amount', 'limite');
        $this->assertSame('pending', $tooMuch->fresh()->status);
        $this->assertSame(1, $user->faturas()->count());
        $this->assertBalance($card, '-100.00');
    }

    public function test_non_card_transfers_and_reversals_including_old_generic_card_transfer_still_work(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $other = $this->bank($user, 'Poupança', '0.00');
        $card = $this->card($user);
        $service = app(FinancialMovementService::class);
        $transfer = $service->transfer($user, [
            'description' => 'Poupança', 'amount' => '40.00', 'movement_date' => '2026-11-17',
            'source_account_id' => $bank->id, 'destination_account_id' => $other->id,
        ]);
        $service->reverse($user, $transfer);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($other, '0.00');
        $this->assertRejected(fn () => $service->reverse($user, $transfer), 'movement', 'já foi estornado');

        $expense = $service->create($user, [
            'type' => 'expense', 'description' => 'Despesa corrente', 'amount' => '12.50',
            'movement_date' => '2026-11-17', 'source_account_id' => $bank->id,
        ]);
        $expenseReversal = $service->reverse($user, $expense);
        $this->assertSame('income', $expenseReversal->type);
        $this->assertBalance($bank, '500.00');

        // Um lançamento anterior à proteção pode ser estornado sem passar pela
        // entrada genérica nem ser confundido com pagamento de dívida inicial.
        $legacy = $user->movimentos()->create([
            'type' => 'transfer', 'status' => 'settled', 'description' => 'Transferência antiga',
            'amount' => '25.00', 'movement_date' => '2026-11-17',
            'source_account_id' => $bank->id, 'destination_account_id' => $card->id,
            'is_historical' => false,
        ]);
        $this->assertBalance($bank, '475.00');
        $this->assertBalance($card, '25.00');
        $reverse = $service->reverse($user, $legacy);
        $this->assertSame($legacy->id, $reverse->reversal_of_id);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '0.00');
    }
}

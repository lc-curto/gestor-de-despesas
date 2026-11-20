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

    public function test_unpaid_invoice_purchase_can_be_deleted_as_an_audited_correction(): void
    {
        $user = User::factory()->create();
        $card = $this->card($user);
        $purchase = $this->purchase($user, $card);
        $invoice = $user->faturas()->firstOrFail();
        $invoice->update(['status' => 'closed']);

        // A fatura fechada continua a ser uma projeção: sem pagamentos ou saldo transportado,
        // eliminar a compra recalcula o período e retira a fatura vazia.
        $this->actingAs($user)->deleteJson('/api/financial/movements/'.$purchase->id)->assertOk();
        $this->assertSame('void', $purchase->fresh()->status);
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $purchase->id, 'action' => 'deleted']);
        $this->assertCount(0, app(CardInvoiceService::class)->listInvoices($user)->items());
        $this->assertSoftDeleted('faturas', ['id' => $invoice->id]);
        $this->assertBalance($card, '0.00');

        // A repetição é idempotente e não volta a alterar saldos.
        $this->deleteJson('/api/financial/movements/'.$purchase->id)->assertOk();
        $replacement = $this->purchase($user, $card, '25.00');
        $this->assertSame($invoice->id, $replacement->invoice_id);
        $this->assertDatabaseHas('faturas', ['id' => $invoice->id, 'deleted_at' => null]);
        $this->assertBalance($card, '-25.00');
    }

    public function test_invoice_purchase_and_realized_payment_events_cannot_be_deleted_directly(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user, limit: '500.00');
        $purchase = $this->purchase($user, $card, '100.00');
        $invoice = $user->faturas()->firstOrFail();
        $invoices = app(CardInvoiceService::class);
        $partial = $invoices->pay($user, $invoice, $bank->id, '2026-11-17', '35.25');

        $this->actingAs($user)->deleteJson('/api/financial/movements/'.$purchase->id)->assertUnprocessable();
        $this->deleteJson('/api/financial/movements/'.$partial->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movement');
        $this->assertSame('settled', $partial->fresh()->status);

        $listed = $invoices->listInvoices($user)->items()[0];
        $this->assertEquals(100, $listed->total_amount);
        $this->assertEquals(35.25, $listed->paid_amount);
        $this->assertSame('64.75', $listed->remaining_amount);
        $this->assertSame('partial', $listed->payment_status);
        $this->assertBalance($card, '-64.75');
        $this->assertBalance($bank, '464.75');

        $this->assertRejected(fn () => $invoices->pay($user, $invoice, $bank->id, '2026-11-17', '100.01'), 'amount', 'pendente');
        $final = $invoices->pay($user, $invoice, $bank->id, '2026-11-17');
        $this->assertSame('64.75', (string) $final->amount);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->deleteJson('/api/financial/movements/'.$final->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movement');
        $this->assertSame('settled', $final->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->assertRejected(fn () => $invoices->pay($user, $invoice, $bank->id, '2026-11-17'), 'invoice', 'totalmente paga');
        $listed = $invoices->listInvoices($user)->items()[0];
        $this->assertEquals(100, $listed->total_amount);
        $this->assertEquals(100, $listed->paid_amount);
        $this->assertSame('0.00', $listed->remaining_amount);
        $this->assertSame('paid', $listed->display_status);
        $this->assertBalance($bank, '400.00');
        $this->assertBalance($card, '0.00');
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

    public function test_identified_opening_debt_payment_can_be_deleted_and_counters_are_recalculated(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user, debt: '300.00');
        $invoices = app(CardInvoiceService::class);
        $payment = $invoices->payOpeningDebt($user, $card->id, $bank->id, '2026-11-17', '100.00');
        $this->assertSame($card->id, $payment->opening_debt_card_id);
        $this->assertSame('100.00', (string) $card->fresh()->opening_debt_paid);

        $this->actingAs($user)->deleteJson('/api/financial/movements/'.$payment->id)->assertOk();
        $this->assertSame('void', $payment->fresh()->status);
        $this->assertSame('0.00', (string) $card->fresh()->opening_debt_paid);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '-300.00');
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $payment->id, 'action' => 'deleted']);
        $this->assertSame(0, $user->movimentos()->whereNotNull('reversal_of_id')->count());

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
        $this->assertRejected(fn () => app(FinancialMovementService::class)->deleteAsCorrection($user, $payment), 'movement', 'Reconcilia');
        app(FinancialAccountService::class)->rename($user, $card, 'Novo nome');
        $this->assertRejected(fn () => app(FinancialMovementService::class)->deleteAsCorrection($user, $payment), 'movement', 'Reconcilia');
        $this->assertRejected(fn () => app(FinancialMovementService::class)->deleteAsCorrection($user, $payment), 'movement', 'Reconcilia');
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
        $this->assertSame(4, $user->movimentos()->count());
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


    public function test_unused_card_is_deleted_with_audit_and_card_with_void_history_is_protected(): void
    {
        $user = User::factory()->create();
        $unused = $this->card($user, 'Cartão sem uso');
        $this->actingAs($user)->deleteJson(route('financial.cards.destroy', $unused->id))->assertOk();
        $this->assertDatabaseMissing('contas_financeiras', ['id' => $unused->id]);
        $this->assertDatabaseHas('deletion_operations', [
            'user_id' => $user->id, 'root_entity_type' => 'card', 'root_entity_id' => $unused->id, 'status' => 'completed',
        ]);
        $this->assertDatabaseHas('deletion_operation_items', [
            'user_id' => $user->id, 'entity_type' => 'ContaFinanceira', 'entity_id' => $unused->id, 'action' => 'hard_deleted',
        ]);

        $used = $this->card($user, 'Cartão com histórico');
        $purchase = $this->purchase($user, $used, '15.00');
        app(FinancialMovementService::class)->deleteAsCorrection($user, $purchase);
        $this->assertSame('void', $purchase->fresh()->status);

        $this->deleteJson(route('financial.cards.destroy', $used->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card');
        $this->assertDatabaseHas('contas_financeiras', ['id' => $used->id]);
    }

    public function test_deleting_the_same_movement_twice_is_idempotent_and_does_not_duplicate_audit_operations(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $movement = app(FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Despesa eliminável', 'amount' => '12.00',
            'movement_date' => '2026-11-17', 'source_account_id' => $bank->id,
        ]);
        $service = app(FinancialMovementService::class);

        $service->deleteAsCorrection($user, $movement);
        $operationsAfterFirstDelete = DB::table('deletion_operations')
            ->where('user_id', $user->id)->where('root_entity_type', 'movement')->where('root_entity_id', $movement->id)->count();
        $itemsAfterFirstDelete = DB::table('deletion_operation_items')
            ->where('user_id', $user->id)->count();

        $service->deleteAsCorrection($user, $movement->fresh());

        $this->assertSame('void', $movement->fresh()->status);
        $this->assertSame(1, $operationsAfterFirstDelete);
        $this->assertSame($operationsAfterFirstDelete, DB::table('deletion_operations')
            ->where('user_id', $user->id)->where('root_entity_type', 'movement')->where('root_entity_id', $movement->id)->count());
        $this->assertSame($itemsAfterFirstDelete, DB::table('deletion_operation_items')
            ->where('user_id', $user->id)->count());
        $this->assertBalance($bank, '500.00');
    }

    public function test_settled_transfers_and_expenses_can_be_deleted_without_inverse_movements(): void
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
        $service->deleteAsCorrection($user, $transfer);
        $this->assertSame('void', $transfer->fresh()->status);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($other, '0.00');

        $expense = $service->create($user, [
            'type' => 'expense', 'description' => 'Despesa corrente', 'amount' => '12.50',
            'movement_date' => '2026-11-17', 'source_account_id' => $bank->id,
        ]);
        $service->deleteAsCorrection($user, $expense);
        $this->assertSame('void', $expense->fresh()->status);
        $this->assertBalance($bank, '500.00');

        // Movimento legado de transferência para cartão: a exclusão retira ambas as pernas.
        $legacy = $user->movimentos()->create([
            'type' => 'transfer', 'status' => 'settled', 'description' => 'Transferência antiga',
            'amount' => '25.00', 'movement_date' => '2026-11-17',
            'source_account_id' => $bank->id, 'destination_account_id' => $card->id,
            'is_historical' => false,
        ]);
        $this->assertBalance($bank, '475.00');
        $this->assertBalance($card, '25.00');
        $service->deleteAsCorrection($user, $legacy);
        $this->assertSame('void', $legacy->fresh()->status);
        $this->assertBalance($bank, '500.00');
        $this->assertBalance($card, '0.00');
        $this->assertSame(0, $user->movimentos()->whereNotNull('reversal_of_id')->count());
    }
    public function test_card_navigation_is_scoped_and_invoice_details_have_contextual_back_links(): void
    {
        $user = User::factory()->create();
        $card = $this->card($user, 'Cartão de Crédito Pessoal');
        $this->purchase($user, $card, '120.00');
        $invoice = $user->faturas()->firstOrFail();
        $this->actingAs($user);

        $cardsPage = $this->get(route('financial.cards.page'));
        $cardsPage->assertOk()
            ->assertSee(route('financial.cards.show', $card->id), false)
            ->assertSee('Disponível')
            ->assertSee('Limite total')
            ->assertSee('Fecho')
            ->assertSee('Vencimento')
            ->assertSee('Dia')
            ->assertDontSee('cardDetails-', false)
            ->assertDontSee(route('financial.accounts.show', $card->id), false);

        $cardPage = $this->get(route('financial.cards.show', $card->id));
        $cardPage->assertOk()
            ->assertSee('← Cartões')
            ->assertSee('Faturas')
            ->assertSee(route('financial.invoices.show', $invoice->id), false)
            ->assertSee('Aberta')
            ->assertSee('120,00');

        $invoiceList = $this->get(route('financial.invoices.page', ['account_id' => $card->id]));
        $invoiceList->assertOk()
            ->assertSee('← '.$card->name)
            ->assertSee('Faturas')
            ->assertSee(route('financial.invoices.show', $invoice->id), false)
            ->assertSee('novembro 2026')
            ->assertSee('Fecho:')
            ->assertSee('Vencimento:');

        $invoiceDetail = $this->get(route('financial.invoices.show', $invoice->id));
        $invoiceDetail->assertOk()
            ->assertSee('← Cartão')
            ->assertSee(route('financial.cards.show', $card->id), false)
            ->assertSee('Fatura de novembro 2026')
            ->assertSee('Aberta')
            ->assertSee('Total')
            ->assertSee('Pago')
            ->assertSee('Em falta')
            ->assertSee('Compras')
            ->assertSee('Compra')
            ->assertSee('Pagamentos')
            ->assertSee('Ainda não existem pagamentos.')
            ->assertSee('Pagar fatura')
            ->assertSee('Encargo')
            ->assertDontSee('data-action="close"', false)
            ->assertDontSee('Fechar fatura')
            ->assertDontSee('O banco informou a decomposição')
            ->assertDontSee('allocationEnabled')
            ->assertDontSee('Por pagar');
    }

    public function test_invoice_detail_route_does_not_expose_another_users_invoice(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = $this->card($owner, 'Cartão privado');
        $purchase = $this->purchase($owner, $card);
        $this->actingAs($other);

        $this->get(route('financial.invoices.show', $purchase->invoice_id))->assertNotFound();
        $this->get(route('financial.cards.show', $card->id))->assertNotFound();
    }

}

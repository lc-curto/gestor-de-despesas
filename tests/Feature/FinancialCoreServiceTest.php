<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\FaturaSaldoTransportado;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\FinancialProjectionService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialCoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

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

    public function test_insufficient_balance_feedback_includes_the_missing_amount_and_next_action(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = $this->account($user, 'Conta à ordem', '10.00', false);

        try {
            app(AccountBalanceService::class)->assertCanDebit($account, '11.00');
            $this->fail('O débito deveria ser rejeitado.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['amount'][0];
            $this->assertStringContainsString('Faltam 1,00 EUR', $message);
            $this->assertStringContainsString('Ativa a permissão de saldo negativo', $message);
        }
    }

    public function test_card_purchase_creates_invoice_and_invoice_payment_is_a_transfer(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '100.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);

        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Supermercado', 'amount' => '47.25',
            'movement_date' => '2026-11-12',
        ])->assertCreated()->assertJsonPath('data.source_account_id', $card->id);
        $this->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra acima do limite', 'amount' => '500.00',
            'movement_date' => '2026-11-12',
        ])->assertUnprocessable();

        $invoice = $user->faturas()->firstOrFail();
        $this->assertSame('2026-11-01', $invoice->reference_month->toDateString());
        $this->assertSame('2026-12-05', $invoice->due_date->toDateString());
        $this->assertSame('-47.25', app(AccountBalanceService::class)->balance($card));
        $invoiceResponse = $this->getJson('/api/financial/invoices')->assertOk();
        $this->assertEquals(47.25, (float) $invoiceResponse->json('data.data.0.total_amount'));

        $paymentResponse = $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'payment_date' => '2026-12-01',
        ])->assertOk()->assertJsonPath('data.type', 'transfer');

        $invoiceParent = $invoice->movimentos()->where('is_settlement_parent', true)->firstOrFail();
        $this->assertSame($invoiceParent->id, $paymentResponse->json('data.settles_movement_id'));
        $this->assertSame('47.25', (string) $invoiceParent->amount);
        $this->assertSame('settled', $invoiceParent->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $invoiceParent = $invoice->movimentos()->where('is_settlement_parent', true)->firstOrFail();
        $this->assertSame('settled', $invoiceParent->status);
        $this->assertSame(1, $invoiceParent->settlements()->where('type', 'transfer')->where('status', 'settled')->count());
        $this->assertSame('47.25', number_format((float) $invoiceParent->settlements()->sum('amount'), 2, '.', ''));
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card));
        $this->assertSame('52.75', app(AccountBalanceService::class)->balance($bank));
        $this->assertDatabaseCount('despesas', 0);
    }

    public function test_card_invoice_supports_multiple_partial_payments_without_creating_expenses(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '100.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);

        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => '60.00',
            'movement_date' => '2026-11-12',
        ])->assertCreated();

        $invoice = $user->faturas()->firstOrFail();
        $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'amount' => '25.00', 'payment_date' => '2026-12-01',
        ])->assertOk()->assertJsonPath('data.type', 'transfer');

        $invoiceData = $this->getJson('/api/financial/invoices')->assertOk()->json('data.data.0');
        $this->assertSame(25.0, (float) $invoiceData['paid_amount']);
        $this->assertSame(35.0, (float) $invoiceData['remaining_amount']);
        $this->assertSame('partial', $invoiceData['display_status']);

        $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'amount' => '35.00', 'payment_date' => '2026-12-02',
        ])->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $invoiceParent = $invoice->movimentos()->where('is_settlement_parent', true)->firstOrFail();
        $this->assertSame(2, $invoiceParent->settlements()->where('type', 'transfer')->where('status', 'settled')->count());
        $this->assertSame('60.00', number_format((float) $invoiceParent->settlements()->sum('amount'), 2, '.', ''));
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card));
        $this->assertSame('40.00', app(AccountBalanceService::class)->balance($bank));
        $this->assertDatabaseCount('despesas', 0);
        $this->assertDatabaseHas('movimentos', [
            'invoice_id' => $invoice->id,
            'type' => 'transfer',
            'amount' => '25.00',
        ]);
    }

    public function test_card_invoice_payment_respects_source_account_overdraft_permission(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '10.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_debt_amount' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);

        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => '60.00',
            'movement_date' => '2026-11-12',
        ])->assertCreated();

        $invoice = $user->faturas()->firstOrFail();
        $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'amount' => '60.00', 'payment_date' => '2026-12-01',
        ])->assertUnprocessable();

        $this->assertSame('10.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-60.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->movimentos()->where('type', 'transfer')->count());
    }

    public function test_installment_commitment_splits_cents_and_supports_partial_payment(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '100.00');
        $response = $this->actingAs($user)->postJson('/api/financial/commitments', [
            'name' => 'Computador', 'total_amount' => '100.00', 'start_date' => '2026-11-01',
            'due_date' => '2026-11-15', 'payment_condition' => 'installments', 'installments_count' => 3,
            'paid_now' => '0',
        ])->assertCreated();
        $debtId = $response->json('data.id');
        $installments = $response->json('data.parcelas');
        $this->assertSame(['33.34', '33.33', '33.33'], array_column($installments, 'amount'));
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debtId)->where('status', 'pending')->count());

        $this->postJson('/api/financial/commitments/'.$debtId.'/installments/'.$installments[0]['id'].'/pay', [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-11-10',
        ])->assertOk()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.paid_amount', '10.00');
        $secondBank = $this->account($user, 'Conta secundária', '100.00');
        $this->postJson('/api/financial/commitments/'.$debtId.'/installments/'.$installments[0]['id'].'/pay', [
            'account_id' => $secondBank->id, 'payment_date' => '2026-11-11',
        ])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('90.00', app(AccountBalanceService::class)->balance($bank));
        $this->assertSame('76.66', app(AccountBalanceService::class)->balance($secondBank));
        $this->assertSame(0, $user->movimentos()->where('installment_id', $installments[0]['id'])->where('status', 'pending')->count());
        $forecast = $user->movimentos()->where('installment_id', $installments[0]['id'])->firstOrFail();
        $this->assertSame('33.34', (string) $forecast->amount);
        $this->assertSame('settled', $forecast->fresh()->status);
        $this->assertTrue((bool) $forecast->fresh()->is_settlement_parent);
        $this->assertSame('33.34', number_format((float) $forecast->settlements()->where('status', 'settled')->sum('amount'), 2, '.', ''));
        $this->assertSame('33.34', (string) $installments[0]['amount']);
        $events = $forecast->settlements()->where('status', 'settled')->orderBy('movement_date')->get();
        $this->assertCount(2, $events);
        $this->assertSame($bank->id, $events[0]->source_account_id);
        $this->assertSame($secondBank->id, $events[1]->source_account_id);
        $this->assertSame('2026-11-10', $events[0]->movement_date->toDateString());
        $this->assertSame('2026-11-11', $events[1]->movement_date->toDateString());
    }

    public function test_commitment_supports_weekly_and_biweekly_installment_schedules(): void
    {
        $user = User::factory()->create();

        $weekly = $this->actingAs($user)->postJson('/api/financial/commitments', [
            'name' => 'Compra semanal',
            'total_amount' => '30.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-05',
            'payment_condition' => 'installments',
            'installments_count' => 3,
            'installment_frequency' => 'weekly',
            'paid_now' => '0',
        ])->assertCreated()->json('data.parcelas');

        $this->assertSame(
            ['2026-11-05', '2026-11-12', '2026-11-19'],
            array_map(fn ($item) => substr($item['due_date'], 0, 10), $weekly)
        );

        $biweekly = $this->postJson('/api/financial/commitments', [
            'name' => 'Compra quinzenal',
            'total_amount' => '30.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-05',
            'payment_condition' => 'installments',
            'installments_count' => 3,
            'installment_frequency' => 'biweekly',
            'paid_now' => '0',
        ])->assertCreated()->json('data.parcelas');

        $this->assertSame(
            ['2026-11-05', '2026-11-19', '2026-12-03'],
            array_map(fn ($item) => substr($item['due_date'], 0, 10), $biweekly)
        );
    }

    public function test_deferred_commitment_has_one_unpaid_installment(): void
    {
        $user = User::factory()->create();

        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Fatura de eletricidade',
            'total_amount' => '90.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);

        $this->assertSame('deferred', $debt->payment_condition);
        $this->assertCount(1, $debt->parcelas);
        $this->assertSame('90.00', (string) $debt->parcelas->first()->amount);
        $this->assertSame('pending', $debt->parcelas->first()->status);
        $this->assertDatabaseCount('movimentos', 1);
        $this->assertDatabaseHas('movimentos', [
            'debt_id' => $debt->id,
            'installment_id' => $debt->parcelas->first()->id,
            'description' => 'Fatura de eletricidade · A prazo',
        ]);
    }

    public function test_installment_cannot_be_settled_using_a_credit_card(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $debt = $this->actingAs($user)->postJson('/api/financial/commitments', [
            'name' => 'Móvel', 'total_amount' => '100.00', 'start_date' => '2026-11-01',
            'due_date' => '2026-11-15', 'payment_condition' => 'installments', 'installments_count' => 2,
            'paid_now' => '0',
        ])->assertCreated()->json('data');

        $this->postJson('/api/financial/commitments/'.$debt['id'].'/installments/'.$debt['parcelas'][0]['id'].'/pay', [
            'account_id' => $card->id, 'payment_date' => '2026-11-12',
        ])->assertUnprocessable();

        $this->assertSame('pending', $user->parcelasDivida()->whereKey($debt['parcelas'][0]['id'])->value('status'));
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card));
        $this->assertDatabaseCount('faturas', 0);
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

    public function test_expense_can_overdraw_account_when_permission_is_enabled(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta', '10.00', true);

        app(FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Despesa acima do saldo', 'amount' => '11.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $this->assertSame('-1.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_expense_cannot_overdraw_account_when_permission_is_disabled(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta', '10.00');

        try {
            app(FinancialMovementService::class)->create($user, [
                'type' => 'expense', 'description' => 'Despesa acima do saldo', 'amount' => '11.00',
                'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
            ]);
            $this->fail('A despesa devia ter sido rejeitada por saldo insuficiente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame('10.00', app(AccountBalanceService::class)->balance($account->fresh()));
        $this->assertDatabaseCount('historico_movimentos', 0);
    }

    public function test_transfer_and_decrease_adjustment_respect_negative_balance_permission(): void
    {
        $user = User::factory()->create();
        $source = $this->account($user, 'Origem', '10.00');
        $destination = $this->account($user, 'Destino', '0.00');
        $service = app(FinancialMovementService::class);

        foreach ([
            fn () => $service->transfer($user, [
                'description' => 'Transferência acima do saldo', 'amount' => '11.00',
                'movement_date' => '2026-11-12', 'source_account_id' => $source->id,
                'destination_account_id' => $destination->id,
            ]),
            fn () => $service->create($user, [
                'type' => 'adjustment', 'adjustment_direction' => 'decrease',
                'description' => 'Ajuste acima do saldo', 'amount' => '11.00',
                'movement_date' => '2026-11-12', 'source_account_id' => $source->id,
            ]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A operação devia ter sido rejeitada por saldo insuficiente.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }

        $balances = app(AccountBalanceService::class);
        $this->assertSame('10.00', $balances->balance($source->fresh()));
        $this->assertSame('0.00', $balances->balance($destination->fresh()));
        $this->assertDatabaseCount('historico_movimentos', 0);

        $permittedSource = $this->account($user, 'Conta com descoberto', '10.00', true);
        $service->transfer($user, [
            'description' => 'Transferência autorizada', 'amount' => '11.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $permittedSource->id,
            'destination_account_id' => $destination->id,
        ]);
        $this->assertSame('-1.00', $balances->balance($permittedSource->fresh()));
        $this->assertSame('11.00', $balances->balance($destination->fresh()));
    }

    public function test_deleting_settled_income_cannot_overdraw_account_without_permission(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta', '0.00');
        $service = app(FinancialMovementService::class);
        $income = $service->create($user, [
            'type' => 'income', 'description' => 'Entrada', 'amount' => '10.00',
            'movement_date' => '2026-11-12', 'destination_account_id' => $account->id,
        ]);
        $service->create($user, [
            'type' => 'expense', 'description' => 'Saída', 'amount' => '5.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        try {
            $service->deleteAsCorrection($user, $income, 'Correção de entrada');
            $this->fail('A correção não deve deixar a conta negativa sem autorização.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame('5.00', app(AccountBalanceService::class)->balance($account->fresh()));
        $this->assertSame('settled', $income->fresh()->status);

        app(FinancialAccountService::class)->setNegativeBalancePermission($user, $account, true);
        $service->deleteAsCorrection($user, $income, 'Correção autorizada');
        $this->assertSame('-5.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_imported_expense_cannot_be_assigned_to_a_credit_card(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_debt_amount' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $legacy = $user->movimentos()->create([
            'type' => 'expense', 'status' => 'settled', 'description' => 'Despesa importada',
            'amount' => '25.00', 'movement_date' => '2026-11-12',
            'legacy_type' => 'despesa', 'legacy_id' => 'legacy-expense-1',
            'is_historical' => false, 'requires_account_assignment' => true,
        ]);

        try {
            app(FinancialMovementService::class)->create($user, [
                'type' => 'expense', 'description' => 'Compra direta inválida', 'amount' => '5.00',
                'movement_date' => '2026-11-12', 'source_account_id' => $card->id,
            ]);
            $this->fail('Uma compra no cartão não pode contornar o serviço de faturação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        try {
            app(FinancialMovementService::class)->assignLegacyAccount($user, $legacy, $card->id);
            $this->fail('Uma despesa importada não pode ser associada diretamente a um cartão.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        $this->assertNull($legacy->fresh()->source_account_id);
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertDatabaseCount('faturas', 0);
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

        try {
            app(FinancialMovementService::class)->updatePending($user, $movement, ['amount' => '9.00']);
            $this->fail('Um movimento liquidado não pode ser editado pela rota de movimentos pendentes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('movement', $exception->errors());
        }

        $this->assertSame('5.00', (string) $movement->fresh()->amount);
        $this->assertSame('95.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_pending_movement_can_be_descriptively_edited_with_audit(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta');
        $movement = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense', 'description' => 'Descrição inicial', 'amount' => '5.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $updated = app(FinancialMovementService::class)->updatePending($user, $movement, [
            'description' => 'Descrição corrigida',
        ]);

        $this->assertSame('Descrição corrigida', $updated->description);
        $this->assertDatabaseHas('historico_movimentos', [
            'movement_id' => $movement->id,
            'action' => 'updated',
        ]);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_settled_movement_update_endpoint_returns_422_and_preserves_financial_data(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta API');
        $movement = app(FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Compra confirmada', 'amount' => '12.50',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $this->actingAs($user)->putJson('/api/financial/movements/'.$movement->id, [
            'amount' => '99.00', 'description' => 'Compra alterada',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('movimentos', [
            'id' => $movement->id, 'status' => 'settled', 'description' => 'Compra confirmada', 'amount' => '12.50',
        ]);
        $this->assertSame('87.50', app(AccountBalanceService::class)->balance($account->fresh()));
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

    public function test_expense_supports_multiple_partial_settlements_across_accounts_without_mutating_original(): void
    {
        $user = User::factory()->create();
        $first = $this->account($user, 'Banco A', '100.00');
        $second = $this->account($user, 'Banco B', '50.00', true);
        $original = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense', 'description' => 'Fatura de eletricidade',
            'amount' => '80.00', 'movement_date' => '2026-11-30',
        ]);
        $service = app(FinancialMovementService::class);

        $partial = $service->settle($user, $original, [
            'source_account_id' => $first->id, 'amount' => '25.00', 'movement_date' => '2026-11-18',
        ]);
        $this->assertSame('partial', $partial->status);
        $this->assertSame('80.00', (string) $partial->amount);
        $this->assertSame('75.00', app(AccountBalanceService::class)->balance($first));

        $complete = $service->settle($user, $original, [
            'source_account_id' => $second->id, 'amount' => '55.00', 'movement_date' => '2026-11-20',
        ]);
        $this->assertSame('settled', $complete->status);
        $this->assertSame('80.00', (string) $complete->amount);
        $this->assertSame('75.00', app(AccountBalanceService::class)->balance($first));
        $this->assertSame('-5.00', app(AccountBalanceService::class)->balance($second));
        $this->assertSame(2, $original->settlements()->where('status', 'settled')->count());
        $this->assertDatabaseHas('movimentos', [
            'id' => $original->id, 'amount' => '80.00', 'status' => 'settled', 'is_settlement_parent' => true,
        ]);
    }

    public function test_income_supports_partial_receipts_and_rejects_amount_above_remaining_balance(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Banco', '20.00');
        $original = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'income', 'description' => 'Serviço prestado',
            'amount' => '100.00', 'movement_date' => '2026-11-30',
        ]);
        $service = app(FinancialMovementService::class);

        $partial = $service->settle($user, $original, [
            'destination_account_id' => $account->id, 'amount' => '35.50', 'movement_date' => '2026-11-18',
        ]);
        $this->assertSame('partial', $partial->status);
        $this->assertSame('55.50', app(AccountBalanceService::class)->balance($account));

        try {
            $service->settle($user, $original, [
                'destination_account_id' => $account->id, 'amount' => '64.51', 'movement_date' => '2026-11-19',
            ]);
            $this->fail('Não deve aceitar recebimento superior ao saldo pendente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame('partial', $original->fresh()->status);
        $this->assertSame('55.50', app(AccountBalanceService::class)->balance($account));
    }

    public function test_deleting_a_settlement_restores_the_original_pending_balance_without_inverse_event(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Banco', '100.00');
        $service = app(FinancialMovementService::class);
        $original = $service->createPending($user, [
            'type' => 'expense', 'description' => 'Seguro',
            'amount' => '60.00', 'movement_date' => '2026-11-30',
        ]);
        $service->settle($user, $original, [
            'source_account_id' => $account->id, 'amount' => '20.00', 'movement_date' => '2026-11-18',
        ]);
        $event = $original->settlements()->where('status', 'settled')->firstOrFail();

        $service->deleteAsCorrection($user, $event, 'Pagamento efetuado por engano');

        $this->assertSame('pending', $original->fresh()->status);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($account));
        $this->assertDatabaseHas('movimentos', ['id' => $event->id, 'status' => 'void']);
        $this->assertSame(0, $user->movimentos()->whereNotNull('reversal_of_id')->count());
    }

    public function test_financial_projection_counts_effective_payment_and_only_remaining_obligation(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Banco', '100.00');
        $service = app(FinancialMovementService::class);
        $original = $service->createPending($user, [
            'type' => 'expense', 'description' => 'Seguro anual',
            'amount' => '100.00', 'movement_date' => '2026-11-30',
        ]);
        $service->settle($user, $original, [
            'source_account_id' => $account->id, 'amount' => '40.00', 'movement_date' => '2026-11-18',
        ]);

        $projection = app(FinancialProjectionService::class)->build(
            $user,
            Carbon::parse('2026-11-18'),
            Carbon::parse('2026-11-30')
        );
        $events = $projection['movements'];
        $effective = $events->firstWhere('settles_movement_id', $original->id);
        $forecast = $events->firstWhere('id', 'settlement-'.$original->id);

        $this->assertNotNull($effective);
        $this->assertNotNull($forecast);
        $this->assertSame(-40.0, (float) $effective->projection_delta);
        $this->assertSame(-60.0, (float) $forecast->projection_delta);
        $this->assertSame(-100.0, (float) $events->sum('projection_delta'));
    }

    public function test_deleting_installment_settlement_restores_installment_and_forecast(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta principal', '100.00');
        $commitments = app(FinancialCommitmentService::class);
        $debt = $commitments->create($user, [
            'name' => 'Eletrodoméstico',
            'total_amount' => '60.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);
        $installment = $debt->parcelas->firstOrFail();

        $commitments->payInstallmentWithMovement($user, $debt, $installment, [
            'account_id' => $account->id,
            'amount' => '20.00',
            'payment_date' => '2026-11-18',
        ]);

        $forecast = $user->movimentos()->where('installment_id', $installment->id)->firstOrFail();
        $event = $forecast->settlements()->where('status', 'settled')->firstOrFail();
        app(FinancialMovementService::class)->deleteAsCorrection($user, $event, 'Pagamento incorreto');

        $this->assertSame('0.00', (string) $installment->fresh()->paid_amount);
        $this->assertSame('pending', $installment->fresh()->status);
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertFalse((bool) $forecast->fresh()->is_settlement_parent);
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_installment_projection_shows_effective_payment_and_only_remaining_balance(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'Conta principal', '100.00');
        $commitments = app(FinancialCommitmentService::class);
        $debt = $commitments->create($user, [
            'name' => 'Eletrodoméstico',
            'total_amount' => '60.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);
        $installment = $debt->parcelas->firstOrFail();

        $commitments->payInstallment($user, $debt, $installment, [
            'account_id' => $account->id,
            'amount' => '20.00',
            'payment_date' => '2026-11-18',
        ]);

        $projection = app(FinancialProjectionService::class)->build(
            $user,
            Carbon::parse('2026-11-18'),
            Carbon::parse('2026-11-30')
        );
        $items = $projection['movements'];
        $effective = $items->filter(fn ($item) => $item->settles_movement_id !== null);
        $remaining = $items->firstWhere('id', 'debt-'.$installment->id);

        $this->assertCount(1, $effective);
        $this->assertSame(-20.0, (float) $effective->first()->projection_delta);
        $this->assertNotNull($remaining);
        $this->assertSame(-40.0, (float) $remaining->projection_delta);
        $this->assertSame(-60.0, (float) $items->sum('projection_delta'));
    }

    public function test_realized_invoice_payment_can_be_cancelled_and_remaining_balance_is_recalculated(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '100.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => '60.00',
            'movement_date' => '2026-11-12',
        ])->assertCreated();
        $invoice = $user->faturas()->firstOrFail();
        $response = $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'amount' => '20.00', 'payment_date' => '2026-12-01',
        ])->assertOk();
        $payment = $user->movimentos()->whereKey($response->json('data.id'))->firstOrFail();

        $this->deleteJson('/api/financial/movements/'.$payment->id)->assertOk();

        $parent = $invoice->movimentos()->where('is_settlement_parent', true)->firstOrFail();
        $this->assertSame('pending', $parent->fresh()->status);
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('60.00', $this->getJson('/api/financial/invoices')->assertOk()->json('data.data.0.remaining_amount'));
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-60.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('void', $payment->fresh()->status);

        $finalResponse = $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'amount' => '60.00', 'payment_date' => '2026-12-02',
        ])->assertOk();
        $finalPayment = $user->movimentos()->whereKey($finalResponse->json('data.id'))->firstOrFail();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('40.00', app(AccountBalanceService::class)->balance($bank->fresh()));

        $this->deleteJson('/api/financial/movements/'.$finalPayment->id)->assertOk();
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('void', $finalPayment->fresh()->status);
        $this->assertSame('60.00', $this->getJson('/api/financial/invoices')->assertOk()->json('data.data.0.remaining_amount'));
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-60.00', app(AccountBalanceService::class)->balance($card->fresh()));
    }

    public function test_actual_invoice_interest_is_recognized_as_expense_and_increases_invoice_balance(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '100.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => '50.00',
            'movement_date' => '2026-11-12',
        ])->assertCreated();
        $invoice = $user->faturas()->firstOrFail();

        $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-12-01',
        ])->assertOk();

        $this->postJson('/api/financial/invoices/'.$invoice->id.'/charges', [
            'charge_type' => 'interest', 'description' => 'Juros por atraso',
            'amount' => '5.00', 'movement_date' => '2026-11-17',
        ])->assertCreated()->assertJsonPath('data.type', 'expense')->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.invoice_charge_type', 'interest');

        $invoiceData = $this->getJson('/api/financial/invoices')->assertOk()->json('data.data.0');
        $this->assertSame('55.00', $invoiceData['total_amount']);
        $this->assertSame('10.00', $invoiceData['paid_amount']);
        $this->assertSame('45.00', $invoiceData['remaining_amount']);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame('-45.00', app(AccountBalanceService::class)->balance($card->fresh()));
    }

    public function test_overdue_invoice_balance_moves_to_next_cycle_without_new_expense_or_cash_event(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'Conta principal', '500.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '1000.00',
        ]);
        $invoices = app(CardInvoiceService::class);
        $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra novembro', 'amount' => '100.00',
            'movement_date' => '2026-11-12',
        ]);
        $sourceInvoice = $user->faturas()->firstOrFail();
        $this->travelTo(Carbon::parse('2026-12-06 12:00:00'));

        $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra dezembro', 'amount' => '50.00',
            'movement_date' => '2026-12-06',
        ]);
        $targetInvoice = $user->faturas()->where('id', '!=', $sourceInvoice->id)->firstOrFail();

        $charge = $invoices->addCharge($user, $targetInvoice, [
            'charge_type' => 'interest',
            'description' => 'Juros da fatura anterior',
            'amount' => '5.00',
            'movement_date' => '2026-12-06',
            'origin_invoice_id' => $sourceInvoice->id,
        ]);

        $this->assertSame('carried', $sourceInvoice->fresh()->status);
        $this->assertSame('155.00', (string) $targetInvoice->fresh()->movimentos()->where('is_settlement_parent', true)->value('amount'));
        $this->assertSame('interest', $charge->invoice_charge_type);
        $this->assertSame($sourceInvoice->id, $charge->origin_invoice_id);

        $carry = FaturaSaldoTransportado::query()
            ->where('source_invoice_id', $sourceInvoice->id)
            ->where('target_invoice_id', $targetInvoice->id)->firstOrFail();
        $this->assertSame('100.00', (string) $carry->amount);

        $invoiceData = collect($invoices->listInvoices($user)->items())->keyBy('id');
        $this->assertSame('0.00', $invoiceData[$sourceInvoice->id]->remaining_amount);
        $this->assertSame('carried', $invoiceData[$sourceInvoice->id]->display_status);
        $this->assertSame('155.00', $invoiceData[$targetInvoice->id]->total_amount);
        $this->assertSame('155.00', $invoiceData[$targetInvoice->id]->remaining_amount);
        $this->assertSame('500.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-155.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame(3, $user->movimentos()->where('type', 'expense')->where('is_settlement_parent', false)->count());

        $projection = app(FinancialProjectionService::class)->build(
            $user,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-01-31')
        );
        $invoiceProjection = $projection['movements']->filter(fn ($item) => str_starts_with((string) $item->id, 'invoice-'));
        $this->assertCount(1, $invoiceProjection);
        $this->assertSame(-155.0, (float) $invoiceProjection->first()->projection_delta);
    }

    public function test_invoice_credit_is_linked_to_purchase_reduces_card_balance_and_nets_expenses(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bank = $this->account($user, 'Conta principal', '500.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '1000.00',
        ]);
        $invoices = app(CardInvoiceService::class);
        $purchase = $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra com devolução parcial', 'amount' => '100.00',
            'movement_date' => '2026-11-12',
        ]);
        $invoice = $user->faturas()->firstOrFail();
        $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id,
            'amount' => '20.00',
            'payment_date' => '2026-11-17',
            'allocation' => ['principal' => '15.00', 'interest' => '5.00'],
        ])->assertOk()->assertJsonPath('data.invoice_payment_allocation.principal', '15.00');
        $this->postJson('/api/financial/invoices/'.$invoice->id.'/pay', [
            'account_id' => $bank->id,
            'amount' => '20.00',
            'payment_date' => '2026-11-17',
            'allocation' => ['principal' => '10.00', 'interest' => '5.00'],
        ])->assertUnprocessable()->assertJsonValidationErrors('allocation');

        $response = $this->postJson('/api/financial/invoices/'.$invoice->id.'/credits', [
            'purchase_movement_id' => $purchase->id,
            'origin_invoice_id' => $invoice->id,
            'description' => 'Devolução parcial',
            'amount' => '10.00',
            'movement_date' => '2026-11-17',
        ])->assertCreated()->assertJsonPath('data.type', 'adjustment')
            ->assertJsonPath('data.invoice_charge_type', 'credit')
            ->assertJsonPath('data.invoice_credit_for_movement_id', $purchase->id);

        $invoiceData = $this->getJson('/api/financial/invoices')->assertOk()->json('data.data.0');
        $this->assertSame('90.00', $invoiceData['total_amount']);
        $this->assertSame('20.00', $invoiceData['paid_amount']);
        $this->assertSame('70.00', $invoiceData['remaining_amount']);
        $this->assertSame('-70.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('480.00', app(AccountBalanceService::class)->balance($bank->fresh()));

        $dashboard = $this->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(90.0, $dashboard->viewData('totalDespesas'));
        $this->assertSame(0.0, $dashboard->viewData('totalReceitas'));

        $movements = $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 10]));
        $movements->assertOk()->assertJsonPath('total_despesas', 90);
        $purchaseRow = collect($movements->json('data'))->firstWhere('financial_movement_id', $purchase->id);
        $this->assertNotNull($purchaseRow);
        $this->assertSame(100.0, (float) $purchaseRow['amount']);

        $creditRow = collect($movements->json('data'))->firstWhere('category_label', 'Crédito de compra');
        $this->assertNotNull($creditRow);
        $this->assertSame(10.0, (float) $creditRow['amount']);
        $this->assertSame('despesa', $creditRow['tipo']);
        $this->assertSame(90.0, (float) $movements->json('total_despesas'));
    }

    public function test_invoice_closes_automatically_and_retroactive_charges_reopen_only_the_remaining_balance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bank = $this->account($user, 'Conta principal', '500.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '1000.00',
        ]);
        $invoices = app(CardInvoiceService::class);
        $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra do ciclo', 'amount' => '80.00',
            'movement_date' => '2026-11-12',
        ]);
        $invoice = $user->faturas()->firstOrFail();

        // No manual close or bank statement reconciliation is required.
        $this->travelTo(Carbon::parse('2026-11-25 12:00:00'));
        $sameDay = $invoices->listInvoices($user)->items()[0];
        $this->assertSame('open', $sameDay->cycle_status);
        $this->assertSame('unpaid', $sameDay->payment_status);

        $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));
        $closed = $invoices->listInvoices($user)->items()[0];
        $this->assertSame('closed', $closed->cycle_status);
        $this->assertSame('unpaid', $closed->payment_status);

        $this->postJson('/api/financial/invoices/'.$invoice->id.'/charges', [
            'charge_type' => 'late_fee', 'description' => 'Multa após o fecho',
            'amount' => '5.00', 'movement_date' => '2026-11-26',
        ])->assertCreated();

        $afterFirstCharge = $invoices->findInvoice($user, $invoice->id);
        $this->assertSame('85.00', $afterFirstCharge->total_amount);
        $this->assertSame('85.00', $afterFirstCharge->remaining_amount);

        $payment = $invoices->pay($user, $invoice, $bank->id, '2026-11-26', '30.00');
        $this->assertSame('settled', $payment->fresh()->status);
        $afterPartial = $invoices->findInvoice($user, $invoice->id);
        $this->assertSame('closed', $afterPartial->cycle_status);
        $this->assertSame('partial', $afterPartial->payment_status);
        $this->assertSame('55.00', $afterPartial->remaining_amount);

        $this->postJson('/api/financial/invoices/'.$invoice->id.'/charges', [
            'charge_type' => 'interest', 'description' => 'Juros após pagamento parcial',
            'amount' => '2.00', 'movement_date' => '2026-11-26',
        ])->assertCreated();

        $afterSecondCharge = $invoices->findInvoice($user, $invoice->id);
        $this->assertSame('87.00', $afterSecondCharge->total_amount);
        $this->assertSame('30.00', $afterSecondCharge->paid_amount);
        $this->assertSame('57.00', $afterSecondCharge->remaining_amount);
        $this->assertSame('partial', $afterSecondCharge->payment_status);

        $invoices->pay($user, $invoice, $bank->id, '2026-11-27', '57.00');
        $paid = $invoices->findInvoice($user, $invoice->id);
        $this->assertSame('paid', $paid->payment_status);
        $this->assertSame('closed', $paid->cycle_status);
        $this->assertSame('0.00', $paid->remaining_amount);
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('413.00', app(AccountBalanceService::class)->balance($bank->fresh()));

        // A new retroactive expense after full payment reopens only the new balance.
        $this->postJson('/api/financial/invoices/'.$invoice->id.'/charges', [
            'charge_type' => 'commission', 'description' => 'Comissão lançada após pagamento integral',
            'amount' => '3.00', 'movement_date' => '2026-11-26',
        ])->assertCreated();

        $reopened = $invoices->findInvoice($user, $invoice->id);
        $this->assertSame('closed', $reopened->cycle_status);
        $this->assertSame('partial', $reopened->payment_status);
        $this->assertSame('90.00', $reopened->total_amount);
        $this->assertSame('87.00', $reopened->paid_amount);
        $this->assertSame('3.00', $reopened->remaining_amount);
        $this->assertSame('-3.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('413.00', app(AccountBalanceService::class)->balance($bank->fresh()));

        $invoices->pay($user, $invoice, $bank->id, '2026-11-28', '3.00');
        $final = $invoices->findInvoice($user, $invoice->id);
        $this->assertSame('paid', $final->payment_status);
        $this->assertSame('0.00', $final->remaining_amount);
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('410.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(3, $invoice->movimentos()->where('invoice_charge_type', '!=', 'credit')->count());
        $this->assertSame(90.00, (float) $invoice->movimentos()->where('is_settlement_parent', true)->value('amount'));
    }
}

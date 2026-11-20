<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\RecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialCriticalProtectionRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
    }

    private function bank(User $user): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => 'Banco', 'type' => 'bank', 'opening_balance' => '200.00',
            'opening_balance_date' => '2026-11-01',
        ]);
    }

    private function card(User $user): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão', 'type' => 'credit_card', 'opening_debt_amount' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '100.00',
        ]);
    }

    private function debt(User $user, ContaFinanceira $bank)
    {
        return app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Compra parcelada', 'total_amount' => '60.00',
            'start_date' => '2026-11-17', 'due_date' => '2026-12-17',
            'payment_condition' => 'installments', 'payment_method' => 'transfer',
            'installments_count' => 2, 'account_id' => $bank->id,
        ]);
    }


    public function test_settled_invoice_payment_can_be_cancelled_with_invoice_and_account_recalculation(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $invoices = app(CardInvoiceService::class);
        $purchase = $invoices->purchase($user, [
            'account_id' => $card->id,
            'description' => 'Compra de teste',
            'amount' => '20.00',
            'movement_date' => '2026-11-17',
        ]);
        $invoice = \App\Models\Fatura::query()->whereKey($purchase->invoice_id)->firstOrFail();
        $payment = $invoices->pay($user, $invoice, $bank->id, '2026-11-17', '20.00');

        $this->actingAs($user)
            ->deleteJson(route('financial.movements.destroy', $payment->id))
            ->assertOk();

        $this->assertSame('void', $payment->fresh()->status);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-20.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertDatabaseHas('deletion_operations', [
            'user_id' => $user->id,
            'root_entity_id' => $payment->id,
            'status' => 'completed',
        ]);
    }

    public function test_deferred_single_due_movement_deletes_its_commitment_without_affecting_installment_payment_reversal(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Eletricidade',
            'total_amount' => '120.00',
            'start_date' => '2026-11-17',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);
        $installment = $debt->parcelas->firstOrFail();
        $forecast = $user->movimentos()->where('debt_id', $debt->id)->firstOrFail();

        $this->actingAs($user)
            ->deleteJson(route('financial.movements.destroy', $forecast->id))
            ->assertOk();

        $this->assertSoftDeleted('dividas', ['id' => $debt->id]);
        $this->assertSame('void', $forecast->fresh()->status);
        $this->assertSoftDeleted('parcelas_dividas', ['id' => $installment->id]);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));
    }

    public function test_another_user_cannot_delete_commitment_or_linked_movement(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $bank = $this->bank($owner);
        $debt = $this->debt($owner, $bank);
        $installment = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $owner->movimentos()->where('installment_id', $installment->id)->firstOrFail();

        $this->actingAs($attacker)
            ->deleteJson(route('financial.commitments.destroy', $debt->id))
            ->assertNotFound();
        $this->deleteJson(route('financial.movements.destroy', $forecast->id))
            ->assertNotFound();

        $this->assertSame('open', $debt->fresh()->status);
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('0.00', $installment->fresh()->paid_amount);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertDatabaseMissing('deletion_operations', [
            'root_entity_id' => $debt->id,
        ]);
        $this->assertDatabaseMissing('deletion_operations', [
            'root_entity_id' => $forecast->id,
        ]);
    }

    public function test_linked_forecasts_cannot_be_edited_confirmed_voided_or_deleted_and_full_payment_remains_valid(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $this->actingAs($user);

        $this->putJson('/api/financial/movements/'.$forecast->id.'/void')->assertNotFound();

        $responses = [
            $this->putJson(route('financial.movements.update', $forecast->id), ['amount' => '1.00']),
            $this->putJson(route('financial.movements.confirm', $forecast->id), ['source_account_id' => $bank->id]),
            $this->deleteJson(route('financial.movements.destroy', $forecast->id)),
            $this->deleteJson(route('financial.accounts.movement.destroy', $forecast->id)),
        ];
        foreach ($responses as $response) {
            $response->assertUnprocessable()->assertJsonValidationErrors('movement');
            $this->assertStringContainsString('Parcelamentos', $response->json('errors.movement.0'));
        }
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('30.00', $forecast->fresh()->amount);
        $this->assertSame('0.00', $part->fresh()->paid_amount);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));

        $this->putJson(route('financial.movements.settle', $forecast->id), [
            'source_account_id' => $bank->id, 'amount' => '30.00', 'expected_amount' => '30.00',
            'movement_date' => '2026-11-17',
        ])->assertOk()->assertJsonPath('data.id', $forecast->id)->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('installment.paid_amount', '30.00')->assertJsonPath('installment.status', 'paid');

        foreach ([
            $this->deleteJson(route('financial.movements.destroy', $forecast->id)),
            $this->deleteJson(route('financial.accounts.movement.destroy', $forecast->id)),
        ] as $response) {
            $response->assertUnprocessable()->assertJsonValidationErrors('movement');
            $this->assertStringContainsString('Parcelamentos', $response->json('errors.movement.0'));
        }
        $this->assertSame('settled', $forecast->fresh()->status);
        $this->assertSame('30.00', $part->fresh()->paid_amount);
        $this->assertSame('170.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(0, $user->movimentos()->whereNotNull('reversal_of_id')->count());
    }

    public function test_deleting_partial_payment_recalculates_installment_without_creating_an_inverse_movement(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-11-17',
        ])->assertOk();
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        $this->deleteJson(route('financial.movements.destroy', $payment->id))->assertOk();
        $this->assertSame('void', $payment->fresh()->status);
        $this->assertSame('0.00', $part->fresh()->paid_amount);
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));

        $this->postJson(route('financial.commitments.cancel', $debt->id))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', $part->fresh()->status);
        $this->assertSame('0.00', $part->fresh()->paid_amount);
        $this->assertSame('void', $forecast->fresh()->status);
        $this->assertSame(0, $user->movimentos()->where('debt_id', $debt->id)->where('status', 'pending')->count());
        $this->assertSame('void', $payment->fresh()->status);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(0, $user->movimentos()->whereNotNull('reversal_of_id')->count());
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $forecast->id, 'action' => 'deleted']);
    }


    public function test_cancelling_partially_paid_commitment_voids_partial_forecast_and_preserves_payment_as_independent_movement(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-11-17',
        ])->assertOk();
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        $this->postJson(route('financial.commitments.cancel', $debt->id))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $part->fresh()->status);
        $this->assertSame('10.00', $part->fresh()->paid_amount);
        $this->assertSame('void', $forecast->fresh()->status);
        $this->assertNull($forecast->fresh()->debt_id);
        $this->assertNull($forecast->fresh()->installment_id);
        $this->assertFalse((bool) $forecast->fresh()->is_settlement_parent);
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->settles_movement_id);
        $this->assertNull($payment->fresh()->debt_id);
        $this->assertNull($payment->fresh()->installment_id);
        $this->assertSame('190.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(0, $user->movimentos()->where('debt_id', $debt->id)->whereIn('status', ['pending', 'partial'])->count());
        $this->assertDatabaseHas('deletion_operations', [
            'user_id' => $user->id,
            'root_entity_type' => 'commitment_cancel',
            'root_entity_id' => $debt->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('deletion_operation_items', [
            'user_id' => $user->id,
            'entity_type' => 'Movimento',
            'entity_id' => $payment->id,
            'action' => 'detached',
        ]);
    }

    public function test_deleting_partially_paid_commitment_preserves_payment_and_detaches_it_from_deleted_aggregate(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-11-17',
        ])->assertOk();
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        $this->deleteJson(route('financial.commitments.destroy', $debt->id))->assertOk();

        $this->assertSoftDeleted('dividas', ['id' => $debt->id]);
        $this->assertSame('cancelled', $part->fresh()->status);
        $this->assertSame('10.00', $part->fresh()->paid_amount);
        $this->assertSame('void', $forecast->fresh()->status);
        $this->assertNull($forecast->fresh()->debt_id);
        $this->assertNull($forecast->fresh()->installment_id);
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->settles_movement_id);
        $this->assertSame('190.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertDatabaseHas('deletion_operations', [
            'user_id' => $user->id, 'root_entity_type' => 'commitment', 'root_entity_id' => $debt->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('deletion_operation_items', [
            'user_id' => $user->id, 'entity_type' => 'Movimento', 'entity_id' => $payment->id, 'action' => 'detached',
        ]);
    }



    public function test_commitment_propagation_is_blocked_when_paid_amount_does_not_match_settlement_events(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-11-17',
        ])->assertOk();
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        // Simula corrupção/edição manual do contador materializado da prestação.
        $part->update(['paid_amount' => '9.00']);

        $this->deleteJson(route('financial.commitments.destroy', $debt->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commitment');
        $this->postJson(route('financial.commitments.cancel', $debt->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commitment');

        $this->assertSame('open', $debt->fresh()->status);
        $this->assertSame('partial', $forecast->fresh()->status);
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertSame('9.00', $part->fresh()->paid_amount);
        $this->assertSame('190.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertDatabaseMissing('deletion_operations', [
            'user_id' => $user->id,
            'root_entity_id' => $debt->id,
            'status' => 'completed',
        ]);
    }


    public function test_deleting_a_previously_cancelled_partially_paid_commitment_keeps_its_payment_independent(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '10.00', 'payment_date' => '2026-11-17',
        ])->assertOk();
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        $this->postJson(route('financial.commitments.cancel', $debt->id))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($payment->fresh()->settles_movement_id);
        $this->assertSame('cancelled', $part->fresh()->status);

        $this->deleteJson(route('financial.commitments.destroy', $debt->id))->assertOk();

        $this->assertSoftDeleted('dividas', ['id' => $debt->id]);
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->debt_id);
        $this->assertNull($payment->fresh()->installment_id);
        $this->assertSame('10.00', $part->fresh()->paid_amount);
        $this->assertSame('190.00', app(AccountBalanceService::class)->balance($bank->fresh()));
    }

    public function test_deleting_fully_paid_commitment_preserves_payment_event_and_detaches_all_aggregate_links(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '30.00', 'payment_date' => '2026-11-17',
        ])->assertOk();
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        $this->deleteJson(route('financial.commitments.destroy', $debt->id))->assertOk();

        $this->assertSoftDeleted('dividas', ['id' => $debt->id]);
        $this->assertSame('paid', $part->fresh()->status);
        $this->assertSame('30.00', $part->fresh()->paid_amount);
        $this->assertSame('void', $forecast->fresh()->status);
        $this->assertNull($forecast->fresh()->debt_id);
        $this->assertNull($forecast->fresh()->installment_id);
        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->settles_movement_id);
        $this->assertSame('170.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(0, $user->movimentos()->where('debt_id', $debt->id)->count());
        $this->assertSame(0, $user->movimentos()->where('installment_id', $part->id)->count());
    }

    public function test_commitment_edit_updates_the_forecast_without_exposing_generic_amount_changes(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();

        $this->actingAs($user)->putJson(route('financial.commitments.update', $debt->id), [
            'name' => 'Nome corrigido', 'due_date' => '2027-01-17',
        ])->assertOk();
        $this->assertSame('Nome corrigido · Parcela 1/2', $forecast->fresh()->description);
        $this->assertSame('2027-01-17', $forecast->fresh()->movement_date->toDateString());
        $this->assertSame('30.00', $forecast->fresh()->amount);
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $forecast->id, 'action' => 'updated']);
    }

    public function test_mismatched_forecast_amount_prevents_payment_without_mutation(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $forecast->update(['amount' => '29.00']); // Simula uma inconsistência anterior.

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $part->id]), [
            'account_id' => $bank->id, 'amount' => '30.00', 'payment_date' => '2026-11-17',
        ])->assertUnprocessable()->assertJsonValidationErrors('movement');
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('0.00', $part->fresh()->paid_amount);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($bank->fresh()));
    }


    public function test_failed_parent_recalculation_rolls_back_movement_deletion_and_audit(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $forecast = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Despesa com pagamento parcial',
            'amount' => '30.00',
            'movement_date' => '2026-11-17',
            'source_account_id' => $bank->id,
        ]);
        app(FinancialMovementService::class)->settle($user, $forecast, [
            'source_account_id' => $bank->id,
            'amount' => '10.00',
            'movement_date' => '2026-11-17',
        ]);
        $payment = $forecast->settlements()->where('status', 'settled')->firstOrFail();

        $this->mock(FinancialMovementService::class, function ($mock): void {
            $mock->shouldReceive('refreshSettlementParentAfterDeletion')
                ->once()
                ->andThrow(new \RuntimeException('Falha simulada no recálculo do agregado.'));
        });

        try {
            app(\App\Services\Financial\FinancialDeletionService::class)
                ->deleteMovement($user, $payment, 'Teste de rollback transacional.');
            $this->fail('A falha de recálculo deveria interromper a operação.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Falha simulada no recálculo do agregado.', $exception->getMessage());
        }

        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertSame('partial', $forecast->fresh()->status);
        $this->assertSame('190.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertDatabaseMissing('deletion_operations', [
            'user_id' => $user->id,
            'root_entity_type' => 'movement',
            'root_entity_id' => $payment->id,
        ]);
        $this->assertDatabaseMissing('deletion_operation_items', [
            'user_id' => $user->id,
            'entity_type' => 'Movimento',
            'entity_id' => $payment->id,
        ]);
    }

    public function test_renegotiation_orchestrator_rejects_an_already_paid_installment(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $forecast = $user->movimentos()->where('installment_id', $part->id)->firstOrFail();
        $part->update(['status' => 'paid', 'paid_amount' => $part->amount]);

        try {
            app(\App\Services\Financial\FinancialDeletionService::class)
                ->renegotiateCommitmentInstallment($user, $debt, $part);
            $this->fail('Uma prestação paga não pode ser substituída na renegociação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('commitment', $exception->errors());
        }

        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('paid', $part->fresh()->status);
        $this->assertSame('open', $debt->fresh()->status);
        $this->assertDatabaseMissing('deletion_operations', [
            'user_id' => $user->id,
            'root_entity_type' => 'commitment_renegotiation',
            'root_entity_id' => $debt->id,
        ]);
    }

    public function test_pausing_recurrence_cannot_delete_a_forecast_also_linked_to_an_installment(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $debt = $this->debt($user, $bank);
        $part = $debt->parcelas->sortBy('installment_number')->first();
        $recurrences = app(RecurrenceService::class);
        $rule = $recurrences->create($user, [
            'type' => 'expense', 'description' => 'Conta recorrente', 'amount' => '10.00',
            'category_id' => $user->categorias()->where('slug', 'casa')->firstOrFail()->id,
            'account_id' => $bank->id, 'frequency' => 'monthly', 'interval_value' => 1,
            'start_date' => '2026-11-18',
        ]);
        $forecast = $user->movimentos()->where('recurrence_id', $rule->id)->orderBy('movement_date')->firstOrFail();
        $forecast->update(['debt_id' => $debt->id, 'installment_id' => $part->id]);

        try {
            $recurrences->setActive($user, $rule, false);
            $this->fail('A previsão vinculada não pode ser eliminada pela recorrência.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('movement', $exception->errors());
        }
        $this->assertTrue((bool) $rule->fresh()->is_active);
        $this->assertSame('pending', $forecast->fresh()->status);
        $this->assertSame('0.00', $part->fresh()->paid_amount);
    }

    public function test_generic_card_income_and_adjustments_are_blocked_but_invoice_purchase_and_payment_work(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $invoices = app(CardInvoiceService::class);
        $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => '80.00', 'movement_date' => '2026-11-17',
        ]);
        $invoice = $user->faturas()->firstOrFail();
        $this->actingAs($user);
        $base = ['description' => 'Genérico', 'amount' => '40.00', 'movement_date' => '2026-11-17'];

        foreach ([
            ['type' => 'income', 'destination_account_id' => $card->id],
            ['type' => 'adjustment', 'adjustment_direction' => 'increase', 'destination_account_id' => $card->id],
            ['type' => 'adjustment', 'adjustment_direction' => 'decrease', 'source_account_id' => $card->id],
            ['type' => 'income', 'status' => 'pending', 'destination_account_id' => $card->id],
        ] as $extra) {
            $this->postJson(route('financial.movements.store'), array_merge($base, $extra))
                ->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        try {
            app(FinancialMovementService::class)->create($user, array_merge($base, [
                'type' => 'income', 'destination_account_id' => $card->id,
                'invoice_id' => $invoice->id, 'reversal_of_id' => $invoice->movimentos()->firstOrFail()->id,
            ]));
            $this->fail('Metadados não podem transformar uma receita genérica num estorno válido.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }
        $pending = $user->movimentos()->create([
            'type' => 'income', 'status' => 'pending', 'description' => 'Receita pendente antiga',
            'amount' => '40.00', 'movement_date' => '2026-11-17', 'destination_account_id' => $card->id,
        ]);
        $this->putJson(route('financial.movements.settle', $pending->id), [])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson(route('financial.movements.confirm', $pending->id), [
            'destination_account_id' => $card->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('-80.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $listed = $invoices->listInvoices($user)->items()[0];
        $this->assertSame('80.00', $listed->remaining_amount);

        $invoices->pay($user, $invoice, $bank->id, '2026-11-17');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('120.00', app(AccountBalanceService::class)->balance($bank->fresh()));
    }

    public function test_legacy_income_cannot_be_reassigned_to_card_and_settled_expense_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user);
        $card = $this->card($user);
        $service = app(FinancialMovementService::class);
        $income = $service->create($user, [
            'type' => 'income', 'description' => 'Receita antiga', 'amount' => '10.00',
            'movement_date' => '2026-11-17', 'destination_account_id' => $bank->id,
            'legacy_type' => 'receita', 'legacy_id' => (string) Str::uuid(),
        ]);
        try {
            $service->assignLegacyAccount($user, $income, $card->id);
            $this->fail('A receita não pode ser reatribuída ao cartão.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }
        $this->assertSame($bank->id, $income->fresh()->destination_account_id);

        $oldExpense = $user->movimentos()->create([
            'type' => 'expense', 'status' => 'settled', 'description' => 'Despesa antiga sem fatura',
            'amount' => '20.00', 'movement_date' => '2026-11-17', 'source_account_id' => $card->id,
        ]);
        $deleted = $service->deleteAsCorrection($user, $oldExpense);
        $this->assertSame('void', $deleted->status);
        $this->assertNull($deleted->reversal_of_id);
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
    }
}

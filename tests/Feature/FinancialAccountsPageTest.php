<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_accounts_page(): void
    {
        $this->get('/contas')->assertRedirect('/login');
    }

    public function test_user_sees_only_their_own_financial_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(FinancialAccountService::class);

        $service->create($owner, [
            'name' => 'Conta da Ana',
            'type' => 'bank',
            'opening_balance' => '120.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $service->create($other, [
            'name' => 'Conta privada de outro utilizador',
            'type' => 'bank',
            'opening_balance' => '50.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($owner)
            ->get('/contas')
            ->assertOk()
            ->assertSee('Conta da Ana')
            ->assertSee('Permitir saldo negativo nesta conta')
            ->assertDontSee('Conta privada de outro utilizador');
    }

    public function test_active_and_closed_accounts_are_rendered_in_separate_tabs(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);

        $active = $service->create($user, [
            'name' => 'Conta ativa',
            'type' => 'bank',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $closed = $service->create($user, [
            'name' => 'Conta encerrada',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $service->close($user, $closed);

        $response = $this->actingAs($user)->get('/contas')->assertOk()
            ->assertSee('id="activeAccountsTab"', false)
            ->assertSee('id="closedAccountsTab"', false)
            ->assertSee('id="activeAccountsPanel"', false)
            ->assertSee('id="closedAccountsPanel"', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee('1 conta ativa')
            ->assertSee('Histórico preservado');

        $html = $response->getContent();
        $activePanelPosition = strpos($html, 'id="activeAccountsPanel"');
        $closedPanelPosition = strpos($html, 'id="closedAccountsPanel"');
        $activeCardPosition = strpos($html, 'data-account-id="'.$active->id.'"');
        $closedCardPosition = strpos($html, 'data-account-id="'.$closed->id.'"');

        $this->assertNotFalse($activePanelPosition);
        $this->assertNotFalse($closedPanelPosition);
        $this->assertNotFalse($activeCardPosition);
        $this->assertNotFalse($closedCardPosition);
        $this->assertTrue($activePanelPosition < $activeCardPosition && $activeCardPosition < $closedPanelPosition);
        $this->assertTrue($closedPanelPosition < $closedCardPosition);
        $this->assertStringContainsString('data-account-status="active"', $html);
        $this->assertStringContainsString('data-account-status="closed"', $html);
    }

    public function test_accounts_page_renders_the_close_account_modal_and_form(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);

        $account = $service->create($user, [
            'name' => 'Conta a encerrar',
            'type' => 'bank',
            'opening_balance' => '75.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $service->create($user, [
            'name' => 'Conta de destino',
            'type' => 'bank',
            'opening_balance' => '10.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)
            ->get('/contas')
            ->assertOk()
            ->assertSee('id="closeAccountModal"', false)
            ->assertSee('id="closeAccountForm"', false)
            ->assertSee('id="closeTransferTarget"', false)
            ->assertSee('id="closeAccountZeroNotice"', false)
            ->assertSee('id="closeAccountNegativeNotice"', false)
            ->assertSee('id="closeAccountNoTargets"', false)
            ->assertSee('data-currency=', false)
            ->assertDontSee('>Não transferir</option>', false)
            ->assertSee(route('financial.accounts.close', $account->id), false);
    }

    public function test_owner_can_rename_and_close_an_account_without_deleting_it(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);
        $account = $service->create($user, [
            'name' => 'Conta antiga', 'type' => 'bank', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id, [
            'name' => 'Conta principal',
        ])->assertOk()->assertJsonPath('data.name', 'Conta principal');

        $this->postJson('/api/financial/accounts/'.$account->id.'/close')
            ->assertOk();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $account->id, 'user_id' => $user->id, 'name' => 'Conta principal',
            'status' => 'closed', 'is_operational' => false,
        ]);
    }

    public function test_owner_can_change_negative_balance_permission_after_creation(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta à ordem', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $this->assertFalse((bool) $account->allow_negative_balance);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => true,
        ])->assertOk();
        $this->assertDatabaseHas('contas_financeiras', ['id' => $account->id, 'allow_negative_balance' => true]);

        $this->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertOk();
        $this->assertDatabaseHas('contas_financeiras', ['id' => $account->id, 'allow_negative_balance' => false]);
    }

    public function test_negative_balance_permission_cannot_be_disabled_when_operational_balance_is_negative(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta em descoberto', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01', 'allow_negative_balance' => true,
        ]);
        app(\App\Services\Financial\FinancialMovementService::class)->create($user, [
            'type' => 'expense', 'description' => 'Débito em descoberto', 'amount' => '125.00',
            'movement_date' => '2026-11-12', 'source_account_id' => $account->id,
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $account->id, 'allow_negative_balance' => true,
        ]);
    }

    public function test_negative_opening_balance_automatically_enables_overdraft_permission(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta com saldo inicial negativo',
            'type' => 'bank',
            'opening_balance' => '-25.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->assertTrue((bool) $account->allow_negative_balance);
        $this->assertSame('-25.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($account));
    }

    public function test_setting_a_negative_opening_balance_enables_permission_in_one_update(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta corrigível',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $updated = app(FinancialAccountService::class)->setOpeningBalance(
            $user,
            $account,
            '-25.00',
            '2026-11-01',
            false
        );

        $this->assertTrue((bool) $updated->allow_negative_balance);
        $this->assertSame('-25.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($updated));
    }

    public function test_negative_opening_balance_cannot_be_disabled_even_after_current_balance_recovers(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta recuperada',
            'type' => 'bank',
            'opening_balance' => '-25.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        app(FinancialMovementService::class)->create($user, [
            'type' => 'income',
            'description' => 'Reposição',
            'amount' => '30.00',
            'movement_date' => '2026-11-12',
            'destination_account_id' => $account->id,
        ]);

        $this->assertSame('5.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($account->fresh()));
        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertUnprocessable();
    }

    public function test_negative_balance_permission_cannot_be_disabled_for_negative_opening_balance(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta negativa', 'type' => 'bank', 'opening_balance' => '-25.00',
            'opening_balance_date' => '2026-11-01', 'allow_negative_balance' => true,
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$account->id.'/settings', [
            'allow_negative_balance' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('contas_financeiras', ['id' => $account->id, 'allow_negative_balance' => true]);
    }

    public function test_account_with_negative_balance_cannot_be_closed(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta em descoberto',
            'type' => 'bank',
            'opening_balance' => '-150.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)
            ->postJson('/api/financial/accounts/'.$account->id.'/close')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $account->id,
            'status' => 'active',
            'is_operational' => true,
        ]);
    }

    public function test_closing_positive_balance_transfers_it_and_keeps_both_accounts(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);
        $source = $service->create($user, [
            'name' => 'Conta a encerrar', 'type' => 'bank', 'opening_balance' => '75.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $target = $service->create($user, [
            'name' => 'Conta de destino', 'type' => 'bank', 'opening_balance' => '10.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->postJson('/api/financial/accounts/'.$source->id.'/close', [
            'transfer_to_account_id' => $target->id,
        ])->assertOk();

        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'transfer',
            'source_account_id' => $source->id, 'destination_account_id' => $target->id,
            'amount' => '75.00',
        ]);
        $this->assertDatabaseHas('contas_financeiras', ['id' => $source->id, 'status' => 'closed']);
        $this->assertDatabaseHas('contas_financeiras', ['id' => $target->id, 'status' => 'active']);
    }

    public function test_closed_account_history_shows_ledger_balance_and_is_read_only(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);

        $source = $service->create($user, [
            'name' => 'Conta encerrada',
            'type' => 'bank',
            'opening_balance' => '75.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $destination = $service->create($user, [
            'name' => 'Conta de destino',
            'type' => 'bank',
            'opening_balance' => '10.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $service->close($user, $source, $destination->id);

        $this->assertSame(
            '0.00',
            app(\\App\\Services\\Financial\\AccountBalanceService::class)->balanceForHistory($source->fresh())
        );

        $this->actingAs($user)
            ->get(route('financial.accounts.show', $source->id))
            ->assertOk()
            ->assertSee('Saldo no encerramento')
            ->assertSee('0,00 EUR')
            ->assertSee('Encerrada')
            ->assertSee('data-editable="0"', false)
            ->assertSee('data-cancelable="0"', false)
            ->assertSee('id="accountMovementDetailsModal"', false)
            ->assertSee('id="accountDeleteModal"', false)
            ->assertSee('modal-header', false)
            ->assertSee('modal-body', false)
            ->assertSee('modal-footer', false)
            ->assertSee('apenas para consulta');
    }

    public function test_credit_cards_are_not_displayed_on_accounts_page(): void
    {
        $user = User::factory()->create();
        app(FinancialAccountService::class)->create($user, ['name' => 'Cartão principal', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1]);
        $this->actingAs($user)->get('/contas')->assertOk()->assertDontSee('Os meus cartões')->assertDontSee('Cartão principal');
    }

    public function test_credit_cards_are_displayed_on_their_own_page(): void
    {
        $user = User::factory()->create();
        app(FinancialAccountService::class)->create($user, ['name' => 'Cartão principal', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1]);
        $this->actingAs($user)->get('/cartoes')->assertOk()->assertSee('Cartões')->assertSee('Cartão principal')->assertSee('Utilizado')->assertSee('Disponível');
    }

    public function test_card_limit_cannot_be_reduced_below_current_utilization(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, ['name' => 'Cartão principal', 'type' => 'credit_card', 'opening_debt_amount' => '350.00', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1]);
        $this->actingAs($user)->putJson('/api/financial/accounts/'.$card->id.'/card-settings', ['credit_limit' => '300.00', 'closing_day' => 10, 'due_day' => 1])->assertUnprocessable();
    }

    public function test_initial_card_debt_can_be_corrected_before_any_movement(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão corrigível', 'type' => 'credit_card', 'opening_debt_amount' => '350.00',
            'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1,
        ]);

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$card->id.'/card-settings', [
            'credit_limit' => '1000.00', 'opening_debt_amount' => '275.50', 'closing_day' => 30, 'due_day' => 31,
        ])->assertOk();

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $card->id, 'opening_debt_amount' => '275.50', 'opening_balance' => '-275.50', 'closing_day' => 30, 'due_day' => 31,
        ]);
    }

    public function test_initial_card_debt_cannot_be_changed_after_a_card_movement(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão usado', 'type' => 'credit_card', 'opening_debt_amount' => '100.00',
            'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra posterior', 'amount' => '20.00', 'movement_date' => '2026-11-16',
        ])->assertCreated();

        $this->actingAs($user)->putJson('/api/financial/accounts/'.$card->id.'/card-settings', [
            'credit_limit' => '1000.00', 'opening_debt_amount' => '200.00', 'closing_day' => 10, 'due_day' => 1,
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'A dívida inicial só pode ser corrigida antes de existirem movimentos no cartão.']);
    }

    public function test_account_movement_details_modal_and_inline_edit_are_available_for_pending_movements(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta de teste',
            'type' => 'bank',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $movement = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Movimento editável',
            'amount' => '15.00',
            'movement_date' => '2026-11-19',
            'source_account_id' => $account->id,
        ]);

        $this->actingAs($user)
            ->get(route('financial.accounts.show', $account->id))
            ->assertOk()
            ->assertSee('data-bs-toggle="modal"', false)
            ->assertSee('data-bs-target="#accountMovementDetailsModal"', false)
            ->assertSee('data-editable="1"', false)
            ->assertSee(route('financial.movements.update', $movement->id), false)
            ->assertSee('Editar')
            ->assertSee('Cancelar')
            ->assertDontSee('accountMovementDetailsStatus', false)
            ->assertDontSee('Este movimento já foi realizado ou está associado', false);
    }

    public function test_pending_movement_can_be_edited_through_the_existing_financial_endpoint(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta de teste',
            'type' => 'bank',
            'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $movement = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Descrição antiga',
            'amount' => '15.00',
            'movement_date' => '2026-11-19',
            'source_account_id' => $account->id,
        ]);

        $this->actingAs($user)->putJson(route('financial.movements.update', $movement->id), [
            'description' => 'Descrição corrigida',
            'amount' => '18.50',
            'movement_date' => '2026-11-18',
            'notes' => 'Nota atualizada',
        ])->assertOk();

        $this->assertDatabaseHas('movimentos', [
            'id' => $movement->id,
            'description' => 'Descrição corrigida',
            'amount' => '18.50',
            'notes' => 'Nota atualizada',
            'status' => 'pending',
        ]);
    }

    public function test_accounts_page_shows_bank_to_cash_transfer_form(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialAccountService::class);
        $service->create($user, [
            'name' => 'Banco', 'type' => 'bank', 'opening_balance' => '100.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $service->create($user, [
            'name' => 'Carteira', 'type' => 'cash', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
        ]);

        $this->actingAs($user)->get('/contas')
            ->assertOk()
            ->assertSee('Transferir')
            ->assertSee('Banco')
            ->assertSee('Carteira');
    }

    public function test_invoice_payment_can_be_corrected_from_account_history_and_recalculates_balances(): void
    {
        $user = User::factory()->create();
        $bank = app(FinancialAccountService::class)->create($user, [
            'name' => 'Banco principal', 'type' => 'bank', 'opening_balance' => '200.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão de teste', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $invoices = app(\App\Services\Financial\CardInvoiceService::class);
        $invoices->purchase($user, [
            'account_id' => $card->id, 'description' => 'Compra de teste',
            'amount' => '20.00', 'movement_date' => '2026-11-17',
        ]);
        $invoice = $user->faturas()->firstOrFail();
        $payment = $invoices->pay($user, $invoice, $bank->id, '2026-11-18');

        $this->actingAs($user)->get(route('financial.accounts.show', $bank->id))
            ->assertOk()
            ->assertSee('data-invoice-payment="1"', false)
            ->assertSee('Editar')
            ->assertSee('Cancelar')
            ->assertDontSee('accountMovementDetailsStatus', false);

        $this->putJson(route('financial.movements.update', $payment->id), [
            'amount' => '15.00',
            'movement_date' => '2026-11-19',
            'source_account_id' => $bank->id,
        ])->assertOk()->assertJsonPath('data.amount', '15.00');

        $this->assertSame('15.00', $payment->fresh()->amount);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame('185.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-5.00', app(\App\Services\Financial\AccountBalanceService::class)->balance($card->fresh()));
        $this->assertDatabaseHas('historico_movimentos', [
            'movement_id' => $payment->id,
            'action' => 'invoice_payment_updated',
        ]);
    }
}

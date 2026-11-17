<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\CardInvoiceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\TestCase;

class FinancialAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_acceptance_trusted_https_proxy_generates_secure_auth_actions_and_assets(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Host' => 'meu-bolso.example.test',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ])
            ->get('/login');

        $response->assertOk()
            ->assertSee('action="https://meu-bolso.example.test/login"', false)
            ->assertSee('meu-bolso.example.test', false)
            ->assertSee('registo', false)
            ->assertSee('href="https://meu-bolso.example.test/css/styles.css"', false);
    }

    private function bank(User $user, string $name = 'Conta principal', string $balance = '1000.00'): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => $name,
            'type' => 'bank',
            'opening_balance' => $balance,
            'opening_balance_date' => '2026-11-01',
        ]);
    }

    public function test_acceptance_cash_purchase_creates_one_settled_expense_and_no_obligation(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = $this->bank($user, balance: '200.00');

        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Compra a pronto',
            'amount' => '150.00',
            'category' => 'alimentacao',
            'expense_date' => '2026-11-09',
            'account_id' => $account->id,
            'paid_now' => '1',
            'payment_account_id' => $account->id,
            'payment_amount' => '150.00',
            'payment_date' => '2026-11-09',
        ])->assertRedirect(route('despesas.index'));

        $this->assertDatabaseCount('dividas', 0);
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id,
            'type' => 'expense',
            'status' => 'settled',
            'source_account_id' => $account->id,
            'amount' => '150.00',
        ]);
        $this->assertSame('50.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_expense_behavior_is_derived_from_the_selected_category_not_the_request(): void
    {
        $user = User::factory()->create();
        $category = $user->categorias()->where('type', 'despesa')->firstOrFail();
        $category->update(['expense_behavior' => 'fixed']);

        $this->actingAs($user)->postJson(route('despesas.store'), [
            'description' => 'Renda mensal',
            'amount' => '850.00',
            'category_id' => $category->id,
            'expense_date' => '2026-11-17',
            'paid_now' => '0',
            'expense_behavior' => 'variable',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id,
            'type' => 'expense',
            'category_id' => $category->id,
            'expense_behavior' => 'fixed',
            'status' => 'pending',
        ]);
    }

    public function test_acceptance_deferred_purchase_creates_one_unpaid_installment_without_changing_cash(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '200.00');

        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Eletricidade',
            'total_amount' => '90.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);

        $this->assertCount(1, $debt->parcelas);
        $this->assertSame('pending', $debt->parcelas->first()->status);
        $this->assertDatabaseCount('movimentos', 1);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_acceptance_partial_obligation_payment_leaves_the_remaining_balance(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '200.00');
        $service = app(FinancialCommitmentService::class);
        $debt = $service->create($user, [
            'name' => 'Eletricidade',
            'total_amount' => '90.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'deferred',
        ]);

        $installment = $debt->parcelas->first();
        $service->payInstallment($user, $debt, $installment, [
            'account_id' => $account->id,
            'amount' => '40.00',
            'payment_date' => '2026-11-16',
        ]);

        $this->assertSame('partial', $installment->fresh()->status);
        $this->assertSame('50.00', number_format((float) $installment->fresh()->amount - (float) $installment->fresh()->paid_amount, 2, '.', ''));
        $this->assertSame('160.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_acceptance_installment_purchase_creates_pending_movements_per_installment_without_total_duplicate(): void
    {
        $user = User::factory()->create();
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Computador',
            'total_amount' => '14000.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'installments',
            'installments_count' => 119,
        ]);

        $this->assertCount(119, $debt->parcelas);
        $this->assertSame('14000.00', number_format((float) $debt->parcelas->sum('amount'), 2, '.', ''));
        $this->assertDatabaseMissing('movimentos', ['amount' => '14000.00', 'status' => 'pending']);
        $this->assertSame(119, $user->movimentos()->where('debt_id', $debt->id)->where('status', 'pending')->count());
    }

    public function test_acceptance_installment_payment_creates_only_the_amount_paid_and_links_the_installment(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '100.00');
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Móvel',
            'total_amount' => '100.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-25',
            'payment_condition' => 'installments',
            'installments_count' => 2,
        ]);

        $installment = $debt->parcelas->first();
        app(FinancialCommitmentService::class)->payInstallment($user, $debt, $installment, [
            'account_id' => $account->id,
            'amount' => '20.00',
            'payment_date' => '2026-11-16',
        ]);

        $forecast = $user->movimentos()->where('installment_id', $installment->id)->firstOrFail();
        $this->assertSame('50.00', (string) $forecast->amount);
        $this->assertSame('partial', $forecast->fresh()->status);
        $this->assertTrue((bool) $forecast->fresh()->is_settlement_parent);
        $this->assertSame('20.00', number_format((float) $forecast->settlements()->where('status', 'settled')->sum('amount'), 2, '.', ''));
        $this->assertDatabaseMissing('movimentos', ['amount' => '100.00']);
    }

    public function test_acceptance_card_purchase_and_invoice_payment_do_not_create_a_second_expense(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user, balance: '100.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa',
            'type' => 'credit_card',
            'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
            'closing_day' => 25,
            'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $invoices = app(CardInvoiceService::class);
        $invoices->purchase($user, [
            'account_id' => $card->id,
            'description' => 'Compra no cartão',
            'amount' => '100.00',
            'movement_date' => '2026-11-12',
        ]);

        $invoice = $user->faturas()->firstOrFail();
        $invoices->pay($user, $invoice, $bank->id, '2026-12-01');

        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame(1, $user->movimentos()->where('type', 'expense')->count());
        $this->assertSame(1, $user->movimentos()->where('type', 'transfer')->count());
    }

    public function test_acceptance_weekly_edit_preserves_frequency_for_future_installments(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialCommitmentService::class);
        $debt = $service->create($user, [
            'name' => 'Compra semanal',
            'total_amount' => '30.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-05',
            'payment_condition' => 'installments',
            'installments_count' => 3,
            'installment_frequency' => 'weekly',
        ]);

        $service->update($user, $debt, ['name' => 'Compra semanal editada', 'due_date' => '2026-11-06']);
        $dates = $debt->fresh()->parcelas->sortBy('installment_number')->pluck('due_date')->map(fn ($date) => $date->toDateString())->all();

        $this->assertSame(['2026-11-06', '2026-11-13', '2026-11-20'], $dates);

        $biweekly = $service->create($user, [
            'name' => 'Compra quinzenal',
            'total_amount' => '30.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-05',
            'payment_condition' => 'installments',
            'installments_count' => 3,
            'installment_frequency' => 'biweekly',
        ]);
        $service->update($user, $biweekly, ['name' => 'Compra quinzenal editada', 'due_date' => '2026-11-06']);
        $biweeklyDates = $biweekly->fresh()->parcelas->sortBy('installment_number')->pluck('due_date')->map(fn ($date) => $date->toDateString())->all();

        $this->assertSame(['2026-11-06', '2026-11-20', '2026-12-04'], $biweeklyDates);
    }

    public function test_acceptance_commitment_summary_includes_items_beyond_the_first_page(): void
    {
        $user = User::factory()->create();
        $service = app(FinancialCommitmentService::class);
        for ($i = 1; $i <= 21; $i++) {
            $service->create($user, [
                'name' => 'Obrigação '.$i,
                'total_amount' => '10.00',
                'start_date' => '2026-11-01',
                'due_date' => '2026-12-01',
                'payment_condition' => 'installments',
                'installments_count' => 2,
            ]);
        }

        // Uma obrigação única com vencimento futuro não integra os indicadores de parcelamentos.
        $service->create($user, [
            'name' => 'Despesa única futura',
            'total_amount' => '75.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-12-01',
            'payment_condition' => 'deferred',
        ]);

        $this->withoutMiddleware(\App\Http\Middleware\EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)
            ->get(route('financial.commitments.page'))
            ->assertOk()
            ->assertSee('210,00')
            ->assertDontSee('Despesa única futura');

        $this->assertSame(210.0, $response->viewData('summary')['open']);
    }

    public function test_acceptance_financial_route_excludes_card_balance_and_projects_future_obligations(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user, balance: '1000.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa',
            'type' => 'credit_card',
            'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01',
            'closing_day' => 25,
            'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        app(CardInvoiceService::class)->purchase($user, [
            'account_id' => $card->id,
            'description' => 'Compra no cartão',
            'amount' => '100.00',
            'movement_date' => '2026-11-12',
        ]);
        app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Seguro',
            'total_amount' => '90.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-12-10',
            'payment_condition' => 'deferred',
        ]);

        $this->withoutMiddleware(\App\Http\Middleware\EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.route', ['mes' => '2026-12', 'modo' => 1]));

        $response->assertOk();
        $this->assertSame(1000.0, $response->viewData('openingBalance'));
        $days = $response->viewData('days');
        $this->assertSame(-100.0, $days['2026-12']['2026-12-05']['cash_delta']);
        $this->assertSame(-90.0, $days['2026-12']['2026-12-10']['cash_delta']);
    }

    public function test_acceptance_dashboard_and_movements_read_canonical_financial_movements(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '1000.00');
        $service = app(\App\Services\Financial\FinancialMovementService::class);
        $service->create($user, [
            'type' => 'income', 'description' => 'Salário canónico', 'amount' => '200.00',
            'movement_date' => '2026-11-09', 'destination_account_id' => $account->id,
        ]);
        $service->create($user, [
            'type' => 'expense', 'description' => 'Despesa canónica', 'amount' => '50.00',
            'movement_date' => '2026-11-09', 'source_account_id' => $account->id,
        ]);

        $this->withoutMiddleware(\App\Http\Middleware\EnsureFinancialDataReviewed::class);
        $dashboard = $this->actingAs($user)->get(route('dashboard', ['mes' => '2026-11']));
        $dashboard->assertOk()->assertSee('Salário canónico')->assertSee('Despesa canónica');
        $this->assertSame(50.0, $dashboard->viewData('totalDespesas'));
        $this->assertSame(200.0, $dashboard->viewData('totalReceitas'));

        $movementsPage = $this->actingAs($user)->get(route('dashboard.movimentos', ['mes' => '2026-11']));
        $movementsPage->assertOk()
            ->assertSee('data-total="200"', false)
            ->assertSee('data-total="50"', false);

        $movements = $this->actingAs($user)->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 10]));
        $movements->assertOk()->assertJsonPath('total_despesas', 50)->assertJsonPath('total_receitas', 200);
        $this->assertCount(2, $movements->json('data'));
    }

    public function test_acceptance_legacy_form_routes_installments_to_commitment_without_creating_legacy_expense(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('despesas.store'), [
            'description' => 'Computador',
            'amount' => '14000.00',
            'category' => 'compras',
            'expense_date' => '2026-11-16',
            'payment_condition' => 'installments',
            'payment_method' => 'transfer',
            'due_date' => '2026-11-25',
            'installments_count' => 119,
            'installment_frequency' => 'monthly',
            'paid_now' => '0',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseCount('despesas', 0);
        $this->assertDatabaseCount('movimentos', 119);
        $this->assertDatabaseCount('dividas', 1);
        $this->assertDatabaseCount('parcelas_divida', 119);
    }

    public function test_acceptance_diagnostic_reports_orphans_and_incomplete_debts_without_mutating_data(): void
    {
        $user = User::factory()->create();
        $user->despesas()->create([
            'description' => 'Legado sem ligação', 'amount' => '20.00', 'currency' => 'EUR',
            'category' => 'casa', 'expense_date' => '2026-11-16',
        ]);
        $debt = \App\Models\Divida::query()->create([
            'user_id' => $user->id, 'name' => 'Obrigação incompleta', 'type' => 'purchase',
            'payment_condition' => 'deferred', 'total_amount' => '90.00',
            'start_date' => '2026-11-16', 'due_date' => '2026-11-25', 'status' => 'active',
        ]);

        $before = [$user->despesas()->count(), \App\Models\Divida::query()->whereKey($debt->id)->count()];
        $report = app(\App\Services\Financial\FinancialDataDiagnosticService::class)->report($user->id);
        $after = [$user->despesas()->count(), \App\Models\Divida::query()->whereKey($debt->id)->count()];

        $this->assertSame(1, $report[0]['counts']['orphan_legacy_expenses']);
        $this->assertSame(1, $report[0]['counts']['incomplete_debts']);
        $this->assertSame($before, $after);
        $this->artisan('financial:diagnose-data', ['--user' => $user->id, '--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('orphan_legacy_expenses');
    }

    public function test_acceptance_deterministic_migration_is_explicit_audited_and_idempotent(): void
    {
        $user = User::factory()->create(['financial_cutoff_date' => '2026-11-12']);
        $legacyAccount = ContaFinanceira::query()->create([
            'user_id' => $user->id, 'name' => 'Histórico anterior à migração', 'type' => 'legacy', 'currency' => 'EUR',
            'opening_balance' => '0.00', 'opening_balance_date' => '2026-11-12', 'is_operational' => false, 'status' => 'historical',
        ]);
        $expense = $user->despesas()->create([
            'description' => 'Despesa órfã recuperável', 'amount' => '25.00', 'currency' => 'EUR',
            'category' => 'casa', 'expense_date' => '2026-11-10',
        ]);

        $this->artisan('financial:migrate-deterministic-data', ['--user' => $user->id, '--json' => true])
            ->assertExitCode(0)->expectsOutputToContain('deterministic');
        $this->assertDatabaseCount('movimentos', 0);

        $this->artisan('financial:migrate-deterministic-data', ['--user' => $user->id, '--apply' => true, '--json' => true])
            ->assertExitCode(0)->expectsOutputToContain('applied_count');
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'type' => 'expense', 'status' => 'settled', 'amount' => '25.00',
            'source_account_id' => $legacyAccount->id, 'legacy_type' => 'despesa', 'legacy_id' => $expense->id,
            'is_historical' => true,
        ]);
        $this->assertDatabaseHas('historico_movimentos', [
            'user_id' => $user->id, 'action' => 'deterministic_legacy_migration',
        ]);

        app(\App\Services\Financial\FinancialDataMigrationService::class)->migrate($user->id);
        $this->assertDatabaseCount('movimentos', 1);
        $this->assertDatabaseCount('historico_movimentos', 1);
    }

    public function test_acceptance_migration_reconstructs_only_single_installment_and_reports_ambiguous_debts(): void
    {
        $user = User::factory()->create();
        $safeDebt = \App\Models\Divida::query()->create([
            'user_id' => $user->id, 'name' => 'Conta a prazo', 'type' => 'purchase', 'payment_condition' => 'deferred',
            'payment_method' => 'bank', 'installments_count' => 1, 'total_amount' => '90.00',
            'start_date' => '2026-11-01', 'due_date' => '2026-11-25', 'status' => 'active',
        ]);
        $ambiguousDebt = \App\Models\Divida::query()->create([
            'user_id' => $user->id, 'name' => 'Compra parcelada sem plano', 'type' => 'purchase', 'payment_condition' => 'installments',
            'payment_method' => 'card', 'installments_count' => 3, 'total_amount' => '300.00',
            'start_date' => '2026-11-01', 'due_date' => '2026-11-25', 'status' => 'active',
        ]);

        $service = app(\App\Services\Financial\FinancialDataMigrationService::class);
        $plan = $service->plan($user->id)[0];
        $this->assertSame(1, $plan['counts']['deterministic']);
        $this->assertSame(1, $plan['counts']['ambiguous']);
        $this->assertSame($ambiguousDebt->id, $plan['ambiguous'][0]['debt_id']);
        $result = $service->migrate($user->id)[0];
        $this->assertSame(1, $result['applied_count']);
        $this->assertDatabaseHas('parcelas_divida', [
            'user_id' => $user->id, 'debt_id' => $safeDebt->id, 'installment_number' => 1,
            'amount' => '90.00', 'status' => 'pending',
        ]);
        $installmentDate = \Illuminate\Support\Carbon::parse(\Illuminate\Support\Facades\DB::table('parcelas_divida')->where('debt_id', $safeDebt->id)->value('due_date'))->toDateString();
        $this->assertSame('2026-11-25', $installmentDate);
        $this->assertDatabaseCount('parcelas_divida', 1);
        $this->assertDatabaseMissing('parcelas_divida', ['debt_id' => $ambiguousDebt->id]);
    }

    public function test_acceptance_legacy_form_cannot_replace_a_settled_movement_with_changed_values(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '100.00');
        $this->actingAs($user)->postJson(route('despesas.store'), [
            'description' => 'Compra confirmada', 'amount' => '50.00', 'category' => 'casa',
            'expense_date' => '2026-11-16', 'account_id' => $account->id, 'paid_now' => '1',
        ])->assertOk();
        $expense = $user->despesas()->where('description', 'Compra confirmada')->firstOrFail();
        $movement = $user->movimentos()->where('legacy_type', 'despesa')->where('legacy_id', $expense->id)->firstOrFail();

        $this->putJson(route('despesas.update', $expense->id), [
            'description' => 'Compra alterada', 'amount' => '55.00', 'category' => 'casa',
            'expense_date' => '2026-11-16', 'account_id' => $account->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('despesas', ['id' => $expense->id, 'description' => 'Compra confirmada', 'amount' => '50.00']);
        $this->assertDatabaseHas('movimentos', ['id' => $movement->id, 'status' => 'settled', 'amount' => '50.00']);
        $this->assertSame(1, $user->movimentos()->count());
        $this->assertSame('50.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_acceptance_cancelled_installment_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user);
        $service = app(FinancialCommitmentService::class);
        $debt = $service->create($user, [
            'name' => 'Obrigação cancelável', 'total_amount' => '90.00',
            'start_date' => '2026-11-01', 'due_date' => '2026-11-25', 'payment_condition' => 'deferred',
        ]);
        $installment = $debt->parcelas->first();
        $service->cancel($user, $debt);

        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [
            'id' => $debt->id, 'installmentId' => $installment->id,
        ]), [
            'account_id' => $account->id, 'amount' => '20.00', 'payment_date' => '2026-11-16',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('movimentos', 1);
        $this->assertDatabaseHas('parcelas_divida', ['id' => $installment->id, 'status' => 'cancelled', 'paid_amount' => '0.00']);
        $this->assertDatabaseHas('movimentos', ['debt_id' => $debt->id, 'installment_id' => $installment->id, 'status' => 'void']);
        $this->assertDatabaseHas('dividas', ['id' => $debt->id, 'status' => 'cancelled']);
    }

    public function test_acceptance_commitment_and_initial_payment_are_created_atomically(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '500.00');

        $response = $this->actingAs($user)->postJson(route('financial.commitments.store'), [
            'name' => 'Conta parcialmente paga', 'total_amount' => '90.00',
            'start_date' => '2026-11-01', 'due_date' => '2026-11-25', 'payment_condition' => 'deferred',
            'paid_now' => '0',
            'initial_payment' => ['account_id' => $account->id, 'amount' => '40.00', 'payment_date' => '2026-11-16'],
        ])->assertCreated()->assertJsonPath('data.parcelas.0.status', 'partial');
        $debtId = $response->json('data.id');
        $installmentId = $response->json('data.parcelas.0.id');

        $this->assertDatabaseCount('dividas', 1);
        $this->assertDatabaseCount('movimentos', 2);
        $this->assertDatabaseHas('movimentos', [
            'type' => 'expense', 'status' => 'settled', 'amount' => '40.00', 'source_account_id' => $account->id,
        ]);
        $forecast = $user->movimentos()->where('debt_id', $debtId)->where('installment_id', $installmentId)->firstOrFail();
        $this->assertSame('partial', $forecast->status);
        $this->assertSame('90.00', (string) $forecast->amount);
        $this->assertSame('40.00', number_format((float) $forecast->settlements()->where('status', 'settled')->sum('amount'), 2, '.', ''));
        $this->assertSame('460.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_acceptance_failed_initial_payment_rolls_back_the_new_commitment(): void
    {
        $user = User::factory()->create();
        $account = $this->bank($user, balance: '500.00');

        $this->actingAs($user)->postJson(route('financial.commitments.store'), [
            'name' => 'Pagamento acima da prestação', 'total_amount' => '90.00',
            'start_date' => '2026-11-01', 'due_date' => '2026-11-25', 'payment_condition' => 'deferred',
            'initial_payment' => ['account_id' => $account->id, 'amount' => '100.00', 'payment_date' => '2026-11-16'],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('dividas', 0);
        $this->assertDatabaseCount('parcelas_divida', 0);
        $this->assertDatabaseCount('movimentos', 0);
        $this->assertSame('500.00', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_acceptance_reversing_opening_debt_payment_restores_card_debt_and_payment_counter(): void
    {
        $user = User::factory()->create();
        $bank = $this->bank($user, balance: '500.00');
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_debt_amount' => '300.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5, 'credit_limit' => '1000.00',
        ]);
        $payment = app(CardInvoiceService::class)->payOpeningDebt($user, $card->id, $bank->id, '2026-11-16', '100.00');
        $this->assertSame($card->id, $payment->opening_debt_card_id);

        // Simula um pagamento antigo criado antes da coluna tipada; a inferência exige
        // correspondência exata entre descrição gerada e cartão de destino.
        \Illuminate\Support\Facades\DB::table('movimentos')->where('id', $payment->id)->update(['opening_debt_card_id' => null]);
        app(FinancialMovementService::class)->reverse($user, $payment->fresh(), 'Correção do pagamento');

        $this->assertDatabaseHas('contas_financeiras', ['id' => $card->id, 'opening_debt_paid' => '0.00']);
        $this->assertSame('500.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-300.00', app(AccountBalanceService::class)->balance($card->fresh()));
        $this->assertDatabaseHas('movimentos', ['id' => $payment->id, 'status' => 'settled', 'opening_debt_card_id' => $card->id]);
    }
}

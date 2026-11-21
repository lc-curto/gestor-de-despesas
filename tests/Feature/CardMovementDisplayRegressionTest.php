<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFinancialDataReviewed;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardMovementDisplayRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $initialOutputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialOutputBufferLevel = ob_get_level();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->assertSame(
            $this->initialOutputBufferLevel,
            ob_get_level(),
            'O teste de cartões deixou um output buffer aberto.'
        );
    }

    public function test_card_purchases_are_in_card_history_not_movements_and_invoice_payment_remains_visible(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $accounts = app(FinancialAccountService::class);
        $bank = $accounts->create($user, [
            'name' => 'Banco', 'type' => 'bank', 'opening_balance' => '1000.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $card = $accounts->create($user, [
            'name' => 'Visa', 'type' => 'credit_card', 'opening_balance' => '0.00',
            'opening_balance_date' => '2026-11-01', 'closing_day' => 25, 'due_day' => 5,
            'credit_limit' => '500.00',
        ]);
        $shopping = $user->categorias()->where('slug', 'compras')->firstOrFail();
        $house = $user->categorias()->where('slug', 'casa')->firstOrFail();
        $incomeCategory = $user->categorias()->where('slug', 'salario')->firstOrFail();
        $this->actingAs($user);

        $purchase = $this->postJson(route('financial.cards.purchase'), [
            'account_id' => $card->id, 'description' => 'Compra canónica no cartão',
            'amount' => '60.00', 'movement_date' => '2026-11-10', 'category_id' => $shopping->id,
        ])->assertCreated();
        $invoiceId = $purchase->json('data.invoice_id');
        $this->assertNotNull($invoiceId);

        $this->postJson(route('despesas.store'), [
            'description' => 'Compra legada no cartão', 'amount' => '40.00',
            'category_id' => $shopping->id, 'expense_date' => '2026-11-11', 'account_id' => $card->id,
        ])->assertOk();
        $legacy = $user->despesas()->where('description', 'Compra legada no cartão')->firstOrFail();
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'legacy_type' => 'despesa', 'legacy_id' => $legacy->id,
            'invoice_id' => $invoiceId,
        ]);

        $this->postJson(route('despesas.store'), [
            'description' => 'Despesa legada de banco', 'amount' => '17.00',
            'category_id' => $house->id, 'expense_date' => '2026-11-12', 'account_id' => $bank->id,
        ])->assertOk();
        $movements = app(FinancialMovementService::class);
        $movements->create($user, [
            'type' => 'expense', 'description' => 'Despesa canónica de banco', 'amount' => '13.00',
            'movement_date' => '2026-11-13', 'source_account_id' => $bank->id, 'category_id' => $house->id,
        ]);
        $movements->create($user, [
            'type' => 'income', 'description' => 'Receita de banco', 'amount' => '200.00',
            'movement_date' => '2026-11-14', 'destination_account_id' => $bank->id, 'category_id' => $incomeCategory->id,
        ]);
        $other = User::factory()->create();
        $otherBank = $accounts->create($other, [
            'name' => 'Banco privado', 'type' => 'bank', 'opening_balance' => '1000.00',
            'opening_balance_date' => '2026-11-01',
        ]);
        $movements->create($other, [
            'type' => 'expense', 'description' => 'Despesa de outro utilizador', 'amount' => '999.00',
            'movement_date' => '2026-11-16', 'source_account_id' => $otherBank->id,
        ]);
        // Uma previsão associada a cartão, ainda sem fatura, não é compra liquidada.
        $pending = $movements->createPending($user, [
            'type' => 'expense', 'description' => 'Previsão sem fatura', 'amount' => '7.00',
            'movement_date' => '2026-11-15', 'source_account_id' => $card->id, 'category_id' => $house->id,
        ]);
        $this->assertNull($pending->invoice_id);

        $payment = $this->postJson(route('financial.invoices.pay', $invoiceId), [
            'account_id' => $bank->id, 'amount' => '35.00', 'payment_date' => '2026-11-16',
        ])->assertOk()->assertJsonPath('data.type', 'transfer')->assertJsonPath('data.invoice_id', $invoiceId);
        $paymentId = $payment->json('data.id');

        $first = $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 2]));
        $first->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 7)
            ->assertJsonPath('total_despesas_filtradas', 6)->assertJsonPath('total_receitas_filtradas', 1)
            ->assertJsonPath('has_more', true)->assertJsonPath('data.0.id', $paymentId)
            ->assertJsonPath('data.0.is_invoice_payment', 1)->assertJsonPath('data.0.tipo', 'despesa')
            ->assertJsonPath('total_despesas', 137)->assertJsonPath('total_receitas', 200);
        $second = $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 2, 'page' => 2]));
        $second->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 7)->assertJsonPath('has_more', true);
        $third = $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 2, 'page' => 3]));
        $third->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 7)->assertJsonPath('has_more', false);
        $items = collect($first->json('data'))->concat($second->json('data'))->concat($third->json('data'));
        $this->assertCount(7, $items->pluck('id')->unique());
        $this->assertEqualsCanonicalizing([
            'Pagamento da fatura Visa · 11/2026', 'Previsão sem fatura', 'Receita de banco',
            'Despesa canónica de banco', 'Despesa legada de banco',
            'Compra canónica no cartão', 'Compra legada no cartão',
        ], $items->pluck('description')->all());
        $this->assertNotContains('Despesa de outro utilizador', $items->pluck('description')->all());
        $this->assertSame($bank->id, $items->firstWhere('id', $paymentId)['account_id']);

        // O pagamento não tem categoria: não pode aumentar a contagem de um filtro específico.
        $shoppingFilter = $this->getJson(route('dashboard.movimentos.data', [
            'mes' => '2026-11', 'categoria' => $shopping->id, 'limit' => 1,
        ]));
        $shoppingFilter->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2)
            ->assertJsonPath('total_despesas_filtradas', 2)->assertJsonPath('has_more', true)
            ->assertJsonPath('total_despesas', 137);
        $this->getJson(route('dashboard.movimentos.data', [
            'mes' => '2026-11', 'categoria' => $shopping->id, 'limit' => 1, 'page' => 2,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2)->assertJsonPath('has_more', false);
        $houseFilter = $this->getJson(route('dashboard.movimentos.data', [
            'mes' => '2026-11', 'categoria' => $house->id, 'limit' => 2,
        ]));
        $houseFilter->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 3)
            ->assertJsonPath('total_despesas_filtradas', 3)->assertJsonPath('has_more', true);
        $this->getJson(route('dashboard.movimentos.data', [
            'mes' => '2026-11', 'categoria' => $house->id, 'limit' => 2, 'page' => 2,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 3)->assertJsonPath('has_more', false);

        $page = $this->get(route('dashboard.movimentos', ['mes' => '2026-11', 'limit' => 10]));
        $page->assertOk()->assertSee('Compra canónica no cartão')->assertSee('Compra legada no cartão')
            ->assertSee('Pagamento da fatura Visa')->assertSee('Despesa legada de banco')
            ->assertSee('Despesa canónica de banco')->assertSee('Receita de banco')
            ->assertSee('data-total="137"', false)->assertSee('data-total="200"', false);
        $this->assertSame(7, $page->viewData('totalMovimentos'));
        $this->assertSame(6, $page->viewData('totalDespesasFiltradas'));
        $filteredPage = $this->get(route('dashboard.movimentos', ['mes' => '2026-11', 'categoria' => $shopping->id]));
        $filteredPage->assertOk();
        $this->assertSame(2, $filteredPage->viewData('totalMovimentos'));
        $this->assertCount(2, $filteredPage->viewData('movimentos'));
        $incomePage = $this->get(route('dashboard.movimentos', ['mes' => '2026-11', 'tipo' => 'receita']));
        $incomePage->assertOk();
        $this->assertSame(['Receita de banco'], $incomePage->viewData('movimentosVisiveis')->pluck('description')->all());
        $expensePage = $this->get(route('dashboard.movimentos', ['mes' => '2026-11', 'tipo' => 'despesa']));
        $expensePage->assertOk();
        $this->assertEqualsCanonicalizing([
            'Pagamento da fatura Visa · 11/2026', 'Previsão sem fatura',
            'Despesa canónica de banco', 'Despesa legada de banco',
            'Compra canónica no cartão', 'Compra legada no cartão',
        ], $expensePage->viewData('movimentosVisiveis')->pluck('description')->all());
        $dashboard = $this->get(route('dashboard', ['mes' => '2026-11', 'limit' => 10]));
        $dashboard->assertOk()->assertSee('Compra canónica no cartão')->assertSee('Compra legada no cartão');
        $this->assertSame(7, $dashboard->viewData('totalMovimentos'));
        $this->assertSame(137.0, $dashboard->viewData('totalDespesas'));

        $this->get(route('financial.cards.page'))->assertOk()->assertSee('Visa');
        $this->get(route('financial.accounts.show', $card->id))->assertOk()
            ->assertSee('Compra canónica no cartão')->assertSee('Compra legada no cartão')->assertSee('Pagamento da fatura Visa');
        $this->get(route('financial.accounts.show', $bank->id))->assertOk()->assertSee('Pagamento da fatura Visa');
        $invoices = $this->getJson(route('financial.invoices.index'));
        $invoices->assertOk()->assertJsonPath('data.data.0.id', $invoiceId)
            ->assertJsonPath('data.data.0.remaining_amount', '65.00')
            ->assertJsonPath('data.data.0.display_status', 'partial')
            ->assertJsonCount(2, 'data.data.0.movimentos');
        $this->assertEqualsCanonicalizing(['Compra canónica no cartão', 'Compra legada no cartão'],
            collect($invoices->json('data.data.0.movimentos'))->pluck('description')->all());
        $this->assertSame('1135.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('-65.00', app(AccountBalanceService::class)->balance($card->fresh()));

        // Em outro mês o pagamento permanece visível mas não se torna uma nova despesa económica.
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(12, 0));
        $finalPayment = $this->postJson(route('financial.invoices.pay', $invoiceId), [
            'account_id' => $bank->id, 'payment_date' => '2026-12-01',
        ])->assertOk()->assertJsonPath('data.type', 'transfer');
        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-12']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $finalPayment->json('data.id'))
            ->assertJsonPath('data.0.is_invoice_payment', 1)->assertJsonPath('total', 1)
            ->assertJsonPath('total_despesas', 0)->assertJsonPath('total_receitas', 0);
        $this->getJson(route('financial.invoices.index'))->assertOk()
            ->assertJsonPath('data.data.0.remaining_amount', '0.00')->assertJsonPath('data.data.0.display_status', 'paid');
        $this->assertSame('1070.00', app(AccountBalanceService::class)->balance($bank->fresh()));
        $this->assertSame('0.00', app(AccountBalanceService::class)->balance($card->fresh()));
    }
}

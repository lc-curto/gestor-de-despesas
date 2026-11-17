<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFinancialDataReviewed;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCommitmentsPageTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    public function test_purchase_card_has_one_primary_payment_action_and_six_history_rows(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = app(FinancialAccountService::class)->create($user, ['name' => 'Conta principal', 'type' => 'bank', 'opening_balance' => '200.00']);
        $debt = app(FinancialCommitmentService::class)->create($user, ['name' => 'Computador', 'total_amount' => '120.00', 'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(), 'payment_condition' => 'installments', 'installments_count' => 6]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $xpath = $this->document($this->actingAs($user)->get(route('financial.commitments.page'))->assertOk()->getContent());
        $this->assertCount(1, $xpath->query('//article[contains(@class,"commitment-card")]'));
        $this->assertStringContainsString('0/6', $xpath->query('//article[contains(@class,"commitment-card")]')->item(0)->textContent);
        $this->assertCount(1, $xpath->query('//button[contains(@class,"commitment-pay-next")]'));
        $this->assertCount(1, $xpath->query('//form[contains(@class,"js-api-form")]')); // Apenas o formulário único do modal de pagamento.
        $this->assertCount(6, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " commitment-installment ")]'));
        $this->assertSame('0.00', $debt->fresh()->parcelas->first()->paid_amount);
    }

    public function test_partial_payment_updates_the_remaining_amount_in_the_correct_disclosure(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = app(FinancialAccountService::class)->create($user, ['name' => 'Carteira', 'type' => 'cash', 'opening_balance' => '200.00']);
        $debt = app(FinancialCommitmentService::class)->create($user, ['name' => 'Móvel', 'total_amount' => '60.00', 'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(), 'payment_condition' => 'installments', 'installments_count' => 3]);
        $first = $debt->parcelas->sortBy('installment_number')->first();
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $endpoint = route('financial.commitments.installments.pay', [$debt->id, $first->id]);
        $this->actingAs($user)->postJson($endpoint, ['amount' => '5.00', 'account_id' => $account->id, 'payment_date' => now()->toDateString()])->assertOk();
        $xpath = $this->document($this->get(route('financial.commitments.page'))->assertOk()->getContent());
        $paymentButton = $xpath->query('//button[contains(@class,"commitment-pay-next")]')->item(0);
        $this->assertNotNull($paymentButton);
        $this->assertSame('15.00', $paymentButton->getAttribute('data-remaining'));
        $this->postJson($endpoint, ['amount' => '15.00', 'account_id' => $account->id, 'payment_date' => now()->toDateString()])->assertOk();
        $updatedButton = $this->document($this->get(route('financial.commitments.page'))->getContent())->query('//button[contains(@class,"commitment-pay-next")]')->item(0);
        $this->assertNotNull($updatedButton);
        $this->assertSame('20.00', $updatedButton->getAttribute('data-remaining'));
    }

    public function test_canonical_payment_changes_only_the_requested_amount_and_rejects_excess(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta', 'type' => 'bank', 'opening_balance' => '200.00',
        ]);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Compra', 'total_amount' => '100.00',
            'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'payment_condition' => 'deferred',
        ]);
        $part = $debt->parcelas->first();
        $endpoint = route('financial.commitments.installments.pay', [$debt->id, $part->id]);
        $data = ['account_id' => $account->id, 'payment_date' => now()->toDateString()];
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $this->actingAs($user);

        $this->postJson($endpoint, $data + ['amount' => '90,50'])->assertUnprocessable();
        $this->postJson($endpoint, $data + ['amount' => '9050.00'])->assertUnprocessable();
        $this->assertSame('0.00', $part->fresh()->paid_amount);
        $this->assertSame('200.00', app(AccountBalanceService::class)->balance($account->fresh()));

        $this->postJson($endpoint, $data + ['amount' => '90.50'])->assertOk();
        $this->assertSame('90.50', $part->fresh()->paid_amount);
        $this->assertSame('109.50', app(AccountBalanceService::class)->balance($account->fresh()));
        $this->assertDatabaseHas('movimentos', [
            'user_id' => $user->id, 'debt_id' => $debt->id, 'installment_id' => $part->id,
            'status' => 'settled', 'amount' => '90.50',
        ]);
        $this->postJson($endpoint, $data + ['amount' => '9.51'])->assertUnprocessable();
        $this->assertSame('90.50', $part->fresh()->paid_amount);
        $this->assertSame('109.50', app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_installment_due_today_is_upcoming_and_agrees_with_summary_and_filters(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-17 12:00:00'));
        $user = User::factory()->create();
        $service = app(FinancialCommitmentService::class);
        foreach ([
            ['Ontem', '20.00', '2026-11-16'],
            ['Hoje', '40.00', '2026-11-17'],
            ['Amanhã', '60.00', '2026-11-18'],
        ] as [$name, $amount, $date]) {
            $service->create($user, [
                'name' => $name, 'total_amount' => $amount, 'start_date' => '2026-11-16',
                'due_date' => $date, 'payment_condition' => 'installments', 'installments_count' => 2,
            ]);
        }
        $service->create($user, [
            'name' => 'Despesa única futura', 'total_amount' => '75.00',
            'start_date' => '2026-11-17', 'due_date' => '2026-12-17',
            'payment_condition' => 'deferred',
        ]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.commitments.page'))->assertOk();
        $summary = $response->viewData('summary');
        $this->assertSame(120.0, $summary['open']);
        $this->assertSame(10.0, $summary['overdue']);
        $this->assertSame(110.0, $summary['upcoming']);
        $response->assertDontSee('Despesa única futura');
        $xpath = $this->document($response->getContent());
        foreach (['Ontem' => ['16/11/2026', 'Atrasada'], 'Hoje' => ['17/11/2026', 'Pendente'], 'Amanhã' => ['18/11/2026', 'Pendente']] as $name => [$dueDate, $status]) {
            $badges = $xpath->query('//article[contains(@class,"commitment-card") and .//span[contains(@class,"commitment-card-name") and normalize-space(.)="'.$name.'"]]//div[contains(@class,"commitment-installment") and .//small[normalize-space(.)="'.$dueDate.'"]]//span[contains(concat(" ", normalize-space(@class), " "), " badge ")]');
            $this->assertCount(1, $badges, $name);
            $this->assertSame($status, trim($badges->item(0)->textContent), $name);
        }

        $activeView = $this->get(route('financial.commitments.page', ['status' => 'active']))->assertOk();
        $this->assertSame(3, $activeView->viewData('commitments')->total());
        $this->assertSame(120.0, $activeView->viewData('summary')['open']);
        $this->assertSame(0, $this->get(route('financial.commitments.page', ['status' => 'completed']))->viewData('commitments')->total());
    }

    public function test_missing_payment_account_keeps_guidance_and_accounts_link_visible(): void
    {
        $user = User::factory()->create();
        app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Compra a prazo', 'total_amount' => '90.00',
            'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'payment_condition' => 'installments', 'installments_count' => 2,
        ]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.commitments.page'))
            ->assertOk()->assertSee('Compra a prazo');
        $xpath = $this->document($response->getContent());

        $this->assertCount(0, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-payment-disclosure ")]'));
        $this->assertCount(1, $xpath->query('//article[contains(@class,"commitment-card")]'));
    }

    public function test_card_commitments_are_excluded_from_list_and_totals_without_modifying_them(): void
    {
        $user = User::factory()->create();
        app(FinancialAccountService::class)->create($user, ['name' => 'Conta', 'type' => 'bank']);
        $service = app(FinancialCommitmentService::class);
        $debts = [];
        foreach ([
            ['Sem método', null, '10.00'],
            ['Por banco', 'bank', '20.00'],
            ['Cartão card', 'card', '30.00'],
            ['Cartão credit', 'credit', '40.00'],
        ] as [$name, $method, $amount]) {
            $debt = $service->create($user, [
                'name' => $name, 'payment_method' => in_array($method, ['card', 'credit'], true) ? null : $method, 'total_amount' => $amount,
                'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
                'payment_condition' => 'installments', 'installments_count' => 2,
            ]);
            // Simula registos antigos, pois a criação atual já rejeita esses métodos.
            if (in_array($method, ['card', 'credit'], true)) $debt->update(['payment_method' => $method]);
            $debts[] = $debt;
        }
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.commitments.page'))->assertOk()
            ->assertSee('Sem método')->assertSee('Por banco')
            ->assertDontSee('Cartão card')->assertDontSee('Cartão credit');

        $this->assertSame(30.0, $response->viewData('summary')['open']);
        $xpath = $this->document($response->getContent());
        $this->assertCount(2, $xpath->query('//article[contains(@class,"commitment-card")]'));
        foreach ($debts as $debt) {
            $this->assertDatabaseHas('dividas', ['id' => $debt->id, 'status' => 'open']);
            $this->assertSame('0.00', $debt->fresh()->parcelas->first()->paid_amount);
        }
    }

    public function test_page_does_not_expose_another_users_commitments_or_payment_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $accounts = app(FinancialAccountService::class);
        $accounts->create($user, ['name' => 'Conta própria', 'type' => 'bank']);
        $accounts->create($other, ['name' => 'Conta privada alheia', 'type' => 'bank']);
        $commitments = app(FinancialCommitmentService::class);
        foreach ([[$user, 'Compra própria'], [$other, 'Compra privada alheia']] as [$owner, $name]) {
            $commitments->create($owner, [
                'name' => $name, 'total_amount' => '90.00',
                'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
                'payment_condition' => 'installments', 'installments_count' => 2,
            ]);
        }
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);

        $this->actingAs($user)->get(route('financial.commitments.page'))->assertOk()
            ->assertSee('Compra própria')->assertSee('Conta própria')
            ->assertDontSee('Compra privada alheia')->assertDontSee('Conta privada alheia');
    }

    public function test_large_installment_plan_is_grouped_and_remaining_parts_are_collapsed(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        app(FinancialAccountService::class)->create($user, ['name' => 'Conta', 'type' => 'bank', 'opening_balance' => '200.00']);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Compra em 120 vezes', 'total_amount' => '120.00',
            'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'payment_condition' => 'installments', 'installments_count' => 120,
        ]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $xpath = $this->document($this->actingAs($user)->get(route('financial.commitments.page'))->assertOk()->getContent());
        $cards = $xpath->query('//article[contains(@class,"commitment-card")]');
        $this->assertCount(1, $cards);
        $this->assertStringContainsString('0/120', $cards->item(0)->textContent);
        $this->assertCount(1, $xpath->query('//button[contains(@class,"commitment-pay-next")]'));
        $this->assertCount(6, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " commitment-installment ")]'));
        $this->assertSame(120, $debt->fresh()->parcelas()->count());

        $secondHistoryPage = $this->get(route('financial.commitments.page', ['installments_'.$debt->id => 2]))->assertOk();
        $secondXPath = $this->document($secondHistoryPage->getContent());
        $this->assertCount(6, $secondXPath->query('//div[contains(concat(" ", normalize-space(@class), " "), " commitment-installment ")]'));
    }

    public function test_purchase_pagination_is_server_side_and_summary_uses_all_commitments(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-17 12:00:00'));
        $user = User::factory()->create();
        $service = app(FinancialCommitmentService::class);

        for ($index = 1; $index <= 12; $index++) {
            $service->create($user, [
                'name' => 'Compra '.$index,
                'total_amount' => '20.00',
                'start_date' => '2026-11-17',
                'due_date' => '2026-12-17',
                'payment_condition' => 'installments',
                'installments_count' => 2,
            ]);
        }

        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $desktop = $this->actingAs($user)->get(route('financial.commitments.page', ['per_page' => 10]))->assertOk();
        $this->assertSame(12, $desktop->viewData('commitments')->total());
        $this->assertSame(10, $desktop->viewData('commitments')->count());
        $this->assertSame(240.0, $desktop->viewData('summary')['open']);

        $mobile = $this->get(route('financial.commitments.page', ['per_page' => 6]))->assertOk();
        $this->assertSame(12, $mobile->viewData('commitments')->total());
        $this->assertSame(6, $mobile->viewData('commitments')->count());
        $this->assertSame(240.0, $mobile->viewData('summary')['open']);
    }


    public function test_editing_total_preserves_paid_amount_and_redistributes_only_pending_installments(): void
    {
        $user = User::factory()->create();
        $account = app(FinancialAccountService::class)->create($user, ['name' => 'Conta', 'type' => 'bank', 'opening_balance' => '200.00']);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Móvel', 'total_amount' => '60.00',
            'start_date' => '2026-11-17', 'due_date' => '2026-12-17',
            'payment_condition' => 'installments', 'installments_count' => 3,
        ]);
        $first = $debt->parcelas->sortBy('installment_number')->first();
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $first->id]), [
            'account_id' => $account->id, 'amount' => '5.00', 'payment_date' => '2026-11-17',
        ])->assertOk();

        $this->putJson(route('financial.commitments.update', $debt->id), [
            'name' => 'Móvel atualizado', 'total_amount' => '90.00', 'due_date' => '2026-12-20',
        ])->assertOk();

        $this->assertSame('90.00', $debt->fresh()->total_amount);
        $this->assertSame('5.00', $first->fresh()->paid_amount);
        $this->assertSame('33.34', $first->fresh()->amount);
        $this->assertSame('28.33', $debt->fresh()->parcelas()->where('installment_number', 2)->value('amount'));
        $this->assertSame('28.33', $debt->fresh()->parcelas()->where('installment_number', 3)->value('amount'));
        $this->assertSame('28.34', $user->movimentos()->where('installment_id', $first->id)->where('status', 'pending')->value('amount'));
        $this->assertSame('5.00', $user->movimentos()->where('installment_id', $first->id)->where('status', 'settled')->value('amount'));
    }


    public function test_commitment_merchant_is_saved_and_displayed_on_purchase_card(): void
    {
        $user = User::factory()->create();
        app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Frigorífico',
            'merchant' => 'Radio Popular',
            'total_amount' => '600.00',
            'start_date' => '2026-11-17',
            'due_date' => '2026-12-17',
            'payment_condition' => 'installments',
            'installments_count' => 6,
        ]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $this->actingAs($user)->get(route('financial.commitments.page'))->assertOk()
            ->assertSee('Frigorífico')->assertSee('Radio Popular');
    }

}

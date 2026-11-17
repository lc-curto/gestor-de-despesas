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

    public function test_six_installment_payment_forms_are_independently_closed_and_accessible(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Conta principal', 'type' => 'bank', 'opening_balance' => '200.00',
        ]);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Computador', 'total_amount' => '120.00',
            'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'payment_condition' => 'installments', 'installments_count' => 6,
        ]);
        $parts = $debt->parcelas->sortBy('installment_number');
        $before = $parts->pluck('paid_amount', 'id')->all();
        $balanceBefore = app(AccountBalanceService::class)->balance($account);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);

        $response = $this->actingAs($user)->get(route('financial.commitments.page'))->assertOk();
        $xpath = $this->document($response->getContent());
        $disclosures = $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-payment-disclosure ")]');
        $this->assertCount(6, $disclosures);

        foreach ($parts as $part) {
            $endpoint = route('financial.commitments.installments.pay', [$debt->id, $part->id]);
            $forms = $xpath->query('//form[@data-endpoint="'.$endpoint.'"]');
            $this->assertCount(1, $forms);
            $form = $forms->item(0);
            $details = $form->parentNode;
            $this->assertInstanceOf(DOMElement::class, $details);
            $this->assertSame('details', $details->tagName);
            $this->assertFalse($details->hasAttribute('open'));
            $this->assertStringContainsString('Registar pagamento', $xpath->query('./summary', $details)->item(0)->textContent);
            $this->assertStringContainsString('prestação '.$part->installment_number.' de 6 de Computador', $form->getAttribute('aria-label'));

            $article = $details->parentNode->parentNode;
            $headerNode = $xpath->query('./div[1]', $article)->item(0);
            $header = $headerNode->textContent;
            $this->assertStringContainsString('Computador', $header);
            $this->assertStringContainsString($part->installment_number.'/6', $header);
            $this->assertStringContainsString($part->due_date->format('d/m/Y'), $header);
            $this->assertCount(1, $xpath->query('.//span[normalize-space(.)="Em falta:" and not(contains(@class, "visually-hidden"))]', $headerNode));
            $this->assertStringContainsString('20,00 EUR', $header);
            $this->assertTrue(str_contains($header, 'A vencer') || str_contains($header, 'Em atraso'));

            foreach (['amount' => 'Valor a pagar', 'account_id' => 'Conta de origem', 'payment_date' => 'Data'] as $name => $label) {
                $field = $xpath->query('.//*[@name="'.$name.'"]', $form)->item(0);
                $this->assertNotNull($field);
                $this->assertNotSame('', $field->getAttribute('id'));
                $labels = $xpath->query('.//label[@for="'.$field->getAttribute('id').'"]', $form);
                $this->assertCount(1, $labels);
                $this->assertSame($label, trim($labels->item(0)->textContent));
                $this->assertCount(1, $xpath->query('//*[@id="'.$field->getAttribute('id').'"]'));
                $this->assertTrue($field->hasAttribute('required'));
            }
            $amount = $xpath->query('.//input[@name="amount"]', $form)->item(0);
            $this->assertSame('text', $amount->getAttribute('type'));
            $this->assertSame('decimal', $amount->getAttribute('inputmode'));
            $this->assertTrue($amount->hasAttribute('data-payment-amount'));
            $this->assertSame('20,00', $amount->getAttribute('value'));
            $this->assertSame('20.00', $amount->getAttribute('data-max-amount'));
            $descriptionIds = explode(' ', $amount->getAttribute('aria-describedby'));
            $this->assertCount(2, $descriptionIds);
            foreach ($descriptionIds as $id) {
                $this->assertCount(1, $xpath->query('//*[@id="'.$id.'"]'));
            }
            $this->assertCount(1, $xpath->query('.//*[@data-payment-error and @role="alert"]', $form));
            $this->assertCount(1, $xpath->query('.//option[@value="'.$account->id.'"]', $form));
            $this->assertCount(1, $xpath->query('.//button[@type="submit" and @disabled]', $form));
        }

        $this->assertSame($before, $debt->fresh()->parcelas->sortBy('installment_number')->pluck('paid_amount', 'id')->all());
        $this->assertSame($balanceBefore, app(AccountBalanceService::class)->balance($account->fresh()));
    }

    public function test_partial_payment_updates_the_remaining_amount_in_the_correct_disclosure(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = app(FinancialAccountService::class)->create($user, [
            'name' => 'Carteira', 'type' => 'cash', 'opening_balance' => '200.00',
        ]);
        $debt = app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Móvel', 'total_amount' => '60.00',
            'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'payment_condition' => 'installments', 'installments_count' => 3,
        ]);
        $first = $debt->parcelas->sortBy('installment_number')->first();
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $this->actingAs($user)->postJson(route('financial.commitments.installments.pay', [$debt->id, $first->id]), [
            'amount' => '5.00', 'account_id' => $account->id, 'payment_date' => now()->toDateString(),
        ])->assertOk();

        $xpath = $this->document($this->get(route('financial.commitments.page'))->assertOk()->getContent());
        $endpoint = route('financial.commitments.installments.pay', [$debt->id, $first->id]);
        $form = $xpath->query('//form[@data-endpoint="'.$endpoint.'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertFalse($form->parentNode->hasAttribute('open'));
        $amount = $xpath->query('.//input[@name="amount"]', $form)->item(0);
        $this->assertSame('15,00', $amount->getAttribute('value'));
        $this->assertSame('15.00', $amount->getAttribute('data-max-amount'));
        $this->assertCount(3, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-payment-disclosure ")]'));

        $this->postJson($endpoint, [
            'amount' => '15.00', 'account_id' => $account->id, 'payment_date' => now()->toDateString(),
        ])->assertOk();
        $afterPayment = $this->get(route('financial.commitments.page'))->assertOk();
        $xpath = $this->document($afterPayment->getContent());
        $this->assertCount(0, $xpath->query('//form[@data-endpoint="'.$endpoint.'"]'));
        $this->assertCount(2, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-payment-disclosure ")]'));
        $afterPayment->assertSee('Histórico de pagamentos')->assertSee('Móvel · Parcela 1');
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
            ['Ontem', '10.00', '2026-11-16'],
            ['Hoje', '20.00', '2026-11-17'],
            ['Amanhã', '30.00', '2026-11-18'],
        ] as [$name, $amount, $date]) {
            $service->create($user, [
                'name' => $name, 'total_amount' => $amount, 'start_date' => '2026-11-16',
                'due_date' => $date, 'payment_condition' => 'deferred',
            ]);
        }
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.commitments.page'))->assertOk();
        $summary = $response->viewData('summary');
        $this->assertSame(60.0, $summary['open']);
        $this->assertSame(10.0, $summary['overdue']);
        $this->assertSame(50.0, $summary['upcoming']);
        $xpath = $this->document($response->getContent());
        foreach (['Ontem' => 'Em atraso', 'Hoje' => 'A vencer', 'Amanhã' => 'A vencer'] as $name => $status) {
            $badges = $xpath->query('//section[@aria-labelledby="paymentsSectionTitle"]//article[.//h3[normalize-space(.)="'.$name.'"]]//span[contains(concat(" ", normalize-space(@class), " "), " badge ")]');
            $this->assertCount(1, $badges, $name);
            $this->assertSame($status, trim($badges->item(0)->textContent), $name);
        }

        foreach (['upcoming' => ['Hoje', 'Amanhã'], 'overdue' => ['Ontem']] as $filter => $expected) {
            $filtered = $this->get(route('financial.commitments.page', ['filter' => $filter]))->assertOk();
            $xpath = $this->document($filtered->getContent());
            $headings = $xpath->query('//section[@aria-labelledby="paymentsSectionTitle"]//article//h3');
            $this->assertCount(count($expected), $headings);
            $this->assertSame($expected, array_map(fn ($heading) => trim($heading->textContent), iterator_to_array($headings)));
        }
    }

    public function test_missing_payment_account_keeps_guidance_and_accounts_link_visible(): void
    {
        $user = User::factory()->create();
        app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Compra a prazo', 'total_amount' => '90.00',
            'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'payment_condition' => 'deferred',
        ]);
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
        $response = $this->actingAs($user)->get(route('financial.commitments.page'))
            ->assertOk()->assertSee('Adiciona uma conta bancária ou de dinheiro para pagar.');
        $xpath = $this->document($response->getContent());

        $this->assertCount(0, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-payment-disclosure ")]'));
        $this->assertCount(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " commitment-item-actions ")]//a[@href="'.route('financial.accounts.page').'"]'));
        $this->assertCount(1, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-details-disclosure ")]'));
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
                'payment_condition' => 'deferred',
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
        $this->assertCount(2, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " commitment-payment-disclosure ")]'));
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
                'payment_condition' => 'deferred',
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
        $groups = $xpath->query('//section[@aria-labelledby="paymentsSectionTitle"]//details[contains(concat(" ", normalize-space(@class), " "), " commitment-group ")]');
        $this->assertCount(1, $groups);
        $group = $groups->item(0);
        $this->assertFalse($group->hasAttribute('open'));
        $this->assertStringContainsString('120 prestações neste filtro', $xpath->query('./summary', $group)->item(0)->textContent);
        $this->assertCount(3, $xpath->query('./div[contains(@class,"commitment-group-items")]/article', $group));
        $more = $xpath->query('./div/details[contains(@class,"commitment-more-items")]', $group)->item(0);
        $this->assertNotNull($more);
        $this->assertFalse($more->hasAttribute('open'));
        $this->assertStringContainsString('Ver mais 117 prestações', $xpath->query('./summary', $more)->item(0)->textContent);
        $this->assertCount(117, $xpath->query('.//article', $more));
        $this->assertCount(120, $xpath->query('.//form[contains(@class,"js-api-form")]', $group));
        $this->assertSame(120, $debt->fresh()->parcelas()->count());
    }
}

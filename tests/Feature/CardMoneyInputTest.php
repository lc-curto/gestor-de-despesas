<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardMoneyInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_forms_render_text_fields_with_decimal_keyboards_and_instructions(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/cartoes')->assertOk();
        $html = $response->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        foreach (['newCardLimit', 'newCardUsed', 'editCardLimit', 'editCardOpeningDebt'] as $id) {
            $input = $xpath->query("//input[@id='$id']")->item(0);
            $this->assertNotNull($input, $id);
            $this->assertSame('text', $input->getAttribute('type'));
            $this->assertSame('decimal', $input->getAttribute('inputmode'));
            $this->assertTrue($input->hasAttribute('required'));
            $helpId = $input->getAttribute('aria-describedby');
            $this->assertNotSame('', $helpId);
            $this->assertNotNull($xpath->query("//*[@id='$helpId']")->item(0));
            $this->assertNotNull($xpath->query("//label[@for='$id']")->item(0));
        }
    }

    public function test_normalized_card_amounts_are_persisted_without_losing_cents(): void
    {
        $user = User::factory()->create();
        // These are the canonical values sent by the UI for 1.000,00 and 90,50.
        $response = $this->actingAs($user)->postJson('/api/financial/accounts', [
            'name' => 'Cartão decimal', 'type' => 'credit_card',
            'credit_limit' => '1000.00', 'opening_debt_amount' => '90.50',
            'closing_day' => '10', 'due_day' => '1',
        ])->assertCreated();

        $card = ContaFinanceira::query()->findOrFail($response->json('data.id'));
        $this->assertSame('1000.00', $card->credit_limit);
        $this->assertSame('90.50', $card->opening_debt_amount);
        $this->assertSame('-90.50', $card->opening_balance);

        // Likewise, the UI sends 90,50 as 90.50, never as 9050.
        $decimalResponse = $this->postJson('/api/financial/accounts', [
            'name' => 'Cartão 90,50', 'type' => 'credit_card', 'credit_limit' => '90.50',
            'opening_debt_amount' => '0.00', 'closing_day' => '10', 'due_day' => '1',
        ])->assertCreated();
        $decimalCard = ContaFinanceira::query()->findOrFail($decimalResponse->json('data.id'));
        $this->assertSame('90.50', $decimalCard->credit_limit);
    }

    public function test_editing_an_existing_card_after_a_purchase_preserves_its_original_amounts(): void
    {
        $user = User::factory()->create();
        $card = app(FinancialAccountService::class)->create($user, [
            'name' => 'Cartão existente', 'type' => 'credit_card', 'credit_limit' => '1234.56',
            'opening_debt_amount' => '90.50', 'closing_day' => 15, 'due_day' => 5,
        ]);
        $this->actingAs($user)->postJson('/api/financial/cards/purchases', [
            'account_id' => $card->id, 'description' => 'Compra', 'amount' => '10.00',
            'movement_date' => now()->toDateString(),
        ])->assertCreated();

        $this->putJson('/api/financial/accounts/'.$card->id.'/card-settings', [
            'credit_limit' => '1234.56', 'opening_debt_amount' => '90.50', 'closing_day' => '15', 'due_day' => '5',
        ])->assertOk();
        $this->putJson('/api/financial/accounts/'.$card->id, ['name' => 'Nome corrigido'])->assertOk();

        $card->refresh();
        $this->assertSame('Nome corrigido', $card->name);
        $this->assertSame('1234.56', $card->credit_limit);
        $this->assertSame('90.50', $card->opening_debt_amount);
        $this->assertSame('-90.50', $card->opening_balance);
    }

    public function test_backend_rejects_invalid_values_and_card_debt_above_the_limit(): void
    {
        $user = User::factory()->create();
        $base = [
            'name' => 'Cartão inválido', 'type' => 'credit_card',
            'credit_limit' => '1000.00', 'opening_debt_amount' => '0.00', 'closing_day' => 10, 'due_day' => 1,
        ];
        foreach (['1.000,00', '90,50', '1.000', '-1', '0', '10000000000'] as $invalid) {
            $this->actingAs($user)->postJson('/api/financial/accounts', array_replace($base, ['credit_limit' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        }
        $this->postJson('/api/financial/accounts', array_replace($base, ['opening_debt_amount' => '-0.01']))
            ->assertUnprocessable()->assertJsonValidationErrors('opening_debt_amount');
        $this->postJson('/api/financial/accounts', array_replace($base, ['opening_debt_amount' => '1000.01']))
            ->assertUnprocessable()->assertJsonValidationErrors('opening_debt_amount');
        $this->assertDatabaseMissing('contas_financeiras', ['user_id' => $user->id, 'name' => 'Cartão inválido']);
    }
}

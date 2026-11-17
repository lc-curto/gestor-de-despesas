<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentMovementRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(User $user): array
    {
        return [
            'name' => 'QA PósDeploy Parcelamento 3x 20261117',
            'total_amount' => '90.00',
            'start_date' => '2026-11-17',
            'due_date' => '2026-12-17',
            'payment_condition' => 'installments',
            'payment_method' => 'transfer',
            'category_id' => $user->categorias()->where('slug', 'casa')->firstOrFail()->id,
            'account_id' => null,
            'installments_count' => 3,
            'installment_frequency' => 'monthly',
        ];
    }

    public function test_transfer_installments_are_persisted_and_visible_in_their_due_months(): void
    {
        $user = User::factory()->create();
        $data = $this->payload($user);
        $response = $this->actingAs($user)->postJson(route('financial.commitments.store'), $data)
            ->assertCreated()->assertJsonCount(3, 'data.parcelas');
        $debt = $user->dividas()->with('parcelas')->findOrFail($response->json('data.id'));

        $this->assertSame('90.00', $debt->total_amount);
        $this->assertSame('transfer', $debt->payment_method);
        $this->assertSame(['2026-12-17', '2027-01-17', '2027-02-17'], $debt->parcelas->sortBy('installment_number')->map(fn ($part) => $part->due_date->toDateString())->values()->all());
        $this->assertSame(['30.00', '30.00', '30.00'], $debt->parcelas->sortBy('installment_number')->pluck('amount')->values()->all());
        $this->assertSame(3, $user->movimentos()->where('debt_id', $debt->id)->count());
        foreach ($debt->parcelas as $part) {
            $this->assertDatabaseHas('movimentos', [
                'user_id' => $user->id, 'debt_id' => $debt->id, 'installment_id' => $part->id,
                'category_id' => $data['category_id'], 'type' => 'expense', 'status' => 'pending', 'amount' => '30.00',
            ]);
        }
        $this->assertDatabaseCount('despesas', 0);

        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11']))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 0);
        foreach (['2026-12' => 1, '2027-01' => 2, '2027-02' => 3] as $month => $number) {
            $this->getJson(route('dashboard.movimentos.data', ['mes' => $month]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.description', $data['name'].' · Parcela '.$number.'/3')
                ->assertJsonPath('data.0.category', $data['category_id']);
        }
    }

    public function test_movement_pagination_counts_canonical_installment_forecasts(): void
    {
        $user = User::factory()->create();
        $data = $this->payload($user);
        $data['due_date'] = '2026-12-01';
        $data['installment_frequency'] = 'weekly';
        $this->actingAs($user)->postJson(route('financial.commitments.store'), $data)->assertCreated();

        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-12', 'limit' => 2]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 3)
            ->assertJsonPath('total_despesas_filtradas', 3)->assertJsonPath('has_more', true);
        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-12', 'limit' => 2, 'page' => 2]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 3)->assertJsonPath('has_more', false);
    }

    public function test_invalid_commitment_is_rejected_without_partial_records(): void
    {
        $user = User::factory()->create();
        $data = $this->payload($user);
        $data['total_amount'] = '0.02';
        $this->actingAs($user)->postJson(route('financial.commitments.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        $this->assertDatabaseCount('dividas', 0);
        $this->assertDatabaseCount('parcelas_divida', 0);
        $this->assertDatabaseCount('movimentos', 0);

        $data['total_amount'] = '0.03';
        $this->postJson(route('financial.commitments.store'), $data)->assertCreated();
        $this->assertDatabaseCount('parcelas_divida', 3);
        $this->assertSame(3, $user->movimentos()->where('amount', '0.01')->count());
    }

    public function test_validation_errors_do_not_create_commitments(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $data = $this->payload($user);
        $data['due_date'] = '2026-11-16';
        $data['category_id'] = $other->categorias()->where('slug', 'casa')->firstOrFail()->id;
        $data['name'] = str_repeat('a', 161);
        $this->actingAs($user)->postJson(route('financial.commitments.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'category_id', 'name']);
        $this->assertDatabaseCount('dividas', 0);
        $this->assertDatabaseCount('parcelas_divida', 0);
        $this->assertDatabaseCount('movimentos', 0);
    }

    public function test_card_payment_methods_are_rejected_without_creating_invisible_debt(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['card', 'credit'] as $method) {
            $data = $this->payload($user);
            $data['payment_method'] = $method;
            $this->postJson(route('financial.commitments.store'), $data)
                ->assertUnprocessable()->assertJsonValidationErrors('payment_method')
                ->assertJsonFragment(['O parcelamento por cartão não está disponível neste formulário. Regista a compra em Cartões.']);
        }
        $this->assertDatabaseCount('dividas', 0);
        $this->assertDatabaseCount('parcelas_divida', 0);
        $this->assertDatabaseCount('movimentos', 0);
        $this->assertDatabaseCount('faturas', 0);
    }
}

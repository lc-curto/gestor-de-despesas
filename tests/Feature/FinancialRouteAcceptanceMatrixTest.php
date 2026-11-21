<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFinancialDataReviewed;
use App\Models\ContaFinanceira;
use App\Models\Movimento;
use App\Models\User;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialCommitmentService;
use App\Services\Financial\FinancialMovementService;
use App\Services\Financial\FinancialProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialRouteAcceptanceMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->withoutMiddleware(EnsureFinancialDataReviewed::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(User $user, string $type = 'bank', string $balance = '1000.00'): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => ucfirst($type),
            'type' => $type,
            'opening_balance' => $balance,
            'opening_balance_date' => '2026-11-01',
            'closing_day' => 25,
            'due_day' => 5,
            'credit_limit' => '2000.00',
        ]);
    }

    private function movement(User $user, string $type, string $amount, string $date, ?string $accountId): Movimento
    {
        $data = [
            'type' => $type,
            'description' => 'Evento de aceitação '.$date,
            'amount' => $amount,
            'movement_date' => $date,
        ];

        if ($type === 'income') {
            $data['destination_account_id'] = $accountId;
        } else {
            $data['source_account_id'] = $accountId;
        }

        return app(FinancialMovementService::class)->create($user, $data);
    }

    private function dayBalance($response, string $date): float
    {
        $id = preg_quote('route-day-'.$date, '~');
        $this->assertSame(
            1,
            preg_match('~<script type="application/json" id="'.$id.'">(.*?)</script>~s', $response->getContent(), $matches),
            'Não foi encontrado o saldo diário de '.$date
        );

        return (float) json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR)['balance'];
    }

    public function test_three_month_projection_carries_closing_balance_and_matches_single_month_views(): void
    {
        $user = User::factory()->create();
        $bank = $this->account($user, 'bank', '1000.00');

        // Movimentos liquidados anteriores ao período integram uma única vez o saldo de abertura.
        $this->movement($user, 'income', '100.00', '2026-11-25', $bank->id);
        $this->movement($user, 'expense', '50.00', '2026-11-28', $bank->id);

        $this->movement($user, 'income', '200.00', '2026-12-15', $bank->id);
        $this->movement($user, 'expense', '75.00', '2027-01-10', $bank->id);
        $this->movement($user, 'expense', '25.00', '2027-02-28', $bank->id);

        $threeMonths = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2026-12',
            'modo' => 3,
        ]))->assertOk();

        $decemberThreeMonth = $this->dayBalance($threeMonths, '2026-12-31');
        $januaryThreeMonth = $this->dayBalance($threeMonths, '2027-01-31');
        $februaryThreeMonth = $this->dayBalance($threeMonths, '2027-02-28');

        $decemberSingle = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2026-12',
            'modo' => 1,
        ]))->assertOk();
        $januarySingle = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2027-01',
            'modo' => 1,
        ]))->assertOk();
        $februarySingle = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2027-02',
            'modo' => 1,
        ]))->assertOk();

        $this->assertSame(1250.0, $decemberThreeMonth);
        $this->assertSame($decemberThreeMonth, $this->dayBalance($decemberSingle, '2026-12-31'));
        $this->assertSame($januaryThreeMonth, $this->dayBalance($januarySingle, '2027-01-31'));
        $this->assertSame($februaryThreeMonth, $this->dayBalance($februarySingle, '2027-02-28'));
        $this->assertSame(1150.0, $februaryThreeMonth);
    }

    public function test_pending_installment_from_previous_month_is_carried_into_next_month_opening_balance(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'bank', '500.00');

        app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Prestação de janeiro',
            'total_amount' => '12.00',
            'start_date' => '2027-01-01',
            'due_date' => '2027-01-10',
            'payment_condition' => 'deferred',
            'payment_method' => 'bank',
        ]);

        $january = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2027-01',
            'modo' => 1,
        ]))->assertOk();

        $this->assertSame(488.0, $this->dayBalance($january, '2027-01-31'));

        $february = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2027-02',
            'modo' => 1,
        ]))->assertOk();

        $this->assertSame(488.0, (float) $february->viewData('openingBalance'));
    }

    public function test_overdue_commitment_is_visible_in_a_separate_section_without_being_assigned_to_period_first_day(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'bank', '500.00');

        app(FinancialCommitmentService::class)->create($user, [
            'name' => 'Eletricidade vencida',
            'total_amount' => '120.00',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-10',
            'payment_condition' => 'deferred',
            'payment_method' => 'bank',
        ]);

        $response = $this->actingAs($user)->get(route('financial.route', [
            'mes' => '2026-12',
            'modo' => 1,
        ]))->assertOk();

        $response->assertSee('Vencidas')->assertSee('Eletricidade vencida');

        $firstDay = $response->viewData('days')['2026-12']['2026-12-01'];
        $this->assertSame(0.0, (float) $firstDay['cash_delta']);
        $this->assertSame(500.0, (float) $response->viewData('openingBalance'));
    }

    public function test_pending_credit_card_expense_does_not_reduce_cash_balance_on_purchase_date(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'bank', '500.00');
        $card = $this->account($user, 'credit_card', '0.00');

        $pendingCardExpense = app(FinancialMovementService::class)->createPending($user, [
            'type' => 'expense',
            'description' => 'Compra pendente no cartão',
            'amount' => '80.00',
            'movement_date' => '2026-12-12',
            'source_account_id' => $card->id,
        ]);

        $projection = app(FinancialProjectionService::class)->build(
            $user,
            Carbon::parse('2026-12-01'),
            Carbon::parse('2026-12-31')
        );

        $this->assertNull(
            $projection['movements']->firstWhere('id', $pendingCardExpense->id),
            'Uma compra pendente no cartão não pode aparecer como saída de caixa na data da compra.'
        );
        $this->assertSame(500.0, $projection['openingBalance'] + $projection['movements']->sum('projection_delta'));
    }
    public function test_negative_opening_balance_is_preserved_in_the_projection(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'bank', '-250.75');

        $projection = app(FinancialProjectionService::class)->build(
            $user,
            Carbon::parse('2026-12-01'),
            Carbon::parse('2026-12-31')
        );

        $this->assertSame(-250.75, $projection['openingBalance']);
        $this->assertCount(0, $projection['movements']);
    }

}

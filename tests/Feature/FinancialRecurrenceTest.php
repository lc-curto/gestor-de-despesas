<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\User;
use App\Services\Financial\AccountBalanceService;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\RecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        Carbon::setTestNow('2026-11-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(User $user, string $type = 'bank'): ContaFinanceira
    {
        return app(FinancialAccountService::class)->create($user, [
            'name' => $type === 'credit_card' ? 'Cartão' : 'Conta',
            'type' => $type,
            'opening_balance' => $type === 'credit_card' ? '0.00' : '100.00',
            'opening_balance_date' => '2026-11-01',
            'closing_day' => $type === 'credit_card' ? 25 : null,
            'due_day' => $type === 'credit_card' ? 5 : null,
            'credit_limit' => $type === 'credit_card' ? '500.00' : null,
        ]);
    }

    private function createRule(User $user, ?string $accountId = null): array
    {
        return [
            'type' => 'expense',
            'description' => 'Internet',
            'amount' => '30.00',
            'category_id' => $user->categorias()->where('type', 'despesa')->firstOrFail()->id,
            'account_id' => $accountId,
            'frequency' => 'monthly',
            'interval_value' => 1,
            'start_date' => '2026-11-15',
            'ends_at' => null,
        ];
    }

    public function test_rule_generates_three_monthly_forecasts_once_and_mirrors_legacy_record(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user))
            ->assertCreated()->assertJsonPath('data.frequency', 'monthly');

        $this->assertSame(3, $user->movimentos()->whereNotNull('recurrence_id')->count());
        $this->assertSame(3, $user->despesas()->count());
        $this->assertSame(3, $user->movimentos()->where('status', 'pending')->where('requires_account_assignment', true)->count());

        app(RecurrenceService::class)->generateForUser($user);
        $this->assertSame(3, $user->movimentos()->whereNotNull('recurrence_id')->count());
        $this->assertSame(0, (int) $user->movimentos()->where('status', 'settled')->count());

        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 50]))
            ->assertOk()->assertJsonPath('data.0.recurrence_id', $user->recorrencias()->firstOrFail()->id);
        $this->get('/recorrencias')->assertNotFound();
        $this->get(route('despesas.create'))->assertOk()->assertSee('Repetir esta despesa');
        $this->get(route('despesas.index'))->assertOk()->assertSee('Previsão recorrente');

        $novemberForecast = $user->movimentos()->where('recurrence_occurrence_date', '2026-11-15')->firstOrFail();
        $this->putJson('/api/financial/movements/'.$novemberForecast->id.'/void', ['reason' => 'Não aplicável este mês.'])->assertOk();
        app(RecurrenceService::class)->generateForUser($user);
        $this->assertSame(3, $user->movimentos()->whereNotNull('recurrence_id')->count());
        $this->getJson(route('dashboard.movimentos.data', ['mes' => '2026-11', 'limit' => 50]))
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_recurrence_is_created_from_the_expense_form(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $category = $user->categorias()->where('type', 'despesa')->firstOrFail();

        $this->actingAs($user)->post(route('despesas.store'), [
            'description' => 'Internet', 'amount' => '30.00', 'account_id' => $account->id,
            'category_id' => $category->id, 'expense_date' => '2026-11-15',
            'recurrence_enabled' => '1', 'recurrence_frequency' => 'monthly',
            'recurrence_interval' => '1',
        ])->assertRedirect(route('despesas.index'));

        $this->assertDatabaseHas('recorrencias', ['description' => 'Internet', 'type' => 'expense']);
        $this->assertSame(3, $user->despesas()->count());
        $this->assertSame(3, $user->movimentos()->whereNotNull('recurrence_id')->count());
    }

    public function test_forecast_can_be_confirmed_reagendado_and_settled_without_losing_history(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user, $account->id))->assertCreated();
        $movement = $user->movimentos()->whereNotNull('recurrence_id')->orderBy('movement_date')->firstOrFail();

        $this->putJson('/api/financial/movements/'.$movement->id, ['movement_date' => '2026-11-20'])
            ->assertOk();
        $this->assertDatabaseHas('despesas', ['id' => $movement->legacy_id, 'expense_date' => '2026-11-20']);
        $this->assertSame('2026-11-15', $movement->fresh()->recurrence_occurrence_date->toDateString());

        $this->putJson('/api/financial/movements/'.$movement->id.'/confirm', ['source_account_id' => $account->id])
            ->assertOk()->assertJsonPath('data.requires_account_assignment', false)->assertJsonPath('data.status', 'pending');
        $this->assertSame('100.00', app(AccountBalanceService::class)->balance($account));

        $this->putJson('/api/financial/movements/'.$movement->id.'/settle', ['source_account_id' => $account->id])
            ->assertOk()->assertJsonPath('data.status', 'settled');
        $this->assertSame('70.00', app(AccountBalanceService::class)->balance($account));
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $movement->id, 'action' => 'confirmed']);
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $movement->id, 'action' => 'settled']);
        $this->assertDatabaseHas('historico_movimentos', ['movement_id' => $movement->id, 'action' => 'updated']);
    }

    public function test_recurring_card_forecast_is_linked_to_invoice_when_liquidated(): void
    {
        $user = User::factory()->create();
        $card = $this->account($user, 'credit_card');
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user, $card->id))->assertCreated();
        $movement = $user->movimentos()->whereNotNull('recurrence_id')->firstOrFail();

        $this->putJson('/api/financial/movements/'.$movement->id.'/confirm', ['source_account_id' => $card->id])->assertOk();
        $this->putJson('/api/financial/movements/'.$movement->id.'/settle', ['source_account_id' => $card->id])
            ->assertOk()->assertJsonPath('data.status', 'settled');

        $this->assertNotNull($movement->fresh()->invoice_id);
        $this->assertSame(1, $user->movimentos()->where('recurrence_id', $movement->recurrence_id)->where('recurrence_occurrence_date', '2026-11-15')->count());
        $this->assertSame(1, $user->despesas()->whereKey($movement->legacy_id)->count());
    }

    public function test_pausing_recurrence_removes_future_forecasts_and_resume_recreates_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user))
            ->assertCreated();

        $rule = $user->recorrencias()->firstOrFail();
        $this->assertSame(3, $user->movimentos()->where('recurrence_id', $rule->id)->count());

        $this->patchJson('/api/financial/recurrences/'.$rule->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $user->movimentos()->where('recurrence_id', $rule->id)->count());
        $this->assertSame(0, $user->despesas()->count());
        $this->assertSame('2026-11-15', $rule->fresh()->next_occurrence->toDateString());

        $this->patchJson('/api/financial/recurrences/'.$rule->id.'/status', ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertSame(3, $user->movimentos()->where('recurrence_id', $rule->id)->count());
        $this->assertSame(3, $user->despesas()->count());
    }

    public function test_editing_recurring_expense_without_repeat_keeps_rule_link_until_it_is_cancelled(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $category = $user->categorias()->where('type', 'despesa')->firstOrFail();
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user, $account->id))
            ->assertCreated();

        $movement = $user->movimentos()->where('recurrence_occurrence_date', '2026-11-15')->firstOrFail();
        $this->putJson('/api/financial/movements/'.$movement->id.'/confirm', ['source_account_id' => $account->id])->assertOk();
        $this->putJson('/api/financial/movements/'.$movement->id.'/settle', ['source_account_id' => $account->id])->assertOk();

        $this->put(route('despesas.update', $movement->legacy_id), [
            'description' => 'Internet atualizada',
            'amount' => '35.00',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'expense_date' => '2026-11-15',
            'expense_behavior' => 'fixed',
        ])->assertRedirect(route('despesas.index'));

        $this->assertDatabaseHas('recorrencias', ['id' => $movement->recurrence_id, 'is_active' => false]);
        $this->assertSame(1, $user->movimentos()->where('recurrence_id', $movement->recurrence_id)->count());
        $this->assertSame(0, $user->movimentos()->where('recurrence_id', $movement->recurrence_id)->where('status', 'pending')->count());
        $this->assertDatabaseHas('movimentos', [
            'legacy_type' => 'despesa',
            'legacy_id' => $movement->legacy_id,
            'recurrence_id' => $movement->recurrence_id,
            'expense_behavior' => 'fixed',
        ]);
    }


    public function test_settling_pending_expense_updates_effective_amount_date_and_legacy_record(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user, $account->id))->assertCreated();

        $movement = $user->movimentos()->whereNotNull('recurrence_id')->orderBy('movement_date')->firstOrFail();
        $this->putJson('/api/financial/movements/'.$movement->id.'/settle', [
            'amount' => '35.00',
            'movement_date' => '2026-11-16',
            'source_account_id' => $account->id,
        ])->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonPath('data.amount', '35.00');

        $this->assertSame('2026-11-16', $movement->fresh()->movement_date->toDateString());
        $this->assertDatabaseHas('despesas', [
            'id' => $movement->legacy_id,
            'amount' => '35.00',
            'expense_date' => '2026-11-16',
        ]);
        $this->assertSame('65.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_settling_pending_income_uses_destination_account(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $category = $user->categorias()->where('type', 'receita')->firstOrFail();
        $this->actingAs($user)->postJson('/api/financial/recurrences', [
            'type' => 'income',
            'description' => 'Salário',
            'amount' => '100.00',
            'category_id' => $category->id,
            'account_id' => $account->id,
            'frequency' => 'monthly',
            'interval_value' => 1,
            'start_date' => '2026-11-15',
        ])->assertCreated();

        $movement = $user->movimentos()->where('type', 'income')->whereNotNull('recurrence_id')->firstOrFail();
        $this->putJson('/api/financial/movements/'.$movement->id.'/settle', [
            'amount' => '125.00',
            'movement_date' => '2026-11-16',
            'destination_account_id' => $account->id,
        ])->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonPath('data.amount', '125.00');

        $this->assertDatabaseHas('receitas', [
            'id' => $movement->legacy_id,
            'amount' => '125.00',
            'income_date' => '2026-11-16',
        ]);
        $this->assertSame('225.00', app(AccountBalanceService::class)->balance($account));
    }

    public function test_recurring_delete_scope_only_removes_selected_occurrence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user))->assertCreated();
        $rule = $user->recorrencias()->firstOrFail();
        $selected = $user->movimentos()->where('recurrence_id', $rule->id)->orderBy('recurrence_occurrence_date')->firstOrFail();

        $this->deleteJson('/api/financial/movements/'.$selected->id.'/recurrence-scope', ['scope' => 'only'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('void', $selected->fresh()->status);
        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame(2, $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'pending')->count());
    }

    public function test_recurring_delete_scope_this_and_future_stops_rule_and_preserves_earlier_occurrence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user))->assertCreated();
        $rule = $user->recorrencias()->firstOrFail();
        $selected = $user->movimentos()->where('recurrence_id', $rule->id)->orderBy('recurrence_occurrence_date')->skip(1)->firstOrFail();

        $this->deleteJson('/api/financial/movements/'.$selected->id.'/recurrence-scope', ['scope' => 'this_and_future'])
            ->assertOk();

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(1, $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'pending')->count());
        $this->assertSame(2, $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'void')->count());
    }

    public function test_recurring_delete_scope_all_preserves_settled_movements_and_removes_pending_occurrences(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $this->actingAs($user)->postJson('/api/financial/recurrences', $this->createRule($user, $account->id))->assertCreated();
        $rule = $user->recorrencias()->firstOrFail();
        $settled = $user->movimentos()->where('recurrence_id', $rule->id)->orderBy('recurrence_occurrence_date')->firstOrFail();
        $this->putJson('/api/financial/movements/'.$settled->id.'/settle', [
            'source_account_id' => $account->id,
        ])->assertOk();

        $this->deleteJson('/api/financial/movements/'.$settled->id.'/recurrence-scope', ['scope' => 'all'])->assertOk();

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame('settled', $settled->fresh()->status);
        $this->assertSame(1, $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'settled')->count());
        $this->assertSame(0, $user->movimentos()->where('recurrence_id', $rule->id)->where('status', 'pending')->count());
    }


}

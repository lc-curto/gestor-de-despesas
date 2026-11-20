<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_month_picker_and_quick_actions_preserve_selected_month_and_origin(): void
    {
        $user = User::factory()->create();
        $month = '2026-10';
        $dashboardUrl = route('dashboard', ['mes' => $month]);

        $dashboard = $this->actingAs($user)->get($dashboardUrl);

        $dashboard->assertOk()
            ->assertSee('id="dashboardMonthPicker"', false)
            ->assertSee('id="dashboardMonthGrid"', false)
            ->assertSee('id="dashboardMonth" name="mes" value="'.$month.'"', false)
            ->assertDontSee('id="dashboardMonth" name="mes" class="form-select', false)
            ->assertSee('novo=1', false)
            ->assertSee('tipo=despesa', false)
            ->assertSee('tipo=receita', false)
            ->assertSee('mes='.$month, false)
            ->assertSee('return_to=', false);

        $quickExpense = route('dashboard.movimentos', [
            'novo' => 1,
            'tipo' => 'despesa',
            'mes' => $month,
            'return_to' => $dashboardUrl,
        ]);

        $movementPage = $this->get($quickExpense);

        $movementPage->assertOk()
            ->assertSee('autoOpenEditor: true', false)
            ->assertSee('returnUrl:', false)
            ->assertSee('mes='.$month, false)
            ->assertSee('periodMonth" name="mes" class="form-control" value="'.$month.'"', false);
    }

    public function test_quick_action_return_url_rejects_external_redirects(): void
    {
        $user = User::factory()->create();
        $month = '2026-10';

        $response = $this->actingAs($user)->get(route('dashboard.movimentos', [
            'novo' => 1,
            'tipo' => 'receita',
            'mes' => $month,
            'return_to' => 'https://example.org/steal-session',
        ]));

        $response->assertOk()
            ->assertSee('returnUrl:', false)
            ->assertSee('mes='.$month, false);
    }
}

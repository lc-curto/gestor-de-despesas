<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_opens_movement_editor_in_place_from_quick_actions_and_mobile_new_menu(): void
    {
        $user = User::factory()->create();
        $month = '2026-10';

        $response = $this->actingAs($user)->get(route('dashboard', ['mes' => $month], false));

        $response->assertOk()
            ->assertSee('id="dashboardMonthPicker"', false)
            ->assertSee('id="dashboardMonthGrid"', false)
            ->assertSee('id="dashboardMonth" name="mes" value="'.$month.'"', false)
            ->assertSee('id="movementEntryModal"', false)
            ->assertSee('id="dashboardMovementForm"', false)
            ->assertSee('id="quickExpenseButton"', false)
            ->assertSee('id="quickIncomeButton"', false)
            ->assertSee('data-dashboard-new-movement="despesa"', false)
            ->assertSee('data-dashboard-new-movement="receita"', false)
            ->assertSee('dashboardHome: true', false)
            ->assertDontSee('id="quickExpenseButton" href=', false)
            ->assertDontSee('id="quickIncomeButton" href=', false);
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

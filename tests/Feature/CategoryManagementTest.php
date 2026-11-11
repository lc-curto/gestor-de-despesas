<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_own_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('categorias.index'))
            ->assertOk()
            ->assertSee('Categorias')
            ->assertSee('Alimentação');
    }

    public function test_user_can_create_a_custom_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('categorias.store'), [
            'name' => 'Animais de estimação',
            'type' => 'despesa',
            'icon' => 'bi-heart',
            'color' => '#2563EB',
        ])->assertRedirect(route('categorias.index'));

        $this->assertDatabaseHas('categorias', [
            'user_id' => $user->id,
            'name' => 'Animais de estimação',
            'type' => 'despesa',
            'slug' => 'animais-de-estimacao',
            'is_active' => true,
        ]);
    }

    public function test_user_cannot_access_another_users_category(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = $owner->categorias()->where('type', 'despesa')->firstOrFail();

        $this->actingAs($other)
            ->put(route('categorias.update', $category->id), [
                'name' => 'Alteração indevida',
                'type' => 'despesa',
                'icon' => 'bi-tag',
                'color' => '#635BFF',
            ])->assertNotFound();
    }

    public function test_category_with_movements_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $category = $user->categorias()->where('type', 'despesa')->firstOrFail();

        $user->despesas()->create([
            'description' => 'Compra de teste',
            'amount' => 10,
            'currency' => 'EUR',
            'category' => 'outros',
            'category_id' => $category->id,
            'expense_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->delete(route('categorias.destroy', $category->id))
            ->assertSessionHasErrors('categoria');

        $this->assertDatabaseHas('categorias', ['id' => $category->id]);
    }

    public function test_category_type_cannot_change_when_movements_are_linked(): void
    {
        $user = User::factory()->create();
        $category = $user->categorias()->where('type', 'despesa')->firstOrFail();

        $user->despesas()->create([
            'description' => 'Compra de teste',
            'amount' => 10,
            'currency' => 'EUR',
            'category' => 'outros',
            'category_id' => $category->id,
            'expense_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->put(route('categorias.update', $category->id), [
                'name' => $category->name,
                'type' => 'receita',
                'icon' => $category->icon,
                'color' => $category->color,
            ])->assertSessionHasErrors('categoria');

        $this->assertDatabaseHas('categorias', [
            'id' => $category->id,
            'type' => 'despesa',
        ]);
    }

    public function test_inactive_category_is_not_available_for_new_movements(): void
    {
        $user = User::factory()->create();
        $category = $user->categorias()->where('type', 'despesa')->firstOrFail();
        $category->update(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('despesas.create'))
            ->assertOk()
            ->assertDontSee('value="'.$category->id.'"', false);
    }
}

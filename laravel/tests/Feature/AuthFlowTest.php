<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_login_and_registration_pages(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }

    public function test_user_can_register_and_is_authenticated(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'currency' => 'EUR',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'currency' => 'EUR']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ]);

        $this->post(route('login.store'), [
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_with_legacy_bcrypt_2a_hash(): void
    {
        $password = 'Password123!';
        $bcryptHash = password_hash($password, PASSWORD_BCRYPT);
        $legacyHash = '$2a$' . substr($bcryptHash, 4);

        // Insere o hash legado diretamente para evitar o cast "hashed" do model.
        $user = User::factory()->create(['email' => 'legacy@example.com']);
        $user->forceFill(['password' => $legacyHash])->saveQuietly();

        $this->assertSame($legacyHash, $user->fresh()->getRawOriginal('password'));

        $this->post(route('login.store'), [
            'email' => 'legacy@example.com',
            'password' => $password,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame($legacyHash, $user->fresh()->getRawOriginal('password'));
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ]);

        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifica se o utilizador recebe um UUID automaticamente.
     */
    public function test_user_receives_uuid_automatically(): void
    {
        $user = User::create([
            'name' => 'Utilizador Teste',
            'email' => 'teste@example.com',
            'password' => 'Password123!',
        ]);

        $this->assertNotEmpty($user->id);
        $this->assertTrue(
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $user->id
            ) === 1
        );
    }

    /**
     * Verifica se a moeda padrão é EUR.
     */
    public function test_user_has_eur_as_default_currency(): void
    {
        $user = User::create([
            'name' => 'Utilizador Teste',
            'email' => 'moeda@example.com',
            'password' => 'Password123!',
        ]);

        $this->assertEquals('EUR', $user->currency);
    }

    /**
     * Verifica se é possível definir outra moeda.
     */
    public function test_user_can_have_a_custom_currency(): void
    {
        $user = User::create([
            'name' => 'Utilizador Teste',
            'email' => 'brasil@example.com',
            'password' => 'Password123!',
            'currency' => 'BRL',
        ]);

        $this->assertEquals('BRL', $user->currency);
    }

    /**
     * Verifica se a password é armazenada com hashing.
     */
    public function test_user_password_is_hashed(): void
    {
        $password = 'Password123!';

        $user = User::create([
            'name' => 'Utilizador Teste',
            'email' => 'password@example.com',
            'password' => $password,
        ]);

        $this->assertNotEquals($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
    }
}

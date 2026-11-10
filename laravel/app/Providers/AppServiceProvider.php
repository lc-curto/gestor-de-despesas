<?php

namespace App\Providers;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

/**
 * Adaptador Bcrypt para hashes legados exportados com o prefixo $2a$.
 *
 * O hash é normalizado apenas em memória durante a verificação.
 * Nenhum valor armazenado na base de dados é alterado.
 */
class LegacyBcryptHasher extends BcryptHasher
{
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if (is_string($hashedValue) && str_starts_with($hashedValue, '$2a$')) {
            $hashedValue = '$2y$'.substr($hashedValue, 4);
        }

        return parent::check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        // Mantém os hashes legados intactos após um login bem-sucedido.
        if (is_string($hashedValue) && str_starts_with($hashedValue, '$2a

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Substitui apenas o driver Bcrypt por um adaptador compatível
        // com hashes $2a$ legados, mantendo o verificador padrão ativo.
        $this->app->singleton('hash.driver', function ($app) {
            return new LegacyBcryptHasher($app['config']['hashing.bcrypt'] ?? []);
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}
)) {
            return false;
        }

        return parent::needsRehash($hashedValue, $options);
    }
}

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Substitui apenas o driver Bcrypt por um adaptador compatível
        // com hashes $2a$ legados, mantendo o verificador padrão ativo.
        $this->app->singleton('hash.driver', function ($app) {
            return new LegacyBcryptHasher($app['config']['hashing.bcrypt'] ?? []);
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}

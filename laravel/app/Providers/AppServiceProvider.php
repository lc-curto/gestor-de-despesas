<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Substitui o driver Bcrypt para aceitar hashes legados $2a$.
        Hash::extend('bcrypt', function ($app) {
            return new LegacyBcryptHasher($app['config']->get('hashing.bcrypt', []));
        });

        Paginator::useBootstrapFive();
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Os testes de feature exercitam handlers autenticados sem reproduzir
        // o fluxo de cookies/tokens de um browser real. A proteção CSRF da
        // aplicação permanece ativa; só o harness é neutralizado.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }
}

@extends('layouts.guest')

@section('title', 'Bem-vindo')

@section('content')
    <div class="text-center">
        <h1 class="h3 fw-bold mb-2">Meu Bolso</h1>
        <p class="text-secondary mb-4">Gestão simples das tuas finanças.</p>

        <div class="d-grid gap-2">
            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="btn btn-primary btn-lg">Entrar</a>
            @endif

            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="btn btn-outline-primary btn-lg">Criar conta</a>
            @endif
        </div>
    </div>
@endsection

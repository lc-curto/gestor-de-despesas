@extends('layouts.guest')

@section('title', 'Iniciar sessão')

@section('content')
    <p class="text-uppercase small fw-semibold text-primary mb-2 text-center">Meu Bolso</p>
    <h1 class="h2 fw-bold mb-2 text-center" id="authTitle">Bem-vindo</h1>
    <p class="text-secondary mb-4 text-center" id="authDescription">
        Inicia sessão para acompanhares as tuas finanças.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <div class="mb-4">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control form-control-lg" id="email" name="email"
                   value="{{ old('email') }}" placeholder="Introduz o teu endereço de email"
                   autocomplete="username" required autofocus>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label">Senha</label>
            <input type="password" class="form-control form-control-lg" id="password" name="password"
                   placeholder="Introduz a tua palavra-passe" autocomplete="current-password" required>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <label class="form-check-label" for="remember">
                <input class="form-check-input me-1" type="checkbox" id="remember" name="remember" value="1">
                Manter sessão
            </label>
            <a href="{{ route('password.request') }}" class="text-decoration-none">Recuperar palavra-passe</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Entrar</button>
    </form>

    <p class="text-center text-secondary mt-4 mb-0">
        Ainda não tens uma conta?
        <a href="{{ route('register') }}" class="text-decoration-none fw-semibold">Criar conta</a>
    </p>
@endsection

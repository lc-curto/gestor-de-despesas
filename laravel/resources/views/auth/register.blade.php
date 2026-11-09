@extends('layouts.guest')

@section('title', 'Criar conta')

@section('content')
    <p class="text-uppercase small fw-semibold text-primary mb-2 text-center">Meu Bolso</p>
    <h1 class="h2 fw-bold mb-2 text-center" id="authTitle">Criar conta</h1>
    <p class="text-secondary mb-4 text-center" id="authDescription">
        Cria a tua conta para começares a gerir as tuas finanças.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $erro)
                    <li>{{ $erro }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}">
        @csrf
        <div class="mb-3">
            <label for="name" class="form-label">Nome completo</label>
            <input type="text" class="form-control form-control-lg" id="name" name="name"
                   value="{{ old('name') }}" placeholder="Ex.: Maria Silva" autocomplete="name"
                   maxlength="80" required>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control form-control-lg" id="email" name="email"
                   value="{{ old('email') }}" placeholder="Introduz o teu endereço de email"
                   autocomplete="username" required>
        </div>

        <div class="mb-3">
            <label for="currency" class="form-label">Moeda</label>
            <select class="form-select form-select-lg" id="currency" name="currency" required>
                @foreach (['EUR' => 'Euro (EUR €)', 'BRL' => 'Real brasileiro (BRL R$)', 'USD' => 'Dólar americano (USD $)', 'GBP' => 'Libra esterlina (GBP £)'] as $codigo => $nome)
                    <option value="{{ $codigo }}" @selected(old('currency', 'EUR') === $codigo)>{{ $nome }}</option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Senha</label>
            <input type="password" class="form-control form-control-lg" id="password" name="password"
                   autocomplete="new-password" minlength="8" required>
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Confirmar palavra-passe</label>
            <input type="password" class="form-control form-control-lg" id="password_confirmation"
                   name="password_confirmation" autocomplete="new-password" minlength="8" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Criar conta</button>
    </form>

    <p class="text-center text-secondary mt-4 mb-0">
        Já tens uma conta?
        <a href="{{ route('login') }}" class="text-decoration-none fw-semibold">Iniciar sessão</a>
    </p>
@endsection

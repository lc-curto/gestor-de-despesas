@extends('layouts.guest')

@section('title', 'Iniciar sessão')

@section('content')
    <h1 class="h2 fw-bold text-center">Bem-vindo</h1>
    <p class="text-secondary text-center mb-4">O teu dinheiro sob controlo.</p>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input class="form-control form-control-lg" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Palavra-passe</label>
            <input class="form-control form-control-lg" id="password" name="password" type="password"
                   required autocomplete="current-password">
        </div>
        <div class="d-flex justify-content-between mb-4">
            <label><input type="checkbox" name="remember" value="1"> Manter sessão</label>
            <a href="{{ route('password.request') }}">Recuperar palavra-passe</a>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Entrar</button>
    </form>
    <p class="text-center mt-4">Não tens conta? <a href="{{ route('register') }}">Criar conta</a></p>
@endsection

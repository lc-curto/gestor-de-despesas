@extends('layouts.guest')

@section('title', 'Criar conta')

@section('content')
    <h1 class="h2 fw-bold text-center">Criar conta</h1>
    <p class="text-secondary text-center mb-4">Regista-te no Meu Bolso.</p>

    @if ($errors->any())
        <div class="alert alert-danger">
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
            <label class="form-label" for="name">Nome</label>
            <input id="name" name="name" class="form-control" value="{{ old('name') }}" required maxlength="80">
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="form-control" value="{{ old('email') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="currency">Moeda principal</label>
            <select id="currency" name="currency" class="form-select">
                @foreach (['EUR', 'BRL', 'USD', 'GBP'] as $codigo)
                    <option value="{{ $codigo }}" @selected(old('currency', 'EUR') === $codigo)>{{ $codigo }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Palavra-passe</label>
            <input id="password" name="password" type="password" class="form-control" minlength="8" required>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Confirmar palavra-passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" minlength="8" required>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Criar conta</button>
    </form>
    <p class="text-center mt-4">Já tens conta? <a href="{{ route('login') }}">Iniciar sessão</a></p>
@endsection

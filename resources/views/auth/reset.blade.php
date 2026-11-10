@extends('layouts.guest')

@section('title', 'Nova palavra-passe')

@section('content')
    <p class="text-uppercase small fw-semibold text-primary mb-2 text-center">Meu Bolso</p>
    <h1 class="h3 fw-bold text-center mb-2">Nova palavra-passe</h1>
    <p class="text-secondary text-center mb-4">Define uma nova palavra-passe para a tua conta.</p>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="form-control form-control-lg"
                   value="{{ request('email') }}" autocomplete="email" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Nova palavra-passe</label>
            <input id="password" name="password" type="password" class="form-control form-control-lg"
                   autocomplete="new-password" minlength="8" required>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Confirmar palavra-passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   class="form-control form-control-lg" autocomplete="new-password" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">Guardar nova palavra-passe</button>
    </form>
@endsection

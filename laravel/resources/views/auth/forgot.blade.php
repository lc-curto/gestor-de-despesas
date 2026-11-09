@extends('layouts.guest')

@section('title', 'Recuperar palavra-passe')

@section('content')
    <p class="text-uppercase small fw-semibold text-primary mb-2 text-center">Meu Bolso</p>
    <h1 class="h3 fw-bold text-center mb-2">Recuperar acesso</h1>
    <p class="text-secondary text-center mb-4">Enviaremos uma ligação para o email associado à tua conta.</p>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-4">
            <label class="form-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="form-control form-control-lg"
                   value="{{ old('email') }}" autocomplete="email" required autofocus>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Enviar ligação</button>
    </form>
    <p class="text-center mt-4 mb-0"><a href="{{ route('login') }}" class="text-decoration-none">Voltar ao início de sessão</a></p>
@endsection

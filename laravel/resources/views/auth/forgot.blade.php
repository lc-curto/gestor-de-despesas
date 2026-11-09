@extends('layouts.guest')

@section('title', 'Recuperar palavra-passe')

@section('content')
    <h1 class="h3 fw-bold text-center">Recuperar acesso</h1>
    <p class="text-secondary text-center mb-4">Enviaremos uma ligação para o email associado à conta.</p>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" class="form-control mb-3" value="{{ old('email') }}" required>
        <button class="btn btn-primary w-100" type="submit">Enviar ligação</button>
    </form>
    <p class="text-center mt-3"><a href="{{ route('login') }}">Voltar</a></p>
@endsection

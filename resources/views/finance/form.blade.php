@extends('layouts.app')

@section('title', $registo ? 'Editar registo' : 'Novo registo')

@section('content')
@php
    $lockedFinancial = $financialMovement?->status === 'settled';
    $selectedAccountId = $financialMovement
        ? ($financialMovement->source_account_id ?: $financialMovement->destination_account_id)
        : null;
@endphp
<header class="page-heading mb-4">
    <a class="small text-decoration-none" href="{{ $returnUrl }}"><i data-lucide="arrow-left" class="me-1"></i> Voltar</a>
    <p class="eyebrow mt-3 mb-1">Movimentos financeiros</p>
    <h1 class="h2 fw-bold mb-1">{{ $registo ? 'Editar' : 'Novo' }} {{ $tipo }}</h1>
    <p class="text-secondary mb-0">{{ $registo ? 'Atualiza os dados deste movimento.' : 'Regista um novo movimento financeiro.' }}</p>
</header>
@if ($errors->any())
    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<form method="POST" action="{{ $registo ? route($tipo === 'despesa' ? 'despesas.update' : 'receitas.update', $registo->id) : route($tipo === 'despesa' ? 'despesas.store' : 'receitas.store') }}" class="card border-0 p-3 p-lg-4">
    @csrf
    @if ($registo) @method('PUT') @endif
    @if (request('return_to')) <input type="hidden" name="return_to" value="{{ request('return_to') }}"> @endif
    <div class="mb-3">
        <label for="description" class="form-label">Descrição</label>
        <input id="description" name="description" class="form-control" maxlength="255" value="{{ old('description', $registo?->description) }}" required>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="amount" class="form-label">Valor</label>
        <input id="amount" name="amount" type="number" min="0.01" step="0.01" class="form-control" value="{{ old('amount', $registo?->amount) }}" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Moeda da conta</label>
            <input class="form-control" value="{{ auth()->user()->currency }}" readonly>
            <div class="form-text">Todos os movimentos utilizam a moeda definida na conta.</div>
        </div>
        <div class="col-md-6">
            <label for="account_id" class="form-label">Conta</label>
            <select id="account_id" name="account_id" class="form-select" required>
                <option value="">Selecionar conta</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected(old('account_id', $selectedAccountId) === $account->id)>{{ $account->name }} · {{ $account->currency }}</option>
                @endforeach
            </select>
            @if ($accounts->isEmpty())
                <div class="form-text text-warning">Cria uma conta ativa e configura o saldo inicial em <a href="{{ route('financial.accounts.page') }}">Contas</a> antes de registar movimentos.</div>
            @endif
        </div>
        <div class="col-md-6">
            <label for="category_id" class="form-label">Categoria</label>
            <select id="category_id" name="category_id" class="form-select" required>
                <option value="">Selecionar</option>
                @foreach ($cats as $id => $label)
                    <option value="{{ $id }}" @selected(old('category_id', $registo?->category_id) === $id)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-text"><a href="{{ route('categorias.index') }}">Gerir categorias</a></div>
        </div>
        <div class="col-md-6">
            <label for="{{ $data }}" class="form-label">Data</label>
            <input id="{{ $data }}" name="{{ $data }}" type="date" class="form-control" value="{{ old($data, $registo?->{$data}?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
        </div>
    </div>
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary" @disabled($accounts->isEmpty())>Guardar</button>
        <a class="btn btn-outline-secondary" href="{{ $returnUrl }}">Cancelar</a>
    </div>
</form>
@endsection

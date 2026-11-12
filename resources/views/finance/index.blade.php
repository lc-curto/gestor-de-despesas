@extends('layouts.app')

@section('title', $tipo === 'despesa' ? 'Despesas' : 'Receitas')

@section('content')
@php
    $isExpense = $tipo === 'despesa';
    $pageTitle = $isExpense ? 'Despesas' : 'Receitas';
@endphp

<header class="finance-page-heading">
    <div class="finance-page-copy">
        <h1 class="h2 fw-bold mb-1">{{ $pageTitle }}</h1>
    </div>
    <a class="btn btn-primary finance-create-button" href="{{ route($isExpense ? 'despesas.create' : 'receitas.create') }}">
        <i data-lucide="plus" aria-hidden="true"></i>
        <span>Novo</span>
    </a>
</header>

<form method="GET" class="finance-filter-card">
    <div class="finance-filter-heading">
        <h2 class="h6 fw-bold mb-0">Filtros</h2>
        <button
            class="btn btn-outline-primary btn-sm"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#financeFilterFields"
            aria-expanded="{{ request()->hasAny(['categoria', 'inicio', 'fim']) ? 'true' : 'false' }}"
            aria-controls="financeFilterFields">
            <i data-lucide="sliders-horizontal" aria-hidden="true"></i>
            <span>Filtros</span>
            <i data-lucide="chevron-down" class="finance-filter-chevron" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse {{ request()->hasAny(['categoria', 'inicio', 'fim']) ? 'show' : '' }}" id="financeFilterFields">
        <div class="finance-filter-fields">
            <div class="finance-filter-category">
                <label for="categoryFilter" class="form-label">Categoria</label>
                <select id="categoryFilter" name="categoria" class="form-select">
                    <option value="">Todas as categorias</option>
                    @foreach ($cats as $id => $label)
                        <option value="{{ $id }}" @selected(request('categoria') === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="startDate" class="form-label">Data inicial</label>
                <input id="startDate" type="date" name="inicio" class="form-control" value="{{ request('inicio') }}">
            </div>
            <div>
                <label for="endDate" class="form-label">Data final</label>
                <input id="endDate" type="date" name="fim" class="form-control" value="{{ request('fim') }}">
            </div>
            <div class="finance-filter-submit">
                <button class="btn btn-primary btn-sm" type="submit">Aplicar filtros</button>
            </div>
        </div>
    </div>
</form>

<section class="finance-records-card" aria-label="Lista de {{ strtolower($pageTitle) }}">
    <div class="finance-records-heading">
        <div>
            <h2 class="h5 fw-bold mb-0">{{ $pageTitle }}</h2>
        </div>
        <span class="finance-record-count">{{ $registos->total() }} {{ $registos->total() === 1 ? 'registo' : 'registos' }}</span>
    </div>

    <div class="finance-record-list">
        @forelse ($registos as $item)
            @php $financialMovement = $linkedMovements->get($item->id); @endphp
            <article class="finance-record-item">
                <div class="finance-record-main">
                    <span class="category-color-icon finance-category-icon" style="--category-color: {{ $item->categoria?->color ?? '#64748B' }}" aria-hidden="true">
                        <i data-lucide="{{ $item->categoria?->icon ?? 'tag' }}"></i>
                    </span>
                    <div class="finance-record-copy">
                        <strong>{{ $item->description }}</strong>
                        <span>{{ $item->{$data}->format('d/m/Y') }} <span aria-hidden="true">·</span> {{ $item->categoria?->name ?? 'Categoria indisponível' }}
                            @if ($financialMovement?->requires_account_assignment)
                                <span class="text-warning">· Conta por associar — usa Editar</span>
                            @elseif ($financialMovement?->is_historical && $financialMovement?->contaOrigem?->type === 'legacy' || $financialMovement?->is_historical && $financialMovement?->contaDestino?->type === 'legacy')
                                <span class="text-secondary">· Histórico — associa uma conta em Editar</span>
                            @elseif ($financialMovement?->contaOrigem || $financialMovement?->contaDestino)
                                <span>· {{ $financialMovement->contaOrigem?->name ?? $financialMovement->contaDestino?->name }}</span>
                            @endif
                        </span>
                    </div>
                </div>
                <div class="finance-record-actions">
                    <strong class="finance-record-amount {{ $isExpense ? 'text-danger' : 'text-success' }}">
                        {{ $isExpense ? '−' : '+' }}{{ number_format((float) $item->amount, 2, ',', '.') }} <small>{{ $item->currency }}</small>
                    </strong>
                    <div class="finance-action-buttons">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route($isExpense ? 'despesas.edit' : 'receitas.edit', $item->id) }}" aria-label="Editar {{ $item->description }}" title="Editar">
                            <i data-lucide="pencil" aria-hidden="true"></i>
                        </a>
                        @if ($financialMovement?->status !== 'settled')
                            <form method="POST" action="{{ route($isExpense ? 'despesas.destroy' : 'receitas.destroy', $item->id) }}" onsubmit="return confirm('Eliminar este registo?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Eliminar {{ $item->description }}" title="Eliminar">
                                    <i data-lucide="trash-2" aria-hidden="true"></i>
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="finance-empty-state">
                <span class="finance-empty-icon" aria-hidden="true"><i data-lucide="{{ $isExpense ? 'wallet' : 'trending-up' }}"></i></span>
                <h3 class="h6 fw-bold">Ainda não existem {{ strtolower($pageTitle) }}</h3>
                <a class="btn btn-primary btn-sm mt-3" href="{{ route($isExpense ? 'despesas.create' : 'receitas.create') }}">Novo</a>
            </div>
        @endforelse
    </div>

    @if ($registos->hasPages())
        <div class="finance-pagination">{{ $registos->links() }}</div>
    @endif
</section>
@endsection

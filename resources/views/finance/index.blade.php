@extends('layouts.app')

@section('title', $tipo === 'despesa' ? 'Despesas' : 'Receitas')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h2 fw-bold mb-0">{{ $tipo === 'despesa' ? 'Despesas' : 'Receitas' }}</h1>
    <a class="btn btn-primary" href="{{ route($tipo === 'despesa' ? 'despesas.create' : 'receitas.create') }}"><i class="bi bi-plus-lg me-1"></i> Novo registo</a>
</div>
<form method="GET" class="card border-0 p-3 mb-3">
    <div class="row g-2">
        <div class="col-md-4">
            <label for="categoryFilter" class="form-label">Categoria</label>
            <select id="categoryFilter" name="categoria" class="form-select">
                <option value="">Todas</option>
                @foreach ($cats as $id => $label)
                    <option value="{{ $id }}" @selected(request('categoria') === $id)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3"><label for="startDate" class="form-label">De</label><input id="startDate" type="date" name="inicio" class="form-control" value="{{ request('inicio') }}"></div>
        <div class="col-6 col-md-3"><label for="endDate" class="form-label">Até</label><input id="endDate" type="date" name="fim" class="form-control" value="{{ request('fim') }}"></div>
        <div class="col-md-2 d-grid align-self-end"><button class="btn btn-outline-primary">Filtrar</button></div>
    </div>
</form>
<section class="card border-0 p-3 p-lg-4">
    @forelse ($registos as $item)
        <article class="expense-item">
            <div class="expense-info">
                <strong>{{ $item->description }}</strong>
                <div class="small text-secondary">{{ $item->{$data}->format('d/m/Y') }} · {{ $item->categoria?->name ?? 'Categoria indisponível' }}</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <strong class="{{ $tipo === 'despesa' ? 'text-danger' : 'text-success' }}">{{ number_format((float) $item->amount, 2, ',', '.') }} {{ $item->currency }}</strong>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route($tipo === 'despesa' ? 'despesas.edit' : 'receitas.edit', $item->id) }}">Editar</a>
                <form method="POST" action="{{ route($tipo === 'despesa' ? 'despesas.destroy' : 'receitas.destroy', $item->id) }}" onsubmit="return confirm('Eliminar este registo?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                </form>
            </div>
        </article>
    @empty
        <p class="text-secondary mb-0">Não existem registos.</p>
    @endforelse
    <div class="mt-3">{{ $registos->links() }}</div>
</section>
@endsection

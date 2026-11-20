@extends('layouts.app')

@section('title', 'Preparar os teus dados')

@section('content')
@php
    $steps = [
        ['id' => 'accounts', 'title' => 'Contas', 'count' => $counts['accounts'], 'unit' => 'contas'], 
        ['id' => 'categories', 'title' => 'Categorias', 'count' => $counts['categories'], 'unit' => 'categorias'], 
        ['id' => 'movements', 'title' => 'Movimentos', 'count' => $counts['movements'] + $counts['legacy_expenses'] + $counts['legacy_incomes'], 'unit' => 'registos'], 
        ['id' => 'classification', 'title' => 'Classificação', 'count' => null, 'unit' => ''], 
    ];
    $stepCount = count(array_filter(array_keys($steps), fn ($i) => $stepStates[$steps[$i]['id']]['done'] ?? false));
    $active = collect($steps)->first(fn ($step) => !($stepStates[$step['id']]['done'] ?? false));
    $isFinal = $active === null;
    $activeIndex = $isFinal ? 4 : array_search($active['id'], array_column($steps, 'id'), true);
@endphp

<div class="container-fluid py-3 py-md-5" style="max-width:760px">
    <div class="mb-4">
        <h1 class="h2 fw-bold mb-2">Revisão de dados</h1>
    </div>

    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small fw-semibold">O teu progresso</span>
                <span class="small text-secondary">{{ $stepCount }} de 4 concluídas</span>
            </div>
            <div class="progress mb-3" style="height:7px" role="progressbar" aria-valuenow="{{ $stepCount * 25 }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width:{{ $stepCount * 25 }}%;background:#635bff"></div>
            </div>
            <div class="d-flex gap-2">
                @foreach (['Contas','Categorias','Movimentos','Classificação'] as $label)
                    <span class="flex-fill rounded-pill {{ $loop->index < $stepCount ? 'bg-success' : 'bg-body-secondary' }}" style="height:5px" title="{{ $label }}"></span>
                @endforeach
            </div>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-danger rounded-3">{{ $errors->first() }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success rounded-3">{{ session('success') }}</div>
    @endif

    @if (!$isFinal)
        @php
            $state = $stepStates[$active['id']];
            $changed = $state['changed'];
        @endphp
        <section class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="small fw-bold text-uppercase text-secondary">Etapa {{ $activeIndex + 1 }} de 4</span>
                    @if ($changed)
                        <span class="badge rounded-pill text-bg-info">Alterações detetadas</span>
                    @else
                        <span class="badge rounded-pill text-bg-warning">A confirmar</span>
                    @endif
                </div>
                <h2 class="h3 fw-bold mb-1">{{ $active['title'] }}</h2>

                @if (!is_null($active['count']))
                    <div class="rounded-3 bg-body-tertiary p-3 mb-3">
                        <span class="d-block h3 fw-bold mb-0">{{ number_format($active['count'], 0, ',', '.') }}</span>
                        <span class="small text-secondary">{{ $active['unit'] }}</span>
                    </div>
                @endif


                <div class="d-grid gap-2 mb-4">
                    @if ($active['id'] === 'accounts')
                        <a class="btn btn-outline-primary rounded-3" href="{{ route('financial.accounts.page') }}">Contas <i data-lucide="arrow-up-right" class="ms-1" aria-hidden="true"></i></a>
                    @elseif ($active['id'] === 'categories')
                        <a class="btn btn-outline-primary rounded-3" href="{{ route('categorias.index') }}">Categorias <i data-lucide="arrow-up-right" class="ms-1" aria-hidden="true"></i></a>
                    @elseif ($active['id'] === 'movements')
                        <a class="btn btn-outline-primary rounded-3" href="{{ route('dashboard.movimentos') }}">Movimentos <i data-lucide="arrow-up-right" class="ms-1" aria-hidden="true"></i></a>
                        <div class="d-flex gap-2">
                            <a class="btn btn-light border rounded-3 flex-fill" href="{{ route('despesas.index') }}">Despesas</a>
                            <a class="btn btn-light border rounded-3 flex-fill" href="{{ route('receitas.index') }}">Receitas</a>
                        </div>
                    @else
                        <a class="btn btn-outline-primary rounded-3" href="{{ route('dashboard.movimentos') }}">Rever <i data-lucide="arrow-up-right" class="ms-1" aria-hidden="true"></i></a>
                    @endif
                </div>

                <div class="border-top pt-3">
                    <form method="POST" action="{{ route('financial.review.step') }}">
                        @csrf
                        <input type="hidden" name="step" value="{{ $active['id'] }}">
                        @if ($changed)
                            <label class="d-flex gap-2 align-items-start rounded-3 border p-3 mb-2">
                                <input class="form-check-input mt-1" type="radio" name="decision" value="changes_reviewed" required>
                                <span><strong class="d-block small">Alterações revistas</strong></span>
                            </label>
                        @else
                            <label class="d-flex gap-2 align-items-start rounded-3 border p-3 mb-2">
                                <input class="form-check-input mt-1" type="radio" name="decision" value="confirmed_correct" required>
                                <span><strong class="d-block small">Sem alterações</strong></span>
                            </label>
                        @endif
                        <div class="form-check my-3">
                            <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="review-confirm" required>
                            <label class="form-check-label small" for="review-confirm">Confirmo que verifiquei esta etapa.</label>
                        </div>
                        <button class="btn btn-primary rounded-3 w-100 py-2" type="submit">Guardar <i data-lucide="arrow-right" class="ms-1" aria-hidden="true"></i></button>
                    </form>
                </div>
            </div>
        </section>
    @else
        <section class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4 text-center">
                <div class="rounded-circle bg-success-subtle text-success d-inline-flex align-items-center justify-content-center mb-3" style="width:60px;height:60px">
                    <i data-lucide="check-check" style="width:30px;height:30px" aria-hidden="true"></i>
                </div>
                <h2 class="h3 fw-bold">Está tudo pronto.</h2>
                <form method="POST" action="{{ route('financial.review.step') }}">
                    @csrf
                    <input type="hidden" name="step" value="final">
                    <div class="form-check text-start my-3">
                        <input class="form-check-input" type="checkbox" name="confirmed" value="1" id="confirm-final" required>
                        <label class="form-check-label small" for="confirm-final">Confirmo que revi os dados e quero continuar.</label>
                    </div>
                    <button class="btn btn-primary rounded-3 w-100 py-2" type="submit">Concluir</button>
                </form>
            </div>
        </section>
    @endif

    <div class="d-flex align-items-center justify-content-center gap-2 text-secondary small mt-4">
        <i data-lucide="save" aria-hidden="true"></i>

    </div>
</div>
@endsection

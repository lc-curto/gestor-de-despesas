@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $simbolos = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
@endphp

<header class="dashboard-intro">
    <div class="dashboard-intro-copy">
        <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span> Resumo financeiro</p>
        <h1 class="h2 fw-bold" id="greetingTitle">Olá, {{ auth()->user()->name }}!</h1>
    </div>
    <div class="date-time-card" aria-label="Data e hora atuais em Lisboa">
        <span class="date-time-label">Agora</span>
        <time id="currentDateTime" datetime="{{ now()->toIso8601String() }}">{{ now()->locale('pt_PT')->translatedFormat('l, d \\d\\e F \\d\\e Y') }} às {{ now()->format('H:i') }}</time>
    </div>
</header>

<section class="row g-3 mb-4" aria-label="Resumo financeiro">
    <div class="col-4 col-xl-4 dashboard-summary-column">
        <article class="summary-card summary-card-primary h-100">
            <span class="summary-icon" aria-hidden="true"><i data-lucide="wallet"></i></span>
            <div class="d-flex align-items-center gap-3">
                <div class="min-w-0">
                    <p class="small mb-1 opacity-75">Saldo do período</p>
                    <p class="summary-value summary-value-text mb-0" id="balanceAmount" data-total="{{ $totalReceitas - $totalDespesas }}">{{ number_format($totalReceitas - $totalDespesas, 2, ',', '.') }} {{ $moeda }}</p>
                </div>
            </div>
        </article>
    </div>
    <div class="col-4 col-xl-4 dashboard-summary-column">
        <article class="summary-card summary-card-expense h-100">
            <span class="summary-icon" aria-hidden="true"><i data-lucide="arrow-down-right"></i></span>
            <div class="d-flex align-items-center gap-3">
                <div>
                    <p class="small text-secondary mb-1">Despesas</p>
                    <p class="summary-value mb-0 text-danger" id="totalAmount" data-total="{{ $totalDespesas }}">{{ number_format($totalDespesas, 2, ',', '.') }} {{ $moeda }}</p>
                </div>
            </div>
        </article>
    </div>
    <div class="col-4 col-xl-4 dashboard-summary-column">
        <article class="summary-card summary-card-income h-100">
            <span class="summary-icon" aria-hidden="true"><i data-lucide="arrow-up-right"></i></span>
            <div class="d-flex align-items-center gap-3">
                <div>
                    <p class="small text-secondary mb-1">Receitas</p>
                    <p class="summary-value mb-0 text-success" id="totalIncome" data-total="{{ $totalReceitas }}">{{ number_format($totalReceitas, 2, ',', '.') }} {{ $moeda }}</p>
                </div>
            </div>
        </article>
    </div>
</section>

<section class="dashboard-entry-bar" aria-label="Novo registo">
    <strong>Novo registo</strong>
    <div class="btn-group" role="group" aria-label="Tipo de registo">
        <button type="button" class="btn btn-danger" id="quickExpenseButton"><i data-lucide="arrow-down-right" aria-hidden="true"></i> Despesa</button>
        <button type="button" class="btn btn-outline-success" id="quickIncomeButton"><i data-lucide="arrow-up-right" aria-hidden="true"></i> Receita</button>
    </div>
</section>

<section class="row g-3 g-xl-4 align-items-start" aria-label="Movimentos">
    <div class="col-12">
        <div class="modal fade" id="movementEntryModal" tabindex="-1" aria-labelledby="recordTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 bg-transparent">
        <article id="movementEntryCard" class="card border-0 shadow-sm rounded-4 movement-editor-card">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i data-lucide="plus" class="text-primary" aria-hidden="true"></i>
                    <div>
                        <h2 id="recordTitle" class="h5 fw-bold mb-0">Adicionar despesa</h2>
                    </div>
                </div>

                <div class="btn-group w-100 record-type-buttons mb-3" role="group" aria-label="Tipo de lançamento">
                    <button type="button" class="btn btn-danger active" id="expenseTypeButton" aria-pressed="true" aria-controls="recordEditorPanel" aria-expanded="true"><i data-lucide="arrow-down-right" class="me-1"></i>Despesa</button>
                    <button type="button" class="btn btn-outline-success" id="incomeTypeButton" aria-pressed="false" aria-controls="recordEditorPanel" aria-expanded="true"><i data-lucide="arrow-up-right" class="me-1"></i>Receita</button>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger small"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <div id="recordEditorPanel">
                    <form id="dashboardMovementForm" method="POST" action="{{ route('despesas.store') }}">
                        @csrf
                        <input type="hidden" name="_method" id="movementMethod" value="POST">
                        <div class="mb-3">
                            <label for="movementDescription" class="form-label" id="descriptionLabel">Descrição</label>
                            <input type="text" class="form-control" id="movementDescription" name="description" placeholder="Ex.: Supermercado" value="{{ old('description') }}" maxlength="255" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="movementAmount" class="form-label">Valor</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="movementAmount" name="amount" placeholder="0,00" min="0.01" step="0.01" value="{{ old('amount') }}" required>
                                    <span class="input-group-text" id="amountCurrencySymbol">{{ $simbolos[$moeda] ?? $moeda }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <label for="movementDate" class="form-label">Data</label>
                                <input type="date" class="form-control" id="movementDate" name="expense_date" value="{{ old('expense_date', old('income_date', now()->toDateString())) }}" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="movementCategory" class="form-label">Categoria</label>
                            <select class="form-select" id="movementCategory" name="category_id" required>
                                <option value="" selected disabled>Seleciona uma categoria</option>
                                @foreach ($categoriasDespesa as $id => $label)
                                    <option value="{{ $id }}" @selected((string) old('category_id') === (string) $id)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger w-100" id="saveMovementButton">Guardar despesa</button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="cancelMovementEdit">Cancelar edição</button>
                        </div>
                    </form>
                </div>
            </div>
        </article>
            </div></div>
        </div>
    </div>

    <div class="col-12">
        <article class="card border-0 shadow-sm rounded-4 history-card">
            <div class="card-body p-0 history-card-body">
                <div class="p-3 p-sm-4 pb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <h2 class="h5 fw-bold mb-0">Movimentos</h2>
                        <form method="GET" action="{{ route('dashboard') }}" id="periodForm" class="period-controls dashboard-period-controls">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="periodPrevious" aria-label="Mês anterior"><i data-lucide="chevron-left"></i></button>
                            <label for="periodMonth" class="visually-hidden">Selecionar mês</label>
                            <input type="month" id="periodMonth" name="mes" class="form-control" value="{{ $mes }}">
                            <input type="hidden" name="tipo" value="{{ $filtroTipo }}">
                            <input type="hidden" name="categoria" value="{{ $filtroCategoria }}">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="periodNext" aria-label="Mês seguinte"><i data-lucide="chevron-right"></i></button>
                        </form>
                    </div>
                </div>

                <div class="px-3 px-sm-4 border-bottom">
                    <form method="GET" action="{{ route('dashboard') }}" class="transaction-filters d-flex flex-wrap align-items-center gap-2 pb-3">
                        <input type="hidden" name="mes" value="{{ $mes }}">
                        <input type="hidden" name="tipo" value="{{ $filtroTipo }}">
                        <button class="btn {{ $filtroTipo === 'todos' ? 'btn-primary' : 'btn-link text-decoration-none' }} mobile-tap-target" name="tipo" value="todos">Tudo</button>
                        <button class="btn {{ $filtroTipo === 'despesa' ? 'btn-primary' : 'btn-link text-decoration-none' }} mobile-tap-target" name="tipo" value="despesa">Despesas</button>
                        <button class="btn {{ $filtroTipo === 'receita' ? 'btn-primary' : 'btn-link text-decoration-none' }} mobile-tap-target" name="tipo" value="receita">Receitas</button>
                        <div class="ms-auto filter-control">
                            <label for="categoryFilter" class="visually-hidden">Filtrar por categoria</label>
                            <select class="form-select" id="categoryFilter" name="categoria">
                                <option value="all">Todas as categorias</option>
                                @foreach ($categorias as $key => $label)
                                    <option value="{{ $key }}" @selected($filtroCategoria === (string) $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                </div>

                @php
                    $totalVisivel = $filtroTipo === 'despesa'
                        ? $totalDespesasFiltradas
                        : ($filtroTipo === 'receita' ? $totalReceitasFiltradas : $totalMovimentos);
                @endphp
                <div id="expensesList" class="expense-list history-list px-3 px-sm-4" aria-live="polite">
                    @forelse ($movimentosVisiveis as $movimento)
                        @php
                            $isExpense = $movimento->tipo === 'despesa';
                            $dataMovimento = \Illuminate\Support\Carbon::parse($movimento->data_movimento);
                            $categoriaLabel = $categorias[$movimento->category] ?? $movimento->category;
                        @endphp
                        <article class="expense-item d-flex justify-content-between align-items-center gap-3 py-3 border-bottom"
                            data-movement-id="{{ $movimento->id }}" data-movement-type="{{ $movimento->tipo }}"
                            data-edit-url="{{ route($isExpense ? 'despesas.update' : 'receitas.update', $movimento->id) }}"
                            data-date="{{ $dataMovimento->format('Y-m-d') }}" data-description="{{ $movimento->description }}"
                            data-amount="{{ $movimento->amount }}" data-currency="{{ $movimento->currency }}"
                            data-category-id="{{ $movimento->category }}">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="expense-icon flex-shrink-0" style="--category-color: {{ $movimento->category_color ?? '#635BFF' }}" aria-hidden="true">
                                    <i data-lucide="{{ $movimento->category_icon ?? 'tag' }}"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="h6 fw-semibold mb-1 text-break">{{ $movimento->description }}</h3>
                                    <p class="small text-secondary mb-0">{{ $categoriaLabel }} · {{ $dataMovimento->format('d/m/Y') }}</p>
                                </div>
                            </div>
                            <div class="expense-actions d-flex align-items-center justify-content-end gap-2">
                                <strong class="{{ $isExpense ? 'text-danger' : 'text-success' }} text-nowrap movement-value"
                                    data-value="{{ $movimento->amount }}" data-currency="{{ $movimento->currency }}">{{ $isExpense ? '−' : '+' }}{{ number_format((float) $movimento->amount, 2, ',', '.') }}</strong>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Ações de {{ $movimento->description }}">
                                    <button type="button" class="btn btn-outline-secondary edit-movement" data-acao="editar" aria-label="Editar {{ $movimento->description }}" title="Editar"><i data-lucide="pencil" aria-hidden="true"></i></button>
                                    <form method="POST" action="{{ route($isExpense ? 'despesas.destroy' : 'receitas.destroy', $movimento->id) }}" data-delete-type="{{ $movimento->tipo }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger" aria-label="Eliminar {{ $movimento->description }}" title="Eliminar"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="empty-state text-center py-4">
                            <div class="empty-icon mb-3" aria-hidden="true">◎</div>
                            @if ($totalVisivel > 0)
                                <h3 class="h6">Mais movimentos por carregar</h3>
                                <p class="small text-secondary mb-0">Não há movimentos deste tipo nesta primeira página. Carrega mais para continuar.</p>
                            @else
                                <h3 class="h6">Ainda não existem movimentos</h3>
                                <p class="small text-secondary mb-0">Adiciona uma despesa ou receita para começar.</p>
                            @endif
                        </div>
                    @endforelse
                    @if ($movimentosVisiveis->count() < $totalVisivel)
                        <div class="d-flex justify-content-center py-3">
                            <button type="button" id="loadMoreMovements" class="btn btn-outline-primary btn-sm px-4">Ver mais movimentos</button>
                        </div>
                    @endif
                </div>
            </div>
        </article>
    </div>
</section>

<section class="mt-4" aria-labelledby="currencyTitle">
    <article class="currency-card rounded-4 p-3 p-md-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-5">
                <div class="d-flex align-items-start gap-3">
                    <div class="currency-icon" aria-hidden="true"><i data-lucide="arrow-left-right"></i></div>
                    <div>
                        <h2 id="currencyTitle" class="h5 fw-bold mb-1">Conversor de moedas</h2>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-4 col-lg-2">
                <label for="conversionAmount" class="form-label">Valor</label>
                <input type="number" class="form-control" id="conversionAmount" placeholder="100,00" min="0.01" step="0.01">
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label for="conversionFrom" class="form-label">De</label>
                <select class="form-select" id="conversionFrom">
                    @foreach (\App\Support\Categorias::MOEDAS as $currency)
                        <option value="{{ $currency }}">{{ $currency }} ({{ $simbolos[$currency] ?? $currency }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label for="conversionTo" class="form-label">Para</label>
                <select class="form-select" id="conversionTo">
                    @foreach (\App\Support\Categorias::MOEDAS as $currency)
                        <option value="{{ $currency }}">{{ $currency }} ({{ $simbolos[$currency] ?? $currency }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-lg-1">
                <button type="button" class="btn btn-primary w-100" id="convertButton" aria-label="Converter valor"><i data-lucide="arrow-right"></i></button>
            </div>
            <div class="col-12">
                <div id="conversionResult" class="conversion-result" aria-live="polite">
                    <strong id="convertedValue">—</strong>
                    <span id="conversionRate" class="small text-secondary"></span>
                </div>
            </div>
        </div>
    </article>
</section>
@endsection

@push('scripts')
<script>
window.meuBolsoDashboard = {
    userId: @json(auth()->id()),
    name: @json(auth()->user()->name),
    currency: @json($moeda),
    initialType: @json(old('income_date') ? 'receita' : 'despesa'),
    initialCategory: @json(old('category_id')),
    type: @json($filtroTipo),
    category: @json($filtroCategoria),
    hasMore: @json($totalMovimentos > $limit),
    initialExpenseCount: @json($totalDespesasFiltradas),
    initialIncomeCount: @json($totalReceitasFiltradas),
    initialMovements: @json($movimentosIniciais),
    movementsUrl: @json(route('dashboard.movimentos')),
    expenseStore: @json(route('despesas.store')),
    incomeStore: @json(route('receitas.store')),
    expenseUpdateBase: @json(url('/despesas')),
    incomeUpdateBase: @json(url('/receitas')),
    expenseCategories: @json($categoriasDespesa),
    incomeCategories: @json($categoriasReceita),
    categories: @json($categorias)
};
</script>
@if ($errors->any())
<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('movementEntryModal')).show());</script>
@endif
<script src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}" defer></script>
@endpush

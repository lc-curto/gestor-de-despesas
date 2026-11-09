@extends('layouts.app')

@section('title', 'Painel')

@section('content')
@php
    $simbolos = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $categoriasDespesa = \App\Support\Categorias::DESPESAS;
    $categoriasReceita = \App\Support\Categorias::RECEITAS;
@endphp

<header class="dashboard-hero mb-3 mb-lg-5">
    <div class="row align-items-end g-3">
        <div class="col-12 col-lg-8">
            <div class="eyebrow mb-3"><span class="eyebrow-dot"></span> Resumo financeiro</div>
            <h1 class="display-6 fw-bold mb-2">Olá, {{ auth()->user()->name }}</h1>
        </div>
        <div class="col-12 col-lg-4">
            <div class="date-time-card d-flex flex-column flex-sm-row justify-content-between gap-1 text-lg-end">
                <span class="small text-secondary">Hoje</span>
                <time>{{ now()->locale('pt_PT')->translatedFormat('l, d \d\e F \d\e Y · H:i') }}</time>
            </div>
        </div>
    </div>
</header>

<section class="row g-2 g-md-3 mb-2 mb-lg-4" aria-label="Resumo financeiro">
    <div class="col-4 col-md-4">
        <article class="summary-card summary-card-primary h-100">
            <div class="d-flex justify-content-between align-items-center gap-3">
                <div>
                    <p class="small mb-1 opacity-75">Saldo do período</p>
                    <p class="summary-value summary-value-text mb-0">{{ number_format($totalReceitas - $totalDespesas, 2, ',', '.') }} {{ $moeda }}</p>
                    <p class="small mb-0 mt-2 opacity-75 d-none d-md-block">Receitas menos despesas</p>
                </div>
                <span class="summary-icon d-none d-md-flex" aria-hidden="true"><i class="bi bi-wallet2"></i></span>
            </div>
        </article>
    </div>
    <div class="col-4 col-md-4">
        <article class="summary-card summary-card-compact h-100">
            <p class="small text-secondary mb-2">Despesas</p>
            <p class="summary-value mb-0 text-danger">{{ number_format($totalDespesas, 2, ',', '.') }} {{ $moeda }}</p>
            <p class="small text-secondary mb-0 mt-2">Total no período</p>
        </article>
    </div>
    <div class="col-4 col-md-4">
        <article class="summary-card summary-card-compact h-100">
            <p class="small text-secondary mb-2">Receitas</p>
            <p class="summary-value mb-0 text-success">{{ number_format($totalReceitas, 2, ',', '.') }} {{ $moeda }}</p>
            <p class="small text-secondary mb-0 mt-2">Total no período</p>
        </article>
    </div>
</section>

@if ($despesasPorMoeda->keys()->merge($receitasPorMoeda->keys())->unique()->count() > 1)
    <div class="alert alert-info small">Os totais principais consideram {{ $moeda }}. Existem movimentos noutras moedas; estes são apresentados individualmente e não são somados sem conversão.</div>
@endif

<section class="row g-2 g-lg-4 mb-3 mb-lg-4 align-items-start" aria-label="Lançamentos e movimentos">
    <div class="col-12 col-lg-4">
        <article class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-2 p-md-4">
                <div class="record-toolbar d-flex flex-row flex-lg-column align-items-center align-items-lg-stretch justify-content-between gap-2 mb-0 mb-lg-4">
                    <div>
                        <p class="text-primary fw-semibold small mb-0 mb-lg-1">Novo registo</p>
                        <h2 id="recordTitle" class="h4 fw-bold mb-0 d-none d-lg-block">Adicionar despesa</h2>
                    </div>
                    <div class="btn-group record-type-buttons" role="group" aria-label="Tipo de lançamento">
                        <button type="button" class="btn btn-danger active" id="expenseTypeButton" aria-pressed="true"><i class="bi bi-arrow-up-right me-1"></i>Despesa</button>
                        <button type="button" class="btn btn-outline-success" id="incomeTypeButton" aria-pressed="false"><i class="bi bi-arrow-down-left me-1"></i>Receita</button>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger small"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <form id="dashboardMovementForm" method="POST" action="{{ route('despesas.store') }}">
                    @csrf
                    <input type="hidden" name="_method" id="movementMethod" value="POST">
                    <input type="hidden" name="return_to_dashboard" value="1">
                    <div class="mb-3">
                        <label for="movementDescription" class="form-label" id="descriptionLabel">Descrição</label>
                        <input type="text" class="form-control" id="movementDescription" name="description" placeholder="Ex.: Supermercado" maxlength="255" value="{{ old('description') }}" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label for="movementAmount" class="form-label">Valor</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="movementAmount" name="amount" placeholder="0,00" min="0.01" step="0.01" value="{{ old('amount') }}" required>
                                <span class="input-group-text" id="amountCurrencySymbol">{{ $simbolos[$moeda] ?? $moeda }}</span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="movementDate" class="form-label">Data</label>
                            <input type="date" class="form-control" id="movementDate" name="expense_date" value="{{ old('expense_date', old('income_date', now()->toDateString())) }}" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="movementCategory" class="form-label">Categoria</label>
                        <select class="form-select" id="movementCategory" name="category" required>
                            <option value="" selected disabled>Seleciona uma categoria</option>
                            @foreach ($categoriasDespesa as $key => $label)
                                <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="d-grid gap-2"><button type="submit" class="btn btn-danger w-100" id="saveMovementButton">Guardar despesa</button><button type="button" class="btn btn-outline-secondary d-none" id="cancelMovementEdit">Cancelar edição</button></div>
                </form>
            </div>
        </article>
    </div>

    <div class="col-12 col-lg-8">
        <article class="card border-0 shadow-sm rounded-4 history-card">
            <div class="card-body p-0 history-card-body">
                <div class="p-3 p-sm-4 pb-3">
                    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                        <div>
                            <p class="text-primary fw-semibold small mb-1">Histórico</p>
                            <h2 class="h4 fw-bold mb-0">Movimentos</h2>
                        </div>
                        <form method="GET" action="{{ route('dashboard') }}" class="period-controls d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-secondary mobile-tap-target" id="periodPrevious" aria-label="Período anterior"><i class="bi bi-chevron-left"></i></button>
                            <label for="periodMonth" class="visually-hidden">Selecionar mês</label>
                            <input type="month" id="periodMonth" name="mes" class="form-control" value="{{ $mes }}">
                            <input type="hidden" name="tipo" value="{{ $filtroTipo }}">
                            <input type="hidden" name="categoria" value="{{ $filtroCategoria }}">
                            <button type="button" class="btn btn-outline-secondary mobile-tap-target" id="periodNext" aria-label="Período seguinte"><i class="bi bi-chevron-right"></i></button>
                            <button class="btn btn-primary" type="submit">Ver</button>
                        </form>
                    </div>
                    <p class="small text-secondary mb-0 mt-2" id="periodCaption">{{ $inicio->locale('pt_PT')->translatedFormat('F Y') }}</p>
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
                            <select class="form-select" id="categoryFilter" name="categoria" onchange="this.form.submit()">
                                <option value="todas">Todas as categorias</option>
                                @foreach ($categorias as $key => $label)
                                    <option value="{{ $key }}" @selected($filtroCategoria === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                </div>

                <div id="expensesList" class="expense-list history-list px-3 px-sm-4" aria-live="polite">
                    @forelse ($movimentos as $movimento)
                        @php
                            $isExpense = $movimento->tipo === 'despesa';
                            $dataMovimento = \Illuminate\Support\Carbon::parse($movimento->data_movimento);
                            $categoriaLabel = $categorias[$movimento->category] ?? $movimento->category;
                        @endphp
                        <article class="expense-item" data-movement-id="{{ $movimento->id }}" data-movement-type="{{ $movimento->tipo }}" data-edit-url="{{ route($isExpense ? 'despesas.update' : 'receitas.update', $movimento->id) }}" data-date="{{ $dataMovimento->format('Y-m-d') }}" data-description="{{ $movimento->description }}" data-amount="{{ $movimento->amount }}" data-category="{{ $movimento->category }}">
                            <div class="expense-info d-flex align-items-center gap-3">
                                <span class="expense-category-icon" aria-hidden="true"><i class="bi {{ $isExpense ? 'bi-arrow-up-right' : 'bi-arrow-down-left' }}"></i></span>
                                <div>
                                    <strong>{{ $movimento->description }}</strong>
                                    <div class="small text-secondary">{{ $dataMovimento->format('d/m/Y') }} · {{ $categoriaLabel }} · {{ $isExpense ? 'Despesa' : 'Receita' }}</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <strong class="{{ $isExpense ? 'text-danger' : 'text-success' }}">{{ $isExpense ? '−' : '+' }} {{ number_format((float) $movimento->amount, 2, ',', '.') }} {{ $movimento->currency }}</strong>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-movement" aria-label="Editar movimento"><i class="bi bi-pencil"></i></button>
                                <form method="POST" action="{{ route($isExpense ? 'despesas.destroy' : 'receitas.destroy', $movimento->id) }}" onsubmit="return confirm('Eliminar este movimento?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Eliminar movimento"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <div class="empty-state text-center py-4">
                            <div class="empty-icon mb-3" aria-hidden="true">◎</div>
                            <h3 class="h6">Ainda não existem movimentos</h3>
                            <p class="small text-secondary mb-0">Adiciona uma despesa ou receita para começar.</p>
                        </div>
                    @endforelse
                </div>
                <div class="px-3 px-sm-4 pb-3 text-center" id="loadMoreContainer" @if($totalMovimentos <= $limit) hidden @endif><button type="button" class="btn btn-outline-primary" id="loadMoreMovements">Carregar mais</button></div>
            </div>
        </article>
    </div>
</section>

<section class="mt-4" aria-labelledby="currencyTitle">
    <article class="currency-card rounded-4 p-4">
        <div class="row g-4 align-items-end">
            <div class="col-12 col-lg-5">
                <div class="d-flex align-items-start gap-3">
                    <div class="currency-icon" aria-hidden="true"><i class="bi bi-arrow-left-right"></i></div>
                    <div>
                        <p class="small text-primary fw-semibold mb-1">Ferramenta financeira</p>
                        <h2 id="currencyTitle" class="h5 fw-bold mb-1">Conversor de moedas</h2>
                        <p class="small text-secondary mb-0">Consulta a conversão sem sair da aplicação. O lançamento é feito manualmente.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-4 col-lg-2">
                <label for="conversionAmount" class="form-label">Valor</label>
                <input type="number" class="form-control" id="conversionAmount" placeholder="100,00" min="0.01" step="0.01" value="1">
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label for="conversionFrom" class="form-label">De</label>
                <select class="form-select" id="conversionFrom">
                    @foreach (\App\Support\Categorias::MOEDAS as $currency)
                        <option value="{{ $currency }}" @selected($currency === $moeda)>{{ $currency }} ({{ $simbolos[$currency] ?? $currency }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-sm-4 col-lg-2">
                <label for="conversionTo" class="form-label">Para</label>
                <select class="form-select" id="conversionTo">
                    @foreach (\App\Support\Categorias::MOEDAS as $currency)
                        <option value="{{ $currency }}" @selected($currency === 'USD')>{{ $currency }} ({{ $simbolos[$currency] ?? $currency }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-lg-1">
                <button type="button" class="btn btn-primary w-100" id="convertButton" aria-label="Converter valor"><i class="bi bi-arrow-right"></i></button>
            </div>
            <div class="col-12">
                <div id="conversionResult" class="conversion-result" aria-live="polite">
                    <span class="small text-secondary">Resultado da conversão</span>
                    <strong id="convertedValue">—</strong>
                    <span id="conversionRate" class="small text-secondary">Consulta informativa; não altera as tuas despesas ou receitas.</span>
                </div>
            </div>
        </div>
    </article>
</section>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('dashboardMovementForm');
    const expenseButton = document.getElementById('expenseTypeButton');
    const incomeButton = document.getElementById('incomeTypeButton');
    const category = document.getElementById('movementCategory');
    const submit = document.getElementById('saveMovementButton');
    const title = document.getElementById('recordTitle');
    const dateInput = document.getElementById('movementDate');
    const monthInput = document.getElementById('periodMonth');

    const expenseCategories = @json($categoriasDespesa);
    const incomeCategories = @json($categoriasReceita);
    const oldCategory = @json(old('category'));

    function setMovementType(type) {
        const isIncome = type === 'receita';
        form.action = isIncome ? @json(route('receitas.store')) : @json(route('despesas.store'));
        dateInput.name = isIncome ? 'income_date' : 'expense_date';
        expenseButton.className = isIncome ? 'btn btn-outline-danger' : 'btn btn-danger active';
        incomeButton.className = isIncome ? 'btn btn-outline-success active' : 'btn btn-outline-success';
        expenseButton.setAttribute('aria-pressed', String(!isIncome));
        incomeButton.setAttribute('aria-pressed', String(isIncome));
        title.textContent = isIncome ? 'Adicionar receita' : 'Adicionar despesa';
        submit.textContent = isIncome ? 'Guardar receita' : 'Guardar despesa';
        submit.className = isIncome ? 'btn btn-success w-100' : 'btn btn-danger w-100';
        category.innerHTML = '<option value="" disabled selected>Seleciona uma categoria</option>';
        Object.entries(isIncome ? incomeCategories : expenseCategories).forEach(([key, label]) => {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = label;
            if (oldCategory === key) option.selected = true;
            category.appendChild(option);
        });
    }

    expenseButton.addEventListener('click', () => setMovementType('despesa'));
    incomeButton.addEventListener('click', () => setMovementType('receita'));
    @if (old('category') && array_key_exists(old('category'), $categoriasReceita) && !array_key_exists(old('category'), $categoriasDespesa))
        setMovementType('receita');
    @endif

    document.getElementById('periodPrevious').addEventListener('click', () => {
        const [year, month] = monthInput.value.split('-').map(Number);
        const date = new Date(year, month - 2, 1);
        monthInput.value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
        monthInput.form.requestSubmit();
    });
    document.getElementById('periodNext').addEventListener('click', () => {
        const [year, month] = monthInput.value.split('-').map(Number);
        const date = new Date(year, month, 1);
        monthInput.value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
        monthInput.form.requestSubmit();
    });

    const convertButton = document.getElementById('convertButton');
    convertButton.addEventListener('click', async () => {
        const amount = Number(document.getElementById('conversionAmount').value);
        const from = document.getElementById('conversionFrom').value;
        const to = document.getElementById('conversionTo').value;
        const value = document.getElementById('convertedValue');
        const rateLabel = document.getElementById('conversionRate');
        if (!Number.isFinite(amount) || amount <= 0) {
            value.textContent = 'Introduz um valor superior a zero.';
            rateLabel.textContent = '';
            return;
        }
        if (from === to) {
            value.textContent = amount.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + to;
            rateLabel.textContent = 'As moedas selecionadas são iguais.';
            return;
        }
        value.textContent = 'A consultar taxa de câmbio…';
        rateLabel.textContent = '';
        try {
            const response = await fetch('https://api.frankfurter.dev/v2/rate/' + encodeURIComponent(from) + '/' + encodeURIComponent(to));
            if (!response.ok) throw new Error('Taxa indisponível');
            const data = await response.json();
            value.textContent = (amount * data.rate).toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + to;
            rateLabel.textContent = '1 ' + from + ' = ' + Number(data.rate).toFixed(6) + ' ' + to + (data.date ? ' · Taxa de ' + data.date : '');
        } catch {
            value.textContent = 'Conversão indisponível.';
            rateLabel.textContent = 'Verifica a ligação à internet e tenta novamente.';
        }
    });
})();
</script>
<script>
window.meuBolsoDashboard = {
  userId: @json(auth()->id()),
  type: @json($filtroTipo),
  category: @json($filtroCategoria),
  hasMore: @json($totalMovimentos > $limit),
  movementsUrl: @json(route('dashboard.movimentos')),
  expenseStore: @json(route('despesas.store')),
  expenseUpdateBase: @json(url('/despesas')),
  incomeUpdateBase: @json(url('/receitas')),
  expenseCategories: @json($categoriasDespesa),
  incomeCategories: @json($categoriasReceita),
  categories: @json($categorias)
};
</script>
<script src="{{ asset('js/dashboard.js') }}" defer></script>
@endpush

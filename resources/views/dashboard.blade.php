@extends('layouts.app')

@section('title', 'Início')

@section('content')
@php
    $currencySymbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $currencySymbol = $currencySymbols[$moeda] ?? $moeda;
    $recentMovements = $movimentos->take(5);
    $maxCategoryTotal = max(1, (float) $expenseCategories->max('total'));
    $greeting = now()->hour < 12 ? 'Bom dia' : (now()->hour < 19 ? 'Boa tarde' : 'Boa noite');
@endphp

<div class="home-dashboard">
    <header class="home-dashboard-intro mb-3">
        <h1 class="h2 fw-bold mb-1">{{ $greeting }}, {{ auth()->user()->name }}!</h1>
        <p class="small text-secondary mb-0">
            <time datetime="{{ now()->toDateString() }}">{{ ucfirst(now()->locale('pt_PT')->translatedFormat('l, d')) }} de {{ now()->locale('pt_PT')->translatedFormat('F') }}</time>
        </p>
    </header>

    <form id="dashboardMonthForm" class="home-month-selector mb-3" method="GET" action="{{ route('dashboard') }}">
        <span class="small text-secondary">Mês de referência:</span>
        <input type="hidden" id="dashboardMonth" name="mes" value="{{ $mes }}">
        <button type="button" class="home-month-trigger" id="openDashboardMonthPicker"
            data-bs-toggle="modal" data-bs-target="#dashboardMonthPicker"
            aria-haspopup="dialog" aria-controls="dashboardMonthPicker">
            <span id="dashboardMonthLabel">{{ ucfirst($inicio->copy()->locale('pt_PT')->translatedFormat('F Y')) }}</span>
            <i data-lucide="chevron-down" aria-hidden="true"></i>
        </button>
        <noscript><input type="month" name="mes" value="{{ $mes }}" class="form-control"><button type="submit" class="btn btn-sm btn-outline-secondary">Aplicar</button></noscript>
    </form>

    <section class="home-financial-card mb-4" aria-label="Resumo financeiro">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <span class="home-financial-label">Saldo disponível</span>
            <button type="button" class="home-financial-eye" id="toggleBalanceVisibility" aria-label="Ocultar saldos" aria-pressed="false"><i data-lucide="eye" aria-hidden="true"></i></button>
        </div>
        <p class="home-financial-balance mb-1 home-sensitive-value" data-visible-value="{{ number_format($availableBalance, 2, ',', '.') }} {{ $currencySymbol }}" data-hidden-value="••••••">{{ number_format($availableBalance, 2, ',', '.') }} {{ $currencySymbol }}</p>
        <p class="home-financial-caption mb-0">Contas bancárias e dinheiro</p>
        <div class="home-financial-divider"></div>
        <div class="home-financial-metrics">
            <div class="home-financial-metric">
                <span>Receitas</span>
                <strong class="home-sensitive-value" data-visible-value="{{ number_format($totalReceitas, 2, ',', '.') }} {{ $currencySymbol }}" data-hidden-value="••••••">{{ number_format($totalReceitas, 2, ',', '.') }} {{ $currencySymbol }}</strong>
            </div>
            <div class="home-financial-metric">
                <span>Despesas</span>
                <strong class="home-sensitive-value" data-visible-value="{{ number_format($totalDespesas, 2, ',', '.') }} {{ $currencySymbol }}" data-hidden-value="••••••">{{ number_format($totalDespesas, 2, ',', '.') }} {{ $currencySymbol }}</strong>
            </div>
            <div class="home-financial-metric">
                <span>Resultado</span>
                <strong class="home-sensitive-value" data-visible-value="{{ $result > 0 ? '+' : ($result < 0 ? '−' : '') }}{{ number_format(abs($result), 2, ',', '.') }} {{ $currencySymbol }}" data-hidden-value="••••••">{{ $result > 0 ? '+' : ($result < 0 ? '−' : '') }}{{ number_format(abs($result), 2, ',', '.') }} {{ $currencySymbol }}</strong>
            </div>
        </div>
    </section>

    <section class="home-quick-actions mb-4" aria-labelledby="homeQuickActionsTitle">
        <h2 class="h6 fw-bold mb-2" id="homeQuickActionsTitle">Ações rápidas</h2>
        <div class="row g-2">
            <div class="col-4">
                <button type="button" class="home-quick-action" id="quickExpenseButton">
                    <i data-lucide="minus-circle" aria-hidden="true"></i>
                    <strong>Despesa</strong>
                </a>
            </div>
            <div class="col-4">
                <button type="button" class="home-quick-action" id="quickIncomeButton">
                    <i data-lucide="plus-circle" aria-hidden="true"></i>
                    <strong>Receita</strong>
                </button>
            </div>
            <div class="col-4">
                <button type="button" class="home-quick-action" data-bs-toggle="modal" data-bs-target="#currencyConverterModal">
                    <i data-lucide="arrow-left-right" aria-hidden="true"></i>
                    <strong>Câmbio</strong>
                </button>
            </div>
        </div>
    </section>

    <section class="home-section-card mb-4" aria-labelledby="homeCategoriesTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h6 fw-bold mb-0" id="homeCategoriesTitle">Despesas por categoria</h2>
            <a class="small text-decoration-none" href="{{ route('categorias.index') }}">Ver todas</a>
        </div>
        @forelse ($expenseCategories as $category)
            <div class="home-category-row">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <span class="small fw-semibold text-truncate">{{ $category->name }}</span>
                    <strong class="small text-nowrap">{{ number_format((float) $category->total, 2, ',', '.') }} {{ $currencySymbol }}</strong>
                </div>
                <div class="home-category-track" role="progressbar"
                    aria-label="Peso de {{ $category->name }} nas despesas por categoria"
                    aria-valuenow="{{ (int) round(((float) $category->total / $maxCategoryTotal) * 100) }}"
                    aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ min(100, (int) round(((float) $category->total / $maxCategoryTotal) * 100)) }}%"></span>
                </div>
            </div>
        @empty
            <p class="small text-secondary mb-0">Ainda não existem despesas por categoria neste mês.</p>
        @endforelse
    </section>

    <section class="home-section-card mb-4" aria-labelledby="homeCardsTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h6 fw-bold mb-0" id="homeCardsTitle">Cartões</h2>
            <a class="small text-decoration-none" href="{{ route('financial.cards.page') }}">Ver todos</a>
        </div>
        @if ($nearestOpenInvoice)
            <a class="home-invoice-card" href="{{ route('financial.invoices.show', ['id' => $nearestOpenInvoice->id, 'return_to' => request()->getRequestUri()]) }}">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <strong class="small text-truncate">Fatura do cartão</strong>
                    <span class="home-invoice-status {{ $nearestOpenInvoice->payment_status === 'overdue' ? 'is-overdue' : 'is-open' }}">
                        {{ $nearestOpenInvoice->payment_status === 'overdue' ? 'Em atraso' : ($nearestOpenInvoice->payment_status === 'partial' ? 'Parcial' : 'Em aberto') }}
                    </span>
                </div>
                <strong class="home-invoice-amount">{{ number_format((float) $nearestOpenInvoice->remaining_amount, 2, ',', '.') }} {{ $currencySymbol }}</strong>
                <span class="small text-secondary">
                    Vencimento: {{ $nearestOpenInvoice->due_date?->locale('pt_PT')->translatedFormat('d M') ?? 'Data não definida' }}
                </span>
            </a>
        @else
            <p class="small text-secondary mb-0">Não existem faturas em aberto.</p>
        @endif
    </section>

    <section class="home-section-card" aria-labelledby="homeRecentTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <h2 class="h6 fw-bold mb-0" id="homeRecentTitle">Últimas movimentações</h2>
            <a class="small text-decoration-none" href="{{ route('dashboard.movimentos') }}">Ver todas</a>
        </div>
        @forelse ($recentMovements as $movement)
            @php
                $isExpense = $movement->tipo === 'despesa';
                $movementDate = \Illuminate\Support\Carbon::parse($movement->data_movimento);
                $movementIcon = $movement->category_icon ?: ($isExpense ? 'arrow-down-right' : 'arrow-up-right');
            @endphp
            <div class="home-recent-item">
                <span class="home-recent-icon" aria-hidden="true"><i data-lucide="{{ $movementIcon }}"></i></span>
                <div class="home-recent-copy">
                    <strong>{{ $movement->description }}</strong>
                    <span>{{ $movement->category_label ?: ($isExpense ? 'Despesa' : 'Receita') }} · {{ $movementDate->format('d/m') }}</span>
                </div>
                <strong class="home-recent-amount {{ $isExpense ? 'is-expense' : 'is-income' }}">
                    {{ $isExpense ? '−' : '+' }}{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $currencySymbols[$movement->currency] ?? $movement->currency }}
                </strong>
            </div>
        @empty
            <p class="small text-secondary mb-0">Ainda não existem movimentações neste mês.</p>
        @endforelse
    </section>
</div>

<div class="visually-hidden" aria-hidden="true">
    <input id="periodMonth" value="{{ $mes }}">
    <span id="totalAmount" data-total="{{ $totalDespesas }}">{{ $totalDespesas }}</span>
    <span id="totalIncome" data-total="{{ $totalReceitas }}">{{ $totalReceitas }}</span>
    <span id="balanceAmount">{{ $result }}</span>
    <span id="periodCaption">{{ $mes }}</span>
    <div id="expensesList"></div>
</div>

@include('partials.movement-editor-modal')

<div class="modal fade" id="dashboardMonthPicker" tabindex="-1" aria-labelledby="dashboardMonthPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm dashboard-month-dialog">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-2">
                <h2 class="modal-title h5 fw-bold" id="dashboardMonthPickerTitle">Escolher mês</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body pt-1">
                <div class="dashboard-month-year d-flex align-items-center justify-content-between mb-3">
                    <button type="button" class="btn btn-sm btn-light" id="dashboardMonthPreviousYear" aria-label="Ano anterior"><i data-lucide="chevron-left"></i></button>
                    <strong id="dashboardMonthYear" aria-live="polite"></strong>
                    <button type="button" class="btn btn-sm btn-light" id="dashboardMonthNextYear" aria-label="Ano seguinte"><i data-lucide="chevron-right"></i></button>
                </div>
                <div class="dashboard-month-grid" id="dashboardMonthGrid" aria-label="Meses do ano"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary flex-fill" id="applyDashboardMonth">Aplicar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="currencyConverterModal" tabindex="-1" aria-labelledby="currencyConverterTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-2">
                <h2 class="modal-title h5 fw-bold" id="currencyConverterTitle">Conversor de moedas</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body pt-2">
                <div class="mb-3">
                    <label for="conversionAmount" class="form-label">Valor</label>
                    <input type="number" class="form-control form-control-lg" id="conversionAmount" placeholder="100,00" min="0.01" step="0.01" value="100">
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label for="conversionFrom" class="form-label">De</label>
                        <select class="form-select" id="conversionFrom">
                            @foreach (\App\Support\Categorias::MOEDAS as $currency)
                                <option value="{{ $currency }}">{{ $currency }} ({{ $currencySymbols[$currency] ?? $currency }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-2 text-center pb-2">
                        <button type="button" class="btn btn-outline-secondary" id="swapCurrencies" aria-label="Inverter moedas"><i data-lucide="arrow-left-right"></i></button>
                    </div>
                    <div class="col-5">
                        <label for="conversionTo" class="form-label">Para</label>
                        <select class="form-select" id="conversionTo">
                            @foreach (\App\Support\Categorias::MOEDAS as $currency)
                                <option value="{{ $currency }}">{{ $currency }} ({{ $currencySymbols[$currency] ?? $currency }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="conversion-result mt-3" aria-live="polite">
                    <span class="small text-secondary">Resultado</span>
                    <strong id="convertedValue">—</strong>
                    <span id="conversionRate" class="small text-secondary"></span>
                </div>
                <button type="button" class="btn btn-primary w-100 mt-3" id="convertButton">Converter</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
window.meuBolsoHome = { currency: @json($moeda) };
window.meuBolsoDashboard = {
    userId: @json(auth()->id()), name: @json(auth()->user()->name), currency: @json($moeda),
    initialType: 'despesa', initialCategory: null, type: @json($filtroTipo), category: @json($filtroCategoria),
    hasMore: @json($totalMovimentos > $limit), initialExpenseCount: @json($totalDespesasFiltradas),
    initialIncomeCount: @json($totalReceitasFiltradas), initialMovements: @json($movimentosIniciais),
    movementsUrl: @json(route('dashboard.movimentos.data')),
    expenseStore: @json(route('despesas.store')), incomeStore: @json(route('receitas.store')),
    commitmentStore: @json(route('financial.commitments.store')), commitmentBase: @json(url('/api/financial/commitments')),
    expenseUpdateBase: @json(url('/despesas')), incomeUpdateBase: @json(url('/receitas')),
    expenseCategories: @json($categoriasDespesa), expenseCategoryBehaviors: @json($expenseCategoryBehaviors),
    incomeCategories: @json($categoriasReceita), categories: @json($categorias),
    financialMovementBase: @json(url('/api/financial/movements'))
};
</script>
<script src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}" defer></script>
<script src="{{ asset('js/dashboard-home.js') }}?v={{ filemtime(public_path('js/dashboard-home.js')) }}" defer></script>
@endpush

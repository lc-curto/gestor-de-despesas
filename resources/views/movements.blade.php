@extends('layouts.app')

@section('title', 'Movimentos')

@section('content')
@php
    $simbolos = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
@endphp

<header class="dashboard-intro movements-intro">
    <div class="dashboard-intro-copy">
        <h1 class="h2 fw-bold">Movimentos</h1>
        <p class="text-secondary mb-0">Consulta e gere as tuas despesas e receitas.</p>
    </div>
</header>

<div id="movementSaveFeedback" class="alert d-none" role="status" aria-live="polite">
    <span id="movementSaveFeedbackMessage"></span>
    <a id="movementSaveFeedbackLink" href="{{ route('financial.commitments.page') }}" class="alert-link d-none">Ver parcelamentos</a>
</div>

<section class="movements-period-summary" aria-label="Resumo financeiro do período">
    <div class="movements-period-summary-heading" id="movementsSummaryPeriod">Resumo de {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $mes)->locale('pt_PT')->translatedFormat('F Y') }}</div>
    <div class="movements-period-summary-values">
        <div class="movements-period-metric">
            <span>Receitas</span>
            <strong id="totalIncome" data-total="{{ $totalReceitas }}" class="text-success">{{ number_format($totalReceitas, 2, ',', '.') }} {{ $moeda }}</strong>
        </div>
        <div class="movements-period-metric">
            <span>Despesas</span>
            <strong id="totalAmount" data-total="{{ $totalDespesas }}" class="text-danger">{{ number_format($totalDespesas, 2, ',', '.') }} {{ $moeda }}</strong>
        </div>
        <div class="movements-period-metric movements-period-result">
            <span>Resultado</span>
            <strong id="balanceAmount">{{ number_format($totalReceitas - $totalDespesas, 2, ',', '.') }} {{ $moeda }}</strong>
        </div>
    </div>
</section>

<section class="dashboard-entry-bar movements-entry-bar" aria-label="Novo movimento">
    <span class="movements-entry-label">Adicionar</span>
    <div class="btn-group" role="group" aria-label="Tipo de registo">
        <button type="button" class="btn btn-outline-danger btn-sm" id="quickExpenseButton"><i data-lucide="arrow-down-right" aria-hidden="true"></i> Despesa</button>
        <button type="button" class="btn btn-outline-success btn-sm" id="quickIncomeButton"><i data-lucide="arrow-up-right" aria-hidden="true"></i> Receita</button>
    </div>
</section>

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
                        <div id="movementFormError" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                        <div class="mb-3">
                            <label for="movementDescription" class="form-label" id="descriptionLabel">Descrição</label>
                            <input type="text" class="form-control" id="movementDescription" name="description" placeholder="Ex.: Supermercado" value="{{ old('description') }}" maxlength="255" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="movementAmount" class="form-label">Valor</label>
                                <div class="input-group">
                                    <input type="text" inputmode="decimal" class="form-control" id="movementAmount" name="amount" placeholder="0,00" aria-describedby="movementAmountHelp" value="{{ old('amount') }}" required>
                                    <span class="input-group-text" id="amountCurrencySymbol">{{ $simbolos[$moeda] ?? $moeda }}</span>
                                </div>
                                <div id="movementAmountHelp" class="form-text">Usa vírgula ou ponto para os cêntimos.</div>
                            </div>
                            <div class="col-6">
                                <label for="movementDate" class="form-label">Data</label>
                                <input type="date" class="form-control" id="movementDate" name="expense_date" value="{{ old('expense_date', old('income_date', now()->toDateString())) }}" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="movementAccount" class="form-label">Conta de saída</label>
                            <select class="form-select" id="movementAccount" name="account_id" required>
                                <option value="">Selecionar conta</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" @selected($accounts->count() === 1)>{{ $account->name }} · {{ $account->currency }}</option>
                                @endforeach
                            </select>
                            @if ($accounts->isEmpty())
                                <div class="form-text text-warning">Cria uma conta e configura o saldo inicial em <a href="{{ route('financial.accounts.page') }}">Contas</a> antes de registar.</div>
                            @endif
                        </div>
                        <div class="border-top pt-3 mb-3">
                            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="recurrence_enabled" value="1" id="dashboardRecurrenceEnabled"> <span class="form-check-label">Repetir esta despesa/receita</span></label>
                            <div id="dashboardRecurrenceFields" class="row g-2" hidden>
                                <div class="col-4"><label for="dashboardRecurrenceInterval" class="form-label">A cada</label><input id="dashboardRecurrenceInterval" name="recurrence_interval" type="number" min="1" max="365" value="1" class="form-control"></div>
                                <div class="col-4"><label for="dashboardRecurrenceFrequency" class="form-label">Frequência</label><select id="dashboardRecurrenceFrequency" name="recurrence_frequency" class="form-select"><option value="daily">Dia(s)</option><option value="weekly">Semana(s)</option><option value="monthly" selected>Mês(es)</option><option value="yearly">Ano(s)</option><option value="custom">Dias</option></select></div>
                                <div class="col-4"><label for="dashboardRecurrenceEnds" class="form-label">Termina em</label><input id="dashboardRecurrenceEnds" name="recurrence_ends_at" type="date" class="form-control"></div>
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
                        <div id="dashboardExpenseBehaviorField" class="mb-3">
                            <label class="form-label d-block">Comportamento da despesa</label>
                            <div class="d-flex gap-3">
                                <label class="form-check"><input class="form-check-input" type="radio" name="expense_behavior" value="variable" checked> <span class="form-check-label">Variável</span></label>
                                <label class="form-check"><input class="form-check-input" type="radio" name="expense_behavior" value="fixed"> <span class="form-check-label">Fixa</span></label>
                            </div>
                            <div class="form-text">Fixa é uma despesa estrutural ou regular, mesmo que o valor varie.</div>
                        </div>
                        <div id="dashboardPaymentFields" class="border-top pt-3 mb-3">
                            <label class="form-label d-block fw-semibold mb-2">Modalidade de pagamento</label>
                            <div class="payment-mode-grid mb-3" role="radiogroup" aria-label="Modalidade de pagamento">
                                <label class="payment-mode-card">
                                    <input class="visually-hidden" type="radio" name="payment_condition" value="cash" checked>
                                    <span class="payment-mode-card-title">Pagamento único</span>
                                    <span class="payment-mode-card-description">Pago numa única vez.</span>
                                    <span class="payment-mode-card-selected" aria-hidden="true">Selecionado</span>
                                </label>
                                <label class="payment-mode-card">
                                    <input class="visually-hidden" type="radio" name="payment_condition" value="deferred">
                                    <span class="payment-mode-card-title">Pagamento com vencimento</span>
                                    <span class="payment-mode-card-description">Tem uma data de vencimento.</span>
                                    <span class="payment-mode-card-selected" aria-hidden="true">Selecionado</span>
                                </label>
                                <label class="payment-mode-card">
                                    <input class="visually-hidden" type="radio" name="payment_condition" value="installments">
                                    <span class="payment-mode-card-title">Pagamento parcelado</span>
                                    <span class="payment-mode-card-description">Dividido em prestações.</span>
                                    <span class="payment-mode-card-selected" aria-hidden="true">Selecionado</span>
                                </label>
                            </div>
                            <div id="dashboardCommitmentOptions" class="row g-2" hidden>
                                <div class="col-12">
                                    <label for="dashboardPaymentMethod" class="form-label">Método de pagamento previsto</label>
                                    <select id="dashboardPaymentMethod" name="payment_method" class="form-select">
                                        <option value="">Selecionar método</option>
                                        <option value="cash">Dinheiro</option><option value="debit">Cartão de débito</option>
                                        <option value="credit">Cartão de crédito (regista em Cartões)</option><option value="transfer">Transferência bancária</option>
                                        <option value="mbway">MB WAY</option><option value="direct_debit">Débito direto</option>
                                    </select>
                                </div>
                                <div class="col-12" id="dashboardDueDateField">
                                    <label for="dashboardDueDate" class="form-label">Data de vencimento</label>
                                    <input id="dashboardDueDate" name="due_date" type="date" class="form-control" value="{{ now()->toDateString() }}">
                                </div>
                                <div class="col-6" id="dashboardInstallmentsField" hidden>
                                    <label for="dashboardInstallments" class="form-label">Número de prestações</label>
                                    <input id="dashboardInstallments" name="installments_count" type="number" min="2" max="120" value="2" class="form-control">
                                </div>
                                <div class="col-6" id="dashboardInstallmentFrequencyField" hidden>
                                    <label for="dashboardInstallmentFrequency" class="form-label">Periodicidade</label>
                                    <select id="dashboardInstallmentFrequency" name="installment_frequency" class="form-select">
                                        <option value="weekly">Semanal</option><option value="biweekly">Quinzenal</option><option value="monthly" selected>Mensal</option>
                                    </select>
                                </div>
                                <div class="col-12" id="dashboardInstallmentSchedule" hidden>
                                    <div class="small fw-semibold mb-2">Resumo do parcelamento</div>
                                    <div class="list-group list-group-flush" id="dashboardInstallmentScheduleList" aria-live="polite"></div>
                                </div>
                            </div>
                            <div id="dashboardPaidFields" class="mt-3">
                                <label class="form-label d-block fw-semibold">Estado do pagamento</label>
                                <div class="payment-status-grid" role="radiogroup" aria-label="Estado do pagamento">
                                    <label class="payment-status-option"><input class="form-check-input" type="radio" name="paid_now" value="0" checked><span>Ainda não paguei</span></label>
                                    <label class="payment-status-option"><input class="form-check-input" type="radio" name="paid_now" value="partial"><span>Paguei uma parte</span></label>
                                    <label class="payment-status-option"><input class="form-check-input" type="radio" name="paid_now" value="1"><span>Já paguei tudo</span></label>
                                </div>
                                <div id="dashboardPaymentDetails" class="row g-2 mt-2" hidden>
                                    <div class="col-12" id="dashboardPaymentAmountField"><label for="dashboardPaymentAmount" class="form-label">Valor pago</label><input id="dashboardPaymentAmount" name="payment_amount" type="text" inputmode="decimal" placeholder="0,00" class="form-control"></div>
                                    <div class="col-6"><label for="dashboardPaymentDate" class="form-label">Data do pagamento</label><input id="dashboardPaymentDate" name="payment_date" type="date" class="form-control" value="{{ now()->toDateString() }}"></div>
                                    <div class="col-6"><label for="dashboardPaymentAccount" class="form-label">Conta utilizada</label><select id="dashboardPaymentAccount" name="payment_account_id" class="form-select"><option value="">Selecionar conta</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $account->currency }}</option>@endforeach</select></div>
                                    <div class="col-12"><label for="dashboardActualPaymentMethod" class="form-label">Método utilizado</label><select id="dashboardActualPaymentMethod" name="actual_payment_method" class="form-select"><option value="">Selecionar método</option><option value="cash">Dinheiro</option><option value="debit">Cartão de débito</option><option value="credit">Cartão de crédito</option><option value="transfer">Transferência bancária</option><option value="mbway">MB WAY</option><option value="direct_debit">Débito direto</option></select></div>
                                </div>
                            </div>
                            <p class="form-text mb-0 mt-2">A recorrência cria novas obrigações. O parcelamento divide o valor desta obrigação em prestações.</p>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger w-100" id="saveMovementButton" >Guardar despesa</button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="cancelMovementEdit">Cancelar edição</button>
                        </div>
                    </form>
                </div>
            </div>
        </article>
            </div></div>
        </div>

<section class="row g-3 g-xl-4 align-items-start" aria-label="Movimentos">
    <div class="col-12">
        <article class="card border-0 shadow-sm rounded-4 history-card">
            <div class="card-body p-0 history-card-body">
                <div class="p-3 p-sm-4 pb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <h2 class="h5 fw-bold mb-0">Histórico de movimentos</h2>
                        <form method="GET" action="{{ route('dashboard.movimentos') }}" id="periodForm" class="period-controls dashboard-period-controls">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="periodPrevious" aria-label="Mês anterior"><i data-lucide="chevron-left"></i></button>
                            <label for="periodMonth" class="visually-hidden">Selecionar mês</label>
                            <input type="month" id="periodMonth" name="mes" class="form-control" value="{{ $mes }}">
                            <input type="hidden" name="tipo" value="{{ $filtroTipo }}">
                            <input type="hidden" name="categoria" value="{{ $filtroCategoria }}">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="periodNext" aria-label="Mês seguinte"><i data-lucide="chevron-right"></i></button>
                        </form>
                    </div>
                </div>

                <div class="px-3 px-sm-4 pb-3">
                    <label class="visually-hidden" for="movementSearch">Pesquisar movimentos</label>
                    <div class="movements-search-row">
                        <div class="input-group">
                            <span class="input-group-text"><i data-lucide="search"></i></span>
                            <input type="search" class="form-control" id="movementSearch" placeholder="Pesquisar movimentos...">
                        </div>
                        <button type="button" class="btn btn-outline-secondary movements-filter-trigger" data-bs-toggle="offcanvas" data-bs-target="#movementFiltersSheet" aria-controls="movementFiltersSheet" aria-label="Abrir filtros avançados" title="Filtros avançados">
                            <i data-lucide="sliders-horizontal" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div class="px-3 px-sm-4 border-bottom">
                    <form method="GET" action="{{ route('dashboard.movimentos') }}" class="transaction-filters movements-type-filters d-flex flex-wrap align-items-center gap-2 pb-3">
                        <input type="hidden" name="mes" value="{{ $mes }}">
                        <input type="hidden" name="tipo" value="{{ $filtroTipo }}">
                        <button class="btn {{ $filtroTipo === 'todos' ? 'btn-primary' : 'btn-link text-decoration-none' }} mobile-tap-target" name="tipo" value="todos" aria-pressed="{{ $filtroTipo === 'todos' ? 'true' : 'false' }}">Tudo</button>
                        <button class="btn {{ $filtroTipo === 'despesa' ? 'btn-primary' : 'btn-link text-decoration-none' }} mobile-tap-target" name="tipo" value="despesa" aria-pressed="{{ $filtroTipo === 'despesa' ? 'true' : 'false' }}">Despesas</button>
                        <button class="btn {{ $filtroTipo === 'receita' ? 'btn-primary' : 'btn-link text-decoration-none' }} mobile-tap-target" name="tipo" value="receita" aria-pressed="{{ $filtroTipo === 'receita' ? 'true' : 'false' }}">Receitas</button>
                    </form>
                </div>

                <div class="offcanvas offcanvas-bottom mobile-action-sheet movement-filters-sheet" tabindex="-1" id="movementFiltersSheet" aria-labelledby="movementFiltersTitle">
                    <div class="offcanvas-header">
                        <h2 class="offcanvas-title h5 fw-bold" id="movementFiltersTitle">Filtros avançados</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
                    </div>
                    <div class="offcanvas-body pt-0">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="categoryFilter" class="form-label small fw-semibold">Categoria</label>
                                <select class="form-select" id="categoryFilter" name="categoria">
                                    <option value="all">Todas as categorias</option>
                                    @foreach ($categorias as $key => $label)
                                        <option value="{{ $key }}" @selected($filtroCategoria === (string) $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="accountFilter" class="form-label small fw-semibold">Conta</label>
                                <select class="form-select" id="accountFilter">
                                    <option value="all">Todas as contas</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary flex-fill" id="clearMovementFilters">Limpar filtros</button>
                            <button type="button" class="btn btn-primary flex-fill" data-bs-dismiss="offcanvas">Concluir</button>
                        </div>
                    </div>
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
                            data-category-id="{{ $movimento->category }}" data-account-id="{{ $movimento->account_id }}"
                            data-movement-status="{{ $movimento->movement_status }}" data-expense-behavior="{{ $movimento->expense_behavior ?? 'variable' }}" data-recurrence-id="{{ $movimento->recurrence_id }}" data-financial-movement-id="{{ $movimento->financial_movement_id }}">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="expense-icon flex-shrink-0" style="--category-color: {{ $movimento->category_color ?? '#635BFF' }}" aria-hidden="true">
                                    <i data-lucide="{{ $movimento->category_icon ?? 'tag' }}"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="h6 fw-semibold mb-1 text-break">{{ $movimento->description }}</h3>
                                    <div class="movement-badges d-flex flex-wrap align-items-center gap-1 mt-1">
                                        @if($movimento->movement_status === 'settled')
                                            <span class="badge rounded-pill text-bg-success">Pago</span>
                                        @elseif($movimento->movement_status === 'partial' || $movimento->movement_status === 'partially_settled')
                                            <span class="badge rounded-pill text-bg-info">Parcialmente pago</span>
                                        @else
                                            <span class="badge rounded-pill text-bg-warning">Pendente</span>
                                        @endif
                                        @if($movimento->recurrence_id)<span class="badge rounded-pill text-bg-light border text-secondary">Recorrente</span>@endif
                                    </div>
                                    <p class="small text-secondary mb-0">{{ $categoriaLabel }} · {{ $dataMovimento->format('d/m/Y') }}</p>
                                </div>
                            </div>
                            <div class="expense-actions d-flex align-items-center justify-content-end gap-2">
                                <strong class="{{ $isExpense ? 'text-danger' : 'text-success' }} text-nowrap movement-value"
                                    data-value="{{ $movimento->amount }}" data-currency="{{ $movimento->currency }}">{{ $isExpense ? '−' : '+' }}{{ number_format((float) $movimento->amount, 2, ',', '.') }}</strong>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Ações de {{ $movimento->description }}">
                                    <button type="button" class="btn btn-outline-secondary edit-movement" data-acao="editar" aria-label="Editar {{ $movimento->description }}" title="Editar"><i data-lucide="pencil" aria-hidden="true"></i></button>
                                    @if ($movimento->movement_status === 'pending' && $movimento->financial_movement_id)
                                        <button type="button" class="btn btn-outline-success" data-movement-action="settle" title="Efetivar" aria-label="Efetivar {{ $movimento->description }}"><i data-lucide="check-circle" aria-hidden="true"></i></button>
                                    @endif
                                    <button type="button" class="btn btn-outline-danger" data-movement-action="delete" aria-label="Excluir {{ $movimento->description }}" title="Excluir"><i data-lucide="trash-2" aria-hidden="true"></i></button>
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




<div class="modal fade" id="settleMovementModal" tabindex="-1" aria-labelledby="settleMovementTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="settleMovementForm">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="settleMovementTitle">Confirmar movimento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary small mb-3" id="settleMovementDescription"></p>
                    <div id="settleMovementError" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                    <div class="mb-3">
                        <label for="settleMovementAmount" class="form-label fw-semibold">Valor efetivo</label>
                        <div class="input-group">
                            <input id="settleMovementAmount" name="amount" type="text" inputmode="decimal" placeholder="0,00" class="form-control" required>
                            <span class="input-group-text">{{ $moeda }}</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="settleMovementDate" class="form-label fw-semibold">Data de realização</label>
                        <input id="settleMovementDate" name="movement_date" type="date" class="form-control" required>
                    </div>
                    <div>
                        <label for="settleMovementAccount" class="form-label fw-semibold" id="settleMovementAccountLabel">Conta</label>
                        <select id="settleMovementAccount" name="account_id" class="form-select" required>
                            <option value="">Selecionar conta</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->currency }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex flex-row gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success flex-fill" id="settleMovementSubmit">Confirmar movimento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteMovementModal" tabindex="-1" aria-labelledby="deleteMovementTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="deleteMovementForm">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="deleteMovementTitle">Excluir movimento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary small mb-2" id="deleteMovementDescription"></p>
                    <p class="alert alert-info small py-2 mb-2" id="deleteMovementNote" hidden></p>
                    <div id="deleteSingleOption" class="form-check border rounded-3 p-3">
                        <input class="form-check-input ms-0 me-2" type="radio" name="delete_scope" id="deleteScopeOnlySingle" value="only" checked>
                        <label class="form-check-label fw-semibold" for="deleteScopeOnlySingle">Só este movimento</label>
                    </div>
                    <div id="deleteRecurringOptions" class="d-grid gap-2" hidden>
                        <label class="form-check border rounded-3 p-3 mb-0" for="deleteScopeOnly">
                            <input class="form-check-input ms-0 me-2" type="radio" name="delete_scope" id="deleteScopeOnly" value="only">
                            <span class="fw-semibold">Só este</span>
                            <span class="d-block small text-secondary">Mantém os seguintes.</span>
                        </label>
                        <label class="form-check border rounded-3 p-3 mb-0" for="deleteScopeFuture">
                            <input class="form-check-input ms-0 me-2" type="radio" name="delete_scope" id="deleteScopeFuture" value="this_and_future">
                            <span class="fw-semibold">Este e os seguintes</span>
                            <span class="d-block small text-secondary">Para a recorrência a partir daqui.</span>
                        </label>
                        <label class="form-check border rounded-3 p-3 mb-0" for="deleteScopeAll">
                            <input class="form-check-input ms-0 me-2" type="radio" name="delete_scope" id="deleteScopeAll" value="all">
                            <span class="fw-semibold">Toda a recorrência</span>
                            <span class="d-block small text-secondary">Remove previsões pendentes.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-grid gap-2">
                    <button type="submit" class="btn btn-danger w-100" id="deleteMovementSubmit">Confirmar exclusão</button>
                    <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>


@endsection

@push('styles')
<style>
    .payment-mode-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.65rem; }
    .payment-status-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.5rem; }
    .payment-status-option { display:flex; align-items:center; gap:.45rem; min-height:44px; padding:.65rem .7rem; border:1px solid var(--bs-border-color); border-radius:.75rem; font-size:.82rem; cursor:pointer; }
    .payment-status-option:has(input:checked) { border-color:var(--bs-primary); background:var(--bs-primary-bg-subtle); }
    @media(max-width:575.98px) { .payment-status-grid { grid-template-columns:1fr; } }
    .payment-mode-card { position:relative; display:flex; min-height:112px; flex-direction:column; gap:.35rem; padding:.85rem; border:1px solid var(--bs-border-color); border-radius:.85rem; background:var(--bs-body-bg); cursor:pointer; transition:border-color .15s ease,background-color .15s ease,box-shadow .15s ease; }
    .payment-mode-card:hover { border-color:var(--bs-primary); }
    .payment-mode-card:focus-within { outline:2px solid var(--bs-primary); outline-offset:2px; }
    .payment-mode-card.is-selected { border:2px solid var(--bs-primary); padding:calc(.85rem - 1px); background:var(--bs-primary-bg-subtle); }
    .payment-mode-card-title { font-size:.9rem; font-weight:650; line-height:1.25; }
    .payment-mode-card-description { color:var(--bs-secondary-color); font-size:.78rem; line-height:1.35; }
    .payment-mode-card-selected { display:none; margin-top:auto; color:var(--bs-primary-text-emphasis); font-size:.7rem; font-weight:650; }
    .payment-mode-card.is-selected .payment-mode-card-selected { display:block; }
    @media (max-width:575.98px) {
        .payment-mode-grid { grid-template-columns:1fr; gap:.5rem; }
        .payment-mode-card { min-height:0; padding:.7rem .8rem; }
        .payment-mode-card.is-selected { padding:calc(.7rem - 1px) calc(.8rem - 1px); }
        .payment-mode-card-selected { position:absolute; right:.8rem; top:.8rem; margin:0; }
        .payment-mode-card-description { padding-right:5.5rem; }
    }
</style>
@endpush

@push('scripts')
<script>document.addEventListener('DOMContentLoaded',()=>{const c=document.getElementById('dashboardRecurrenceEnabled'),f=document.getElementById('dashboardRecurrenceFields');if(c&&f){const sync=()=>f.hidden=!c.checked;c.addEventListener('change',sync);sync();}});</script>
@push('scripts')
<script>
window.meuBolsoDashboard = {
    userId: @json(auth()->id()),
    name: @json(auth()->user()->name),
    currency: @json($moeda),
    initialType: @json(request()->query('tipo') === 'receita' || old('income_date') ? 'receita' : 'despesa'),
    autoOpenEditor: @json(request()->boolean('novo')),
    initialCategory: @json(old('category_id')),
    type: @json($filtroTipo),
    category: @json($filtroCategoria),
    hasMore: @json($totalMovimentos > $limit),
    initialExpenseCount: @json($totalDespesasFiltradas),
    initialIncomeCount: @json($totalReceitasFiltradas),
    initialMovements: @json($movimentosIniciais),
    movementsUrl: @json(route('dashboard.movimentos.data')),
    expenseStore: @json(route('despesas.store')),
    incomeStore: @json(route('receitas.store')),
    commitmentStore: @json(route('financial.commitments.store')),
    commitmentBase: @json(url('/api/financial/commitments')),
    expenseUpdateBase: @json(url('/despesas')),
    incomeUpdateBase: @json(url('/receitas')),
    expenseCategories: @json($categoriasDespesa),
    incomeCategories: @json($categoriasReceita),
    categories: @json($categorias),
    financialMovementBase: @json(url('/api/financial/movements'))
};
</script>

@if ($errors->any())
<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('movementEntryModal')).show());</script>
@endif
<script src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}" defer></script>
@endpush

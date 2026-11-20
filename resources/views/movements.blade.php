@extends('layouts.app')

@section('title', 'Movimentos')

@section('content')
@php
    $simbolos = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
@endphp

<header class="dashboard-intro movements-intro">
    <div class="dashboard-intro-copy">
        <h1 class="h2 fw-bold">Movimentos</h1>
        
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

@include('partials.movement-editor-modal')
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
                            <button type="button" class="btn btn-outline-secondary flex-fill" id="clearMovementFilters">Limpar</button>
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
                        <article class="expense-item d-flex flex-column align-items-stretch gap-1 py-3 border-bottom"
                            data-movement-id="{{ $movimento->id }}" data-movement-type="{{ $movimento->tipo }}"
                            data-edit-url="{{ route($isExpense ? 'despesas.update' : 'receitas.update', $movimento->id) }}"
                            data-date="{{ $dataMovimento->format('Y-m-d') }}" data-description="{{ $movimento->description }}"
                            data-amount="{{ $movimento->amount }}" data-remaining-amount="{{ $movimento->remaining_amount ?? $movimento->amount }}" data-currency="{{ $movimento->currency }}"
                            data-category-id="{{ $movimento->category }}" data-account-id="{{ $movimento->account_id }}"
                            data-movement-status="{{ $movimento->movement_status }}" data-is-settlement-event="{{ (bool) ($movimento->is_settlement_event ?? false) ? 1 : 0 }}" data-expense-behavior="{{ $movimento->expense_behavior ?? 'variable' }}" data-recurrence-id="{{ $movimento->recurrence_id }}" data-recurrence-frequency="{{ $movimento->recurrence_frequency }}" data-financial-movement-id="{{ $movimento->financial_movement_id }}">
                            <div class="d-flex justify-content-between align-items-center gap-3 w-100">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="expense-icon flex-shrink-0" style="--category-color: {{ $movimento->category_color ?? '#635BFF' }}" aria-hidden="true">
                                    <i data-lucide="{{ $movimento->category_icon ?? 'tag' }}"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="h6 fw-semibold mb-1 text-break">{{ $movimento->description }}</h3>
                                    @if (in_array($movimento->movement_status, ['partial', 'partially_settled'], true))
                                        @php
                                            $valorPendente = max(0, (float) ($movimento->remaining_amount ?? $movimento->amount));
                                            $valorPago = max(0, (float) $movimento->amount - $valorPendente);
                                            $percentagemPaga = (float) $movimento->amount > 0
                                                ? min(100, max(0, ($valorPago / (float) $movimento->amount) * 100))
                                                : 0;
                                        @endphp
                                        <div class="small text-secondary d-flex flex-wrap justify-content-between gap-2">
                                            <span class="text-success">Pago: {{ number_format($valorPago, 2, ',', '.') }} {{ $moeda }}</span>
                                            <span>Pendente: {{ number_format($valorPendente, 2, ',', '.') }} {{ $moeda }}</span>
                                        </div>
                                    @endif
                                    <p class="small text-secondary mb-0">{{ $categoriaLabel }} · {{ $dataMovimento->format('d/m/Y') }}</p>
                                    <div class="small d-flex flex-wrap align-items-center gap-2 mt-1">
                                        @if ($movimento->movement_status === 'settled')
                                            <span class="text-success">Pago</span>
                                        @elseif (in_array($movimento->movement_status, ['partial', 'partially_settled'], true))
                                            <span class="text-info-emphasis">Parcial</span>
                                        @elseif ($movimento->movement_status === 'pending')
                                            <span class="text-secondary">Pendente</span>
                                        @endif
                                        @if ($movimento->recurrence_id)
                                            <span class="text-body-tertiary d-inline-flex align-items-center gap-1"><i data-lucide="repeat" aria-hidden="true"></i> Recorrente</span>
                                        @endif
                                    </div>
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
                            </div>
                            @if (in_array($movimento->movement_status, ['partial', 'partially_settled'], true))
                                <div class="w-100 rounded-pill mt-1" role="progressbar" aria-label="Percentagem do movimento paga"
                                    aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($percentagemPaga) }}"
                                    style="height: 4px; background: var(--bs-tertiary-bg); overflow: hidden;">
                                    <div class="rounded-pill h-100" style="width: {{ $percentagemPaga }}%; background: var(--bs-success-border-subtle, #86b99a);"></div>
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="empty-state text-center py-4">
                            <div class="empty-icon mb-3" aria-hidden="true">◎</div>
                            @if ($totalVisivel > 0)
                                <h3 class="h6">Mais movimentos por carregar</h3>
                                <p class="small text-secondary mb-0">Sem movimentos nesta página.</p>
                            @else
                                <h3 class="h6">Ainda não existem movimentos</h3>
                                
                            @endif
                        </div>
                    @endforelse
                    @if ($movimentosVisiveis->count() < $totalVisivel)
                        <div class="d-flex justify-content-center py-3">
                            <button type="button" id="loadMoreMovements" class="btn btn-outline-primary btn-sm px-4">Mais</button>
                        </div>
                    @endif
                </div>
            </div>
        </article>
    </div>
</section>




<div class="modal fade" id="movementDetailsModal" tabindex="-1" aria-labelledby="movementDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="movementDetailsTitle">Detalhes do movimento</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="expense-icon flex-shrink-0" aria-hidden="true"><i data-lucide="wallet"></i></div>
                    <div class="min-w-0 flex-grow-1">
                        <h3 class="h6 fw-semibold mb-1 text-break" id="movementDetailsName">Movimento</h3>
                        <p class="small text-secondary mb-0" id="movementDetailsType">Despesa</p>
                    </div>
                    <span class="badge text-bg-secondary" id="movementDetailsStatus">Pendente</span>
                </div>
                <div class="mb-3">
                    <div class="h3 fw-bold mb-0" id="movementDetailsAmount">0,00 €</div>
                    <div class="small text-secondary">Valor total do movimento</div>
                </div>
                <hr>
                <section id="movementDetailsPaymentHeading" class="mb-3" hidden>
                    <h3 class="small fw-semibold mb-3">Estado do pagamento</h3>
                    <div class="d-flex justify-content-between gap-3 mb-2">
                        <div id="movementDetailsPaidLine"><div class="small text-secondary">Valor pago</div><strong id="movementDetailsPaid">0,00 €</strong></div>
                        <div id="movementDetailsPendingLine" class="text-end"><div class="small text-secondary">Valor pendente</div><strong id="movementDetailsPending">0,00 €</strong></div>
                    </div>
                    <div id="movementDetailsProgressWrap" class="progress rounded-pill" style="height:8px" hidden>
                        <div id="movementDetailsProgress" class="progress-bar bg-success" role="progressbar" aria-label="Percentagem paga" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                    </div>
                    <div class="small text-secondary mt-2" id="movementDetailsProgressLabel"></div>
                </section>
                <hr>
                <h3 class="small fw-semibold mb-3">Informações</h3>
                <div class="d-flex justify-content-between gap-3 mb-2"><span class="small text-secondary">Data</span><span class="small fw-medium" id="movementDetailsDate">—</span></div>
                <div class="d-flex justify-content-between gap-3 mb-2"><span class="small text-secondary">Categoria</span><span class="small fw-medium" id="movementDetailsCategory">—</span></div>
                <div class="d-flex justify-content-between gap-3"><span class="small text-secondary">Tipo</span><span class="small fw-medium" id="movementDetailsKind">—</span></div>
                <hr>
                <h3 class="small fw-semibold mb-3">Ações</h3>
                <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                    <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" id="movementDetailsSettle">
                        <i data-lucide="check-circle" class="text-success" aria-hidden="true"></i><span>Pagar</span><i data-lucide="chevron-right" class="ms-auto text-secondary" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" id="movementDetailsEdit">
                        <i data-lucide="pencil" aria-hidden="true"></i><span>Editar</span><i data-lucide="chevron-right" class="ms-auto text-secondary" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 text-danger" id="movementDetailsDelete">
                        <i data-lucide="trash-2" aria-hidden="true"></i><span>Eliminar</span><i data-lucide="chevron-right" class="ms-auto text-secondary" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="settleMovementModal" tabindex="-1" aria-labelledby="settleMovementTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="settleMovementForm">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="settleMovementTitle">Confirmar movimento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-body fw-semibold small mb-3" id="settleMovementDescription"></p>
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
                        <select id="settleMovementAccount" name="account_id" class="form-select account-picker-native">
                            <option value="">Selecionar conta</option>
                            @if ($accounts->where('type', 'bank')->isNotEmpty())
                                <optgroup label="Contas bancárias">
                                    @foreach ($accounts->where('type', 'bank') as $account)
                                        @php $accountBalance = $accountBalances[$account->id]['balance'] ?? null; @endphp
                                        <option value="{{ $account->id }}" data-account-type="bank" @if($accountBalance !== null) style="color:{{ $accountBalance < 0 ? '#dc3545' : 'inherit' }}" @endif>{{ $account->name }} · {{ $account->currency }} · {{ $accountBalance !== null ? number_format($accountBalance, 2, ',', '.') . ' ' . $account->currency : 'Saldo indisponível' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($accounts->where('type', 'credit_card')->isNotEmpty())
                                <optgroup label="Cartões de crédito">
                                    @foreach ($accounts->where('type', 'credit_card') as $account)
                                        @php $availableCredit = $accountBalances[$account->id]['available_credit'] ?? null; @endphp
                                        <option value="{{ $account->id }}" data-account-type="credit_card">{{ $account->name }} · {{ $account->currency }} · {{ $availableCredit !== null ? number_format($availableCredit, 2, ',', '.') . ' ' . $account->currency . ' disponível' : 'Crédito indisponível' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($accounts->where('type', 'cash')->isNotEmpty())
                                <optgroup label="Dinheiro">
                                    @foreach ($accounts->where('type', 'cash') as $account)
                                        @php $accountBalance = $accountBalances[$account->id]['balance'] ?? null; @endphp
                                        <option value="{{ $account->id }}" data-account-type="cash" @if($accountBalance !== null) style="color:{{ $accountBalance < 0 ? '#dc3545' : 'inherit' }}" @endif>{{ $account->name }} · {{ $account->currency }} · {{ $accountBalance !== null ? number_format($accountBalance, 2, ',', '.') . ' ' . $account->currency : 'Saldo indisponível' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex flex-row gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success flex-fill" id="settleMovementSubmit">Confirmar</button>
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
                    <div id="deleteSingleConfirmation" hidden></div>
                    <div id="deleteRecurringOptions" class="d-grid gap-2" hidden>
                        <label class="form-check border rounded-3 p-3 mb-0" for="deleteScopeOnly">
                            <input class="form-check-input ms-0 me-2" type="radio" name="delete_scope" id="deleteScopeOnly" value="only">
                            <span class="fw-semibold">Apenas este movimento</span>
                            <span class="d-block small text-secondary">Só esta ocorrência.</span>
                        </label>
                        <label class="form-check border rounded-3 p-3 mb-0" for="deleteScopeFuture">
                            <input class="form-check-input ms-0 me-2" type="radio" name="delete_scope" id="deleteScopeFuture" value="this_and_future">
                            <span class="fw-semibold">Este e os seguintes</span>
                            <span class="d-block small text-secondary">Esta e futuras pendentes.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex flex-row gap-2">
                    <button type="submit" class="btn btn-danger flex-fill" id="deleteMovementSubmit">Eliminar</button>
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>


@endsection

@push('styles')
<style>
    #deleteRecurringOptions[hidden] { display:none !important; }
    .account-picker-native { position:absolute !important; width:1px !important; height:1px !important; padding:0 !important; margin:-1px !important; overflow:hidden !important; clip:rect(0,0,0,0) !important; white-space:nowrap !important; border:0 !important; }
    .account-picker .dropdown-menu { width:100%; max-height:min(55vh, 420px); overflow-y:auto; }
    .account-picker .dropdown-item { white-space:normal; padding:.7rem .85rem; }
    .account-picker .account-option { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .account-picker .account-name { min-width:0; overflow-wrap:anywhere; }
    .account-picker .account-balance { flex-shrink:0; font-size:.875rem; font-weight:600; text-align:right; }
    .account-picker .account-balance.negative { color:var(--bs-danger); }
    .account-picker .account-group { padding:.45rem .85rem .25rem; color:var(--bs-secondary-color); font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }

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

@push('styles')

<style>
/* Modal de exclusão: textos curtos e ações alinhadas. */
#deleteMovementModal .modal-footer{display:flex;flex-direction:row;align-items:stretch;gap:.5rem}
#deleteMovementModal .modal-footer>.btn{display:inline-flex;flex:1 1 0;min-width:0;align-items:center;justify-content:center;margin:0;padding-inline:.5rem;white-space:nowrap}
@media(max-width:380px){
    #deleteMovementModal .modal-footer>.btn{font-size:.82rem;padding-inline:.35rem}
}
.movement-entry-dialog{max-width:560px}
.movement-editor-card .card-body{padding:24px!important}
.movement-editor-card .form-label{font-size:.875rem;font-weight:600}
.movement-editor-card .form-control,.movement-editor-card .form-select{min-height:48px;border-radius:12px}
.movement-editor-card .input-group-lg>.form-control,.movement-editor-card .input-group-lg>.input-group-text{min-height:52px}
.movement-editor-card .record-type-buttons .btn{min-height:46px;font-weight:700}
.movement-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.movement-status-grid .payment-status-option{min-height:48px;border:1px solid var(--bs-border-color);border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:8px;cursor:pointer}
.movement-status-grid .payment-status-option:has(input:checked){background:#dbeafe;border-color:#0d6efd}
.movement-details-accordion .accordion-button{min-height:58px;border-radius:12px!important}
.movement-details-accordion .accordion-button:not(.collapsed){background:#dbeafe;color:#174078;box-shadow:none}
.movement-details-accordion .accordion-button::after{align-self:center}
.movement-entry-dialog{width:calc(100% - 2rem);max-width:560px;max-height:calc(100dvh - 2rem);margin:1rem auto;display:flex;align-items:center}
.movement-entry-dialog .modal-content{max-height:calc(100dvh - 2rem);overflow:hidden}
.movement-editor-card{max-height:calc(100dvh - 2rem);min-height:0;overflow:hidden}
.movement-editor-card .card-body{max-height:calc(100dvh - 2rem);overflow-y:auto;overscroll-behavior:contain}
@media(max-width:575.98px){
.movement-entry-dialog{width:calc(100% - 24px);max-width:440px;max-height:calc(100dvh - 24px);margin:12px auto;align-items:center}
.movement-entry-dialog .modal-content{max-height:calc(100dvh - 24px);min-height:0;border-radius:1rem!important}
.movement-editor-card{max-height:calc(100dvh - 24px);min-height:0;border-radius:1rem!important}
.movement-editor-card .card-body{max-height:calc(100dvh - 24px);padding:20px 16px!important}
.movement-date-category{display:grid;grid-template-columns:1fr}
.movement-date-category>[class*="col-"]{width:100%}
.movement-status-grid{grid-template-columns:1fr 1fr}
}
</style>
@endpush

@push('scripts')
<script>document.addEventListener('DOMContentLoaded',()=>{const c=document.getElementById('dashboardRecurrenceEnabled'),f=document.getElementById('dashboardRecurrenceFields');if(c&&f){const sync=()=>f.hidden=!c.checked;c.addEventListener('change',sync);sync();}});</script>
<script>
window.meuBolsoDashboard = {
    userId: @json(auth()->id()),
    name: @json(auth()->user()->name),
    currency: @json($moeda),
    initialType: @json(request()->query('tipo') === 'receita' || old('income_date') ? 'receita' : 'despesa'),
    returnUrl: @json($returnUrl),
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
    expenseCategoryBehaviors: @json($expenseCategoryBehaviors),
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

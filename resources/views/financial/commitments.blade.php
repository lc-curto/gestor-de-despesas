@extends('layouts.app')

@section('title', 'Parcelamentos')

@section('content')
<div class="app-content commitments-page">
    <x-financial.page-header title="Parcelamentos">
        <x-slot:subtitle>Compras divididas em duas ou mais prestações.</x-slot:subtitle>
        <x-slot:actions>
            <button type="button" class="btn btn-primary btn-sm commitment-add-button" data-bs-toggle="modal" data-bs-target="#createCommitmentModal" aria-label="Nova compra">
                <i data-lucide="plus" aria-hidden="true"></i>
            </button>
        </x-slot:actions>
    </x-financial.page-header>

    <div id="commitmentFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <section class="commitment-summary-card card border-0 shadow-sm rounded-4 mb-4" aria-label="Resumo financeiro">
        <div class="card-body p-3">
            <span class="small text-secondary">Total em aberto</span>
            <strong class="d-block fs-3 mt-1 commitment-summary-total">{{ auth()->user()->currency }} {{ number_format($summary['open'], 2, ',', '.') }}</strong>
            <div class="commitment-summary-secondary">
                <div>
                    <span>Próxima prestação</span>
                    @if ($summary['next_installment'])
                        <strong>{{ auth()->user()->currency }} {{ number_format(max(0, (float) $summary['next_installment']->amount - (float) $summary['next_installment']->paid_amount), 2, ',', '.') }}</strong>
                    @else
                        <strong>—</strong>
                    @endif
                </div>
                <div>
                    <span>Em atraso</span>
                    <strong class="{{ $summary['overdue'] > 0 ? 'text-danger' : '' }}">{{ auth()->user()->currency }} {{ number_format($summary['overdue'], 2, ',', '.') }}</strong>
                </div>
            </div>
        </div>
    </section>

    <section class="commitment-list-panel" aria-labelledby="commitmentsTitle">
        @php
            $commitmentFilters = [
                'active' => ['Ativas', $summary['active_count']],
                'completed' => ['Concluídas', $summary['completed_count']],
                'archived' => ['Arquivadas', $summary['archived_count']],
                'all' => ['Todas', $summary['total_count']],
            ];
            $currentFilter = $commitmentFilters[$status] ?? $commitmentFilters['active'];
        @endphp
        <div class="commitment-filter d-flex justify-content-end mb-3">
            <div class="dropdown">
                <button class="btn btn-sm btn-link text-decoration-none commitment-filter-trigger d-inline-flex align-items-center gap-1"
                    id="commitmentsFilterDropdown" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="text-secondary">Mostrar:</span>
                    <span>{{ $currentFilter[0] }}</span>
                    <span class="commitment-filter-count">({{ $currentFilter[1] }})</span>
                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="commitmentsFilterDropdown">
                    @foreach ($commitmentFilters as $key => [$label, $count])
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-3 {{ $status === $key ? 'active' : '' }}"
                                href="{{ route('financial.commitments.page', array_merge(request()->except('purchases', 'status'), ['status' => $key])) }}"
                                @if ($status === $key) aria-current="page" @endif>
                                <span>{{ $label }}</span>
                                <span class="small text-secondary ms-auto">{{ $count }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <form class="mb-3" method="GET" action="{{ route('financial.commitments.page') }}" role="search">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <div class="input-group commitment-search-control">
                <span class="input-group-text"><i data-lucide="search" aria-hidden="true"></i></span>
                <label class="visually-hidden" for="commitmentSearch">Pesquisar compra ou comerciante</label>
                <input class="form-control" id="commitmentSearch" type="search" name="q" value="{{ $search }}" placeholder="Pesquisar compra ou comerciante..." autocomplete="off">
                @if ($search !== '')<a href="{{ route('financial.commitments.page', ['status' => $status, 'per_page' => $perPage]) }}" class="btn btn-outline-secondary" aria-label="Limpar pesquisa"><i data-lucide="x" aria-hidden="true"></i></a>@endif
            </div>
        </form>

        <div class="commitment-list-heading">
            <h2 class="h5 fw-bold mb-0" id="commitmentsTitle">
                {{ $status === 'active' ? 'Compras ativas' : ($status === 'completed' ? 'Compras concluídas' : ($status === 'archived' ? 'Compras arquivadas' : 'Todas as compras')) }}
            </h2>
            <span class="small text-secondary">{{ $commitments->total() }} resultados</span>
        </div>

        <div class="commitment-cards">
            @forelse ($commitments as $commitment)
                @php
                    $history = $commitment->installmentsPage;
                    $partsTotal = (int) $commitment->installments_count;
                    $partsPaid = (int) $commitment->paid_installments_count;
                    $isCompleted = $commitment->remaining_amount <= 0.009;
                    $isSoftDeleted = $commitment->trashed();
                    $isCancelled = $commitment->status === 'cancelled';
                    $isArchived = $commitment->archived_at !== null || $isSoftDeleted || $isCancelled;
                    $next = $commitment->next_installment;
                    $nextRemaining = $next ? max(0, (float) $next->amount - (float) $next->paid_amount) : 0;
                @endphp
                <article class="commitment-card card border-0 shadow-sm rounded-4" data-commitment-card data-commitment-id="{{ $commitment->id }}">
                    <button class="commitment-card-toggle" type="button" data-bs-toggle="modal" data-bs-target="#commitmentDetailsModal-{{ $commitment->id }}" aria-haspopup="dialog">
                        <span class="commitment-card-heading">
                            <span class="commitment-card-name">{{ $commitment->name }}@if ($commitment->merchant)<small class="commitment-merchant">{{ $commitment->merchant }}</small>@endif</span>
                            <span class="commitment-status {{ $isCancelled ? 'is-neutral' : ($isCompleted ? 'is-complete' : ($commitment->has_overdue_installment ? 'is-overdue' : 'is-active')) }}">
                                <span aria-hidden="true"></span>{{ $isCancelled ? 'Cancelado' : ($isCompleted ? 'Concluído' : ($commitment->has_overdue_installment ? 'Em atraso' : 'Em dia')) }}
                            </span>
                        </span>
                        <span class="commitment-card-balance">{{ auth()->user()->currency }} {{ number_format($commitment->remaining_amount, 2, ',', '.') }}</span>
                        <span class="commitment-card-installments">{{ $partsPaid }}/{{ $partsTotal }} prestações pagas</span>
                        <span class="progress commitment-progress" role="progressbar"
                            aria-label="Percentagem do valor pago"
                            aria-valuenow="{{ $commitment->progress_percent }}"
                            aria-valuemin="0" aria-valuemax="100">
                            <span class="progress-bar" style="width: {{ $commitment->progress_percent }}%"></span>
                        </span>
                        <span class="commitment-card-foot">
                            <small>{{ $next ? ($commitment->has_overdue_installment ? 'Prestação vencida' : 'Próxima prestação').' · '.$next->due_date->format('d/m') : 'Plano concluído' }}</small>
                            <i data-lucide="chevron-right" aria-hidden="true"></i>
                        </span>
                    </button>

                    <div class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-top">
                        <span class="small text-secondary">Ver detalhes</span>
                        <div class="d-flex align-items-center gap-2">

                            <x-financial.action-menu label="Ações da compra">
                                <li><button type="button" class="dropdown-item js-edit-commitment" @disabled($isCompleted)
                                    data-url="{{ route('financial.commitments.update', $commitment->id) }}"
                                    data-name="{{ $commitment->name }}" data-merchant="{{ $commitment->merchant }}"
                                    data-total="{{ number_format((float) $commitment->total_amount, 2, '.', '') }}">Editar</button></li>
                                <li><button type="button" class="dropdown-item js-renegotiate-commitment" @disabled($isCompleted)
                                    data-url="{{ route('financial.commitments.renegotiate', $commitment->id) }}"
                                    data-name="{{ $commitment->name }}"
                                    data-remaining="{{ number_format((float) $commitment->remaining_amount, 2, '.', '') }}"
                                    data-count="{{ max(1, $partsTotal - $partsPaid) }}"
                                    data-frequency="{{ $commitment->installment_frequency ?? 'monthly' }}"
                                    data-due="{{ $next && $next->due_date->gte(today()) ? $next->due_date->format('Y-m-d') : now()->addMonth()->toDateString() }}">Renegociar</button></li>
                                @if ($isArchived)
                                    @if ($isSoftDeleted)
                                        <li><button type="button" class="dropdown-item js-restore-commitment" data-url="{{ route('financial.commitments.restore', $commitment->id) }}">Recuperar</button></li>
                                    @elseif (!$isCancelled)
                                        <li><button type="button" class="dropdown-item js-restore-commitment" data-url="{{ route('financial.commitments.restore', $commitment->id) }}">Restaurar</button></li>
                                    @endif
                                    <li><button type="button" class="dropdown-item js-archive-commitment" data-archived="true" data-name="{{ $commitment->name }}" data-delete-url="{{ route('financial.commitments.destroy', $commitment->id) }}">Gerir</button></li>
                                @else
                                    <li><button type="button" class="dropdown-item js-archive-commitment" data-url="{{ route('financial.commitments.archive', $commitment->id) }}" data-name="{{ $commitment->name }}" data-delete-url="{{ route('financial.commitments.destroy', $commitment->id) }}">Arquivar</button></li>
                                @endif
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger js-cancel-commitment" @disabled($isCompleted)
                                    data-url="{{ route('financial.commitments.cancel', $commitment->id) }}" data-name="{{ $commitment->name }}">Cancelar</button></li>
                            </x-financial.action-menu>
                        </div>
                    </div>

                </article>

                    <div class="modal fade commitment-details-modal" id="commitmentDetailsModal-{{ $commitment->id }}" tabindex="-1" aria-labelledby="commitmentDetailsTitle-{{ $commitment->id }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-modal-dialog">
                            <div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
                                <div class="modal-header">
                                    <div>
                                        <h2 class="modal-title h5 fw-bold mb-1" id="commitmentDetailsTitle-{{ $commitment->id }}">{{ $commitment->name }}</h2>
                                        @if ($commitment->merchant)<small class="text-secondary">{{ $commitment->merchant }}</small>@endif
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="commitment-details">
                        <div class="commitment-detail-summary">
                            <div><small>Valor total</small><strong>{{ auth()->user()->currency }} {{ number_format((float) $commitment->total_amount, 2, ',', '.') }}</strong></div>
                            <div><small>Valor pago</small><strong>{{ auth()->user()->currency }} {{ number_format((float) $commitment->paid_amount_total, 2, ',', '.') }}</strong></div>
                            <div><small>Em aberto</small><strong>{{ auth()->user()->currency }} {{ number_format($commitment->remaining_amount, 2, ',', '.') }}</strong></div>
                            <div><small>Prestações</small><strong>{{ $partsPaid }}/{{ $partsTotal }}</strong></div>
                        </div>

                        @if ($next)
                            <div class="commitment-next-payment">
                                <div><strong>Próxima prestação</strong><small>{{ $next->installment_number }}/{{ $partsTotal }} · {{ $next->due_date->format('d/m/Y') }}</small></div>
                                <strong>{{ auth()->user()->currency }} {{ number_format($nextRemaining, 2, ',', '.') }}</strong>
                                @if ($paymentAccounts->isNotEmpty())
                                    <button type="button" class="btn btn-primary commitment-pay-next"
                                        data-pay-url="{{ route('financial.commitments.installments.pay', [$commitment->id, $next->id]) }}"
                                        data-debt-name="{{ $commitment->name }}"
                                        data-installment-label="{{ $next->installment_number }}/{{ $partsTotal }}"
                                        data-remaining="{{ number_format($nextRemaining, 2, '.', '') }}">Pagar</button>
                                @else
                                    <p class="small text-secondary mb-0"><a href="{{ route('financial.accounts.page') }}">Criar conta</a></p>
                                @endif
                            </div>
                        @endif



                                    </div>
                                </div>
                                <div class="modal-footer border-0 pt-2">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                                    <button type="button" class="btn btn-outline-secondary commitment-history-trigger" data-bs-toggle="modal" data-bs-target="#commitmentHistoryModal-{{ $commitment->id }}" data-bs-dismiss="modal">Histórico completo</button>
                                </div>
                            </div>
                        </div>
                    </div>

                <div class="modal fade commitment-history-modal" id="commitmentHistoryModal-{{ $commitment->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg commitment-modal-dialog">
                        <div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title h5 fw-bold mb-1">Histórico de prestações</h2>
                                    <small class="text-secondary">{{ $commitment->name }} · {{ $history->total() }} prestações</small>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <div class="commitment-history">
                            @foreach ($history as $installment)
                                @php
                                    $remaining = max(0, (float) $installment->amount - (float) $installment->paid_amount);
                                    $paid = $installment->status === 'paid' || $remaining <= 0.009;
                                    $overdue = $installment->status !== 'cancelled' && !$paid && $installment->due_date->lt(today());
                                    $label = $paid ? 'Paga' : ($installment->status === 'cancelled' ? 'Substituída' : ($overdue ? 'Atrasada' : ($installment->id === $next?->id ? 'Próxima' : 'Pendente')));
                                @endphp
                                <div class="commitment-installment border-bottom py-2">
                                    <x-financial.history-row
                                        :icon="$paid ? 'check-circle' : ($overdue ? 'alert-circle' : 'calendar-clock')"
                                        :icon-class="$paid ? 'bg-success-subtle text-success' : ($overdue ? 'bg-danger-subtle text-danger' : 'bg-body-secondary text-secondary')"
                                        :title="'Prestação ' . $installment->installment_number . '/' . $partsTotal"
                                        :subtitle="$installment->due_date->format('d/m/Y')"
                                        :amount="auth()->user()->currency . ' ' . number_format($remaining > 0 ? $remaining : (float) $installment->amount, 2, ',', '.')"
                                        :amount-class="$paid ? 'text-success' : ($overdue ? 'text-danger' : 'text-body')"
                                        :status="$label"
                                        :status-class="$paid ? 'text-bg-success' : ($overdue ? 'text-bg-danger' : ($label === 'Próxima' ? 'text-bg-warning' : 'text-bg-secondary'))"
                                    />
                                </div>
                            @endforeach
                            @if ($history->hasPages())
                                @php
                                    // Each purchase owns an isolated history page parameter.
                                    // Remove other history cursors so they cannot interfere with this navigation.
                                    $historyQuery = request()->query();
                                    foreach (array_keys($historyQuery) as $queryKey) {
                                        if (str_starts_with($queryKey, 'installments_')) {
                                            unset($historyQuery[$queryKey]);
                                        }
                                    }
                                    $historyQuery['history_modal'] = $commitment->id;
                                    $historyPageUrl = fn (int $page) => route('financial.commitments.page', array_merge(
                                        $historyQuery,
                                        [$history->getPageName() => $page]
                                    )).'#commitmentHistoryModal-'.$commitment->id;
                                @endphp
                                <nav class="commitment-history-pagination" aria-label="Paginação do histórico de {{ $commitment->name }}">
                                    <span>Página {{ $history->currentPage() }} de {{ $history->lastPage() }}</span>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Navegar no histórico">
                                        <a class="btn btn-outline-secondary {{ $history->onFirstPage() ? 'disabled' : '' }}"
                                           href="{{ $history->onFirstPage() ? '#' : $historyPageUrl($history->currentPage() - 1) }}"
                                           @if ($history->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>« Anterior</a>
                                        <a class="btn btn-outline-primary {{ $history->hasMorePages() ? '' : 'disabled' }}"
                                           href="{{ $history->hasMorePages() ? $historyPageUrl($history->currentPage() + 1) : '#' }}"
                                           @if (!$history->hasMorePages()) aria-disabled="true" tabindex="-1" @endif>Seguinte »</a>
                                    </div>
                                </nav>
                            @endif
                        </div>
                            </div>
                            <div class="modal-footer border-0 pt-2">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center p-4 rounded-4 border bg-body-tertiary">
                    <strong class="d-block">Nenhuma compra encontrada.</strong>
                    <span class="text-secondary small">Sem resultados.</span>
                </div>
            @endforelse
        </div>

        @if ($commitments->hasPages())
            <div class="commitment-purchase-pagination">
                <div class="commitment-pagination-meta">
                    <span>A mostrar {{ $commitments->firstItem() }}–{{ $commitments->lastItem() }} de {{ $commitments->total() }}</span>
                    <span>{{ $perPage }} compras por página</span>
                </div>
                {{ $commitments->onEachSide(1)->links('pagination::bootstrap-5') }}
            </div>
        @else
            <div class="commitment-pagination-meta"><span>A mostrar {{ $commitments->total() }} de {{ $commitments->total() }}</span><span>{{ $perPage }} compras por página</span></div>
        @endif
    </section>
</div>

<div class="modal fade" id="createCommitmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog commitment-modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
            <form id="createCommitmentForm">
                <div class="modal-header border-0 pb-2"><h2 class="modal-title h5 fw-bold">Nova compra parcelada</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body">
                    <label class="form-label" for="newCommitmentName">Nome da compra</label><input class="form-control mb-3" id="newCommitmentName" name="name" maxlength="160" required>
                    <label class="form-label" for="newCommitmentMerchant">Comerciante (opcional)</label><input class="form-control mb-3" id="newCommitmentMerchant" name="merchant" maxlength="160">
                    <label class="form-label" for="newCommitmentTotal">Valor total (€)</label><input class="form-control mb-3" id="newCommitmentTotal" name="total_amount" type="number" min="0.01" step="0.01" required>
                    <label class="form-label" for="newCommitmentCount">Número de prestações</label><input class="form-control mb-3" id="newCommitmentCount" name="installments_count" type="number" min="2" max="120" value="3" required>
                    <label class="form-label" for="newCommitmentFrequency">Frequência</label><select class="form-select mb-3" id="newCommitmentFrequency" name="installment_frequency"><option value="monthly">Mensal</option><option value="weekly">Semanal</option><option value="biweekly">Quinzenal</option></select>
                    <label class="form-label" for="newCommitmentStart">Data da compra</label><input class="form-control mb-3" id="newCommitmentStart" name="start_date" type="date" value="{{ now()->toDateString() }}" required>
                    <label class="form-label" for="newCommitmentDue">Vencimento da primeira prestação</label><input class="form-control mb-3" id="newCommitmentDue" name="due_date" type="date" value="{{ now()->addMonth()->toDateString() }}" required>
                    <label class="form-label" for="newCommitmentAccount">Conta associada (opcional)</label><select class="form-select" id="newCommitmentAccount" name="account_id"><option value="">Sem conta associada</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select>
                    <div class="small text-danger mt-2" id="createCommitmentError" role="alert"></div>
                </div>
                <div class="modal-footer border-0 pt-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Criar</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="commitmentPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog commitment-modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
            <form id="commitmentPaymentForm" class="js-api-form" method="POST" data-endpoint="">
                @csrf
                <div class="modal-header border-0 pb-2">
                    <div><h2 class="modal-title h5 fw-bold mb-1">Pagar prestação</h2><small class="text-secondary" id="paymentCommitmentName"></small></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary" id="paymentInstallmentLabel"></p>
                    <label class="form-label" for="commitmentPaymentAmount">Valor a pagar</label>
                    <input class="form-control mb-3" id="commitmentPaymentAmount" name="amount" type="text" inputmode="decimal" data-payment-amount data-max-amount="0.00" required>
                    <label class="form-label" for="commitmentPaymentAccount">Conta de origem</label>
                    <select class="form-select mb-3" id="commitmentPaymentAccount" name="account_id" required>
                        <option value="">Selecionar conta</option>
                        @foreach ($paymentAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <label class="form-label" for="commitmentPaymentDate">Data do pagamento</label>
                    <input class="form-control" id="commitmentPaymentDate" name="payment_date" type="date" value="{{ now()->toDateString() }}" required>
                    <div class="small text-danger mt-2" id="commitmentPaymentError" role="alert"></div>
                </div>
                <div class="modal-footer border-0 pt-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Pagar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCommitmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog commitment-modal-dialog"><div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content"><form id="editCommitmentForm">
        <div class="modal-header border-0 pb-2"><h2 class="modal-title h5 fw-bold">Editar compra</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <label class="form-label" for="editCommitmentName">Nome da compra</label><input class="form-control mb-3" id="editCommitmentName" name="name" required maxlength="160">
            <label class="form-label" for="editCommitmentMerchant">Comerciante (opcional)</label><input class="form-control mb-3" id="editCommitmentMerchant" name="merchant" maxlength="160">
            <label class="form-label" for="editCommitmentTotal">Valor total</label><input class="form-control mb-3" id="editCommitmentTotal" name="total_amount" type="number" min="0.01" step="0.01" required>
            
            <div id="editCommitmentError" class="small text-danger mt-2"></div>
        </div>
        <div class="modal-footer border-0 pt-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="renegotiateCommitmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog commitment-modal-dialog"><div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
        <form id="renegotiateCommitmentForm">
            <div class="modal-header border-0 pb-2"><div><h2 class="modal-title h5 fw-bold">Renegociar parcelamento</h2><small class="text-secondary" id="renegotiateCommitmentName"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                
                <label class="form-label" for="renegotiateRemaining">Saldo a renegociar (€)</label><input class="form-control mb-3" id="renegotiateRemaining" name="remaining_amount" type="number" min="0.01" step="0.01" required>
                <label class="form-label" for="renegotiateCount">Número de novas prestações</label><input class="form-control mb-3" id="renegotiateCount" name="installments_count" type="number" min="1" max="120" required>
                <label class="form-label" for="renegotiateFrequency">Frequência</label><select class="form-select mb-3" id="renegotiateFrequency" name="installment_frequency"><option value="monthly">Mensal</option><option value="biweekly">Quinzenal</option><option value="weekly">Semanal</option></select>
                <label class="form-label" for="renegotiateDue">Vencimento da primeira nova prestação</label><input class="form-control" id="renegotiateDue" name="due_date" type="date" min="{{ today()->toDateString() }}" required>
                <div class="commitment-renegotiation-preview mt-3 p-3 rounded-3 bg-body-tertiary"><small class="text-secondary d-block">Valor por prestação</small><strong id="renegotiatePreview">—</strong><small class="text-secondary d-block mt-1" id="renegotiatePreviewNote"></small></div>
                <div id="renegotiateCommitmentError" class="small text-danger mt-2" role="alert"></div>
            </div>
            <div class="modal-footer border-0 pt-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button><button type="submit" class="btn btn-primary">Renegociar</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="cancelCommitmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog commitment-modal-dialog"><div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
        <div class="modal-header border-0 pb-2"><h2 class="modal-title h5 fw-bold">Cancelar compra</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><p id="cancelCommitmentMessage" class="mb-2"></p><p class="small text-secondary">Pagamentos anteriores não serão reembolsados.</p><div id="cancelCommitmentError" class="small text-danger mt-2" role="alert"></div></div>
        <div class="modal-footer border-0 pt-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button><button type="button" class="btn btn-danger" id="confirmCancelCommitment">Cancelar</button></div>
    </div></div>
</div>
<div class="modal fade" id="archiveCommitmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog commitment-modal-dialog"><div class="modal-content rounded-4 border-0 shadow-sm commitment-modal-content">
        <div class="modal-header border-0 pb-2"><h2 class="modal-title h5 fw-bold">Arquivar compra</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><p id="archiveCommitmentMessage" class="mb-2"></p><p class="small text-secondary">Histórico e pagamentos serão preservados.</p><div id="archiveCommitmentError" class="small text-danger mt-2" role="alert"></div></div>
        <div class="modal-footer border-0 pt-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
            <button type="button" class="btn btn-primary" id="confirmArchiveCommitment">Arquivar</button>
            <button type="button" class="btn btn-outline-danger" id="deleteCommitmentButton">Eliminar</button>
        </div>
    </div></div>
</div>

@endsection

@push('styles')
<style>
.commitments-page{max-width:1500px;margin-inline:auto;container-type:inline-size}
.commitment-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;width:100%}
.commitment-metric{position:relative;min-width:0;min-height:126px;padding:1.15rem 1.25rem;background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:1rem;display:flex;flex-direction:column;justify-content:space-between;gap:.55rem;overflow:hidden}
.commitment-metric span{font-size:.78rem;font-weight:600;letter-spacing:.01em;color:var(--bs-secondary-color)}
.commitment-metric small{font-size:.78rem;color:var(--bs-secondary-color);line-height:1.4}
.commitment-metric strong{font-size:1.3rem;line-height:1.25;font-weight:750;overflow-wrap:anywhere;word-break:normal;font-variant-numeric:tabular-nums}
.commitment-metric-primary{grid-column:auto;background:linear-gradient(135deg,var(--bs-primary-bg-subtle),var(--bs-body-bg));border-color:color-mix(in srgb,var(--bs-primary) 22%,var(--bs-border-color))}
.commitment-metric-primary strong{font-size:1.75rem;letter-spacing:-.025em}
.commitment-metric:not(.commitment-metric-primary){background:var(--bs-tertiary-bg)}
.commitment-metric:not(.commitment-metric-primary) strong{font-size:1.25rem}
@container (max-width: 620px){
 .commitment-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem;width:100%}
 .commitment-metric{min-height:116px;padding:.9rem;border-radius:.9rem}
 .commitment-metric-primary{grid-column:1/-1;min-height:132px}
 .commitment-metric-primary strong{font-size:1.7rem}
 .commitment-metric:not(.commitment-metric-primary){min-height:124px}
 .commitment-metric:not(.commitment-metric-primary) strong{font-size:1.16rem}
}
@container (max-width: 360px){
 .commitment-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
 .commitment-metric{padding:.75rem}
 .commitment-metric strong{font-size:1.05rem}
 .commitment-metric-primary{grid-column:1/-1}
}
.commitment-history-trigger{padding:0 .9rem .85rem;display:flex;justify-content:flex-end}
.commitment-history-modal .modal-dialog{max-width:680px}
.commitment-history-modal .modal-content{max-height:min(88vh,900px)}
.commitment-history-modal .modal-body{overflow-y:auto}
.commitment-history-modal .commitment-history{margin:0}
.commitment-history-modal .commitment-installment{padding:.8rem 0}
.commitment-history-modal .commitment-history-pagination{position:sticky;bottom:0;background:var(--bs-body-bg);padding:.65rem 0}
@media(max-width:767.98px){
 .commitment-history-modal .modal-dialog{margin:.5rem;max-width:none;min-height:calc(100% - 1rem)}
 .commitment-history-modal .modal-content{max-height:calc(100dvh - 1rem);border-radius:1.1rem}
 .commitment-history-modal .modal-body{padding:.85rem}
}
.commitment-history-trigger{padding:0 .9rem .85rem;display:flex;justify-content:flex-end}
.commitment-history-modal .modal-dialog{max-width:680px}
.commitment-history-modal .modal-content{max-height:min(88vh,900px)}
.commitment-history-modal .modal-body{overflow-y:auto}
.commitment-history-modal .commitment-history{margin:0}
.commitment-history-modal .commitment-installment{padding:.8rem 0}
.commitment-history-modal .commitment-history-pagination{position:sticky;bottom:0;background:var(--bs-body-bg);padding:.65rem 0}
@media(max-width:767.98px){
 .commitment-history-modal .modal-dialog{margin:.5rem;max-width:none;min-height:calc(100% - 1rem)}
 .commitment-history-modal .modal-content{max-height:calc(100dvh - 1rem);border-radius:1.1rem}
 .commitment-history-modal .modal-body{padding:.85rem}
}
.commitment-history-pagination{flex-wrap:wrap}
.commitment-history-pagination .pagination{display:flex;flex-wrap:wrap;gap:.25rem}
.commitment-history-pagination .page-item{margin:0}
.commitment-history-pagination .page-link{white-space:nowrap}
.commitment-list-panel{background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:1.15rem;padding:1.25rem}
.commitment-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
.commitment-tabs .btn{border-radius:999px;padding:.45rem .85rem}
.commitment-search{display:flex;align-items:center;gap:.6rem;min-height:44px;padding:0 .8rem;border:1px solid var(--bs-border-color);border-radius:.75rem;margin-bottom:1.25rem}
.commitment-search>span{font-size:1.3rem;color:var(--bs-secondary-color)}
.commitment-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:var(--bs-body-color);padding:.6rem 0}
.commitment-clear-search{font-size:1.3rem;text-decoration:none;color:var(--bs-secondary-color)}
.commitment-list-heading,.commitment-history-heading{display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-bottom:.85rem}
.commitment-cards{display:grid;grid-template-columns:minmax(0,1fr);gap:.85rem}
.commitment-card{min-width:0;border:1px solid var(--bs-border-color);border-radius:1rem;background:var(--bs-body-bg);box-shadow:0 2px 8px rgba(0,0,0,.035);overflow:hidden}
/* Alternância quase impercetível entre cards, adaptável aos temas claro e escuro. */
.commitment-card:nth-child(even){
    background:color-mix(in srgb,var(--bs-body-color) 2.5%,var(--bs-body-bg));
}
.commitment-card-toggle{display:flex;width:100%;flex-direction:column;gap:.85rem;padding:1rem 1.1rem .8rem;text-align:left;border:0;background:transparent;color:inherit}
.commitment-card-toggle:hover{background:var(--bs-tertiary-bg)}
.commitment-card-heading,.commitment-card-balances,.commitment-card-foot{display:flex;justify-content:space-between;align-items:center;gap:.75rem;width:100%}
.commitment-card-name{font-weight:700;font-size:1rem;overflow-wrap:anywhere;display:flex;flex-direction:column;gap:.15rem}
.commitment-merchant{font-size:.76rem;font-weight:400;color:var(--bs-secondary-color)}
.commitment-card-balances>span{display:flex;flex-direction:column;gap:.22rem;min-width:0}
.commitment-card-balances>span:last-child{text-align:right}
.commitment-card-balances small,.commitment-detail-summary small{font-size:.75rem;color:var(--bs-secondary-color)}
.commitment-card-balances strong{font-size:1.05rem;line-height:1.25;font-variant-numeric:tabular-nums}
.commitment-card-balance-primary strong{font-size:1.15rem}
.commitment-card-balance-secondary strong{font-size:1.05rem}
.commitment-card-balance-secondary strong small{font-size:.8rem;font-weight:500}
.commitment-progress-block{display:flex;flex-direction:column;gap:.45rem;width:100%}
.commitment-progress{height:6px;width:100%;background:var(--bs-tertiary-bg);border-radius:99px;margin:0}
.commitment-progress .progress-bar{background:var(--bs-primary);border-radius:99px}
.commitment-card-foot{font-size:.73rem;color:var(--bs-secondary-color)}
.commitment-card-utility{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.65rem 1.1rem .8rem;border-top:1px solid var(--bs-border-color)}
.commitment-card-details-label{display:inline-flex;align-items:center;gap:.3rem;padding:0;border:0;background:transparent;font-size:.84rem;color:var(--bs-primary);font-weight:600}
.commitment-card-details-label span{display:inline-block;transition:transform .15s}
.commitment-card-toggle[aria-expanded=true]+.commitment-card-utility .commitment-card-details-label span{transform:rotate(180deg)}
.commitment-history-trigger{padding:.35rem .7rem;font-size:.78rem}
.commitment-history-count{font-variant-numeric:tabular-nums}
.commitment-details{border-top:1px solid var(--bs-border-color);padding:1rem 1.1rem}
.commitment-detail-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.85rem;margin-bottom:1rem}
.commitment-detail-summary>div{display:flex;flex-direction:column;gap:.2rem}
.commitment-next-payment{display:grid;grid-template-columns:1fr auto;align-items:center;gap:.45rem .75rem;padding:1rem;background:var(--bs-tertiary-bg);border-radius:.85rem;margin-bottom:1.1rem}
.commitment-next-payment>div{display:flex;flex-direction:column;gap:.2rem}
.commitment-next-payment small{color:var(--bs-secondary-color);font-size:.78rem}
.commitment-next-payment>.btn,.commitment-next-payment>p{grid-column:1/-1}
.commitment-next-payment>.btn{width:100%;min-height:42px}
.commitment-history-heading{margin-bottom:.4rem}
.commitment-history-heading small{color:var(--bs-secondary-color)}
.commitment-installment{display:flex;justify-content:space-between;align-items:center;gap:.6rem;padding:.7rem 0;border-bottom:1px solid var(--bs-border-color)}
.commitment-installment>div{display:flex;align-items:center;gap:.65rem;min-width:0}
.commitment-installment small{font-size:.78rem;color:var(--bs-secondary-color)}
.commitment-installment-value{flex-direction:column;align-items:flex-end!important;gap:.25rem!important}
.commitment-installment-value strong{font-size:.88rem}
.commitment-installment-value .badge{font-size:.68rem}
.commitment-history-pagination,.commitment-pagination-meta{display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-top:.75rem;font-size:.75rem;color:var(--bs-secondary-color)}
.commitment-history-pagination>div{min-width:0}
.commitment-history-pagination .pagination{margin:0;gap:.15rem}
.commitment-history-pagination .page-link{padding:.25rem .5rem;font-size:.75rem}
.commitment-card-actions{display:flex;justify-content:space-between;gap:.5rem;padding-top:.85rem;margin-top:.85rem;border-top:1px solid var(--bs-border-color)}
.commitment-empty-state{display:flex;flex-direction:column;align-items:center;gap:.35rem;padding:2rem 1rem;border:1px dashed var(--bs-border-color);border-radius:.85rem}
.commitment-purchase-pagination{margin-top:1rem}
.commitment-purchase-pagination .pagination{justify-content:center;margin:.5rem 0 0}
.commitment-purchase-pagination .page-link{border-radius:.5rem;margin:0 .1rem}
@media(max-width:767.98px){
 .commitment-history-pagination{flex-direction:row;align-items:center;justify-content:space-between}
 .commitment-history-pagination .pagination{max-width:100%}
 .commitment-history-pagination .page-link{padding:.3rem .55rem}
 .commitment-list-panel{padding:.85rem;border-radius:1rem}
 .commitment-tabs{gap:.35rem;margin-bottom:.85rem}
 .commitment-tabs .btn{flex:1 1 auto;padding:.45rem .55rem;font-size:.78rem}
 .commitment-cards{grid-template-columns:minmax(0,1fr);gap:.65rem}
 .commitment-card-toggle{padding:.9rem .9rem .75rem;gap:.75rem}
 .commitment-card-utility{padding:.6rem .9rem .7rem}
 .commitment-card-balances strong{font-size:1rem}
 .commitment-card-balance-primary strong{font-size:1.08rem}
 .commitment-card-foot{font-size:.7rem}
 .commitment-details{padding:.9rem}
 .commitment-detail-summary{gap:.75rem}
 .commitment-payment-dialog{margin:.5rem;align-items:flex-end;min-height:calc(100% - 1rem)}
 .commitment-payment-dialog .modal-content{border-radius:1.1rem 1.1rem .6rem .6rem}
 .commitment-payment-dialog .modal-body{padding-top:.5rem}
 .commitment-history-pagination{align-items:flex-start;flex-direction:column}
 .commitment-pagination-meta{align-items:flex-start;flex-direction:column}
}


/* Final mobile KPI layout: two equal columns, with no unused third track. */
.commitments-page .commitment-metric-primary{
    background:var(--bs-body-bg);
    border-color:var(--bs-border-color);
}
.commitments-page .commitment-metric:not(.commitment-metric-primary){
    background:var(--bs-body-bg);
}
@media (max-width:767.98px){
    .commitments-page .commitment-summary{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:.65rem;
        width:100%;
    }
    .commitments-page .commitment-metric-primary{
        grid-column:1 / -1;
    }
    .commitments-page .commitment-metric:not(.commitment-metric-primary){
        grid-column:span 1;
        width:100%;
        min-width:0;
        min-height:126px;
        padding:.9rem;
        background:var(--bs-body-bg);
    }
}


.commitment-management{border-top:1px solid var(--bs-border-color);padding-top:.9rem;margin-top:.9rem}
.commitment-card-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem;padding:0;margin:0;border:0}
.commitment-card-actions .btn{min-height:42px;white-space:normal}
.commitment-renegotiation-preview strong{font-size:1.25rem;font-variant-numeric:tabular-nums}

/* Parcelamentos: pequenos ajustes sobre os componentes Bootstrap partilhados. */
.commitments-page .commitment-tabs .nav-link {
    padding: .45rem .8rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    font-size: .8rem;
    line-height: 1.35;
    white-space: nowrap;
}
.commitments-page .commitment-tabs .nav-link.active {
    border-color: var(--app-primary);
    background: var(--app-primary);
    color: #fff;
}
.commitments-page .commitment-modal-content {
    overflow: hidden;
}
.commitments-page .commitment-modal-content .modal-header,
.commitments-page .commitment-modal-content .modal-footer {
    padding-right: 1.25rem;
    padding-left: 1.25rem;
}
.commitments-page .commitment-modal-content .modal-body {
    padding: 1rem 1.25rem;
}
.commitments-page .commitment-modal-content .form-label {
    margin-bottom: .35rem;
    font-size: .875rem;
    font-weight: 600;
}
.commitments-page .commitment-modal-content .form-control,
.commitments-page .commitment-modal-content .form-select {
    min-height: 2.75rem;
}
.commitments-page .commitment-modal-content .modal-footer .btn {
    min-width: 6rem;
}
@media (max-width: 575.98px) {
    .commitments-page .commitment-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: .2rem;
    }
    .commitments-page .commitment-tabs .nav-link {
        flex: 0 0 auto;
    }
}

.commitment-details-modal .modal-dialog { max-width: 560px; }
.commitment-details-modal .commitment-details { padding: 0; border: 0; }
.commitment-details-modal .commitment-detail-summary { margin-bottom: .9rem; }
.commitment-details-modal .commitment-next-payment { margin-bottom: 0; }
.commitment-card-toggle { cursor: pointer; }
.commitment-card-toggle:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: -3px; }
@media (max-width: 575.98px) {
    .commitment-details-modal .modal-dialog { margin: .5rem; min-height: calc(100% - 1rem); }
    .commitment-details-modal .modal-content { max-height: calc(100dvh - 1rem); border-radius: 1.1rem; }
}


/* Redesign da página de parcelamentos: resumo, filtro e cartões compactos. */
.commitments-page { max-width: 1500px; }
.commitments-page .commitment-add-button { display:inline-flex; width:42px; height:42px; align-items:center; justify-content:center; padding:0; border-radius:.75rem; }
.commitments-page .commitment-add-button svg { width:1.2rem; height:1.2rem; }
.commitments-page .commitment-summary-card { border:1px solid var(--app-border); background:var(--bs-body-bg); }
.commitments-page .commitment-summary-total { font-size:1.8rem; line-height:1.2; font-weight:800; letter-spacing:-.035em; font-variant-numeric:tabular-nums; }
.commitment-summary-secondary { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; margin-top:1rem; padding-top:.85rem; border-top:1px solid var(--app-border); }
.commitment-summary-secondary > div { display:flex; min-width:0; flex-direction:column; gap:.2rem; }
.commitment-summary-secondary > div + div { padding-left:1rem; border-left:1px solid var(--app-border); }
.commitment-summary-secondary span { color:var(--bs-secondary-color); font-size:.75rem; }
.commitment-summary-secondary strong { font-size:.95rem; font-weight:700; font-variant-numeric:tabular-nums; }
.commitment-filter-trigger { color:var(--bs-body-color); font-size:.82rem; font-weight:600; }
.commitment-filter-trigger:hover { color:var(--app-primary); }
.commitment-filter-trigger svg { width:.9rem; height:.9rem; }
.commitment-filter-count { color:var(--bs-secondary-color); font-weight:500; }
.commitments-page .commitment-search-control { border:1px solid var(--app-border); border-radius:.75rem; overflow:hidden; }
.commitments-page .commitment-search-control .input-group-text { padding-inline:.75rem; border:0; background:var(--bs-body-bg); color:var(--bs-secondary-color); }
.commitments-page .commitment-search-control .form-control { min-height:2.8rem; border:0; box-shadow:none; font-size:.88rem; }
.commitments-page .commitment-list-panel { padding:0; border:0; background:transparent; box-shadow:none; }
.commitments-page .commitment-list-heading { margin-bottom:.8rem; }
.commitments-page .commitment-cards { gap:.7rem; }
.commitments-page .commitment-card { border:1px solid var(--app-border); border-radius:1rem; box-shadow:none; }
.commitments-page .commitment-card-toggle { display:flex; width:100%; flex-direction:column; gap:.55rem; padding:.9rem 1rem; border:0; background:transparent; color:var(--bs-body-color); text-align:left; }
.commitments-page .commitment-card-heading { display:flex; width:100%; align-items:flex-start; justify-content:space-between; gap:.75rem; }
.commitments-page .commitment-card-name { display:flex; min-width:0; flex-direction:column; gap:.15rem; font-size:.92rem; font-weight:700; }
.commitments-page .commitment-merchant { color:var(--bs-secondary-color); font-size:.76rem; font-weight:400; }
.commitment-status { display:inline-flex; flex:0 0 auto; align-items:center; gap:.35rem; color:var(--bs-secondary-color); font-size:.72rem; font-weight:500; }
.commitment-status > span { width:7px; height:7px; border-radius:50%; background:var(--bs-secondary-color); }
.commitment-status.is-active > span,.commitment-status.is-complete > span { background:#29965b; }
.commitment-status.is-overdue > span { background:#d04444; }
.commitment-status.is-active,.commitment-status.is-complete { color:#26834b; }
.commitment-status.is-overdue { color:#c63f3f; }
.commitment-card-balance { font-size:1.28rem; line-height:1.2; font-weight:800; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
.commitment-card-installments { color:var(--bs-secondary-color); font-size:.73rem; }
.commitments-page .commitment-progress { width:100%; height:5px; border-radius:999px; background:var(--bs-tertiary-bg); }
.commitments-page .commitment-progress .progress-bar { border-radius:inherit; background:var(--app-primary); }
.commitment-card-foot { display:flex; width:100%; align-items:center; justify-content:space-between; gap:.5rem; color:var(--bs-secondary-color); }
.commitment-card-foot small { font-size:.73rem; }
.commitment-card-foot svg { width:1rem; height:1rem; }
.commitments-page .commitment-card > .border-top { padding:.45rem .75rem !important; }
@media (min-width:768px) {
    .commitments-page .commitment-summary-card { max-width:none; }
    .commitments-page .commitment-cards { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media (max-width:575.98px) {
    .commitments-page .commitment-card-toggle { padding:.85rem .9rem; }
    .commitments-page .commitment-card-balance { font-size:1.22rem; }
    .commitment-summary-secondary { gap:.65rem; }
    .commitment-summary-secondary > div + div { padding-left:.65rem; }
}
</style>
@endpush

@push('scripts')
<script src="{{ asset('js/commitments-payment.js') }}?v={{ filemtime(public_path('js/commitments-payment.js')) }}"></script>
<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const feedback = document.getElementById('commitmentFeedback');
    const showError = (element, message) => { element.className = 'alert alert-danger'; element.textContent = message; element.scrollIntoView({behavior:'smooth',block:'center'}); };

    // The backend paginates purchases; this client hint selects the approved page size by viewport.
    const syncPageSize = () => {
        const expected = window.matchMedia('(max-width: 767.98px)').matches ? '6' : '10';
        const url = new URL(window.location.href);
        if (url.searchParams.get('per_page') !== expected) {
            url.searchParams.set('per_page', expected);
            url.searchParams.delete('purchases');
            window.location.replace(url.toString());
        }
    };
    syncPageSize();

    const cards = [...document.querySelectorAll('[data-commitment-card]')];
    // Reopen the installment-history modal after navigating between its pages.
    const historyModalId = new URL(window.location.href).searchParams.get('history_modal');
    if (historyModalId) {
        const historyModalElement = document.getElementById('commitmentHistoryModal-' + historyModalId);
        if (historyModalElement) bootstrap.Modal.getOrCreateInstance(historyModalElement).show();
    }

    // Cada compra abre o seu próprio modal de detalhe.
    cards.forEach(card => {
        const detailsModal = document.getElementById('commitmentDetailsModal-' + card.dataset.commitmentId);
        const toggle = card.querySelector('.commitment-card-toggle');
        detailsModal?.addEventListener('shown.bs.modal', () => toggle.setAttribute('aria-expanded', 'true'));
        detailsModal?.addEventListener('hidden.bs.modal', () => toggle.setAttribute('aria-expanded', 'false'));
    });

    document.getElementById('createCommitmentForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form=event.currentTarget,button=form.querySelector('[type="submit"]');
        button.disabled=true;
        const values=Object.fromEntries(new FormData(form).entries());
        values.merchant=values.merchant||null;
        values.payment_condition='installments';
        values.paid_now='0';
        values.installments_count=Number(values.installments_count);
        if(!values.account_id)delete values.account_id;
        try{
            const response=await fetch(@json(route('financial.commitments.store')),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify(values)});
            const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível criar a compra.');
            window.location.href='{{ route('financial.commitments.page') }}';
        }catch(error){document.getElementById('createCommitmentError').textContent=error.message;button.disabled=false;}
    });

    document.querySelectorAll('.js-edit-commitment').forEach(button => button.addEventListener('click', () => {
        const form = document.getElementById('editCommitmentForm');
        form.dataset.url = button.dataset.url;
        form.elements.name.value = button.dataset.name;
        form.elements.merchant.value = button.dataset.merchant || '';
        form.elements.total_amount.value = button.dataset.total || '';
        document.getElementById('editCommitmentError').textContent = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editCommitmentModal')).show();
    }));
    document.getElementById('editCommitmentForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget, button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const response = await fetch(form.dataset.url,{method:'PUT',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({name:form.elements.name.value,merchant:form.elements.merchant.value,total_amount:form.elements.total_amount.value})});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar.');
            window.location.reload();
        } catch(error) {
            document.getElementById('editCommitmentError').textContent=error.message;
            button.disabled=false;
        }
    });

    const renegotiateModalElement = document.getElementById('renegotiateCommitmentModal');
    const renegotiateModal = bootstrap.Modal.getOrCreateInstance(renegotiateModalElement);
    const renegotiateForm = document.getElementById('renegotiateCommitmentForm');
    const updateRenegotiationPreview = () => {
        const amount = Number(renegotiateForm.elements.remaining_amount.value || 0);
        const count = Number(renegotiateForm.elements.installments_count.value || 0);
        const preview = document.getElementById('renegotiatePreview');
        preview.textContent = count > 0 && amount > 0
            ? new Intl.NumberFormat('pt-PT', {style:'currency',currency:@json(auth()->user()->currency)}).format(amount / count)
            : '—';
    };
    document.querySelectorAll('.js-renegotiate-commitment').forEach(button => button.addEventListener('click', () => {
        renegotiateForm.dataset.url = button.dataset.url;
        renegotiateForm.elements.remaining_amount.value = button.dataset.remaining;
        renegotiateForm.elements.installments_count.value = button.dataset.count;
        renegotiateForm.elements.installment_frequency.value = button.dataset.frequency;
        renegotiateForm.elements.due_date.value = button.dataset.due;
        document.getElementById('renegotiateCommitmentName').textContent = button.dataset.name;
        document.getElementById('renegotiateCommitmentError').textContent = '';
        updateRenegotiationPreview();
        renegotiateModal.show();
    }));
    renegotiateForm.elements.remaining_amount.addEventListener('input', updateRenegotiationPreview);
    renegotiateForm.elements.installments_count.addEventListener('input', updateRenegotiationPreview);
    renegotiateForm.addEventListener('submit', async event => {
        event.preventDefault();
        const button = renegotiateForm.querySelector('[type="submit"]');
        button.disabled = true;
        const payload = Object.fromEntries(new FormData(renegotiateForm).entries());
        payload.installments_count = Number(payload.installments_count);
        try {
            const response = await fetch(renegotiateForm.dataset.url, {method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify(payload)});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível renegociar.');
            window.location.reload();
        } catch (error) {
            document.getElementById('renegotiateCommitmentError').textContent = error.message;
            button.disabled = false;
        }
    });

    const archiveModalElement = document.getElementById('archiveCommitmentModal');
    const archiveModal = bootstrap.Modal.getOrCreateInstance(archiveModalElement);
    const archiveButton = document.getElementById('confirmArchiveCommitment');
    const deleteButton = document.getElementById('deleteCommitmentButton');
    let archiveActionUrl = null;
    let deleteActionUrl = null;
    let archiveActionName = '';
    document.querySelectorAll('.js-archive-commitment').forEach(button => button.addEventListener('click', () => {
        const alreadyArchived = button.dataset.archived === 'true';
        archiveActionUrl = alreadyArchived ? null : button.dataset.url;
        deleteActionUrl = button.dataset.deleteUrl;
        archiveActionName = button.dataset.name;
        archiveModalElement.querySelector('.modal-title').textContent = alreadyArchived ? 'Gerir compra arquivada' : 'Arquivar compra';
        archiveButton.hidden = alreadyArchived;
        document.getElementById('archiveCommitmentMessage').textContent = alreadyArchived
            ? 'A compra "' + archiveActionName + '" está arquivada. Podes restaurá-la ou eliminá-la definitivamente.'
            : 'Pretendes arquivar "' + archiveActionName + '"?';
        document.getElementById('archiveCommitmentError').textContent = '';
        archiveModal.show();
    }));
    document.querySelectorAll('.js-restore-commitment').forEach(button => button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const response = await fetch(button.dataset.url, {method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(result.message || 'Não foi possível restaurar a compra.');
            const recoveredAsArchived = Boolean(result.data?.archived_at);
            window.location.href = @json(route('financial.commitments.page')) + '?status=' + (recoveredAsArchived ? 'archived' : 'active');
        } catch (error) {
            button.disabled = false;
            showError(feedback, error.message);
        }
    }));
    archiveButton.addEventListener('click', async () => {
        if (!archiveActionUrl) return;
        archiveButton.disabled = true;
        try {
            const response = await fetch(archiveActionUrl,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(result.message||'Não foi possível arquivar.');
            window.location.reload();
        } catch(error) {
            document.getElementById('archiveCommitmentError').textContent=error.message;
            archiveButton.disabled=false;
        }
    });
    deleteButton.addEventListener('click', async () => {
        if (!deleteActionUrl || !window.confirm('Excluir compra? Pagamentos preservados; previsões anuladas.')) return;
        deleteButton.disabled = true;
        try {
            const response = await fetch(deleteActionUrl,{method:'DELETE',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível eliminar.');
            window.location.reload();
        } catch(error) {
            document.getElementById('archiveCommitmentError').textContent=error.message;
            deleteButton.disabled=false;
        }
    });

    const cancelModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('cancelCommitmentModal'));
    let cancelActionUrl = null;
    document.querySelectorAll('.js-cancel-commitment').forEach(button => button.addEventListener('click', () => {
        cancelActionUrl = button.dataset.url;
        document.getElementById('cancelCommitmentMessage').textContent = 'Pretendes cancelar "' + button.dataset.name + '"?';
        document.getElementById('cancelCommitmentError').textContent = '';
        cancelModal.show();
    }));
    document.getElementById('confirmCancelCommitment').addEventListener('click', async () => {
        if (!cancelActionUrl) return;
        const button = document.getElementById('confirmCancelCommitment');
        button.disabled = true;
        try {
            const response = await fetch(cancelActionUrl,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível cancelar.');
            window.location.reload();
        } catch(error) {
            document.getElementById('cancelCommitmentError').textContent = error.message;
            button.disabled = false;
        }
    });

    const paymentModalElement=document.getElementById('commitmentPaymentModal');
    const paymentModal=bootstrap.Modal.getOrCreateInstance(paymentModalElement);
    const paymentForm=document.getElementById('commitmentPaymentForm');
    document.querySelectorAll('.commitment-pay-next').forEach(button=>button.addEventListener('click',()=>{
        paymentForm.dataset.endpoint=button.dataset.payUrl;
        document.getElementById('paymentCommitmentName').textContent=button.dataset.debtName;
        document.getElementById('paymentInstallmentLabel').textContent='Prestação '+button.dataset.installmentLabel;
        document.getElementById('commitmentPaymentAmount').value=button.dataset.remaining.replace('.',',');
        document.getElementById('commitmentPaymentAmount').dataset.maxAmount=button.dataset.remaining;
        document.getElementById('commitmentPaymentError').textContent='';
        const detailsModalElement = button.closest('.commitment-details-modal');
        if (detailsModalElement) {
            detailsModalElement.addEventListener('hidden.bs.modal', () => paymentModal.show(), { once: true });
            bootstrap.Modal.getOrCreateInstance(detailsModalElement).hide();
        } else {
            paymentModal.show();
        }
    }));
    if (!window.CommitmentPayment || !window.CommitmentPayment.bind(paymentForm, token, feedback, showError)) {
        showError(feedback, 'Não foi possível carregar a validação de valores. Atualiza a página antes de pagar.');
    }

})();
</script>
@endpush

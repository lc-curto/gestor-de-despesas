@extends('layouts.app')

@section('title', 'Parcelamentos')

@section('content')
<div class="app-content commitments-page">
    <header class="page-heading commitments-heading mb-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div><h1 class="h2 fw-bold mb-1">Parcelamentos</h1><p class="text-secondary mb-0">Gestão de compras e prestações</p></div>
        <button type="button" class="btn btn-primary mt-3 mt-md-0" data-bs-toggle="modal" data-bs-target="#createCommitmentModal">Nova compra</button>
    </header>

    <div id="commitmentFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <section class="commitment-summary mb-4" aria-label="Resumo financeiro">
        <article class="commitment-metric commitment-metric-primary">
            <span>Total em aberto</span>
            <strong>{{ auth()->user()->currency }} {{ number_format($summary['open'], 2, ',', '.') }}</strong>
            <small>{{ $summary['active_count'] }} compras ativas</small>
        </article>
        <article class="commitment-metric">
            <span>Próxima prestação</span>
            @if ($summary['next_installment'])
                <strong>{{ auth()->user()->currency }} {{ number_format(max(0, (float) $summary['next_installment']->amount - (float) $summary['next_installment']->paid_amount), 2, ',', '.') }}</strong>
                <small>{{ $summary['next_installment']->divida?->name }} · {{ $summary['next_installment']->due_date->lt(today()) ? 'vencida' : $summary['next_installment']->due_date->format('d/m/Y') }}</small>
            @else
                <strong>—</strong><small>Sem prestações pendentes</small>
            @endif
        </article>
        <article class="commitment-metric">
            <span>Prestações em atraso</span>
            <strong class="{{ $summary['overdue'] > 0 ? 'text-danger' : '' }}">{{ auth()->user()->currency }} {{ number_format($summary['overdue'], 2, ',', '.') }}</strong>
            <small>{{ $summary['overdue_commitments'] }} compras com prestações vencidas</small>
        </article>
    </section>

    <section class="commitment-list-panel" aria-labelledby="commitmentsTitle">
        <nav class="commitment-tabs" aria-label="Estado das compras">
            @foreach (['active' => ['Ativos', $summary['active_count']], 'completed' => ['Concluídos', $summary['completed_count']], 'all' => ['Todos', $summary['total_count']]] as $key => [$label, $count])
                <a href="{{ route('financial.commitments.page', array_merge(request()->except('purchases'), ['status' => $key])) }}"
                   class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                   @if ($status === $key) aria-current="page" @endif>{{ $label }} ({{ $count }})</a>
            @endforeach
        </nav>

        <form class="commitment-search" method="GET" action="{{ route('financial.commitments.page') }}" role="search">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <label class="visually-hidden" for="commitmentSearch">Pesquisar compra ou comerciante</label>
            <span aria-hidden="true">⌕</span>
            <input id="commitmentSearch" type="search" name="q" value="{{ $search }}" placeholder="Pesquisar compra ou comerciante..." autocomplete="off">
            @if ($search !== '')<a href="{{ route('financial.commitments.page', ['status' => $status, 'per_page' => $perPage]) }}" class="commitment-clear-search" aria-label="Limpar pesquisa">×</a>@endif
        </form>

        <div class="commitment-list-heading">
            <h2 class="h5 fw-bold mb-0" id="commitmentsTitle">
                {{ $status === 'active' ? 'Compras ativas' : ($status === 'completed' ? 'Compras concluídas' : 'Todas as compras') }}
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
                    $next = $commitment->next_installment;
                    $nextRemaining = $next ? max(0, (float) $next->amount - (float) $next->paid_amount) : 0;
                @endphp
                <article class="commitment-card" data-commitment-card data-commitment-id="{{ $commitment->id }}">
                    <button class="commitment-card-toggle" type="button" aria-expanded="false" aria-controls="commitment-details-{{ $commitment->id }}">
                        <span class="commitment-card-heading">
                            <span class="commitment-card-name">{{ $commitment->name }}@if ($commitment->merchant)<small class="commitment-merchant">{{ $commitment->merchant }}</small>@endif</span>
                            <span class="badge {{ $isCompleted ? 'text-bg-success' : ($commitment->has_overdue_installment ? 'text-bg-danger' : 'text-bg-success') }}">{{ $isCompleted ? 'Concluído' : ($commitment->has_overdue_installment ? 'Em atraso' : 'Em dia') }}</span>
                        </span>
                        <span class="commitment-card-balances">
                            <span><small>Saldo em aberto</small><strong>{{ auth()->user()->currency }} {{ number_format($commitment->remaining_amount, 2, ',', '.') }}</strong></span>
                            <span><small>Prestações pagas</small><strong>{{ $partsPaid }}/{{ $partsTotal }}</strong></span>
                        </span>
                        <span class="progress commitment-progress" role="progressbar" aria-label="Valor pago" aria-valuenow="{{ $commitment->progress_percent }}" aria-valuemin="0" aria-valuemax="100">
                            <span class="progress-bar" style="width: {{ $commitment->progress_percent }}%"></span>
                        </span>
                        <span class="commitment-card-foot">
                            <small>{{ number_format($commitment->progress_percent, 1, ',', '.') }}% pago</small>
                            <small>{{ $next ? 'Próxima: '.$next->due_date->format('d/m/Y') : 'Concluído' }}</small>
                        </span>
                        <span class="commitment-card-details-label">Ver detalhes <span aria-hidden="true">⌄</span></span>
                    </button>

                    <div class="commitment-history-trigger">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#commitmentHistoryModal-{{ $commitment->id }}">
                            Ver histórico ({{ $history->total() }})
                        </button>
                    </div>

                    <div class="commitment-details" id="commitment-details-{{ $commitment->id }}" hidden>
                        <div class="commitment-detail-summary">
                            <div><small>Valor total</small><strong>{{ auth()->user()->currency }} {{ number_format((float) $commitment->total_amount, 2, ',', '.') }}</strong></div>
                            <div><small>Valor pago</small><strong>{{ auth()->user()->currency }} {{ number_format((float) $commitment->paid_amount_total, 2, ',', '.') }}</strong></div>
                            <div><small>Em aberto</small><strong>{{ auth()->user()->currency }} {{ number_format($commitment->remaining_amount, 2, ',', '.') }}</strong></div>
                            <div><small>Prestações</small><strong>{{ $partsPaid }} de {{ $partsTotal }}</strong></div>
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
                                        data-remaining="{{ number_format($nextRemaining, 2, '.', '') }}">Pagar próxima prestação</button>
                                @else
                                    <p class="small text-secondary mb-0">Para pagar, adiciona primeiro uma conta bancária ou de dinheiro ativa em <a href="{{ route('financial.accounts.page') }}">Contas</a>.</p>
                                @endif
                            </div>
                        @endif



                        <div class="commitment-card-actions">
                            <button type="button" class="btn btn-outline-secondary btn-sm js-edit-commitment"
                                data-url="{{ route('financial.commitments.update', $commitment->id) }}"
                                data-name="{{ $commitment->name }}"
                                data-total="{{ number_format((float) $commitment->total_amount, 2, '.', '') }}"
                                data-merchant="{{ $commitment->merchant }}"
                                data-due="{{ $next?->due_date?->format('Y-m-d') ?? $commitment->due_date?->format('Y-m-d') }}">Editar compra</button>
                            <button type="button" class="btn btn-outline-danger btn-sm js-cancel-commitment"
                                data-url="{{ route('financial.commitments.cancel', $commitment->id) }}"
                                data-name="{{ $commitment->name }}">Cancelar compra</button>
                        </div>
                    </div>
                </article>

                <div class="modal fade commitment-history-modal" id="commitmentHistoryModal-{{ $commitment->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                        <div class="modal-content rounded-4 border-0">
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
                                    $overdue = !$paid && $installment->due_date->lt(today());
                                    $label = $paid ? 'Paga' : ($overdue ? 'Atrasada' : ($installment->id === $next?->id ? 'Próxima' : 'Pendente'));
                                @endphp
                                <div class="commitment-installment">
                                    <div><strong>{{ $installment->installment_number }}/{{ $partsTotal }}</strong><small>{{ $installment->due_date->format('d/m/Y') }}</small></div>
                                    <div class="commitment-installment-value">
                                        <strong>{{ auth()->user()->currency }} {{ number_format($remaining > 0 ? $remaining : (float) $installment->amount, 2, ',', '.') }}</strong>
                                        <span class="badge {{ $paid ? 'text-bg-success' : ($overdue ? 'text-bg-danger' : ($label === 'Próxima' ? 'text-bg-warning' : 'text-bg-secondary')) }}">{{ $label }}</span>
                                    </div>
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
                            <div class="modal-footer border-0">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="commitment-empty-state">
                    <strong>Nenhuma compra encontrada.</strong>
                    <span class="text-secondary small">Altera a pesquisa ou seleciona outro separador.</span>
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
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog">
        <div class="modal-content rounded-4 border-0">
            <form id="createCommitmentForm">
                <div class="modal-header border-0"><h2 class="modal-title h5 fw-bold">Nova compra parcelada</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
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
                <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Criar compra</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="commitmentPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable commitment-payment-dialog">
        <div class="modal-content rounded-4 border-0">
            <form id="commitmentPaymentForm" class="js-api-form" method="POST" data-endpoint="">
                @csrf
                <div class="modal-header border-0">
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
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Confirmar pagamento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCommitmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0"><form id="editCommitmentForm">
        <div class="modal-header border-0"><h2 class="modal-title h5 fw-bold">Editar compra</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <label class="form-label" for="editCommitmentName">Nome da compra</label><input class="form-control mb-3" id="editCommitmentName" name="name" required maxlength="160">
            <label class="form-label" for="editCommitmentMerchant">Comerciante (opcional)</label><input class="form-control mb-3" id="editCommitmentMerchant" name="merchant" maxlength="160">
            <label class="form-label" for="editCommitmentTotal">Valor total (€)</label><input class="form-control mb-3" id="editCommitmentTotal" name="total_amount" type="number" min="0.01" step="0.01" required>
            <label class="form-label" for="editCommitmentDue">Próximo vencimento</label><input class="form-control" id="editCommitmentDue" name="due_date" type="date" required>
            <div id="editCommitmentError" class="small text-danger mt-2"></div>
        </div>
        <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>
@endsection

@push('styles')
<style>
.commitments-page{max-width:1500px;margin-inline:auto;container-type:inline-size}
.commitment-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}
.commitment-metric{min-width:0;padding:1.1rem 1.25rem;background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:1rem;display:flex;flex-direction:column;justify-content:center;gap:.35rem}
.commitment-metric span,.commitment-metric small{font-size:.8rem;color:var(--bs-secondary-color)}
.commitment-metric strong{font-size:1.35rem;line-height:1.3;font-weight:750;overflow-wrap:normal;word-break:normal}
.commitment-metric-primary{grid-column:auto}
.commitment-metric small{line-height:1.4}
@container (max-width: 620px){
 .commitment-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}
 .commitment-metric{padding:.8rem .9rem;border-radius:.85rem}
 .commitment-metric-primary{grid-column:1/-1}
 .commitment-metric strong{font-size:1.15rem}
 .commitment-metric-primary strong{font-size:1.55rem}
 .commitment-metric small{font-size:.76rem}
}
@container (max-width: 360px){
 .commitment-summary{grid-template-columns:minmax(0,1fr)}
 .commitment-metric-primary{grid-column:auto}
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
.commitment-card-toggle{display:flex;width:100%;flex-direction:column;gap:.7rem;padding:1rem 1.1rem;text-align:left;border:0;background:transparent;color:inherit}
.commitment-card-toggle:hover{background:var(--bs-tertiary-bg)}
.commitment-card-heading,.commitment-card-balances,.commitment-card-foot{display:flex;justify-content:space-between;align-items:center;gap:.75rem;width:100%}
.commitment-card-name{font-weight:700;font-size:1rem;overflow-wrap:anywhere;display:flex;flex-direction:column;gap:.15rem}
.commitment-merchant{font-size:.76rem;font-weight:400;color:var(--bs-secondary-color)}
.commitment-card-balances>span{display:flex;flex-direction:column;gap:.2rem}
.commitment-card-balances>span:last-child{text-align:right}
.commitment-card-balances small,.commitment-detail-summary small{font-size:.75rem;color:var(--bs-secondary-color)}
.commitment-card-balances strong{font-size:1rem}
.commitment-progress{height:7px;width:100%;background:var(--bs-tertiary-bg);border-radius:99px}
.commitment-progress .progress-bar{background:var(--bs-primary);border-radius:99px}
.commitment-card-foot{font-size:.75rem;color:var(--bs-secondary-color)}
.commitment-card-details-label{align-self:flex-end;font-size:.85rem;color:var(--bs-primary);font-weight:600}
.commitment-card-details-label span{display:inline-block;transition:transform .15s}
.commitment-card-toggle[aria-expanded=true] .commitment-card-details-label span{transform:rotate(180deg)}
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
 .commitment-card-toggle{padding:.9rem}
 .commitment-details{padding:.9rem}
 .commitment-detail-summary{gap:.75rem}
 .commitment-payment-dialog{margin:.5rem;align-items:flex-end;min-height:calc(100% - 1rem)}
 .commitment-payment-dialog .modal-content{border-radius:1.1rem 1.1rem .6rem .6rem}
 .commitment-payment-dialog .modal-body{padding-top:.5rem}
 .commitment-history-pagination{align-items:flex-start;flex-direction:column}
 .commitment-pagination-meta{align-items:flex-start;flex-direction:column}
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

    cards.forEach(card => {
        const toggle = card.querySelector('.commitment-card-toggle');
        const details = card.querySelector('.commitment-details');
        toggle.addEventListener('click', () => {
            const opening = toggle.getAttribute('aria-expanded') !== 'true';
            if (window.matchMedia('(max-width: 767.98px)').matches && opening) {
                cards.forEach(other => {
                    if (other !== card) {
                        other.querySelector('.commitment-card-toggle').setAttribute('aria-expanded','false');
                        other.querySelector('.commitment-details').hidden = true;
                    }
                });
            }
            toggle.setAttribute('aria-expanded', String(opening));
            details.hidden = !opening;
        });
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
        form.elements.total_amount.value = button.dataset.total;
        form.elements.due_date.value = button.dataset.due || '';
        document.getElementById('editCommitmentError').textContent = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editCommitmentModal')).show();
    }));
    document.getElementById('editCommitmentForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget, button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const response = await fetch(form.dataset.url,{method:'PUT',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({name:form.elements.name.value,merchant:form.elements.merchant.value,total_amount:form.elements.total_amount.value,due_date:form.elements.due_date.value})});
            const result = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar.');
            window.location.reload();
        } catch(error) {
            document.getElementById('editCommitmentError').textContent=error.message;
            button.disabled=false;
        }
    });

    document.querySelectorAll('.js-cancel-commitment').forEach(button=>button.addEventListener('click',async()=>{
        if(!window.confirm('Cancelar "'+button.dataset.name+'"? Os pagamentos já realizados serão preservados.')) return;
        button.disabled=true;
        try {
            const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});
            const result=await response.json().catch(()=>({}));
            if(!response.ok) throw new Error(result.message||'Não foi possível cancelar.');
            window.location.reload();
        } catch(error) { showError(feedback,error.message);button.disabled=false; }
    }));

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
        paymentModal.show();
    }));
    if (!window.CommitmentPayment || !window.CommitmentPayment.bind(paymentForm, token, feedback, showError)) {
        showError(feedback, 'Não foi possível carregar a validação de valores. Atualiza a página antes de pagar.');
    }

})();
</script>
@endpush

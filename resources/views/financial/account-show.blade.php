@extends('layouts.app')

@section('title', $account->name)

@section('content')
@php
    $typeLabels = ['bank' => 'Banco', 'cash' => 'Dinheiro', 'credit_card' => 'Cartão'];
    $statusLabels = ['settled' => 'Liquidado', 'pending' => 'Pendente', 'void' => 'Anulado'];
@endphp

<div class="account-history-page">
    <header class="page-heading account-history-heading">
        <a class="small text-decoration-none" href="{{ route('financial.accounts.page') }}">
            <i data-lucide="arrow-left" class="me-1" aria-hidden="true"></i> Voltar às contas
        </a>
        <div class="mt-3">
            <div class="mb-3">
                <p class="eyebrow mb-1">Histórico da conta</p>
                <h1 class="h2 fw-bold mb-1">{{ $account->name }}</h1>
                <p class="text-secondary mb-0">{{ $typeLabels[$account->type] ?? $account->type }} · {{ $account->currency }}</p>
            </div>
            <div class="account-history-balance rounded-4 bg-body-tertiary p-3">
                @if ($account->type === 'credit_card')
                    <span>Crédito disponível</span>
                    <strong>{{ $account->credit_limit !== null && $account->current_balance !== null ? number_format(max(0, (float) $account->credit_limit - abs((float) $account->current_balance)), 2, ',', '.') . ' ' . $account->currency : '—' }}</strong>
                    <small class="text-secondary">Dívida utilizada: {{ number_format(abs((float) ($account->current_balance ?? 0)), 2, ',', '.') }} {{ $account->currency }}</small>
                @else
                    <span>Saldo atual</span>
                    <strong class="{{ (float) ($account->current_balance ?? 0) < 0 ? 'text-danger' : 'text-body' }}">{{ $account->current_balance !== null ? number_format((float) $account->current_balance, 2, ',', '.') . ' ' . $account->currency : '—' }}</strong>
                @endif
                <small class="d-block text-secondary mt-1">{{ $movements->total() }} {{ $movements->total() === 1 ? 'movimento registado' : 'movimentos registados' }}</small>
            </div>
        </div>
    </header>

    @if ($account->type === 'credit_card' && $openingDebtRemaining > 0)
    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3 p-sm-4">
            <h2 class="h5 fw-bold mb-1">Dívida anterior à aplicação</h2>
            <p class="small text-secondary mb-3">Por pagar: <strong>{{ number_format($openingDebtRemaining, 2, ',', '.') }} {{ $account->currency }}</strong></p>
            <form id="openingDebtPaymentForm" data-endpoint="{{ route('financial.cards.pay-opening-debt', $account->id) }}">
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label" for="openingDebtPaymentAccount">Pagar a partir de</label><select id="openingDebtPaymentAccount" name="account_id" class="form-select" required><option value="">Selecionar conta</option>@foreach ($paymentAccounts as $paymentAccount)<option value="{{ $paymentAccount->id }}">{{ $paymentAccount->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label" for="openingDebtPaymentAmount">Valor</label><input id="openingDebtPaymentAmount" name="amount" type="text" inputmode="decimal" min="0.01" max="{{ number_format($openingDebtRemaining, 2, '.', '') }}" data-money="positive" class="form-control" required aria-describedby="openingDebtPaymentAmountHelp"><div class="form-text" id="openingDebtPaymentAmountHelp">Ex.: 90,50 ou 1.000,00</div></div>
                    <div class="col-md-3"><label class="form-label" for="openingDebtPaymentDate">Data</label><input id="openingDebtPaymentDate" name="payment_date" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit" title="Registar pagamento"><i data-lucide="check" aria-hidden="true"></i></button></div>
                </div>
                <div class="small mt-2" id="openingDebtPaymentFeedback" role="status"></div>
            </form>
        </div>
    </section>
    @endif

    <section class="card border-0 shadow-sm rounded-4 history-card account-history-card" aria-labelledby="accountMovementsTitle">
        <div class="card-body p-0 history-card-body">
            <div class="px-3 px-sm-4 py-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <h2 id="accountMovementsTitle" class="h5 fw-bold mb-0">Movimentos</h2>
                    <span class="small text-secondary">{{ $movements->total() }} {{ $movements->total() === 1 ? 'registo' : 'registos' }}</span>
                </div>
            </div>

            <div class="history-list px-3 px-sm-4">
                @forelse ($movements as $movement)
                    @php
                        $isOutgoing = $movement->type === 'expense'
                            || ($movement->type === 'transfer' && $movement->source_account_id === $account->id)
                            || ($movement->type === 'adjustment' && $movement->adjustment_direction === 'decrease');
                        $isTransfer = $movement->type === 'transfer';
                        $isIncome = $movement->type === 'income' || ($movement->type === 'adjustment' && !$isOutgoing);
                        $isCurrentAccountSource = $isTransfer && $movement->source_account_id === $account->id;
                        $category = $isTransfer ? 'Transferência' : ($movement->categoria?->name ?? ucfirst($movement->type));
                        $sign = $isOutgoing ? '−' : '+';
                        $editRoute = $movement->legacy_type === 'despesa'
                            ? route('despesas.edit', $movement->legacy_id) . '?return_to=' . $account->id
                            : ($movement->legacy_type === 'receita' ? route('receitas.edit', $movement->legacy_id) . '?return_to=' . $account->id : null);
                        $deleteRoute = $movement->legacy_type === 'despesa'
                            ? route('despesas.destroy', $movement->legacy_id) . '?return_to=' . $account->id
                            : ($movement->legacy_type === 'receita'
                                ? route('receitas.destroy', $movement->legacy_id) . '?return_to=' . $account->id
                                : route('financial.accounts.movement.destroy', $movement->id));
                    @endphp
                    <article class="expense-item account-movement-item d-flex justify-content-between align-items-center gap-2 py-3 border-bottom">
                        <div class="d-flex align-items-center gap-3 min-w-0">
                            <div class="expense-icon account-movement-icon flex-shrink-0" aria-hidden="true">
                                <i data-lucide="{{ $isTransfer ? 'arrow-left-right' : ($isOutgoing ? 'arrow-down-left' : 'arrow-up-right') }}"></i>
                            </div>
                            <div class="min-w-0 flex-grow-1">
                                <h3 class="account-movement-description h6 fw-semibold mb-1">{{ $movement->description }}</h3>
                                <p class="small text-secondary mb-1">{{ $category }} · {{ $movement->movement_date->format('d/m/Y') }}</p>
                                @if ($movement->status === 'pending')
                                    <span class="badge rounded-pill text-bg-warning">Pendente</span>
                                @elseif ($movement->status === 'void')
                                    <span class="badge rounded-pill text-bg-secondary">Anulado</span>
                                @endif
                                @if ($isTransfer)
                                    <p class="small text-secondary mb-0 text-truncate movement-transfer-counterpart">
                                        {{ $isCurrentAccountSource ? 'Para' : 'De' }}: {{ $isCurrentAccountSource ? ($movement->contaDestino?->name ?? 'Conta encerrada') : ($movement->contaOrigem?->name ?? 'Conta encerrada') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        <div class="expense-actions account-movement-actions d-flex align-items-center justify-content-end gap-2 flex-shrink-0">
                            <strong class="{{ $isOutgoing ? 'text-danger' : 'text-success' }} text-nowrap movement-value">{{ $sign }}{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->currency ?? $account->currency }}</strong>
                            <div class="d-inline-flex align-items-center gap-1" role="group" aria-label="Ações de {{ $movement->description }}">
                                @if ($editRoute)
                                    <a class="btn btn-sm btn-light border account-action-button" href="{{ $editRoute }}" aria-label="Editar {{ $movement->description }}" title="Editar"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                @endif
                                <button class="btn btn-sm btn-light border text-danger account-action-button js-account-delete" type="button" data-delete-url="{{ $deleteRoute }}" aria-label="Eliminar movimento" title="Eliminar"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="empty-state text-center py-5">
                        <div class="empty-icon mb-3" aria-hidden="true">◎</div>
                        <h3 class="h6">Ainda não existem movimentos</h3>
                        
                    </div>
                @endforelse
            </div>

            @if ($movements->hasPages())
                <div class="p-3 p-sm-4 border-top">
                    {{ $movements->links() }}
                </div>
            @endif
        </div>
    </section>
</div>

<div class="modal fade" id="accountDeleteModal" tabindex="-1" aria-labelledby="accountDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="accountDeleteForm" method="POST">
                @csrf
                @method('DELETE')
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="accountDeleteTitle">Eliminar movimento?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1" id="accountDeleteDescription">Este movimento será eliminado e os saldos das suas contas serão atualizados.</p>
                    <small class="text-secondary" id="accountDeleteHint">Esta ação não pode ser desfeita.</small>
                </div>
                <div class="modal-footer border-0 d-flex flex-row gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger flex-fill" id="accountDeleteSubmit">Eliminar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const accountDeleteModalElement = document.getElementById('accountDeleteModal');
if (accountDeleteModalElement) {
    const accountDeleteModal = bootstrap.Modal.getOrCreateInstance(accountDeleteModalElement);
    const accountDeleteForm = document.getElementById('accountDeleteForm');
    const accountDeleteSubmit = document.getElementById('accountDeleteSubmit');

    document.querySelectorAll('.js-account-delete').forEach((button) => {
        button.addEventListener('click', () => {
            accountDeleteForm.action = button.dataset.deleteUrl;
            accountDeleteSubmit.disabled = false;
            accountDeleteSubmit.textContent = 'Eliminar';
            document.getElementById('accountDeleteDescription').textContent = 'Este movimento será eliminado e os saldos das suas contas serão atualizados.';
            document.getElementById('accountDeleteHint').hidden = false;
            accountDeleteModal.show();
        });
    });

    accountDeleteForm.addEventListener('submit', () => {
        accountDeleteSubmit.disabled = true;
        accountDeleteSubmit.textContent = 'A eliminar…';
    });
}

document.getElementById('openingDebtPaymentForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget, button = form.querySelector('[type="submit"]'), feedback = document.getElementById('openingDebtPaymentFeedback');
    const field = form.elements.amount;
    let text = String(field.value || '').trim().replace(/[\u00a0\s]/g, '');
    let canonical = '';
    try {
        if (!/^\d+(?:,\d{1,2}|\.\d{1,2})?$/.test(text) && !/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/.test(text)) throw new Error('Usa o formato 90,50 ou 1.000,00.');
        if (text.includes(',')) { const parts = text.split(','); canonical = parts[0].replace(/\./g, '') + '.' + parts[1].padEnd(2, '0'); }
        else if (text.includes('.')) { const parts = text.split('.'); canonical = parts[0] + '.' + parts[1].padEnd(2, '0'); }
        else canonical = text + '.00';
        if (!(Number(canonical) > 0) || Number(canonical) > {{ number_format($openingDebtRemaining, 2, '.', '') }}) throw new Error('O valor deve ser positivo e não pode exceder a dívida restante.');
        field.value = canonical;
    } catch (error) { feedback.className='small mt-2 text-danger'; feedback.textContent=error.message; return; }
    button.disabled = true;
    try {
        const response = await fetch(form.dataset.endpoint, {method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))});
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível registar o pagamento.');
        window.location.reload();
    } catch (error) { feedback.className='small mt-2 text-danger'; feedback.textContent=error.message; button.disabled=false; }
});
</script>
@endpush

@push('styles')
<style>
.account-history-page { width:100%; max-width:none; }
.account-history-heading { margin-bottom:1.25rem; }
.account-history-balance { text-align:left; }
.account-history-balance span { display:block; color:var(--bs-secondary-color); font-size:.8rem; }
.account-history-balance strong { display:block; font-size:1.65rem; letter-spacing:-.04em; line-height:1.2; }
.account-history-card { overflow:hidden; }
.account-movement-item:last-child { border-bottom:0 !important; }
.account-movement-item .expense-icon { width:2.5rem; height:2.5rem; display:flex; align-items:center; justify-content:center; border-radius:.8rem; background:rgba(220,53,69,.1); color:var(--bs-danger); }
.account-movement-item .movement-value { font-size:.9rem; }
.account-movement-description { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.account-movement-actions { max-width:58%; }
.account-action-button { width:2.25rem; height:2.25rem; display:inline-flex; align-items:center; justify-content:center; border-radius:.65rem; }
.movement-transfer-counterpart { max-width:100%; font-size:.78rem; }
@media (max-width:640px) {
    .account-history-heading { margin-bottom:1rem; }
    .account-history-balance strong { font-size:1.55rem; }
    .account-movement-item { gap:.5rem !important; }
    .account-movement-item > .d-flex:first-child { flex:1 1 0; min-width:0; gap:.6rem !important; }
    .account-movement-actions { flex-direction:column; align-items:flex-end !important; gap:.35rem !important; }
    .account-movement-actions > div { justify-content:flex-end; }
    .account-movement-description { font-size:.9rem; }
    .account-movement-item .movement-value { font-size:.82rem; }
}
</style>
@endpush

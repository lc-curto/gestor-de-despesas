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
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mt-3">
            <div>
                <p class="eyebrow mb-1">Histórico da conta</p>
                <h1 class="h2 fw-bold mb-1">{{ $account->name }}</h1>
                <p class="text-secondary mb-0">{{ $typeLabels[$account->type] ?? $account->type }} · {{ $account->currency }}</p>
            </div>
            <div class="account-history-balance">
                @if ($account->type === 'credit_card')
                    <span>Crédito disponível</span>
                    <strong>{{ $account->credit_limit !== null && $account->current_balance !== null ? number_format(max(0, (float) $account->credit_limit - abs((float) $account->current_balance)), 2, ',', '.') . ' ' . $account->currency : '—' }}</strong>
                    <small class="text-secondary">Dívida utilizada: {{ number_format(abs((float) ($account->current_balance ?? 0)), 2, ',', '.') }} {{ $account->currency }}</small>
                @else
                    <span>Saldo atual</span>
                    <strong>{{ $account->current_balance !== null ? number_format((float) $account->current_balance, 2, ',', '.') . ' ' . $account->currency : '—' }}</strong>
                @endif
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
            <div class="p-3 p-sm-4 pb-3 border-bottom">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h2 id="accountMovementsTitle" class="h5 fw-bold mb-1">Movimentos</h2>
                        
                    </div>
                    <span class="small text-secondary">{{ $movements->total() }} {{ $movements->total() === 1 ? 'movimento' : 'movimentos' }}</span>
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
                    <article class="expense-item account-movement-item d-flex justify-content-between align-items-center gap-3 py-3 border-bottom">
                        <div class="d-flex align-items-center gap-3 min-w-0">
                            <div class="expense-icon {{ $isOutgoing ? '' : 'income-icon' }} flex-shrink-0" aria-hidden="true">
                                <i data-lucide="{{ $isTransfer ? 'arrow-left-right' : ($isOutgoing ? 'arrow-down-left' : 'arrow-up-right') }}"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="h6 fw-semibold mb-1 text-break">{{ $movement->description }}</h3>
                                <p class="small text-secondary mb-1">{{ $category }} · {{ $movement->movement_date->format('d/m/Y') }}</p>
                                @if ($isTransfer)
                                    <div class="movement-transfer-route {{ $isCurrentAccountSource ? 'is-outgoing' : 'is-incoming' }}">
                                        <span class="movement-transfer-direction">{{ $isCurrentAccountSource ? 'Saída' : 'Entrada' }}</span>
                                        <span>{{ $movement->contaOrigem?->name ?? 'Conta encerrada' }}</span>
                                        <i data-lucide="arrow-right" aria-hidden="true"></i>
                                        <span>{{ $movement->contaDestino?->name ?? 'Conta encerrada' }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="account-movement-actions">
                            <div class="account-movement-meta">
                                <strong class="{{ $isOutgoing ? 'text-danger' : 'text-success' }} text-nowrap movement-value">{{ $sign }}{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->currency ?? $account->currency }}</strong>
                                <span class="small text-secondary">{{ $statusLabels[$movement->status] ?? $movement->status }}</span>
                            </div>
                            <div class="account-movement-buttons">
                                @if ($editRoute)
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ $editRoute }}" aria-label="Editar {{ $movement->description }}" title="Editar"><i data-lucide="pencil" aria-hidden="true"></i></a>
                                @endif
                                <form method="POST" action="{{ $deleteRoute }}" onsubmit="return confirm('Eliminar este movimento e atualizar todos os movimentos dependentes e saldos associados?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" type="submit" aria-label="Eliminar movimento" title="Eliminar movimento"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                </form>
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
@endsection

@push('scripts')
<script>
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
.account-history-balance { text-align:right; }
.account-history-balance span { display:block; color:var(--bs-secondary-color); font-size:.8rem; }
.account-history-balance strong { display:block; font-size:1.5rem; letter-spacing:-.03em; }
.account-history-card { overflow:hidden; }
.account-movement-item:last-child { border-bottom:0 !important; }
.account-movement-item .expense-icon { background:rgba(220,53,69,.1); color:var(--bs-danger); }
.account-movement-item .income-icon { background:rgba(25,135,84,.1); color:var(--bs-success); }
.account-movement-item .movement-value { font-size:1rem; }
.movement-transfer-route { display:flex; align-items:center; flex-wrap:wrap; gap:.35rem; color:var(--bs-secondary-color); font-size:.75rem; }
.movement-transfer-route svg { width:13px; height:13px; }
.movement-transfer-direction { padding:.12rem .42rem; border-radius:999px; font-size:.68rem; font-weight:700; }
.movement-transfer-route.is-outgoing .movement-transfer-direction { background:rgba(220,53,69,.1); color:var(--bs-danger); }
.movement-transfer-route.is-incoming .movement-transfer-direction { background:rgba(25,135,84,.1); color:var(--bs-success); }
@media (max-width:640px) {
    .account-history-balance { text-align:left; }

    .account-movement-item {
        display:grid !important;
        grid-template-columns:minmax(0,1fr) minmax(6.5rem,auto);
        align-items:center !important;
        gap:.55rem !important;
        flex-wrap:nowrap !important;
    }

    .account-movement-item > :first-child {
        display:flex;
        min-width:0 !important;
        flex:1 1 auto;
        gap:.55rem !important;
    }

    .account-movement-item > :first-child > .min-w-0 {
        flex:1 1 auto;
        min-width:0;
    }

    .account-movement-item h3 {
        overflow-wrap:anywhere;
    }

    .account-movement-actions {
        display:flex;
        min-width:0;
        flex-direction:column;
        align-items:flex-end;
        justify-content:center;
        gap:.4rem;
    }

    .account-movement-meta {
        display:flex;
        max-width:100%;
        flex-direction:column;
        align-items:flex-end;
        gap:.1rem;
        text-align:right;
    }

    .account-movement-meta .movement-value {
        font-size:.82rem;
        white-space:nowrap;
    }

    .account-movement-buttons {
        display:flex;
        flex-direction:row;
        flex-wrap:nowrap;
        align-items:center;
        justify-content:flex-end;
        gap:.3rem;
    }

    .account-movement-buttons form {
        display:flex;
        margin:0;
    }

    .account-movement-buttons .btn {
        display:inline-flex;
        width:2.25rem;
        min-width:2.25rem;
        max-width:2.25rem;
        height:2.25rem;
        min-height:2.25rem;
        max-height:2.25rem;
        flex:0 0 2.25rem;
        align-items:center;
        justify-content:center;
        padding:.25rem;
    }
}</style>
@endpush

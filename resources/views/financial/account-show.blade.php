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
                <span>Saldo atual</span>
                <strong>{{ $account->current_balance !== null ? number_format((float) $account->current_balance, 2, ',', '.') . ' ' . $account->currency : '—' }}</strong>
            </div>
        </div>
    </header>

    <section class="card border-0 shadow-sm rounded-4 history-card account-history-card" aria-labelledby="accountMovementsTitle">
        <div class="card-body p-0 history-card-body">
            <div class="p-3 p-sm-4 pb-3 border-bottom">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h2 id="accountMovementsTitle" class="h5 fw-bold mb-1">Movimentos</h2>
                        <p class="small text-secondary mb-0">Receitas, despesas e transferências desta conta.</p>
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
                        <div class="d-flex align-items-center justify-content-end gap-3">
                            <div class="text-end">
                                <strong class="{{ $isOutgoing ? 'text-danger' : 'text-success' }} text-nowrap movement-value">{{ $sign }}{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->currency ?? $account->currency }}</strong>
                                <span class="d-block small text-secondary">{{ $statusLabels[$movement->status] ?? $movement->status }}</span>
                            </div>
                            @if ($editRoute)
                                <a class="btn btn-outline-secondary btn-sm" href="{{ $editRoute }}" aria-label="Editar {{ $movement->description }}" title="Editar"><i data-lucide="pencil" aria-hidden="true"></i></a>
                            @endif
                            <form method="POST" action="{{ $deleteRoute }}" onsubmit="return confirm('Eliminar este movimento? O saldo será recalculado.')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm" type="submit" aria-label="Eliminar movimento" title="Eliminar movimento"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="empty-state text-center py-5">
                        <div class="empty-icon mb-3" aria-hidden="true">◎</div>
                        <h3 class="h6">Ainda não existem movimentos</h3>
                        <p class="small text-secondary mb-0">Os lançamentos associados a esta conta aparecerão aqui.</p>
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
    .account-movement-item { align-items:flex-start !important; flex-wrap:wrap; }
    .account-movement-item > :last-child { width:100%; justify-content:flex-end; }
}
</style>
@endpush

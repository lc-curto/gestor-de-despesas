@extends('layouts.app')

@section('title', 'Contas')

@section('content')
@php
    $typeLabels = ['bank' => 'Banco', 'cash' => 'Dinheiro', 'credit_card' => 'Cartão'];
    $statusLabels = ['active' => 'Ativa', 'setup_pending' => 'Por configurar', 'closed' => 'Encerrada'];
@endphp

<div class="accounts-page">
    <header class="accounts-toolbar">
        <div>
            <h1 class="h2 fw-bold mb-0">Contas</h1>
            <span class="small text-secondary">{{ $accounts->count() }} {{ $accounts->count() === 1 ? 'conta' : 'contas' }}</span>
        </div>
        <div class="d-flex gap-2">
            @if ($transferTargets->count() >= 2)
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#accountTransferModal">
                    <i data-lucide="arrow-left-right" aria-hidden="true"></i><span class="ms-1">Transferir</span>
                </button>
            @endif
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newAccountModal">
                <i data-lucide="plus" aria-hidden="true"></i><span class="ms-1">Nova conta</span>
            </button>
        </div>
    </header>

    <div id="accountFeedback" class="d-none" role="status" aria-live="polite"></div>

    @if ($accounts->isEmpty())
        <section class="accounts-empty card border-0 shadow-sm rounded-4">
            <div class="card-body text-center py-5">
                <span class="accounts-empty-icon"><i data-lucide="wallet-cards" aria-hidden="true"></i></span>
                <h2 class="h5 fw-bold mt-3">Ainda não tens contas</h2>
                <button class="btn btn-primary mt-2" type="button" data-bs-toggle="modal" data-bs-target="#newAccountModal">Adicionar conta</button>
            </div>
        </section>
    @else
        <section class="accounts-list" aria-label="As minhas contas">
            @foreach ($accounts as $account)
                @php
                    $icon = $account->type === 'cash' ? 'wallet' : ($account->type === 'credit_card' ? 'credit-card' : 'landmark');
                @endphp
                <article class="account-card">
                    <a class="account-card-link" href="{{ route('financial.accounts.show', $account->id) }}" aria-label="Ver histórico de {{ $account->name }}">
                    <span class="account-icon"><i data-lucide="{{ $icon }}" aria-hidden="true"></i></span>
                    <div class="account-main">
                        <div class="account-title-row">
                            <h2 class="account-name">{{ $account->name }}</h2>
                            <span class="account-status {{ $account->status === 'active' ? 'is-active' : ($account->status === 'closed' ? 'is-closed' : 'is-pending') }}">
                                {{ $statusLabels[$account->status] ?? $account->status }}
                            </span>
                        </div>
                        <div class="account-subtitle">{{ $typeLabels[$account->type] ?? $account->type }} <span aria-hidden="true">·</span> {{ $account->currency }}</div>
                    </div>
                    <div class="account-balance">
                        @if ($account->current_balance !== null)
                            <strong>{{ number_format((float) $account->current_balance, 2, ',', '.') }} <small>{{ $account->currency }}</small></strong>
                            <span>{{ $account->type === 'credit_card' ? 'Utilização atual' : 'Saldo atual' }}</span>
                        @elseif ($account->type === 'credit_card')
                            <strong>—</strong><span>Cartão</span>
                        @else
                            <strong>—</strong><span>Saldo por configurar</span>
                        @endif
                    </div>
                    </a>
                    @if ($account->is_operational && $account->status !== 'closed')
                        <div class="dropdown account-menu">
                            <button class="account-menu-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações para {{ $account->name }}">
                                <span>Ações</span><i data-lucide="chevron-down" aria-hidden="true"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><button class="dropdown-item js-edit-account" type="button" data-bs-toggle="modal" data-bs-target="#renameAccountModal"
                                    data-endpoint="{{ route('financial.accounts.update', $account->id) }}"
                                    data-settings-endpoint="{{ route('financial.accounts.settings', $account->id) }}"
                                    data-opening-endpoint="{{ route('financial.accounts.opening-balance', $account->id) }}"
                                    data-name="{{ $account->name }}" data-allowed="{{ $account->allow_negative_balance ? '1' : '0' }}"
                                    data-opening="{{ $account->opening_balance !== null ? '1' : '0' }}"
                                    data-opening-value="{{ $account->opening_balance }}" data-opening-date="{{ $account->opening_balance_date?->format('d/m/Y') }}" data-opening-iso="{{ $account->opening_balance_date?->toDateString() }}" data-movements="{{ $account->movimentos_origem_count + $account->movimentos_destino_count }}"
                                    data-type="{{ $account->type }}" data-currency="{{ $account->currency }}">Editar conta</button></li>
                                @if (in_array($account->type, ['bank', 'cash'], true) && $account->status === 'active' && $account->opening_balance !== null)
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item text-danger js-close-account" type="button" data-bs-toggle="modal" data-bs-target="#closeAccountModal" data-endpoint="{{ route('financial.accounts.close', $account->id) }}" data-id="{{ $account->id }}" data-name="{{ $account->name }}">Encerrar conta</button></li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </article>
            @endforeach
        </section>
    @endif

</div>

{{-- Nova conta --}}
<div class="modal fade" id="newAccountModal" tabindex="-1" aria-labelledby="newAccountTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="accountCreateForm" data-endpoint="{{ route('financial.accounts.store') }}">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="newAccountTitle">Nova conta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="accountName" class="form-label">Nome</label>
                        <input id="accountName" name="name" class="form-control" maxlength="120" required placeholder="Ex.: Conta principal">
                    </div>
                    <div class="mb-3">
                        <label for="accountType" class="form-label">Tipo</label>
                        <select id="accountType" name="type" class="form-select" required>
                            <option value="bank">Banco</option>
                            <option value="cash">Dinheiro</option>
                            <option value="credit_card">Cartão de crédito</option>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-7">
                            <label for="openingBalance" class="form-label">Saldo inicial</label>
                            <input id="openingBalance" name="opening_balance" type="number" class="form-control" min="-9999999999.99" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="Opcional">
                        </div>
                        <div class="col-5">
                            <label for="openingBalanceDate" class="form-label">Data do saldo</label>
                            <input id="openingBalanceDate" name="opening_balance_date" type="date" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input id="allowNegativeBalance" name="allow_negative_balance" type="checkbox" value="1" class="form-check-input">
                        <label for="allowNegativeBalance" class="form-check-label">Permitir saldo negativo</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Editar conta --}}
<div class="modal fade" id="renameAccountModal" tabindex="-1" aria-labelledby="renameAccountTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content border-0 shadow rounded-4 account-edit-modal">
            <form id="accountNameForm" data-endpoint="">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="renameAccountTitle">Editar conta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-4">
                    <label for="renameAccountName" class="form-label">Nome</label>
                    <input id="renameAccountName" name="name" class="form-control" maxlength="120" required>
                    <p class="small text-secondary mt-2 mb-0">Este nome aparece nas despesas, receitas e transferências.</p>
                    <div id="editOpeningSummary" class="account-opening-summary mt-4 d-none">
                        <div><span class="account-opening-summary-label">Saldo inicial</span><strong id="editOpeningSummaryValue"></strong></div>
                        <span id="editOpeningSummaryDate"></span>
                    </div>
                    <div id="editOpeningBalanceFields" class="mt-3">
<label for="editOpeningBalance" class="form-label">Saldo inicial</label>
                        <input id="editOpeningBalance" name="opening_balance" type="number" class="form-control" min="-999999999.99" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="Ex.: 1500,00">
                        <label for="editOpeningDate" class="form-label mt-3">Data do saldo inicial</label>
                        <input id="editOpeningDate" name="opening_balance_date" type="date" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                        <div class="form-text">Usa a data em que o saldo real foi apurado.</div>
                    </div>
                    <div id="editNegativeBalanceField" class="form-check mt-3">
                        <input id="editAllowNegative" name="allow_negative_balance" type="checkbox" value="1" class="form-check-input">
                        <label for="editAllowNegative" class="form-check-label">Permitir saldo negativo</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Saldo negativo --}}
<div class="modal fade" id="negativeBalanceModal" tabindex="-1" aria-labelledby="negativeBalanceTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="negativeBalanceForm" data-endpoint="">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="negativeBalanceTitle">Saldo negativo</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-3" id="negativeBalanceAccountName"></p>
                    <div class="form-check">
                        <input id="negativeBalanceAllowed" name="allow_negative_balance" type="checkbox" value="1" class="form-check-input">
                        <label for="negativeBalanceAllowed" class="form-check-label">Permitir saldo negativo</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Configurar saldo --}}
<div class="modal fade" id="openingBalanceModal" tabindex="-1" aria-labelledby="openingBalanceTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="openingBalanceForm" data-endpoint="">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="openingBalanceTitle">Definir saldo inicial</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-3" id="openingBalanceAccountName">Indica o saldo atual desta conta. A data é definida automaticamente como hoje.</p>
                    <div class="row g-3">
                        <div class="col-7">
                            <label for="openingBalanceValue" class="form-label">Saldo</label>
                            <input id="openingBalanceValue" name="opening_balance" type="number" class="form-control" min="-9999999999.99" max="9999999999.99" step="0.01" required>
                        </div>
                        <div class="col-5">
                            <label for="openingBalanceReference" class="form-label">Data do saldo</label>
                            <input id="openingBalanceReference" name="opening_balance_date" type="date" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required>
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input id="allowNegativeOpening" name="allow_negative_balance" type="checkbox" value="1" class="form-check-input">
                        <label for="allowNegativeOpening" class="form-check-label">Permitir saldo negativo</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Encerrar --}}
<div class="modal fade" id="closeAccountModal" tabindex="-1" aria-labelledby="closeAccountTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="closeAccountForm" data-endpoint="">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="closeAccountTitle">Encerrar conta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3" id="closeAccountName"></p>
                    <label for="closeTransferTarget" class="form-label">Transferir saldo para</label>
                    <select id="closeTransferTarget" name="transfer_to_account_id" class="form-select">
                        <option value="">Não transferir</option>
                        @foreach ($transferTargets as $target)
                            <option value="{{ $target->id }}">{{ $target->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Encerrar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Transferência --}}
@if ($transferTargets->count() >= 2)
    <div class="modal fade" id="accountTransferModal" tabindex="-1" aria-labelledby="accountTransferTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-4">
                <form id="accountTransferForm" data-endpoint="{{ route('financial.movements.store') }}">
                    <input type="hidden" name="type" value="transfer">
                    <div class="modal-header border-0 pb-0">
                        <h2 class="modal-title h5 fw-bold" id="accountTransferTitle">Transferir</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="transferAmount" class="form-label">Valor</label>
                            <input id="transferAmount" name="amount" type="number" class="form-control" min="0.01" max="9999999999.99" step="0.01" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="transferSource" class="form-label">De</label>
                                <select id="transferSource" name="source_account_id" class="form-select" required>
                                    <option value="">Origem</option>
                                    @foreach ($transferTargets as $target)
                                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label for="transferDestination" class="form-label">Para</label>
                                <select id="transferDestination" name="destination_account_id" class="form-select" required>
                                    <option value="">Destino</option>
                                    @foreach ($transferTargets as $target)
                                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-1">
                            <label for="transferDate" class="form-label">Data</label>
                            <input id="transferDate" name="movement_date" type="date" class="form-control" value="{{ now()->toDateString() }}" required>
                        </div>
                        <input type="hidden" name="description" value="Transferência entre contas">
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Transferir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection

@push('styles')
<style>
.accounts-page { width:100%; max-width:none; margin:0; }
.accounts-toolbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; }
.accounts-list { display:grid; gap:.75rem; }
.account-card { display:grid; grid-template-columns:48px minmax(0,1fr) minmax(180px,auto) 100px; align-items:center; gap:1rem; padding:1.15rem 1.25rem; border:1px solid var(--app-border); border-radius:1rem; background:var(--bs-body-bg); box-shadow:var(--app-shadow); }
.account-card-link { display:contents; color:inherit; text-decoration:none; }
.account-card-link:hover .account-name { color:var(--app-primary); }
.account-icon { display:grid; place-items:center; width:46px; height:46px; border-radius:.85rem; background:rgba(99,91,255,.1); color:var(--app-primary); }
.account-icon svg { width:21px; height:21px; }
.account-main { min-width:0; }
.account-title-row { display:flex; align-items:center; flex-wrap:wrap; gap:.55rem; }
.account-name { margin:0; font-size:1rem; font-weight:700; overflow-wrap:anywhere; }
.account-subtitle { margin-top:.2rem; color:var(--bs-secondary-color); font-size:.85rem; }
.account-status { padding:.2rem .55rem; border-radius:999px; font-size:.7rem; font-weight:600; }
.account-status.is-active { background:rgba(22,163,74,.1); color:#18734a; }
.account-status.is-pending { background:rgba(245,158,11,.14); color:#896000; }
.account-status.is-closed { background:var(--bs-tertiary-bg); color:var(--bs-secondary-color); }
.account-balance { text-align:right; }
.account-balance strong { display:block; font-size:1.1rem; white-space:nowrap; }
.account-balance strong small { font-size:.8rem; font-weight:600; }
.account-balance span { display:block; color:var(--bs-secondary-color); font-size:.75rem; }
.account-menu { justify-self:end; }
.account-menu-button { display:inline-flex; align-items:center; justify-content:center; gap:.35rem; min-width:5.5rem; height:36px; padding:0 .65rem; border:1px solid var(--app-border); border-radius:.7rem; color:var(--bs-secondary-color); background:var(--bs-body-bg); font-size:.78rem; font-weight:650; }
.account-menu-button:hover, .account-menu-button:focus-visible { background:rgba(99,91,255,.08); color:var(--app-primary); }
.account-menu-button svg { width:15px; height:15px; }
.account-menu .dropdown-menu { min-width:180px; padding:.4rem; border:1px solid var(--app-border); border-radius:.8rem; box-shadow:var(--app-shadow); }
.account-menu .dropdown-item { border-radius:.55rem; padding:.55rem .7rem; font-size:.9rem; }
.account-edit-modal { min-width:min(100%,32rem); }
.account-edit-modal .modal-header { padding:1.35rem 1.5rem .25rem; }
.account-edit-modal .modal-footer { padding:1rem 1.5rem 1.35rem; }
.account-opening-summary { display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; padding:1rem; border:1px solid color-mix(in srgb,var(--app-primary) 18%,var(--app-border)); border-radius:.85rem; background:rgba(99,91,255,.07); color:var(--app-primary); }
.account-opening-summary > div { display:grid; gap:.25rem; }
.account-opening-summary-label { color:var(--bs-secondary-color); font-size:.78rem; }
.account-opening-summary strong { font-size:1.35rem; letter-spacing:-.02em; }
.account-opening-summary > span { color:var(--bs-secondary-color); font-size:.78rem; white-space:nowrap; }
.accounts-empty { border:1px dashed var(--app-border) !important; box-shadow:var(--app-shadow) !important; }
.accounts-empty-icon { display:inline-grid; place-items:center; width:54px; height:54px; border-radius:1rem; background:rgba(99,91,255,.1); color:var(--app-primary); }
.accounts-empty-icon svg { width:25px; height:25px; }
.transfer-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.7rem 0; border-top:1px solid var(--app-border); }
.transfer-row > div { display:grid; gap:.2rem; }
.transfer-row span { color:var(--bs-secondary-color); font-size:.82rem; }
.transfer-amount { white-space:nowrap; }
#accountFeedback:not(.d-none) { margin-bottom:1rem; }
@media (max-width:640px) {
    .accounts-toolbar { align-items:flex-start; flex-direction:column; }
    .account-card { grid-template-columns:42px minmax(0,1fr) 5.5rem; gap:.7rem; padding:.85rem; }
    .account-icon { width:40px; height:40px; }
    .account-balance { grid-column:2; grid-row:2; text-align:left; }
    .account-menu { grid-column:3; grid-row:1; }
    .transfer-row { align-items:flex-start; }
}
</style>
@endpush

@push('scripts')
<script>
(() => {
    const feedback = document.getElementById('accountFeedback');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    function showFeedback(message, isError = false, form = null) {
        const modalBody = form?.querySelector('.modal-body');
        if (modalBody) {
            let inline = modalBody.querySelector('.account-form-feedback');
            if (!inline) {
                inline = document.createElement('div');
                inline.className = 'account-form-feedback small text-danger mt-3';
                inline.setAttribute('role', 'alert');
                modalBody.append(inline);
            }
            inline.textContent = message;
            return;
        }
        feedback.className = `alert ${isError ? 'alert-danger' : 'alert-success'}`;
        feedback.textContent = message;
        feedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function submitJsonForm(form, method = 'POST', reload = true) {
        form.querySelector('.account-form-feedback')?.remove();
        const data = Object.fromEntries(new FormData(form).entries());
        if (!data.opening_balance) {
            delete data.opening_balance;
            delete data.opening_balance_date;
        }
        if (!data.transfer_to_account_id) delete data.transfer_to_account_id;
        const negative = form.querySelector('[name="allow_negative_balance"]');
        if (negative) data.allow_negative_balance = negative.checked;

        const submit = form.querySelector('[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const response = await fetch(form.dataset.endpoint, {
                method,
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(data),
                credentials: 'same-origin',
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validationMessage = Object.values(result.errors || {}).flat().join(' ');
                throw new Error(validationMessage || result.message || 'Não foi possível guardar.');
            }
            if (reload) window.location.reload();
        } catch (error) {
            if (submit) submit.disabled = false;
            throw error;
        }
    }

    document.getElementById('accountCreateForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try { await submitJsonForm(event.currentTarget); }
        catch (error) { showFeedback(error.message, true, event.currentTarget); }
    });

    document.querySelectorAll('.js-edit-account').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('accountNameForm');
            form.dataset.endpoint = button.dataset.endpoint;
            form.dataset.settingsEndpoint = button.dataset.settingsEndpoint;
            form.dataset.openingEndpoint = button.dataset.openingEndpoint;
            form.dataset.openingConfigured = button.dataset.opening === '1' ? '1' : '0';
            form.dataset.hasMovements = button.dataset.movements === '0' ? '0' : '1';
            form.elements.name.value = button.dataset.name;
            form.querySelector('[name="allow_negative_balance"]').checked = button.dataset.allowed === '1';
            const openingFields = document.getElementById('editOpeningBalanceFields');
            const openingSummary = document.getElementById('editOpeningSummary');
            const negativeField = document.getElementById('editNegativeBalanceField');
            openingFields.classList.remove('d-none');
            document.getElementById('editOpeningBalance').value = button.dataset.openingValue || '';
            document.getElementById('editOpeningDate').value = button.dataset.openingIso || '{{ now()->toDateString() }}';
            openingSummary.classList.toggle('d-none', button.dataset.opening !== '1');
            form.elements.opening_balance.value = button.dataset.opening === '1' ? (button.dataset.openingValue || '') : '';
            document.getElementById('editOpeningSummaryValue').textContent = button.dataset.opening === '1'
                ? Number(button.dataset.openingValue || 0).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + button.dataset.currency
                : '';
            document.getElementById('editOpeningSummaryDate').textContent = button.dataset.opening === '1'
                ? 'Definido em ' + (button.dataset.openingDate || '—')
                : '';
            negativeField.classList.toggle('d-none', !['bank', 'cash'].includes(button.dataset.type));
        });
    });
    document.getElementById('accountNameForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            await submitJsonForm(form, 'PUT', false);
            if (form.elements.opening_balance.value) {
                const opening = new FormData();
                opening.set('opening_balance', form.elements.opening_balance.value);
                opening.set('opening_balance_date', document.getElementById('editOpeningDate').value);
                opening.set('allow_negative_balance', form.elements.allow_negative_balance.checked ? '1' : '0');
                await fetch(form.dataset.openingEndpoint, {
                    method: 'PUT', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(Object.fromEntries(opening.entries())), credentials: 'same-origin'
                }).then(async (response) => { if (!response.ok) throw new Error((await response.json()).message || 'Não foi possível guardar o saldo inicial.'); });
            }
            if (!document.getElementById('editNegativeBalanceField').classList.contains('d-none')) {
                await fetch(form.dataset.settingsEndpoint, {
                    method: 'PUT', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ allow_negative_balance: form.elements.allow_negative_balance.checked }), credentials: 'same-origin'
                });
            }
            window.location.reload();
        }
        catch (error) { showFeedback(error.message, true, event.currentTarget); }
    });

    document.querySelectorAll('.js-negative-balance').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('negativeBalanceForm');
            form.dataset.endpoint = button.dataset.endpoint;
            form.querySelector('[name="allow_negative_balance"]').checked = button.dataset.allowed === '1';
            document.getElementById('negativeBalanceAccountName').textContent = button.dataset.name;
        });
    });
    document.getElementById('negativeBalanceForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try { await submitJsonForm(event.currentTarget, 'PUT'); }
        catch (error) { showFeedback(error.message, true, event.currentTarget); }
    });

    document.querySelectorAll('.js-opening-balance').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('openingBalanceForm');
            form.dataset.endpoint = button.dataset.endpoint;
            form.elements.opening_balance.value = '';
            document.getElementById('openingBalanceReference').value = '{{ now()->toDateString() }}';
            form.querySelector('[name="allow_negative_balance"]').checked = false;
            document.getElementById('openingBalanceAccountName').textContent = button.dataset.name;
        });
    });
    document.getElementById('openingBalanceForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try { await submitJsonForm(event.currentTarget, 'PUT'); }
        catch (error) { showFeedback(error.message, true, event.currentTarget); }
    });

    document.querySelectorAll('.js-close-account').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('closeAccountForm');
            const target = document.getElementById('closeTransferTarget');
            form.dataset.endpoint = button.dataset.endpoint;
            document.getElementById('closeAccountName').textContent = button.dataset.name;
            target.value = '';
            [...target.options].forEach((option) => { option.disabled = option.value === button.dataset.id; });
        });
    });
    document.getElementById('closeAccountForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try { await submitJsonForm(event.currentTarget); }
        catch (error) { showFeedback(error.message, true, event.currentTarget); }
    });

    document.querySelectorAll('.js-account-transfer').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('transferSource').value = button.dataset.source;
            document.getElementById('transferDestination').value = '';
        });
    });
    document.getElementById('accountTransferForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (form.elements.source_account_id.value === form.elements.destination_account_id.value) {
            showFeedback('Escolhe duas contas diferentes.', true, form);
            return;
        }
        try { await submitJsonForm(form); }
        catch (error) { showFeedback(error.message, true, form); }
    });
})();
</script>
@endpush

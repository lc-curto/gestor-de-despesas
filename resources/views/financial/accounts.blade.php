@extends('layouts.app')

@section('title', 'Contas')

@section('content')
@php
    $typeLabels = ['bank' => 'Banco', 'cash' => 'Dinheiro', 'credit_card' => 'Cartão'];
    $statusLabels = ['active' => 'Ativa', 'setup_pending' => 'Por configurar', 'closed' => 'Encerrada'];
    $activeAccounts = $accounts->reject(fn ($account) => $account->status === 'closed')->values();
    $closedAccounts = $accounts->filter(fn ($account) => $account->status === 'closed')->values();
@endphp

<div class="app-content accounts-rebuilt" id="accountsPage">
    <header class="accounts-header-v2">
        <div>
            <h1 class="h2 fw-bold mb-0">Contas</h1>
            <span class="small text-secondary">{{ $activeAccounts->count() }} {{ $activeAccounts->count() === 1 ? 'conta ativa' : 'contas ativas' }}</span>
        </div>
        <div class="d-flex gap-2">
            @if ($transferTargets->count() >= 2)
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#accountTransferModal">
                    <i data-lucide="arrow-left-right" aria-hidden="true"></i><span class="ms-1">Transferir</span>
                </button>
            @endif
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newAccountModal">
                <i data-lucide="plus" aria-hidden="true"></i><span class="ms-1">Nova</span>
            </button>
        </div>
    </header>

    <div id="accountFeedback" class="d-none" role="status" aria-live="polite"></div>

    <div class="accounts-tabs nav nav-fill" id="accountsTabs" role="tablist" aria-label="Tipo de conta">
        <button
            class="nav-link active"
            id="activeAccountsTab"
            type="button"
            role="tab"
            data-bs-toggle="tab"
            data-bs-target="#activeAccountsPanel"
            aria-controls="activeAccountsPanel"
            aria-selected="true"
        >
            <span>Ativas</span>
            <span class="accounts-tab-count">{{ $activeAccounts->count() }}</span>
        </button>
        <button
            class="nav-link"
            id="closedAccountsTab"
            type="button"
            role="tab"
            data-bs-toggle="tab"
            data-bs-target="#closedAccountsPanel"
            aria-controls="closedAccountsPanel"
            aria-selected="false"
        >
            <span>Encerradas</span>
            <span class="accounts-tab-count">{{ $closedAccounts->count() }}</span>
        </button>
    </div>

    <div class="tab-content accounts-tab-content" id="accountsTabContent">
        @foreach (['active' => $activeAccounts, 'closed' => $closedAccounts] as $accountGroup => $groupAccounts)
            <section
                class="tab-pane fade {{ $accountGroup === 'active' ? 'show active' : '' }}"
                id="{{ $accountGroup === 'active' ? 'activeAccountsPanel' : 'closedAccountsPanel' }}"
                role="tabpanel"
                aria-labelledby="{{ $accountGroup === 'active' ? 'activeAccountsTab' : 'closedAccountsTab' }}"
                tabindex="0"
            >
                @if ($groupAccounts->isEmpty())
                    <section class="accounts-empty card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center py-5">
                            <span class="accounts-empty-icon"><i data-lucide="wallet-cards" aria-hidden="true"></i></span>
                            <h2 class="h5 fw-bold mt-3">
                                {{ $accountGroup === 'active' ? 'Não tens contas ativas' : 'Ainda não existem contas encerradas' }}
                            </h2>
                            @if ($accountGroup === 'active')
                                <button class="btn btn-primary mt-2" type="button" data-bs-toggle="modal" data-bs-target="#newAccountModal">Adicionar</button>
                            @endif
                        </div>
                    </section>
                @else
                    <section class="accounts-list-v2" aria-label="{{ $accountGroup === 'active' ? 'Contas ativas' : 'Contas encerradas' }}">
                        @foreach ($groupAccounts as $account)
                            @php
                                $icon = $account->type === 'cash' ? 'wallet' : ($account->type === 'credit_card' ? 'credit-card' : 'landmark');
                            @endphp
                            <article class="account-row-v2" data-account-id="{{ $account->id }}" data-account-status="{{ $account->status }}">
                                <a class="account-row-link-v2" href="{{ route('financial.accounts.show', $account->id) }}" aria-label="Ver histórico de {{ $account->name }}">
                                    <span class="account-row-icon-v2"><i data-lucide="{{ $icon }}" aria-hidden="true"></i></span>
                                    <div class="account-row-main-v2">
                                        <div class="account-row-title-v2">
                                            <h2 class="account-row-name-v2">{{ $account->name }}</h2>
                                            <span class="account-row-status-v2 {{ $account->status === 'active' ? 'is-active' : ($account->status === 'closed' ? 'is-closed' : 'is-pending') }}">
                                                {{ $statusLabels[$account->status] ?? $account->status }}
                                            </span>
                                        </div>
                                        <div class="account-row-subtitle-v2">{{ $typeLabels[$account->type] ?? $account->type }} <span aria-hidden="true">·</span> {{ $account->currency }}</div>
                                    </div>
                                </a>
                                <div class="account-row-balance-v2">
                                    @if ($accountGroup === 'closed')
                                        <strong>—</strong><span>Histórico preservado</span>
                                    @elseif ($account->current_balance !== null)
                                        @if ($account->type === 'credit_card' && $account->credit_limit !== null)
                                            <strong>{{ number_format(max(0, (float) $account->credit_limit - abs((float) $account->current_balance)), 2, ',', '.') }} <small>{{ $account->currency }}</small></strong>
                                            <span>Crédito disponível</span>
                                        @else
                                            <strong>{{ number_format((float) $account->current_balance, 2, ',', '.') }} <small>{{ $account->currency }}</small></strong>
                                            <span>{{ $account->type === 'credit_card' ? 'Dívida utilizada' : 'Saldo atual' }}</span>
                                        @endif
                                    @elseif ($account->type === 'credit_card')
                                        <strong>—</strong><span>Cartão</span>
                                    @else
                                        <strong>—</strong><span>Saldo por configurar</span>
                                    @endif
                                </div>
                                @if ($accountGroup === 'active' && $account->is_operational && $account->status !== 'closed')
                                    <div class="dropdown account-row-menu-v2">
                                        <button class="account-row-menu-v2-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações para {{ $account->name }}">
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
                                                data-type="{{ $account->type }}" data-currency="{{ $account->currency }}">Editar</button></li>
                                            @if (in_array($account->type, ['bank', 'cash'], true) && $account->status === 'active' && $account->opening_balance !== null)
                                                <li><hr class="dropdown-divider"></li>
                                                <li><button class="dropdown-item text-danger js-close-account" type="button" data-bs-toggle="modal" data-bs-target="#closeAccountModal" data-endpoint="{{ route('financial.accounts.close', $account->id) }}" data-id="{{ $account->id }}" data-name="{{ $account->name }}" data-balance="{{ number_format((float) ($account->current_balance ?? 0), 2, '.', '') }}" data-currency="{{ $account->currency }}">Encerrar</button></li>
                                            @endif
                                        </ul>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </section>
                @endif
            </section>
        @endforeach
    </div>

</div>

{{-- Nova conta: cria uma conta bancária ou carteira com o saldo de partida --}}
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
                        <label for="accountName" class="form-label">Nome da conta</label>
                        <input id="accountName" name="name" class="form-control" maxlength="120" required placeholder="Ex.: Conta principal">
                    </div>
                    <div class="mb-3">
                        <label for="accountType" class="form-label">Tipo de conta</label>
                        <select id="accountType" name="type" class="form-select" required>
                            <option value="bank">Conta bancária</option>
                            <option value="cash">Dinheiro / carteira</option>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label for="openingBalance" class="form-label">Saldo inicial</label>
                            <input id="openingBalance" name="opening_balance" type="text" inputmode="decimal" class="form-control" min="-9999999999.99" max="9999999999.99" data-money="opening" value="0.00" required aria-describedby="openingBalanceHelp">
                            <div class="form-text" id="openingBalanceHelp">Ex.: 90,50 ou -90,50.</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="openingBalanceDate" class="form-label">Data do saldo</label>
                            <input id="openingBalanceDate" name="opening_balance_date" type="date" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required>
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="allowNegativeBalance" name="allow_negative_balance" value="1">
                        <label class="form-check-label" for="allowNegativeBalance">Permitir saldo negativo nesta conta</label>
                        <div class="form-text">Permitir saldo negativo.</div>
                    </div>
                    <div class="account-form-feedback small text-danger mt-3" role="alert"></div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Transferência: movimenta dinheiro entre duas contas existentes; não é receita nem despesa --}}
@if ($transferTargets->count() >= 2)
<div class="modal fade" id="accountTransferModal" tabindex="-1" aria-labelledby="accountTransferTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="accountTransferForm" data-endpoint="{{ route('financial.movements.store') }}">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="accountTransferTitle">Transferir entre contas</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="type" value="transfer">
                    <input type="hidden" name="description" value="Transferência entre contas">
                    <div class="mb-3">
                        <label for="transferAmount" class="form-label">Valor</label>
                        <input id="transferAmount" name="amount" type="text" inputmode="decimal" class="form-control" min="0.01" max="9999999999.99" data-money="positive" required placeholder="0,00">
                        
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="transferSource" class="form-label">Conta de origem</label>
                            <select id="transferSource" name="source_account_id" class="form-select" required>
                                <option value="">Selecionar</option>
                                @foreach ($transferTargets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="transferDestination" class="form-label">Conta de destino</label>
                            <select id="transferDestination" name="destination_account_id" class="form-select" required>
                                <option value="">Selecionar</option>
                                @foreach ($transferTargets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="transferDate" class="form-label">Data da transferência</label>
                        <input id="transferDate" name="movement_date" type="date" class="form-control" value="{{ now()->toDateString() }}" required>
                    </div>
                    <div class="account-form-feedback small text-danger mt-3" role="alert"></div>
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

{{-- Edição de conta: utiliza a estrutura de modal reutilizável do sistema. --}}
<x-modal id="renameAccountModal" aria-labelledby="editAccountTitle">
    <form id="accountNameForm">
        <x-modal-header
            title="Editar conta"
            title-id="editAccountTitle"
            description="Atualiza os dados da tua conta."
        />
        <x-modal-body>
            <div class="mb-3">
                <label for="editAccountName" class="form-label">Nome da conta</label>
                <input id="editAccountName" name="name" class="form-control" maxlength="120" required>
            </div>

            <div id="editOpeningBalanceFields" class="mb-3">
                <label for="editOpeningBalance" class="form-label">Saldo inicial</label>
                <input id="editOpeningBalance" name="opening_balance" type="text" inputmode="decimal"
                    class="form-control" data-money="opening" data-money-optional="true"
                    placeholder="Ex.: 1.000,00">
                <div class="form-text">Altera o saldo de partida sem apagar os movimentos registados.</div>
                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <label for="editOpeningDate" class="form-label">Data do saldo inicial</label>
                        <input id="editOpeningDate" name="opening_balance_date" type="date" class="form-control"
                            max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}">
                    </div>
                </div>
                <div id="editOpeningSummary" class="small text-secondary mt-2 d-none">
                    Saldo inicial atual: <strong id="editOpeningSummaryValue"></strong>
                    <span class="d-block" id="editOpeningSummaryDate"></span>
                </div>
            </div>

            <div id="editNegativeBalanceField" class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="editAllowNegativeBalance" name="allow_negative_balance" value="1">
                <label class="form-check-label" for="editAllowNegativeBalance">Permitir saldo negativo</label>
                <div class="form-text">Autoriza que esta conta fique com saldo abaixo de zero.</div>
            </div>

            <div class="account-form-feedback small text-danger mt-3" role="alert" aria-live="polite"></div>
        </x-modal-body>
        <x-modal-footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">Guardar</button>
        </x-modal-footer>
    </form>
</x-modal>

{{-- Encerramento: preserva o histórico e transfere o saldo positivo quando necessário. --}}
<x-modal id="closeAccountModal" aria-labelledby="closeAccountTitle">
    <form id="closeAccountForm">
        <x-modal-header
            title="Encerrar conta"
            title-id="closeAccountTitle"
            description="O histórico e os movimentos serão mantidos."
        />
        <x-modal-body>
            <p class="mb-3">
                Confirmas o encerramento de <strong id="closeAccountName"></strong>?
            </p>

            <div id="closeAccountPositiveNotice" class="alert alert-info d-none mb-3" role="status">
                O saldo de <strong id="closeAccountPositiveAmount"></strong> será transferido para a conta selecionada.
            </div>

            <div id="closeAccountTransferGroup" class="mb-3 d-none">
                <label for="closeTransferTarget" class="form-label">Conta de destino</label>
                <select id="closeTransferTarget" name="transfer_to_account_id" class="form-select">
                    <option value="" disabled selected>Selecionar</option>
                    @foreach ($transferTargets as $target)
                        <option value="{{ $target->id }}" data-currency="{{ $target->currency }}">{{ $target->name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="closeAccountNoTargets" class="alert alert-warning d-none mb-3" role="alert">
                Não existe outra conta ativa, configurada e na mesma moeda para receber o saldo. Cria ou configura uma conta de destino antes de encerrar.
            </div>

            <div id="closeAccountZeroNotice" class="alert alert-success d-none mb-3" role="status">
                O saldo está a zero. Podes encerrar sem transferência.
            </div>

            <div id="closeAccountNegativeNotice" class="alert alert-warning d-none mb-3" role="alert">
                <p class="mb-2">Saldo atual: <strong id="closeAccountNegativeAmount"></strong></p>
                <p class="mb-2">Não é possível encerrar enquanto o saldo estiver negativo.</p>
                <p class="mb-0">Confere o saldo inicial e os movimentos liquidados. Corrige os registos incorretos. Se o descoberto for real, regista uma entrada verdadeira ou transfere dinheiro de outra conta para cobrir a diferença.</p>
            </div>

            <div id="closeAccountFeedback" class="small text-danger mt-3" role="alert" aria-live="polite"></div>
        </x-modal-body>
        <x-modal-footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button id="closeAccountSubmit" type="submit" class="btn btn-danger">Encerrar</button>
        </x-modal-footer>
    </form>
</x-modal>

@endsection



@push('styles')
<style>
/* A página segue a mesma hierarquia de Cartões, sem depender de regras globais. */
.accounts-rebuilt { width: 100%; min-width: 0; }
.accounts-rebuilt .accounts-tabs {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--mb-border);
}
.accounts-rebuilt .accounts-tabs .nav-link {
    position: relative;
    display: inline-flex;
    flex: 1 1 50%;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    min-height: 2.7rem;
    padding: .5rem .25rem .7rem;
    border: 0;
    border-radius: 0;
    background: transparent;
    color: var(--mb-muted);
    font-size: .88rem;
    font-weight: 600;
    box-shadow: none;
}
.accounts-rebuilt .accounts-tabs .nav-link.active {
    background: transparent;
    color: var(--mb-primary);
    box-shadow: none;
}
.accounts-rebuilt .accounts-tabs .nav-link.active::after {
    position: absolute;
    right: 0;
    bottom: -1px;
    left: 0;
    height: 2px;
    background: var(--mb-primary);
    content: "";
}
.accounts-rebuilt .accounts-tab-count {
    color: var(--mb-muted);
    font-size: .76rem;
    font-weight: 500;
    line-height: 1;
}
.accounts-rebuilt .accounts-tabs .nav-link.active .accounts-tab-count {
    color: var(--mb-primary);
}
.accounts-rebuilt .accounts-tab-content { min-width: 0; }
.accounts-rebuilt .accounts-header-v2 { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin: 0 0 1.25rem; }
.accounts-rebuilt .accounts-header-v2 > :first-child { min-width: 0; flex: 1 1 auto; }
.accounts-rebuilt .accounts-header-v2 h1 { display: block; margin: 0 0 .25rem; color: var(--mb-text); font-size: clamp(1.45rem, 2.2vw, 1.9rem); line-height: 1.2; }
.accounts-rebuilt .accounts-header-v2 .small { display: block; color: var(--mb-muted); }
.accounts-rebuilt .accounts-header-v2 > :last-child { display: flex; flex: 0 0 auto; flex-wrap: wrap; gap: .5rem; }
.accounts-rebuilt .accounts-list-v2 { display: grid; gap: .85rem; min-width: 0; }
.accounts-rebuilt .account-row-v2 { display: flex; align-items: center; gap: 1rem; min-width: 0; min-height: 6.25rem; padding: 1.1rem 1.25rem; border: 1px solid var(--mb-border); border-radius: var(--mb-radius-lg); background: var(--mb-surface); box-shadow: var(--mb-shadow); }
.accounts-rebuilt .account-row-link-v2 { display: flex; flex: 1 1 auto; min-width: 0; align-items: center; gap: .85rem; color: var(--mb-text); text-decoration: none; }
.accounts-rebuilt .account-row-icon-v2 { display: grid; width: 2.8rem; height: 2.8rem; flex: 0 0 2.8rem; place-items: center; border-radius: .85rem; background: rgba(99,91,255,.1); color: var(--mb-primary); }
.accounts-rebuilt .account-row-icon-v2 svg { width: 1.3rem; height: 1.3rem; }
.accounts-rebuilt .account-row-main-v2 { min-width: 0; }
.accounts-rebuilt .account-row-title-v2 { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.accounts-rebuilt .account-row-name-v2 { margin: 0; color: var(--mb-text); font-size: 1rem; font-weight: 750; overflow-wrap: anywhere; }
.accounts-rebuilt .account-row-status-v2 { display: inline-flex; align-items: center; padding: .22rem .55rem; border-radius: 999px; font-size: .7rem; font-weight: 700; }
.accounts-rebuilt .account-row-subtitle-v2 { margin-top: .2rem; color: var(--mb-muted); font-size: .82rem; }
.accounts-rebuilt .account-row-balance-v2 { flex: 0 0 11rem; min-width: 0; margin-left: auto; color: var(--mb-text); text-align: right; }
.accounts-rebuilt .account-row-balance-v2 strong { display: block; color: var(--mb-text); white-space: nowrap; }
.accounts-rebuilt .account-row-balance-v2 span { display: block; color: var(--mb-muted); font-size: .75rem; }

.accounts-rebuilt .account-row-menu-v2 { flex: 0 0 auto; }
.accounts-rebuilt .account-row-menu-v2-button { display: inline-flex; align-items: center; gap: .35rem; min-height: 2.4rem; padding: .45rem .7rem; border: 1px solid var(--mb-border); border-radius: .6rem; background: var(--mb-surface); color: var(--mb-text); font-size: .82rem; white-space: nowrap; }
.accounts-rebuilt .account-row-menu-v2-button:hover { border-color: var(--mb-primary); color: var(--mb-primary); }
.accounts-rebuilt .account-row-menu-v2-button svg { width: 1rem; height: 1rem; }
.accounts-rebuilt .account-row-status-v2.is-active { background: rgba(33,132,91,.1); color: var(--mb-positive); }
.accounts-rebuilt .account-row-status-v2.is-pending { background: rgba(220,150,35,.13); color: #93620b; }
.accounts-rebuilt .account-row-status-v2.is-closed { background: var(--mb-page); color: var(--mb-muted); }
.accounts-rebuilt .accounts-empty { border: 1px dashed var(--mb-border); background: var(--mb-surface); }
.accounts-rebuilt .accounts-empty-icon { display: inline-grid; place-items: center; width: 3.4rem; height: 3.4rem; border-radius: 1rem; background: rgba(99,91,255,.1); color: var(--mb-primary); }
.accounts-rebuilt .accounts-empty-icon svg { width: 1.5rem; height: 1.5rem; }
@media (max-width: 767.98px) {
    .accounts-rebuilt .accounts-header-v2 { align-items: flex-start; flex-direction: column; }
    .accounts-rebuilt .accounts-header-v2 > :last-child { width: 100%; }
    .accounts-rebuilt .accounts-header-v2 > :last-child .btn { flex: 1 1 auto; }
    .accounts-rebuilt .account-row-v2 { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-rows: auto auto; gap: .6rem .7rem; min-height: 0; padding: .9rem; }
    .accounts-rebuilt .account-row-link-v2 { grid-column: 1; grid-row: 1; }
    .accounts-rebuilt .account-row-menu-v2 { grid-column: 2; grid-row: 1; align-self: start; }
    .accounts-rebuilt .account-row-balance-v2 { display: flex; grid-column: 1 / -1; grid-row: 2; align-items: baseline; justify-content: space-between; gap: .6rem; padding-top: .6rem; border-top: 1px solid var(--mb-border); text-align: left; }
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

    function parseMoney(value, allowNegative = false) {
        let text = String(value ?? '').trim().replace(/[\u00a0\s]/g, '');
        if (!text) throw new Error('Indica um valor.');
        if (text.includes('-') && (!allowNegative || text.indexOf('-') !== 0)) throw new Error('Indica um valor monetário válido.');
        const negative = text.startsWith('-');
        if (negative) text = text.slice(1);
        if (!text || !/^\d[\d.,]*$/.test(text)) throw new Error('Indica um valor monetário válido.');
        let integerPart = text;
        let decimalPart = '';
        if (text.includes(',')) {
            const parts = text.split(',');
            if (parts.length !== 2 || !/^\d{1,2}$/.test(parts[1]) || !/^(?:\d+|\d{1,3}(?:\.\d{3})+)$/.test(parts[0])) throw new Error('Usa o formato 90,50 ou 1.000,00.');
            integerPart = parts[0].replace(/\./g, '');
            decimalPart = parts[1];
        } else if (text.includes('.')) {
            const parts = text.split('.');
            if (parts.length !== 2 || !/^\d+$/.test(parts[0]) || !/^\d{1,2}$/.test(parts[1])) throw new Error('Usa no máximo duas casas decimais.');
            integerPart = parts[0];
            decimalPart = parts[1];
        }
        integerPart = integerPart.replace(/^0+(?=\d)/, '');
        const canonical = `${negative ? '-' : ''}${integerPart}.${decimalPart.padEnd(2, '0')}`;
        const number = Number(canonical);
        if (!Number.isFinite(number) || Math.abs(number) > 9999999999.99 || (!allowNegative && number <= 0)) throw new Error(allowNegative ? 'O valor deve estar entre -9.999.999.999,99 e 9.999.999.999,99.' : 'O valor deve ser maior que zero.');
        return canonical;
    }

    function normalizeMoneyFields(form) {
        form.querySelectorAll('[data-money]').forEach((field) => {
            if (field.dataset.moneyOptional === 'true' && !field.value.trim()) return;
            field.value = parseMoney(field.value, field.dataset.money === 'opening');
        });
    }

    async function submitJsonForm(form, method = 'POST', reload = true) {
        form.querySelector('.account-form-feedback')?.remove();
        normalizeMoneyFields(form);
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

    document.getElementById('newAccountModal')?.addEventListener('hidden.bs.modal', () => {
        const form = document.getElementById('accountCreateForm');
        form?.reset();
        form?.querySelector('.account-form-feedback')?.replaceChildren();
    });

    document.getElementById('accountCreateForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            await submitJsonForm(form);
        } catch (error) {
            showFeedback(error.message, true, form);
        }
    });

    document.querySelectorAll('.js-account-transfer').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('accountTransferForm');
            if (!form) return;
            form.elements.source_account_id.value = button.dataset.source || '';
            form.elements.destination_account_id.value = '';
        });
    });

    document.getElementById('accountTransferForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const source = form.elements.source_account_id.value;
        const destination = form.elements.destination_account_id.value;
        if (!source || !destination || source === destination) {
            showFeedback('Seleciona duas contas diferentes.', true, form);
            return;
        }
        try {
            await submitJsonForm(form);
        } catch (error) {
            showFeedback(error.message, true, form);
        }
    });

    document.querySelectorAll('.js-edit-credit-card').forEach((button) => button.addEventListener('click', () => { const form=document.getElementById('creditCardEditForm'); form.dataset.updateUrl=button.dataset.updateUrl; form.dataset.settingsUrl=button.dataset.settingsUrl; form.elements.name.value=button.dataset.name; form.elements.credit_limit.value=button.dataset.limit||''; form.elements.closing_day.value=button.dataset.closing||''; form.elements.due_day.value=button.dataset.due||''; document.getElementById('editCardUsed').textContent=Number(button.dataset.used||0).toLocaleString('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; form.querySelector('.account-form-feedback').textContent=''; }));
    document.getElementById('creditCardEditForm')?.addEventListener('submit',async(event)=>{event.preventDefault();const form=event.currentTarget,submit=form.querySelector('[type="submit"]');submit.disabled=true;const headers={'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token};try{let response=await fetch(form.dataset.settingsUrl,{method:'PUT',credentials:'same-origin',headers,body:JSON.stringify({credit_limit:form.elements.credit_limit.value,closing_day:form.elements.closing_day.value,due_day:form.elements.due_day.value})});let result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o cartão.');response=await fetch(form.dataset.updateUrl,{method:'PUT',credentials:'same-origin',headers,body:JSON.stringify({name:form.elements.name.value})});result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o nome.');window.location.reload();}catch(error){form.querySelector('.account-form-feedback').textContent=error.message;submit.disabled=false;}});
    document.querySelectorAll('.js-close-credit-card').forEach((button)=>button.addEventListener('click',async()=>{if(!window.confirm('Encerrar cartão? Histórico preservado.'))return;button.disabled=true;try{const response=await fetch(button.dataset.closeUrl,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(result.message||'Não foi possível encerrar o cartão.');window.location.reload();}catch(error){showFeedback(error.message,true);button.disabled=false;}}));
    document.querySelectorAll('.js-edit-account').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById('accountNameForm');
            if (!form) return;

            form.querySelector('.account-form-feedback')?.replaceChildren();
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
            if (!form.elements.opening_balance.value
                && !document.getElementById('editNegativeBalanceField').classList.contains('d-none')) {
                const settingsResponse = await fetch(form.dataset.settingsEndpoint, {
                    method: 'PUT', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ allow_negative_balance: form.elements.allow_negative_balance.checked }), credentials: 'same-origin'
                });
                const settingsResult = await settingsResponse.json().catch(() => ({}));
                if (!settingsResponse.ok) {
                    const validationMessage = Object.values(settingsResult.errors || {}).flat().join(' ');
                    throw new Error(validationMessage || settingsResult.message || 'Não foi possível atualizar a permissão de saldo negativo.');
                }
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

    const closeAccountModal = document.getElementById('closeAccountModal');
    const closeAccountForm = document.getElementById('closeAccountForm');
    const closeTransferTarget = document.getElementById('closeTransferTarget');
    const closeAccountSubmit = document.getElementById('closeAccountSubmit');
    const closeAccountFeedback = document.getElementById('closeAccountFeedback');

    function formatCloseAccountAmount(cents, currency) {
        try {
            return new Intl.NumberFormat('pt-PT', {
                style: 'currency',
                currency,
            }).format(cents / 100);
        } catch {
            return (cents / 100).toFixed(2).replace('.', ',') + ' ' + currency;
        }
    }

    function clearCloseAccountFeedback() {
        if (closeAccountFeedback) closeAccountFeedback.textContent = '';
        if (feedback) {
            feedback.className = 'd-none';
            feedback.textContent = '';
        }
    }

    function setCloseAccountState(button) {
        if (!closeAccountForm || !closeTransferTarget || !closeAccountSubmit) return;

        const balance = Number(button.dataset.balance);
        const balanceCents = Number.isFinite(balance) ? Math.round(balance * 100) : null;
        const currency = button.dataset.currency || 'EUR';
        const sourceId = button.dataset.id;
        const positive = balanceCents !== null && balanceCents > 0;
        const negative = balanceCents === null || balanceCents < 0;
        const zero = balanceCents === 0;
        const options = Array.from(closeTransferTarget.options).filter((option) => {
            if (!option.value) return false;

            const compatible = option.value !== sourceId && option.dataset.currency === currency;
            option.disabled = !compatible;
            option.hidden = !compatible;

            return compatible;
        });

        closeAccountForm.dataset.endpoint = button.dataset.endpoint;
        closeAccountForm.dataset.balanceCents = balanceCents === null ? '' : String(balanceCents);
        closeAccountForm.dataset.sourceId = sourceId;
        closeAccountForm.dataset.currency = currency;
        document.getElementById('closeAccountName').textContent = button.dataset.name;
        closeTransferTarget.value = '';
        closeTransferTarget.required = positive && options.length > 0;

        document.getElementById('closeAccountPositiveNotice').classList.toggle('d-none', !positive || options.length === 0);
        document.getElementById('closeAccountPositiveAmount').textContent = positive
            ? formatCloseAccountAmount(balanceCents, currency)
            : '';

        document.getElementById('closeAccountTransferGroup').classList.toggle('d-none', !positive || options.length === 0);
        document.getElementById('closeAccountNoTargets').classList.toggle('d-none', !positive || options.length > 0);
        document.getElementById('closeAccountZeroNotice').classList.toggle('d-none', !zero);
        document.getElementById('closeAccountNegativeNotice').classList.toggle('d-none', !negative);
        document.getElementById('closeAccountNegativeAmount').textContent = negative && balanceCents !== null
            ? formatCloseAccountAmount(balanceCents, currency)
            : '';

        closeAccountSubmit.disabled = negative || (positive && options.length === 0);
        clearCloseAccountFeedback();
    }

    document.querySelectorAll('.js-close-account').forEach((button) => {
        button.addEventListener('click', () => setCloseAccountState(button));
    });

    closeAccountModal?.addEventListener('show.bs.modal', clearCloseAccountFeedback);
    closeAccountModal?.addEventListener('hidden.bs.modal', () => {
        closeAccountForm?.reset();
        closeAccountForm?.removeAttribute('data-endpoint');
        closeAccountForm?.removeAttribute('data-balance-cents');
        closeTransferTarget.required = false;
        closeAccountSubmit.disabled = false;
        clearCloseAccountFeedback();
    });

    closeAccountForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const rawBalanceCents = closeAccountForm.dataset.balanceCents;
        const balanceCents = rawBalanceCents === undefined || rawBalanceCents === ''
            ? null
            : Number(rawBalanceCents);

        if (!Number.isFinite(balanceCents) || balanceCents < 0) {
            closeAccountFeedback.textContent = 'Regulariza o saldo antes de encerrar a conta.';
            return;
        }
        if (balanceCents > 0 && !closeTransferTarget.value) {
            closeAccountFeedback.textContent = 'Seleciona a conta que vai receber o saldo.';
            return;
        }

        try {
            await submitJsonForm(closeAccountForm);
        } catch (error) {
            closeAccountFeedback.textContent = error.message;
            closeAccountSubmit.disabled = balanceCents > 0
                && !Array.from(closeTransferTarget.options).some((option) => option.value === closeTransferTarget.value && !option.disabled);
        }
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

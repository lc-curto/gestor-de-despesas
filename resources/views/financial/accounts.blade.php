@extends('layouts.app')

@section('title', 'Contas')

@section('content')
@php
    $typeLabels = ['bank' => 'Conta bancária', 'cash' => 'Dinheiro', 'credit_card' => 'Cartão de crédito'];
    $statusLabels = ['active' => 'Ativa', 'setup_pending' => 'Saldo por configurar', 'closed' => 'Encerrada'];
@endphp

<header class="dashboard-intro mb-4">
    <div class="dashboard-intro-copy">
        <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span> Organização financeira</p>
        <h1 class="h2 fw-bold">Contas</h1>
        <p class="text-secondary mb-0">Cria contas e define o saldo real de referência. Os movimentos existentes não são alterados.</p>
    </div>
</header>

<div id="accountFeedback" class="d-none" role="status" aria-live="polite"></div>

<div class="row g-4 align-items-start">
    <div class="col-12 col-xl-4">
        <article class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3">Adicionar conta</h2>
                <form id="accountCreateForm" data-endpoint="{{ route('financial.accounts.store') }}">
                    <div class="mb-3">
                        <label for="accountName" class="form-label">Nome</label>
                        <input id="accountName" name="name" class="form-control" maxlength="120" required placeholder="Ex.: Conta principal">
                    </div>
                    <div class="mb-3">
                        <label for="accountType" class="form-label">Tipo</label>
                        <select id="accountType" name="type" class="form-select" required>
                            <option value="bank">Conta bancária</option>
                            <option value="cash">Dinheiro</option>
                            <option value="credit_card">Cartão de crédito</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="openingBalance" class="form-label">Saldo inicial <span class="text-secondary fw-normal">(opcional)</span></label>
                        <input id="openingBalance" name="opening_balance" type="number" class="form-control" min="-9999999999.99" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="0,00">
                        <div class="form-text">Usa o saldo real na data indicada. Não introduzas novamente os movimentos históricos.</div>
                    </div>
                    <div class="mb-3">
                        <label for="openingBalanceDate" class="form-label">Data do saldo</label>
                        <input id="openingBalanceDate" name="opening_balance_date" type="date" class="form-control" value="{{ now()->toDateString() }}">
                    </div>
                    <div class="form-check mb-3">
                        <input id="allowNegativeBalance" name="allow_negative_balance" type="checkbox" value="1" class="form-check-input">
                        <label for="allowNegativeBalance" class="form-check-label">Permitir saldo negativo</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Guardar conta</button>
                </form>
            </div>
        </article>
        <article class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-1">Transferir entre contas</h2>
                <p class="small text-secondary">Transferências entre banco e dinheiro não são registadas como receita ou despesa.</p>
                @if ($transferTargets->count() >= 2)
                    <form id="accountTransferForm" data-endpoint="{{ route('financial.movements.store') }}">
                        <input type="hidden" name="type" value="transfer">
                        <div class="mb-3">
                            <label for="transferDescription" class="form-label">Descrição</label>
                            <input id="transferDescription" name="description" class="form-control" maxlength="255" required value="Transferência entre contas">
                        </div>
                        <div class="mb-3">
                            <label for="transferAmount" class="form-label">Valor</label>
                            <input id="transferAmount" name="amount" type="number" class="form-control" min="0.01" max="9999999999.99" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="transferDate" class="form-label">Data</label>
                            <input id="transferDate" name="movement_date" type="date" class="form-control" value="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="mb-3">
                            <label for="transferSource" class="form-label">De</label>
                            <select id="transferSource" name="source_account_id" class="form-select" required>
                                <option value="">Conta de origem</option>
                                @foreach ($transferTargets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="transferDestination" class="form-label">Para</label>
                            <select id="transferDestination" name="destination_account_id" class="form-select" required>
                                <option value="">Conta de destino</option>
                                @foreach ($transferTargets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100">Registar transferência</button>
                    </form>
                @else
                    <p class="small text-secondary mb-0">Configura pelo menos duas contas bancárias ou de dinheiro para transferir.</p>
                @endif
            </div>
        </article>
    </div>

    <div class="col-12 col-xl-8">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h5 fw-bold mb-0">As minhas contas</h2>
            <span class="badge text-bg-light">{{ $accounts->count() }}</span>
        </div>

        @forelse ($accounts as $account)
            <article class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <h3 class="h6 fw-bold mb-0">{{ $account->name }}</h3>
                                <span class="badge rounded-pill text-bg-light">{{ $typeLabels[$account->type] ?? $account->type }}</span>
                                <span class="badge rounded-pill {{ $account->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $statusLabels[$account->status] ?? $account->status }}</span>
                            </div>
                            <p class="small text-secondary mb-0">Moeda: {{ $account->currency }}</p>
                        </div>
                        <div class="text-xl-end">
                            @if ($account->current_balance !== null)
                                <div class="small text-secondary">Saldo calculado</div>
                                <strong class="fs-5">{{ number_format((float) $account->current_balance, 2, ',', '.') }} {{ $account->currency }}</strong>
                            @elseif ($account->type === 'credit_card')
                                <span class="small text-secondary">Cálculo de cartões será disponibilizado na fase de faturas.</span>
                            @else
                                <span class="small text-warning-emphasis">Saldo inicial por configurar</span>
                            @endif
                        </div>
                    </div>

                    @if ($account->is_operational && $account->status !== 'closed' && $account->type !== 'credit_card')
                        <hr>
                        <div class="row g-3 align-items-end">
                            <form class="accountNameForm col-12 col-md-5" data-endpoint="{{ route('financial.accounts.update', $account->id) }}">
                                <label for="account-name-{{ $account->id }}" class="form-label small">Nome da conta</label>
                                <div class="input-group input-group-sm">
                                    <input id="account-name-{{ $account->id }}" name="name" class="form-control" maxlength="120" value="{{ $account->name }}" required>
                                    <button class="btn btn-outline-secondary" type="submit">Renomear</button>
                                </div>
                            </form>
                            @if ($account->status === 'active' && $account->opening_balance !== null)
                                <form class="closeAccountForm col-12 col-md-7" data-endpoint="{{ route('financial.accounts.close', $account->id) }}">
                                    <label for="transfer-target-{{ $account->id }}" class="form-label small">Transferir saldo ao encerrar <span class="text-secondary">(se aplicável)</span></label>
                                    <div class="input-group input-group-sm">
                                        <select id="transfer-target-{{ $account->id }}" name="transfer_to_account_id" class="form-select">
                                            <option value="">Não transferir</option>
                                            @foreach ($transferTargets->where('id', '!=', $account->id) as $target)
                                                <option value="{{ $target->id }}">{{ $target->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-outline-danger" type="submit">Encerrar</button>
                                    </div>
                                    <div class="form-text">A conta fica preservada para consulta; contas com saldo negativo exigem reconciliação.</div>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if ($account->opening_balance === null && $account->is_operational)
                        <hr>
                        <form class="openingBalanceForm row g-2 align-items-end" data-endpoint="{{ route('financial.accounts.opening-balance', $account->id) }}">
                            <div class="col-12"><span class="small fw-semibold">Configurar saldo inicial</span></div>
                            <div class="col-12 col-md-4">
                                <label for="balance-{{ $account->id }}" class="form-label small">Saldo real</label>
                                <input id="balance-{{ $account->id }}" name="opening_balance" type="number" class="form-control form-control-sm" min="-9999999999.99" max="9999999999.99" step="0.01" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="balance-date-{{ $account->id }}" class="form-label small">Data de referência</label>
                                <input id="balance-date-{{ $account->id }}" name="opening_balance_date" type="date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="form-check mb-2">
                                    <input id="allow-negative-{{ $account->id }}" name="allow_negative_balance" type="checkbox" value="1" class="form-check-input">
                                    <label for="allow-negative-{{ $account->id }}" class="form-check-label small">Permitir saldo negativo</label>
                                </div>
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Guardar saldo</button>
                            </div>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 text-center text-secondary">Ainda não existem contas. Adiciona uma para começar.</div>
            </div>
        @endforelse

        <article class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3">Transferências recentes</h2>
                @forelse ($transfers as $transfer)
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 border-bottom">
                        <div>
                            <div class="fw-semibold">{{ $transfer->description }}</div>
                            <div class="small text-secondary">{{ $transfer->movement_date->format('d/m/Y') }} · {{ $transfer->contaOrigem?->name ?? 'Conta encerrada' }} → {{ $transfer->contaDestino?->name ?? 'Conta encerrada' }}</div>
                        </div>
                        <strong>{{ number_format((float) $transfer->amount, 2, ',', '.') }} {{ auth()->user()->currency }}</strong>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Ainda não há transferências entre contas.</p>
                @endforelse
            </div>
        </article>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const feedback = document.getElementById('accountFeedback');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    function showFeedback(message, isError = false) {
        feedback.className = `alert ${isError ? 'alert-danger' : 'alert-success'}`;
        feedback.textContent = message;
    }

    async function submitJsonForm(form, method = 'POST') {
        const data = Object.fromEntries(new FormData(form).entries());
        if (!data.opening_balance) {
            delete data.opening_balance;
            delete data.opening_balance_date;
        }
        data.allow_negative_balance = data.allow_negative_balance === '1';

        const response = await fetch(form.dataset.endpoint, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify(data),
            credentials: 'same-origin',
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validationMessage = Object.values(result.errors || {}).flat().join(' ');
            throw new Error(validationMessage || result.message || 'Não foi possível guardar os dados.');
        }
        window.location.reload();
    }

    document.getElementById('accountCreateForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            await submitJsonForm(form);
        } catch (error) {
            showFeedback(error.message, true);
        }
    });

    document.querySelectorAll('.openingBalanceForm').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                await submitJsonForm(event.currentTarget, 'PUT');
            } catch (error) {
                showFeedback(error.message, true);
            }
        });
    });

    document.querySelectorAll('.accountNameForm').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                await submitJsonForm(event.currentTarget, 'PUT');
            } catch (error) {
                showFeedback(error.message, true);
            }
        });
    });

    document.querySelectorAll('.closeAccountForm').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm('Encerrar esta conta? O histórico será preservado.')) return;
            try {
                await submitJsonForm(event.currentTarget);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });
    });

    document.getElementById('accountTransferForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (form.elements.source_account_id.value === form.elements.destination_account_id.value) {
            showFeedback('Seleciona duas contas diferentes.', true);
            return;
        }
        try {
            await submitJsonForm(form);
        } catch (error) {
            showFeedback(error.message, true);
        }
    });
})();
</script>
@endpush

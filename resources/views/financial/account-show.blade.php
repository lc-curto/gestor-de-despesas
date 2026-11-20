@extends('layouts.app')

@section('title', $account->name)

@section('content')
@php
    $typeLabels = ['bank' => 'Banco', 'cash' => 'Dinheiro', 'credit_card' => 'Cartão'];
    $isClosedAccount = $account->status === 'closed';
    $accountStatusLabel = $isClosedAccount ? 'Encerrada' : ($account->status === 'active' ? 'Ativa' : 'Por configurar');
    $movementCount = $movements->total();
    $accountCurrency = $account->currency;
    $accountCurrencySymbol = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$', 'CHF' => 'CHF', 'CAD' => 'CA$', 'AUD' => 'A$'][$accountCurrency] ?? $accountCurrency;
@endphp

<div class="account-history-page">
    <header class="account-history-heading mb-3">
        <a class="btn btn-link p-0 text-decoration-none d-inline-flex align-items-center gap-2" href="{{ $backUrl }}">
            <i data-lucide="arrow-left" aria-hidden="true"></i>
            <span>Voltar</span>
        </a>

        <div class="mt-4">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="small fw-bold text-uppercase text-primary">Histórico da conta</span>
                @if ($isClosedAccount)
                    <span class="badge text-bg-secondary">Encerrada</span>
                @elseif ($account->status === 'active')
                    <span class="badge text-bg-success">Ativa</span>
                @else
                    <span class="badge text-bg-warning">Por configurar</span>
                @endif
            </div>
            <h1 class="h2 fw-bold mb-1">{{ $account->name }}</h1>
            <p class="text-secondary mb-0">{{ $typeLabels[$account->type] ?? $account->type }} · {{ $account->currency }}</p>
        </div>
    </header>

    <section class="card border-0 shadow-sm rounded-4 account-history-summary mb-3" aria-label="Resumo da conta">
        <div class="card-body p-3 p-sm-4">
            <div class="mb-3">
                @if ($account->type === 'credit_card')
                    @if ($isClosedAccount)
                        <span class="small text-secondary">Dívida no encerramento</span>
                        <strong class="account-history-primary-value {{ (float) ($account->current_balance ?? 0) < 0 ? 'text-danger' : 'text-body' }}">
                            {{ $account->current_balance !== null ? number_format(abs((float) $account->current_balance), 2, ',', '.') . ' ' . $account->currency : '—' }}
                        </strong>
                    @else
                        <span class="small text-secondary">Crédito disponível</span>
                        <strong class="account-history-primary-value">
                            {{ $account->credit_limit !== null && $account->current_balance !== null ? number_format(max(0, (float) $account->credit_limit - abs((float) $account->current_balance)), 2, ',', '.') . ' ' . $account->currency : '—' }}
                        </strong>
                        <small class="d-block text-secondary mt-1">Dívida utilizada: {{ number_format(abs((float) ($account->current_balance ?? 0)), 2, ',', '.') }} {{ $account->currency }}</small>
                    @endif
                @else
                    <span class="small text-secondary">{{ $isClosedAccount ? 'Saldo no encerramento' : 'Saldo atual' }}</span>
                    <strong class="account-history-primary-value {{ (float) ($account->current_balance ?? 0) < 0 ? 'text-danger' : 'text-body' }}">
                        {{ $account->current_balance !== null ? number_format((float) $account->current_balance, 2, ',', '.') . ' ' . $account->currency : '—' }}
                    </strong>
                @endif
            </div>

            <div class="row g-3 pt-3 border-top">
                <div class="col-4">
                    <span class="d-block small text-secondary">Movimentos</span>
                    <strong class="fs-6">{{ $movementCount }}</strong>
                </div>
                <div class="col-4">
                    <span class="d-block small text-secondary">Estado</span>
                    <strong class="fs-6">{{ $accountStatusLabel }}</strong>
                </div>
                <div class="col-4">
                    <span class="d-block small text-secondary">Moeda</span>
                    <strong class="fs-6">{{ $account->currency }}</strong>
                </div>
            </div>
        </div>
    </section>

    @if ($account->type === 'credit_card' && $openingDebtRemaining > 0)
    <section class="card border rounded-4 mb-4">
        <div class="card-body p-3 p-sm-4">
            <h2 class="h5 fw-bold mb-1">Dívida anterior à aplicação</h2>
            <p class="small text-secondary mb-3">Por pagar: <strong>{{ number_format($openingDebtRemaining, 2, ',', '.') }} {{ $account->currency }}</strong></p>
            <form id="openingDebtPaymentForm" data-endpoint="{{ route('financial.cards.pay-opening-debt', $account->id) }}">
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label" for="openingDebtPaymentAccount">Pagar a partir de</label><select id="openingDebtPaymentAccount" name="account_id" class="form-select" required><option value="">Selecionar conta</option>@foreach ($paymentAccounts as $paymentAccount)<option value="{{ $paymentAccount->id }}">{{ $paymentAccount->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label" for="openingDebtPaymentAmount">Valor</label><input id="openingDebtPaymentAmount" name="amount" type="text" inputmode="decimal" min="0.01" max="{{ number_format($openingDebtRemaining, 2, '.', '') }}" data-money="positive" class="form-control" required aria-describedby="openingDebtPaymentAmountHelp"><div class="form-text" id="openingDebtPaymentAmountHelp">Ex.: 90,50 ou 1.000,00</div></div>
                    <div class="col-md-3"><label class="form-label" for="openingDebtPaymentDate">Data</label><input id="openingDebtPaymentDate" name="payment_date" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit" title="Registar pagamento" aria-label="Registar pagamento"><i data-lucide="check" aria-hidden="true"></i></button></div>
                </div>
                <div class="small mt-2" id="openingDebtPaymentFeedback" role="status"></div>
            </form>
        </div>
    </section>
    @endif

    <section class="card border-0 shadow-sm rounded-4 account-history-card" aria-labelledby="accountMovementsTitle">
        <div class="card-body p-0">
            <div class="d-flex align-items-center justify-content-between gap-2 p-3 p-sm-4 border-bottom">
                <h2 id="accountMovementsTitle" class="h5 fw-bold mb-0">Movimentos</h2>
                <span class="small text-secondary">{{ $movementCount }} {{ $movementCount === 1 ? 'registo' : 'registos' }}</span>
            </div>
            <div class="list-group list-group-flush">
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
                        $isInvoicePayment = $movement->type === 'transfer'
                            && $movement->status === 'settled'
                            && $movement->invoice_id !== null
                            && $movement->settles_movement_id !== null;
                        $canEditSettlementParent = $movement->is_settlement_parent
                            && in_array($movement->status, ['partial', 'settled'], true)
                            && in_array($movement->type, ['income', 'expense'], true)
                            && ! $movement->debt_id && ! $movement->installment_id
                            && ! $movement->invoice_id && ! $movement->recurrence_id;
                        $canEditOperationalMovement = $movement->status === 'settled'
                            && in_array($movement->type, ['transfer', 'adjustment'], true)
                            && ! $movement->is_historical && ! $movement->invoice_id
                            && ! $movement->settles_movement_id && ! $movement->reversal_of_id
                            && ! $movement->debt_id && ! $movement->installment_id && ! $movement->recurrence_id
                            && ! $movement->is_settlement_parent;
                        $canEditMovement = ($movement->status === 'pending'
                            && in_array($movement->type, ['income', 'expense'], true)
                            && ! $movement->debt_id && ! $movement->installment_id
                            && ! $movement->invoice_id && ! $movement->is_settlement_parent)
                            || $isInvoicePayment || $canEditSettlementParent || $canEditOperationalMovement;
                        $requiresOriginManagement = (bool) ($movement->debt_id || $movement->installment_id
                            || $movement->recurrence_id
                            || ($movement->status === 'partial' && ! $movement->is_settlement_parent));
                        $actionNote = $isClosedAccount
                            ? 'Esta conta está encerrada. O histórico está disponível apenas para consulta.'
                            : ($movement->debt_id || $movement->installment_id
                                ? 'Este movimento pertence a um parcelamento. Faça alterações na área Parcelamentos.'
                                : ($movement->recurrence_id
                                    ? 'Este movimento pertence a uma recorrência. Faça alterações na área de Recorrências.'
                                    : ($movement->is_settlement_parent
                                        ? ($canEditSettlementParent
                                            ? 'Podes corrigir os dados. Os pagamentos já registados serão preservados e os saldos recalculados.'
                                            : 'Este valor é gerido automaticamente a partir dos movimentos associados.')
                                        : ($movement->status === 'partial'
                                            ? 'Este movimento tem um pagamento parcial e precisa de reconciliação antes de ser alterado.'
                                            : ''))));
                        $canEditMovement = ! $isClosedAccount && $canEditMovement;
                        $canCancelMovement = ! $isClosedAccount && ! $requiresOriginManagement && $movement->status !== 'void';
                        $deleteRoute = route('financial.accounts.movement.destroy', $movement->id);
                    @endphp
                    <article class="list-group-item list-group-item-action account-movement-item financial-history-item"
                        tabindex="0" role="button" aria-label="Ver detalhes de {{ $movement->description }}"
                        data-bs-toggle="modal" data-bs-target="#accountMovementDetailsModal"
                        data-account-movement
                        data-description="{{ $movement->description }}"
                        data-movement-id="{{ $movement->id }}"
                        data-amount="{{ number_format((float) $movement->amount, 2, '.', '') }}"
                        data-notes="{{ $movement->notes ?? '' }}"
                        data-date-iso="{{ $movement->movement_date->format('Y-m-d') }}"
                        data-editable="{{ $canEditMovement ? '1' : '0' }}"
                        data-cancelable="{{ $canCancelMovement ? '1' : '0' }}"
                        data-action-note="{{ $actionNote }}"
                        data-invoice-payment="{{ $isInvoicePayment ? '1' : '0' }}"
                        data-source-account-id="{{ $movement->source_account_id }}"
                        data-destination-account-id="{{ $movement->destination_account_id }}"
                        data-movement-type="{{ $movement->type }}"
                        data-adjustment-direction="{{ $movement->adjustment_direction }}"
                        data-signed-amount="{{ $sign }}{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $accountCurrencySymbol }}"
                        data-amount-class="{{ $isOutgoing ? 'text-danger' : 'text-success' }}"
                        data-category="{{ $category }}"
                        data-date="{{ $movement->movement_date->format('d/m/Y') }}"
                        data-kind="{{ $isTransfer ? 'Transferência' : ($movement->type === 'adjustment' ? 'Ajuste de saldo' : ($isOutgoing ? 'Despesa' : 'Receita')) }}"
                        data-counterpart="{{ $isTransfer ? (($isCurrentAccountSource ? 'Para: ' : 'De: ') . ($isCurrentAccountSource ? ($movement->contaDestino?->name ?? 'Conta encerrada') : ($movement->contaOrigem?->name ?? 'Conta encerrada'))) : '' }}"
                        data-edit-url="{{ route('financial.movements.update', $movement->id) }}"
                        data-delete-url="{{ $deleteRoute }}">
                        <x-financial.history-row
                            icon="{{ $isTransfer ? 'arrow-left-right' : ($isOutgoing ? 'arrow-down-left' : 'arrow-up-right') }}"
                            icon-class="{{ $isTransfer ? 'bg-primary-subtle text-primary' : ($isOutgoing ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success') }}"
                            :title="$movement->description"
                            :subtitle="$category . ' · ' . $movement->movement_date->format('d/m/Y')"
                            :amount="$sign . number_format((float) $movement->amount, 2, ',', '.') . ' ' . $accountCurrencySymbol"
                            :amount-class="$isOutgoing ? 'text-danger' : 'text-success'"
                            :status="$movement->status === 'pending' ? 'Pendente' : ($movement->status === 'void' ? 'Anulado' : null)"
                            :status-class="$movement->status === 'pending' ? 'text-bg-warning' : 'text-bg-secondary'"
                            :counterpart="$isTransfer ? (($isCurrentAccountSource ? 'Para: ' : 'De: ') . ($isCurrentAccountSource ? ($movement->contaDestino?->name ?? 'Conta encerrada') : ($movement->contaOrigem?->name ?? 'Conta encerrada'))) : null"
                        />
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

<x-modal id="accountMovementDetailsModal" aria-labelledby="accountMovementDetailsTitle" :scrollable="true">
    <x-modal-header title="Detalhes do movimento" title-id="accountMovementDetailsTitle" />
    <x-modal-body>
        <x-financial.movement-details-panel prefix="accountMovement" related-label="Conta relacionada" destructive-label="Cancelar" :show-actions="! $isClosedAccount" />
                    <form id="accountMovementEditForm" class="d-none" novalidate>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="accountMovementEditBack">Voltar</button>
                    <h3 class="h6 fw-bold mb-0">Editar movimento</h3>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="accountMovementEditDescription">Descrição</label>
                    <input class="form-control" id="accountMovementEditDescription" name="description" maxlength="255" required>
                </div>
                <div class="mb-3" id="accountMovementEditAccountField" hidden>
                    <label class="form-label" for="accountMovementEditAccount">Conta de origem</label>
                    <select class="form-select" id="accountMovementEditAccount" name="source_account_id">
                        @foreach ($paymentAccounts as $paymentAccount)
                            <option value="{{ $paymentAccount->id }}">{{ $paymentAccount->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3" id="accountMovementEditDestinationField" hidden>
                    <label class="form-label" for="accountMovementEditDestination">Conta de destino</label>
                    <select class="form-select" id="accountMovementEditDestination" name="destination_account_id">
                        @foreach ($paymentAccounts as $paymentAccount)
                            <option value="{{ $paymentAccount->id }}">{{ $paymentAccount->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3" id="accountMovementEditDirectionField" hidden>
                    <label class="form-label" for="accountMovementEditDirection">Tipo de ajuste</label>
                    <select class="form-select" id="accountMovementEditDirection" name="adjustment_direction">
                        <option value="increase">Aumentar saldo</option>
                        <option value="decrease">Diminuir saldo</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="accountMovementEditAmount">Valor</label>
                    <input class="form-control" id="accountMovementEditAmount" name="amount" type="number" min="0.01" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="accountMovementEditDate">Data</label>
                    <input class="form-control" id="accountMovementEditDate" name="movement_date" type="date" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="accountMovementEditNotes">Observações</label>
                    <textarea class="form-control" id="accountMovementEditNotes" name="notes" rows="2"></textarea>
                </div>
                <div class="small mb-3" id="accountMovementEditFeedback" role="status" aria-live="polite"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill" id="accountMovementEditCancel">Cancelar</button>
                    <button type="submit" class="btn btn-primary flex-fill" id="accountMovementEditSubmit">Guardar</button>
                </div>
            </form>
    </x-modal-body>
</x-modal>

<x-modal id="accountDeleteModal" aria-labelledby="accountDeleteTitle">
    <form id="accountDeleteForm" method="POST">
                @csrf
                @method('DELETE')
                <x-modal-header title="Cancelar movimento?" title-id="accountDeleteTitle" />
                <x-modal-body>
                    <p class="mb-1" id="accountDeleteDescription">Este movimento será cancelado e os saldos relacionados serão atualizados.</p>
                    <small class="text-secondary" id="accountDeleteHint">O histórico será preservado para manter o registo da operação.</small>
                </x-modal-body>
                <x-modal-footer>
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger flex-fill" id="accountDeleteSubmit">Cancelar</button>
                </x-modal-footer>
            </form>
</x-modal>
@endsection

@push('scripts')
<script>
const accountDeleteModalElement = document.getElementById('accountDeleteModal');
const accountDetailsElement = document.getElementById('accountMovementDetailsModal');
if (accountDeleteModalElement && accountDetailsElement) {
    const accountDeleteModal = bootstrap.Modal.getOrCreateInstance(accountDeleteModalElement);
    const accountDetailsModal = bootstrap.Modal.getOrCreateInstance(accountDetailsElement);
    const accountDeleteForm = document.getElementById('accountDeleteForm');
    const accountDeleteSubmit = document.getElementById('accountDeleteSubmit');
    let activeAccountMovement = null;
    let returnToAccountDetails = false;

    const detailsContent = document.getElementById('accountMovementDetailsContent');
    const editForm = document.getElementById('accountMovementEditForm');
    const editButton = document.getElementById('accountMovementDetailsEdit');
    const editFeedback = document.getElementById('accountMovementEditFeedback');
    const editSubmit = document.getElementById('accountMovementEditSubmit');

    const showMovementDetails = () => {
        detailsContent.classList.remove('d-none');
        editForm.classList.add('d-none');
        editFeedback.textContent = '';
        editFeedback.className = 'small mb-3';
    };

    const openAccountMovementDetails = (row, showModal = false) => {
        activeAccountMovement = row;
        document.getElementById('accountMovementDetailsName').textContent = row.dataset.description;
        document.getElementById('accountMovementDetailsCategory').textContent = row.dataset.category;
        document.getElementById('accountMovementDetailsAmount').textContent = row.dataset.signedAmount;
        document.getElementById('accountMovementDetailsAmount').className = 'fs-3 d-block mb-1 ' + row.dataset.amountClass;
        document.getElementById('accountMovementDetailsDate').textContent = row.dataset.date;
        document.getElementById('accountMovementDetailsKind').textContent = row.dataset.kind;
        const counterpartRow = document.getElementById('accountMovementDetailsRelatedRow');
        counterpartRow.hidden = !row.dataset.counterpart;
        document.getElementById('accountMovementDetailsRelated').textContent = row.dataset.counterpart;
        document.getElementById('accountDeleteForm').action = row.dataset.deleteUrl;
        editButton.hidden = row.dataset.editable !== '1';
        document.getElementById('accountMovementDetailsDelete').hidden = row.dataset.cancelable !== '1';
        const actionNote = document.getElementById('accountMovementDetailsActionNote');
        actionNote.textContent = row.dataset.actionNote || '';
        actionNote.hidden = !row.dataset.actionNote;
        showMovementDetails();
        if (showModal) accountDetailsModal.show();
    };

    // Bootstrap abre o modal pelo data-bs-toggle; este listener apenas carrega os dados.
    document.querySelectorAll('[data-account-movement]').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            openAccountMovementDetails(row);
        });
        row.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key)) return;
            event.preventDefault();
            openAccountMovementDetails(row, true);
        });
    });

    const enterMovementEditMode = () => {
        if (!activeAccountMovement) return;
        detailsContent.classList.add('d-none');
        editForm.classList.remove('d-none');
        editForm.action = activeAccountMovement.dataset.editUrl;
        document.getElementById('accountMovementEditDescription').value = activeAccountMovement.dataset.description;
        document.getElementById('accountMovementEditAmount').value = activeAccountMovement.dataset.amount;
        document.getElementById('accountMovementEditDate').value = activeAccountMovement.dataset.dateIso;
        document.getElementById('accountMovementEditNotes').value = activeAccountMovement.dataset.notes;
        const isInvoicePayment = activeAccountMovement.dataset.invoicePayment === '1';
        const movementType = activeAccountMovement.dataset.movementType;
        const isTransfer = movementType === 'transfer' && !isInvoicePayment;
        const isAdjustment = movementType === 'adjustment';
        const accountField = document.getElementById('accountMovementEditAccountField');
        const destinationField = document.getElementById('accountMovementEditDestinationField');
        const directionField = document.getElementById('accountMovementEditDirectionField');
        accountField.hidden = !(isInvoicePayment || isTransfer || (isAdjustment && activeAccountMovement.dataset.adjustmentDirection === 'decrease'));
        destinationField.hidden = !(isTransfer || (isAdjustment && activeAccountMovement.dataset.adjustmentDirection === 'increase'));
        directionField.hidden = !isAdjustment;
        document.getElementById('accountMovementEditAccount').disabled = !(isInvoicePayment || isTransfer || isAdjustment);
        document.getElementById('accountMovementEditDestination').disabled = !(isTransfer || isAdjustment);
        document.getElementById('accountMovementEditDirection').disabled = !isAdjustment;
        document.getElementById('accountMovementEditAccount').value = activeAccountMovement.dataset.sourceAccountId || '';
        document.getElementById('accountMovementEditDestination').value = activeAccountMovement.dataset.destinationAccountId || '';
        document.getElementById('accountMovementEditDirection').value = activeAccountMovement.dataset.adjustmentDirection || 'increase';
        editFeedback.textContent = '';
        editFeedback.className = 'small mb-3';
    };

    document.getElementById('accountMovementEditDirection').addEventListener('change', (event) => {
        if (!activeAccountMovement || activeAccountMovement.dataset.movementType !== 'adjustment') return;
        const decrease = event.target.value === 'decrease';
        document.getElementById('accountMovementEditAccountField').hidden = !decrease;
        document.getElementById('accountMovementEditDestinationField').hidden = decrease;
    });

    editButton.addEventListener('click', enterMovementEditMode);
    document.getElementById('accountMovementEditBack').addEventListener('click', showMovementDetails);
    document.getElementById('accountMovementEditCancel').addEventListener('click', showMovementDetails);

    editForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeAccountMovement) return;
        editSubmit.disabled = true;
        editSubmit.textContent = 'Aguardar';
        editFeedback.textContent = '';
        try {
            const payload = Object.fromEntries(new FormData(editForm).entries());
            const response = await fetch(editForm.action, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) {
                const errors = Object.values(result.errors || {}).flat();
                throw new Error(errors[0] || result.message || 'Não foi possível guardar as alterações.');
            }
            window.location.reload();
        } catch (error) {
            editFeedback.className = 'small mb-3 text-danger';
            editFeedback.textContent = error.message;
        } finally {
            editSubmit.disabled = false;
            editSubmit.textContent = 'Guardar';
        }
    });

    document.getElementById('accountMovementDetailsDelete').addEventListener('click', () => {
        if (!activeAccountMovement) return;
        returnToAccountDetails = true;
        accountDetailsModal.hide();
    });

    accountDetailsElement.addEventListener('hidden.bs.modal', () => {
        if (!returnToAccountDetails || !activeAccountMovement) return;
        accountDeleteSubmit.disabled = false;
        accountDeleteSubmit.textContent = 'Cancelar';
        document.getElementById('accountDeleteDescription').textContent = 'Este movimento será cancelado e os saldos relacionados serão atualizados.';
        document.getElementById('accountDeleteHint').textContent = 'O histórico será preservado para manter o registo da operação.';
        document.getElementById('accountDeleteHint').hidden = false;
        accountDeleteModal.show();
    });

    accountDeleteModalElement.addEventListener('hidden.bs.modal', () => {
        if (!returnToAccountDetails || !activeAccountMovement) return;
        returnToAccountDetails = false;
        accountDetailsModal.show();
    });

    accountDeleteForm.addEventListener('submit', () => {
        returnToAccountDetails = false;
        accountDeleteSubmit.disabled = true;
        accountDeleteSubmit.textContent = 'Aguardar';
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
.account-history-page { width: 100%; min-width: 0; }
.account-history-summary .account-history-primary-value {
    display: block;
    margin-top: .2rem;
    font-size: clamp(1.75rem, 5vw, 2.1rem);
    font-weight: 800;
    letter-spacing: -.04em;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.account-history-summary .row > div + div {
    border-left: 1px solid var(--mb-border);
}
.account-movement-item { cursor: pointer; }
.account-movement-item:hover { background: var(--bs-tertiary-bg); }
.account-movement-item:focus-visible {
    position: relative;
    z-index: 1;
    outline: 2px solid var(--mb-primary);
    outline-offset: -2px;
}
.account-movement-icon { width: 2.25rem; height: 2.25rem; }
.account-movement-description { overflow-wrap: anywhere; }
.account-movement-actions { max-width: 48%; }
.account-movement-item .movement-value { font-size: .9rem; }
.account-history-card .pagination { margin-bottom: 0; }
.account-history-card .page-link {
    background: var(--bs-body-bg);
    border-color: var(--bs-border-color);
    color: var(--bs-body-color);
}
.account-history-card .page-item.active .page-link {
    background: var(--bs-primary);
    border-color: var(--bs-primary);
    color: var(--bs-white);
}
.account-history-card .page-item.disabled .page-link {
    background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
}
@media (max-width: 575.98px) {
    .account-history-summary .row > div + div { border-left: 0; }
    .account-history-summary .row > div:not(:first-child) { border-left: 1px solid var(--mb-border); }
}
</style>
@endpush
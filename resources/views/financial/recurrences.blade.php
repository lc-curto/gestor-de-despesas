@extends('layouts.app')

@section('title', 'Recorrências')

@section('content')
@php
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $symbol = $symbols[$currency] ?? $currency;
    $frequencyLabels = ['daily' => ['dia', 'dias'], 'weekly' => ['semana', 'semanas'], 'monthly' => ['mês', 'meses'], 'yearly' => ['ano', 'anos'], 'custom' => ['dia', 'dias']];
@endphp

<header class="dashboard-intro mb-4">
    <div class="dashboard-intro-copy">
        <h1 class="h2 fw-bold">Recorrências</h1>
        <p class="text-secondary mb-0">Planeia receitas e despesas que se repetem. As previsões não alteram o saldo até serem liquidadas.</p>
    </div>
</header>

<div class="alert alert-info rounded-4 mb-4">
    <strong>Como funciona:</strong> são geradas previsões para os próximos 90 dias. Confirma uma previsão associando uma conta; quando ocorrer, liquida-a. Podes reagendar ou anular qualquer ocorrência. As alterações ficam no histórico.
</div>
@if ($accounts->isEmpty())
    <div class="alert alert-warning rounded-4">Para confirmar ou liquidar previsões, <a href="{{ route('financial.accounts.page') }}">cria e configura uma conta</a>.</div>
@endif

<div id="recurrenceFeedback" class="alert d-none" role="status" aria-live="polite"></div>

<section class="row g-4 align-items-start">
    <div class="col-12 col-xl-4">
        <article class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3">Nova regra recorrente</h2>
                <form id="recurrenceForm" class="vstack gap-3">
                    <div>
                        <label class="form-label" for="ruleType">Tipo</label>
                        <select class="form-select" id="ruleType" name="type" required>
                            <option value="expense">Despesa</option>
                            <option value="income">Receita</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="ruleDescription">Descrição</label>
                        <input class="form-control" id="ruleDescription" name="description" maxlength="255" placeholder="Ex.: Renda, salário, internet" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="ruleAmount">Valor</label>
                            <div class="input-group"><input class="form-control" id="ruleAmount" name="amount" type="number" min="0.01" step="0.01" required><span class="input-group-text">{{ $symbol }}</span></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="ruleCategory">Categoria</label>
                            <select class="form-select" id="ruleCategory" name="category_id" required>
                                <option value="">Selecionar</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" data-type="{{ $category->type }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="ruleAccount">Conta predefinida <span class="text-secondary">(opcional)</span></label>
                        <select class="form-select" id="ruleAccount" name="account_id">
                            <option value="">Escolher ao confirmar</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" data-type="{{ $account->type }}">{{ $account->name }} · {{ $account->currency }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="ruleInterval">Repetir a cada</label>
                            <input class="form-control" id="ruleInterval" name="interval_value" type="number" min="1" max="365" value="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="ruleFrequency">Frequência</label>
                            <select class="form-select" id="ruleFrequency" name="frequency" required>
                                <option value="daily">Dia(s)</option>
                                <option value="weekly">Semana(s)</option>
                                <option value="monthly" selected>Mês(es)</option>
                                <option value="yearly">Ano(s)</option>
                                <option value="custom">Dias personalizados</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label" for="ruleStart">Primeira ocorrência</label><input class="form-control" id="ruleStart" name="start_date" type="date" value="{{ today()->toDateString() }}" required></div>
                        <div class="col-6"><label class="form-label" for="ruleEnd">Termina em <span class="text-secondary">(opcional)</span></label><input class="form-control" id="ruleEnd" name="ends_at" type="date"></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Criar regra e gerar previsões</button>
                </form>
            </div>
        </article>
    </div>

    <div class="col-12 col-xl-8">
        <article class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="h5 fw-bold mb-0">Regras</h2>
                    <span class="small text-secondary">{{ $recurrences->where('is_active', true)->count() }} ativas</span>
                </div>
                @forelse ($recurrences as $rule)
                    @php
                        $nextForecast = $rule->movimentos->first();
                        $unit = $frequencyLabels[$rule->frequency] ?? [$rule->frequency, $rule->frequency];
                        $frequencyText = $rule->interval_value . ' ' . $unit[$rule->interval_value === 1 ? 0 : 1];
                    @endphp
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 py-3 border-top">
                        <div class="min-w-0">
                            <div class="fw-semibold">{{ $rule->description }} <span class="badge {{ $rule->type === 'expense' ? 'text-bg-danger' : 'text-bg-success' }}">{{ $rule->type === 'expense' ? 'Despesa' : 'Receita' }}</span></div>
                            <div class="small text-secondary">{{ number_format((float) $rule->amount, 2, ',', '.') }} {{ $symbol }} · a cada {{ $frequencyText }} · {{ $rule->categoria?->name ?? 'Sem categoria' }}</div>
                            <div class="small text-secondary">Próxima previsão: {{ $nextForecast?->movement_date?->format('d/m/Y') ?? 'por gerar' }}{{ $rule->conta ? ' · ' . $rule->conta->name : '' }}</div>
                        </div>
                        <button type="button" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-secondary' : 'btn-outline-primary' }} rule-status-button" data-id="{{ $rule->id }}" data-active="{{ $rule->is_active ? '1' : '0' }}">{{ $rule->is_active ? 'Pausar' : 'Retomar' }}</button>
                    </div>
                @empty
                    <div class="text-center py-4 text-secondary">Ainda não existem regras recorrentes.</div>
                @endforelse
            </div>
        </article>

        <article class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h5 fw-bold mb-0">Previsões e histórico</h2>
                    <span class="small text-secondary">{{ $occurrences->total() }} ocorrências</span>
                </div>
                <p class="small text-secondary mb-3">As ocorrências futuras e passadas ficam ligadas à regra original. As previsões pendentes não alteram saldos.</p>
                @forelse ($occurrences as $event)
                    @php
                        $isExpense = $event->type === 'expense';
                        $isOpen = $event->status === 'pending';
                        $confirmed = $isOpen && !$event->requires_account_assignment;
                        $eligibleAccounts = $accounts->filter(fn ($account) => $isExpense || $account->type !== 'credit_card');
                        $selectedAccount = $event->source_account_id ?: $event->destination_account_id ?: $event->recorrencia?->account_id;
                        $eventHistory = $event->historico->sortByDesc('created_at');
                        $stateLabel = $event->status === 'settled' ? 'Liquidada' : ($event->status === 'void' ? 'Anulada' : ($confirmed ? 'Confirmada' : 'Por confirmar'));
                    @endphp
                    <div class="border-top py-3" data-occurrence-id="{{ $event->id }}">
                        <div class="d-flex flex-wrap justify-content-between gap-3">
                            <div class="min-w-0">
                                <div class="fw-semibold text-break">{{ $event->description }} <span class="badge {{ $event->status === 'settled' ? 'text-bg-success' : ($event->status === 'void' ? 'text-bg-secondary' : 'text-bg-warning') }}">{{ $stateLabel }}</span></div>
                                <div class="small text-secondary">{{ $event->movement_date->format('d/m/Y') }} · {{ $event->recorrencia?->categoria?->name ?? $event->categoria?->name ?? 'Sem categoria' }} · {{ $event->recorrencia?->description ?? 'Regra removida' }}</div>
                            </div>
                            <strong class="{{ $isExpense ? 'text-danger' : 'text-success' }} text-nowrap">{{ $isExpense ? '−' : '+' }}{{ number_format((float) $event->amount, 2, ',', '.') }} {{ $symbol }}</strong>
                        </div>
                        @if ($isOpen)
                            <div class="row g-2 align-items-end mt-2">
                                <div class="col-12 col-md-5">
                                    <label class="form-label small mb-1" for="account-{{ $event->id }}">{{ $confirmed ? 'Conta associada' : 'Conta para confirmar' }}</label>
                                    <select class="form-select form-select-sm occurrence-account" id="account-{{ $event->id }}">
                                        <option value="">Selecionar conta</option>
                                        @foreach ($eligibleAccounts as $account)
                                            <option value="{{ $account->id }}" @selected($selectedAccount === $account->id)>{{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-auto">
                                    @if ($confirmed && $event->movement_date->lte(today()))
                                        <button type="button" class="btn btn-sm btn-success settle-occurrence" data-id="{{ $event->id }}" data-type="{{ $event->type }}">Liquidar</button>
                                        <button type="button" class="btn btn-sm btn-outline-primary confirm-occurrence" data-id="{{ $event->id }}" data-type="{{ $event->type }}">Atualizar conta</button>
                                    @elseif (!$confirmed)
                                        <button type="button" class="btn btn-sm btn-primary confirm-occurrence" data-id="{{ $event->id }}" data-type="{{ $event->type }}">Confirmar previsão</button>
                                    @else
                                        <button type="button" class="btn btn-sm btn-outline-primary confirm-occurrence" data-id="{{ $event->id }}" data-type="{{ $event->type }}">Atualizar conta</button>
                                        <span class="small text-secondary">Disponível para liquidação na data prevista.</span>
                                    @endif
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-1" for="date-{{ $event->id }}">Reagendar</label>
                                    <input class="form-control form-control-sm occurrence-date" id="date-{{ $event->id }}" type="date" value="{{ $event->movement_date->toDateString() }}">
                                </div>
                                <div class="col-6 col-md-auto d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary reschedule-occurrence" data-id="{{ $event->id }}">Guardar data</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger void-occurrence" data-id="{{ $event->id }}">Anular</button>
                                </div>
                            </div>
                        @endif
                        @if ($eventHistory->isNotEmpty())
                            <details class="mt-2 small">
                                <summary class="text-secondary">Histórico de alterações ({{ $eventHistory->count() }})</summary>
                                <ul class="mt-2 mb-0">
                                    @foreach ($eventHistory as $entry)
                                        <li>{{ ['created_pending' => 'Previsão gerada', 'confirmed' => 'Previsão confirmada', 'settled' => 'Movimento liquidado', 'updated' => 'Movimento reagendado/alterado', 'voided' => 'Previsão anulada'][$entry->action] ?? $entry->action }} · {{ $entry->created_at->format('d/m/Y H:i') }}{{ $entry->reason ? ' · ' . $entry->reason : '' }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-4 text-secondary">As previsões geradas pelas regras aparecerão aqui.</div>
                @endforelse
                <div class="mt-3">{{ $occurrences->links() }}</div>
            </div>
        </article>
    </div>
</section>
@endsection

@push('scripts')
<script>
(() => {
    const root = '/api/financial';
    const feedback = document.getElementById('recurrenceFeedback');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const showMessage = (message, ok = true) => {
        feedback.textContent = message;
        feedback.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`;
        feedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    const request = async (url, method, payload) => {
        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: payload ? JSON.stringify(payload) : undefined
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat().join(' ') || 'Não foi possível concluir a operação.');
        return result;
    };
    const accountPayload = (button) => {
        const row = button.closest('[data-occurrence-id]');
        const accountId = row.querySelector('.occurrence-account')?.value;
        if (!accountId) throw new Error('Seleciona uma conta.');
        return button.dataset.type === 'expense' ? { source_account_id: accountId } : { destination_account_id: accountId };
    };

    const syncFormOptions = () => {
        const categoryType = document.getElementById('ruleType').value === 'expense' ? 'despesa' : 'receita';
        const category = document.getElementById('ruleCategory');
        [...category.options].forEach((option) => {
            if (!option.value) return;
            option.hidden = option.dataset.type !== categoryType;
            if (option.selected && option.hidden) category.value = '';
        });
        const accountType = document.getElementById('ruleType').value;
        const account = document.getElementById('ruleAccount');
        [...account.options].forEach((option) => {
            if (!option.value) return;
            option.hidden = accountType === 'income' && option.dataset.type === 'credit_card';
            if (option.selected && option.hidden) account.value = '';
        });
    };
    document.getElementById('ruleType').addEventListener('change', syncFormOptions);
    syncFormOptions();

    document.getElementById('recurrenceForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.interval_value = Number(payload.interval_value);
        payload.account_id ||= null;
        payload.ends_at ||= null;
        try {
            const result = await request(`${root}/recurrences`, 'POST', payload);
            showMessage(result.message || 'Regra criada.');
            setTimeout(() => location.reload(), 500);
        } catch (error) { showMessage(error.message, false); }
    });

    document.querySelectorAll('.rule-status-button').forEach((button) => button.addEventListener('click', async () => {
        try {
            const active = button.dataset.active !== '1';
            const result = await request(`${root}/recurrences/${button.dataset.id}/status`, 'PATCH', { is_active: active });
            showMessage(result.message || 'Estado atualizado.');
            setTimeout(() => location.reload(), 400);
        } catch (error) { showMessage(error.message, false); }
    }));

    document.querySelectorAll('.confirm-occurrence').forEach((button) => button.addEventListener('click', async () => {
        try {
            await request(`${root}/movements/${button.dataset.id}/confirm`, 'PUT', accountPayload(button));
            showMessage('Previsão confirmada; o saldo só muda quando for liquidada.');
            setTimeout(() => location.reload(), 400);
        } catch (error) { showMessage(error.message, false); }
    }));

    document.querySelectorAll('.settle-occurrence').forEach((button) => button.addEventListener('click', async () => {
        try {
            await request(`${root}/movements/${button.dataset.id}/settle`, 'PUT', accountPayload(button));
            showMessage('Movimento liquidado.');
            setTimeout(() => location.reload(), 400);
        } catch (error) { showMessage(error.message, false); }
    }));

    document.querySelectorAll('.reschedule-occurrence').forEach((button) => button.addEventListener('click', async () => {
        try {
            const row = button.closest('[data-occurrence-id]');
            const date = row.querySelector('.occurrence-date').value;
            if (!date) throw new Error('Escolhe uma nova data.');
            await request(`${root}/movements/${button.dataset.id}`, 'PUT', { movement_date: date });
            showMessage('Previsão reagendada e registada no histórico.');
            setTimeout(() => location.reload(), 400);
        } catch (error) { showMessage(error.message, false); }
    }));

    document.querySelectorAll('.void-occurrence').forEach((button) => button.addEventListener('click', async () => {
        if (!confirm('Anular esta ocorrência? A regra recorrente mantém-se.')) return;
        try {
            await request(`${root}/movements/${button.dataset.id}/void`, 'PUT', { reason: 'Anulada pelo utilizador na página de recorrências.' });
            showMessage('Previsão anulada e registada no histórico.');
            setTimeout(() => location.reload(), 400);
        } catch (error) { showMessage(error.message, false); }
    }));
})();
</script>
@endpush

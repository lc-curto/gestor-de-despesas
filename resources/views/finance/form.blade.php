@extends('layouts.app')

@section('title', $registo ? 'Editar registo' : 'Novo registo')

@section('content')
@php
    $lockedFinancial = $financialMovement?->status === 'settled';
    $selectedAccountId = $financialMovement
        ? ($financialMovement->source_account_id ?: $financialMovement->destination_account_id)
        : null;
@endphp
<header class="page-heading mb-4">
    <a class="small text-decoration-none" href="{{ $returnUrl }}"><i data-lucide="arrow-left" class="me-1"></i> Voltar</a>
    <p class="eyebrow mt-3 mb-1">Movimentos financeiros</p>
    <h1 class="h2 fw-bold mb-1">{{ $registo ? 'Editar' : 'Novo' }} {{ $tipo }}</h1>
    
</header>
@if ($errors->any())
    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if ($lockedFinancial)
    <div class="alert alert-info" role="status">Este movimento já está liquidado. Os dados financeiros não podem ser alterados aqui; para corrigir um valor ou uma data, usa o estorno e regista o movimento correto.</div>
@endif
<form method="POST" action="{{ $registo ? route($tipo === 'despesa' ? 'despesas.update' : 'receitas.update', $registo->id) : route($tipo === 'despesa' ? 'despesas.store' : 'receitas.store') }}" class="card border-0 p-3 p-lg-4">
    @csrf
    @if ($registo) @method('PUT') @endif
    @if (request('return_to')) <input type="hidden" name="return_to" value="{{ request('return_to') }}"> @endif
    @if (request()->boolean('card_only')) <input type="hidden" name="card_only" value="1"> @endif
    <div class="mb-3">
        <label for="description" class="form-label">Descrição</label>
        <input id="description" name="description" class="form-control" maxlength="255" value="{{ old('description', $registo?->description) }}" required>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="amount" class="form-label">Valor</label>
        <input id="amount" name="amount" type="number" min="0.01" step="0.01" class="form-control" value="{{ old('amount', $registo?->amount) }}" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Moeda da conta</label>
            <input class="form-control" value="{{ auth()->user()->currency }}" readonly>
            
        </div>
        <div class="col-md-6">
            <label for="account_id" class="form-label">Conta</label>
            <select id="account_id" name="account_id" class="form-select" required>
                <option value="">Selecionar conta</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected(old('account_id', $selectedAccountId) === $account->id) data-closing="{{ $account->closing_day }}" data-due="{{ $account->due_day }}" data-limit="{{ $account->credit_limit }}">{{ $account->name }} · {{ $account->currency }}</option>
                @endforeach
            </select>
            @if ($tipo === 'despesa')
                
            @endif
            @if ($accounts->isEmpty())
                <div class="form-text text-warning"><a href="{{ route('financial.accounts.page') }}">Criar conta</a></div>
            @endif
        </div>
        <div class="col-md-6">
            <label for="category_id" class="form-label">Categoria</label>
            <select id="category_id" name="category_id" class="form-select" required>
                <option value="">Selecionar</option>
                @foreach ($cats as $id => $label)
                    <option value="{{ $id }}" @selected(old('category_id', $registo?->category_id) === $id)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-text"><a href="{{ route('categorias.index') }}">Gerir categorias</a></div>
        </div>
        @if ($tipo === "despesa")
        <div class="col-12">
            <label class="form-label d-block">Comportamento da despesa</label>
            <div class="d-flex flex-wrap gap-3">
                <label class="form-check"><input class="form-check-input" type="radio" name="expense_behavior" value="variable" @checked(old("expense_behavior", $financialMovement?->expense_behavior ?? "variable") === "variable")> <span class="form-check-label">Variável</span></label>
                <label class="form-check"><input class="form-check-input" type="radio" name="expense_behavior" value="fixed" @checked(old("expense_behavior", $financialMovement?->expense_behavior ?? "variable") === "fixed")> <span class="form-check-label">Fixa</span></label>
            </div>
            
        </div>
        @endif
        <div class="col-md-6">
            <label for="{{ $data }}" class="form-label">Data</label>
            <input id="{{ $data }}" name="{{ $data }}" type="date" class="form-control" value="{{ old($data, $registo?->{$data}?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
        </div>
    </div>
    @if ($tipo === 'despesa' && request()->boolean('card_only'))
    <section class="alert alert-light border mt-3 mb-0" id="cardCycleInfo" aria-live="polite">
        <strong>Faturação do cartão</strong>
        
    </section>
    @endif
    @if ($tipo === 'despesa' && !$registo && !request()->boolean('card_only'))
    <hr class="my-4">
    <section aria-labelledby="paymentConditionsTitle">
        <h2 id="paymentConditionsTitle" class="h6 fw-bold mb-3">Condições de pagamento</h2>
        <div class="d-flex flex-wrap gap-3 mb-3">
            <label class="form-check"><input class="form-check-input" type="radio" name="payment_condition" value="cash" checked> <span class="form-check-label">A pronto</span></label>
            <label class="form-check"><input class="form-check-input" type="radio" name="payment_condition" value="deferred"> <span class="form-check-label">A prazo</span></label>
            <label class="form-check"><input class="form-check-input" type="radio" name="payment_condition" value="installments"> <span class="form-check-label">Parcelado</span></label>
        </div>
        <div id="commitmentFields" class="row g-3" hidden>
            <div class="col-md-6"><label for="payment_method" class="form-label">Método de pagamento</label><select id="payment_method" name="payment_method" class="form-select"><option value="">Selecionar</option><option value="cash">Dinheiro</option><option value="card">Cartão</option><option value="transfer">Transferência</option><option value="mbway">MB WAY</option><option value="other">Outro</option></select></div>
            <div class="col-md-6" id="installmentCountField" hidden><label for="installments_count" class="form-label">Número de parcelas</label><input id="installments_count" name="installments_count" type="number" min="2" max="120" class="form-control" value="2"></div>
            <div class="col-md-6"><label for="due_date" class="form-label">Primeiro vencimento</label><input id="due_date" name="due_date" type="date" class="form-control" value="{{ now()->toDateString() }}"></div>
        </div>
        <div id="effectivePaymentFields" class="mt-4" hidden>
            <label class="form-label d-block">Já foi pago?</label>
            <div class="d-flex gap-3 mb-3"><label class="form-check"><input class="form-check-input" type="radio" name="paid_now" value="1"> <span class="form-check-label">Sim</span></label><label class="form-check"><input class="form-check-input" type="radio" name="paid_now" value="0" checked> <span class="form-check-label">Ainda não</span></label></div>
            <div id="effectivePaymentInputs" class="row g-3" hidden><div class="col-md-6"><label for="payment_amount" class="form-label">Valor pago</label><input id="payment_amount" name="payment_amount" type="number" min="0.01" step="0.01" class="form-control"></div><div class="col-md-6"><label for="payment_date" class="form-label">Data do pagamento</label><input id="payment_date" name="payment_date" type="date" class="form-control" value="{{ now()->toDateString() }}"></div><div class="col-md-6"><label for="payment_account_id" class="form-label">Conta do pagamento</label><select id="payment_account_id" name="payment_account_id" class="form-select"><option value="">Selecionar conta</option>@foreach ($accounts->whereIn('type', ['bank', 'cash'])->where('is_operational', true)->where('status', 'active') as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $account->currency }}</option>@endforeach</select>@if ($accounts->whereIn('type', ['bank', 'cash'])->where('is_operational', true)->where('status', 'active')->isEmpty())<div class="form-text text-warning">Cria uma conta ativa.</div>@endif</div></div>
        </div>
    </section>
    @endif
    @if (!$registo)
    <hr class="my-4">
    <section aria-labelledby="recurrenceTitle">
        <h2 id="recurrenceTitle" class="h6 fw-bold mb-3">Repetição</h2>
        <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="recurrence_enabled" value="1" id="recurrence_enabled" @checked(old('recurrence_enabled'))> <span class="form-check-label">Repetir esta {{ $tipo }}</span></label>
        <div id="recurrenceFields" class="row g-3" hidden>
            <div class="col-md-4"><label for="recurrence_interval" class="form-label">Repetir a cada</label><input id="recurrence_interval" name="recurrence_interval" type="number" min="1" max="365" class="form-control" value="{{ old('recurrence_interval', 1) }}"></div>
            <div class="col-md-4"><label for="recurrence_frequency" class="form-label">Frequência</label><select id="recurrence_frequency" name="recurrence_frequency" class="form-select"><option value="daily">Dia(s)</option><option value="weekly">Semana(s)</option><option value="monthly" selected>Mês(es)</option><option value="yearly">Ano(s)</option><option value="custom">Dias personalizados</option></select></div>
            <div class="col-md-4"><label for="recurrence_ends_at" class="form-label">Termina em <span class="text-secondary">(opcional)</span></label><input id="recurrence_ends_at" name="recurrence_ends_at" type="date" class="form-control" value="{{ old('recurrence_ends_at') }}"></div>
        </div>
        
    </section>
    @endif
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary" @disabled($accounts->isEmpty())>Guardar</button>
        <a class="btn btn-outline-secondary" href="{{ $returnUrl }}">Cancelar</a>
    </div>
</form>
@endsection
@if (!$registo)
@push('scripts')
<script>document.addEventListener('DOMContentLoaded',()=>{const c=document.getElementById('recurrence_enabled'),f=document.getElementById('recurrenceFields');if(c&&f){const sync=()=>f.hidden=!c.checked;c.addEventListener('change',sync);sync();}});</script>
@endpush
@endif
@if ($tipo === 'despesa' && !$registo && !request()->boolean('card_only'))
@push('scripts')
<script>
(() => {
    const form = document.querySelector('form[action="{{ route('despesas.store') }}"]');
    if (!form) return;

    const condition = () => form.querySelector('[name="payment_condition"]:checked')?.value || 'cash';
    const commitmentFields = document.getElementById('commitmentFields');
    const effectiveFields = document.getElementById('effectivePaymentFields');
    const paymentInputs = document.getElementById('effectivePaymentInputs');
    const paymentAmount = document.getElementById('payment_amount');
    const paymentAccount = document.getElementById('payment_account_id');
    const account = document.getElementById('account_id');
    const installmentCount = document.getElementById('installments_count');
    const submitButton = form.querySelector('button[type="submit"], button:not([type])');
    let submitting = false;

    const refresh = () => {
        const cash = condition() === 'cash';
        const paid = form.querySelector('[name="paid_now"]:checked')?.value === '1';
        commitmentFields.hidden = cash;
        effectiveFields.hidden = cash;
        paymentInputs.hidden = cash || !paid;
        document.getElementById('installmentCountField').hidden = condition() !== 'installments';
        account.required = cash;
        paymentAmount.required = !cash && paid;
        paymentAccount.required = !cash && paid;
        if (installmentCount && condition() === 'installments') {
            const total = Number(form.querySelector('[name="amount"]').value || 0);
            const count = Number(installmentCount.value || 1);
            if (total > 0 && count > 0) {
                paymentAmount.max = (Math.ceil((total * 100) / count) / 100).toFixed(2);
            }
        } else {
            paymentAmount.removeAttribute('max');
        }
    };

    form.querySelectorAll('[name="payment_condition"], [name="paid_now"]').forEach(input => input.addEventListener('change', refresh));
    form.querySelector('[name="amount"]').addEventListener('input', refresh);
    installmentCount?.addEventListener('input', refresh);
    refresh();

    form.addEventListener('submit', async event => {
        if (condition() === 'cash') return;
        event.preventDefault();
        if (submitting) return;
        submitting = true;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = 'A guardar…';
        }

        try {
            const data = Object.fromEntries(new FormData(form).entries());
            const body = {
                name: data.description,
                total_amount: data.amount,
                start_date: data.expense_date,
                due_date: data.due_date,
                payment_condition: data.payment_condition,
                payment_method: data.payment_method,
                installments_count: data.installments_count,
            };
            if (data.paid_now === '1') {
                body.initial_payment = {
                    account_id: data.payment_account_id,
                    amount: data.payment_amount,
                    payment_date: data.payment_date,
                };
            }

            const response = await fetch('{{ route('financial.commitments.store') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(body),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const messages = result.errors ? Object.values(result.errors).flat().join('\n') : result.message;
                alert(messages || 'Não foi possível guardar a obrigação e o pagamento. Verifica os dados e tenta novamente.');
                return;
            }

            if (form.dataset.modal === '1') {
                window.location.reload();
            } else {
                window.location.href = '{{ route('despesas.index') }}';
            }
        } catch (error) {
            alert('Não foi possível confirmar a gravação. Verifica a ligação e o estado dos dados antes de voltares a tentar.');
        } finally {
            submitting = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText || 'Guardar';
            }
        }
    });
})();
</script>
@endpush
@endif
@if ($tipo === 'despesa' && request()->boolean('card_only'))
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('account_id');
    const info = document.getElementById('cardCycleDescription');
    const sync = () => {
        const option = select?.selectedOptions[0];
        if (!option || !info) return;
        const closing = option.dataset.closing;
        const due = option.dataset.due;
        info.textContent = closing && due
            ? 'Fecho no dia ' + closing + ' · Vencimento no dia ' + due + '. As compras recorrentes serão lançadas em cada fatura correspondente.'
            : 'Configura os dias de fecho e vencimento deste cartão antes de guardar.';
    };
    select?.addEventListener('change', sync);
    sync();
});
</script>
@endpush
@endif
@if (request()->boolean('modal'))
@push('styles')
<style>
    .app-sidebar,.sidebar-toggle,.app-sidebar-footer,.site-footer{display:none!important}
    .app-main{margin-left:0!important;padding:1rem!important;min-height:0!important}
    .app-content{max-width:none!important}
    .page-heading{display:none}
    .app-shell{min-height:0}
</style>
@endpush
@endif

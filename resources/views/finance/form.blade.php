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
    <p class="text-secondary mb-0">{{ $registo ? 'Atualiza os dados deste movimento.' : 'Regista um novo movimento financeiro.' }}</p>
</header>
@if ($errors->any())
    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<form method="POST" action="{{ $registo ? route($tipo === 'despesa' ? 'despesas.update' : 'receitas.update', $registo->id) : route($tipo === 'despesa' ? 'despesas.store' : 'receitas.store') }}" class="card border-0 p-3 p-lg-4">
    @csrf
    @if ($registo) @method('PUT') @endif
    @if (request('return_to')) <input type="hidden" name="return_to" value="{{ request('return_to') }}"> @endif
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
            <div class="form-text">Todos os movimentos utilizam a moeda definida na conta.</div>
        </div>
        <div class="col-md-6">
            <label for="account_id" class="form-label">Conta</label>
            <select id="account_id" name="account_id" class="form-select" required>
                <option value="">Selecionar conta</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected(old('account_id', $selectedAccountId) === $account->id)>{{ $account->name }} · {{ $account->currency }}</option>
                @endforeach
            </select>
            @if ($tipo === 'despesa')
                <div class="form-text">Se escolheres um cartão, esta despesa entra automaticamente na fatura do cartão.</div>
            @endif
            @if ($accounts->isEmpty())
                <div class="form-text text-warning">Cria uma conta ativa e configura o saldo inicial em <a href="{{ route('financial.accounts.page') }}">Contas</a> antes de registar movimentos.</div>
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
        <div class="col-md-6">
            <label for="{{ $data }}" class="form-label">Data</label>
            <input id="{{ $data }}" name="{{ $data }}" type="date" class="form-control" value="{{ old($data, $registo?->{$data}?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
        </div>
    </div>
    @if ($tipo === 'despesa' && !$registo)
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
            <div id="effectivePaymentInputs" class="row g-3" hidden><div class="col-md-6"><label for="payment_amount" class="form-label">Valor pago</label><input id="payment_amount" name="payment_amount" type="number" min="0.01" step="0.01" class="form-control"></div><div class="col-md-6"><label for="payment_date" class="form-label">Data do pagamento</label><input id="payment_date" name="payment_date" type="date" class="form-control" value="{{ now()->toDateString() }}"></div><div class="col-md-6"><label for="payment_account_id" class="form-label">Conta do pagamento</label><select id="payment_account_id" name="payment_account_id" class="form-select"><option value="">Selecionar conta</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $account->currency }}</option>@endforeach</select></div></div>
        </div>
    </section>
    @endif
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary" @disabled($accounts->isEmpty())>Guardar</button>
        <a class="btn btn-outline-secondary" href="{{ $returnUrl }}">Cancelar</a>
    </div>
</form>
@endsection
@if ($tipo === 'despesa' && !$registo)
@push('scripts')
<script>
(() => { const form=document.querySelector('form[action="{{ route('despesas.store') }}"]'); if(!form)return; const get=()=>form.querySelector('[name="payment_condition"]:checked')?.value||'cash', commitments=document.getElementById('commitmentFields'), effective=document.getElementById('effectivePaymentFields'), inputs=document.getElementById('effectivePaymentInputs'), account=document.getElementById('account_id'); const refresh=()=>{const cash=get()==='cash'; commitments.hidden=cash; effective.hidden=cash; document.getElementById('installmentCountField').hidden=get()!=='installments'; account.required=cash||!inputs.hidden;}; form.querySelectorAll('[name="payment_condition"]').forEach(x=>x.addEventListener('change',refresh)); form.querySelectorAll('[name="paid_now"]').forEach(x=>x.addEventListener('change',()=>{inputs.hidden=x.value!=='1'||!x.checked;refresh()})); refresh(); form.addEventListener('submit',async e=>{if(get()==='cash')return;e.preventDefault();const d=Object.fromEntries(new FormData(form).entries()),t=document.querySelector('meta[name="csrf-token"]').content,r=await fetch('{{ route('financial.commitments.store') }}',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':t},body:JSON.stringify({name:d.description,total_amount:d.amount,start_date:d.expense_date,due_date:d.due_date,payment_condition:d.payment_condition,payment_method:d.payment_method,installments_count:d.installments_count})}),j=await r.json();if(!r.ok){alert(j.message||'Não foi possível criar o compromisso.');return;}if(d.paid_now==='1'){const i=j.data.parcelas[0];await fetch('{{ url('/api/financial/commitments') }}/'+j.data.id+'/installments/'+i.id+'/pay',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':t},body:JSON.stringify({account_id:d.payment_account_id,amount:d.payment_amount||d.amount,payment_date:d.payment_date})});}if(form.dataset.modal==='1'){window.location.reload();return;}window.location.href='{{ route('despesas.index') }}';});})();
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

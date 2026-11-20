@extends('layouts.app')

@section('title', 'Fatura · '.$invoice->reference_month->translatedFormat('F Y'))

@section('content')
@php
    $currency = $card->currency;
    $cycleLabels = ['open' => 'Aberta', 'closed' => 'Fechada'];
    $cycleClasses = ['open' => 'text-bg-primary', 'closed' => 'text-bg-secondary'];
    $progress = (float) $invoice->total_amount > 0 ? min(100, (float) $invoice->paid_amount / (float) $invoice->total_amount * 100) : 100;
    $locked = in_array($invoice->status, ['closed', 'paid', 'carried'], true);
@endphp
<div class="app-content invoice-detail-page">
    <header class="mb-3">
        <a href="{{ route('financial.invoices.page', ['account_id' => $card->id]) }}" class="small text-secondary text-decoration-none">← Faturas</a>
        <div class="d-flex align-items-start justify-content-between gap-2 mt-2">
            <div class="min-w-0">
                <h1 class="h3 fw-bold mb-0 text-capitalize">Fatura de {{ $invoice->reference_month->translatedFormat('F Y') }}</h1>
                <p class="small text-secondary mb-0">{{ $card->name }}</p>
            </div>
            <span class="badge flex-shrink-0 {{ $cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary' }}">{{ $cycleLabels[$invoice->cycle_status] ?? 'Fechada' }}</span>
        </div>
    </header>

    <div id="invoiceFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    <section class="invoice-detail-context" data-invoice-id="{{ $invoice->id }}" data-remaining="{{ $invoice->remaining_amount }}" data-currency="{{ $currency }}">
        <div class="card border rounded-4 mb-3">
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-4"><span class="small text-secondary d-block">Total</span><strong class="fs-5">{{ number_format((float)$invoice->total_amount,2,',','.') }} €</strong></div>
                    <div class="col-4"><span class="small text-secondary d-block">Pago</span><strong class="fs-5 text-success">{{ number_format((float)$invoice->paid_amount,2,',','.') }} €</strong></div>
                    <div class="col-4"><span class="small text-secondary d-block">Em falta</span><strong class="fs-5 {{ (float)$invoice->remaining_amount > 0 ? 'text-warning' : 'text-success' }}">{{ number_format((float)$invoice->remaining_amount,2,',','.') }} €</strong></div>
                </div>
                <div class="progress mt-3" role="progressbar" aria-label="Percentagem paga" aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100" style="height:7px">
                    <div class="progress-bar" style="width:{{ $progress }}%"></div>
                </div>
                <div class="small text-secondary mt-1">{{ number_format($progress,0) }}% pago</div>
                <div class="row g-2 border-top mt-3 pt-3 small">
                    <div class="col-6"><span class="text-secondary d-block">Fecho</span><strong>{{ $invoice->closing_date->format('d/m/Y') }}</strong></div>
                    <div class="col-6"><span class="text-secondary d-block">Vencimento</span><strong>{{ $invoice->due_date->format('d/m/Y') }}</strong></div>
                </div>
                <button type="button" class="btn btn-primary w-100 mt-3 js-invoice-action" data-action="pay" @disabled((float)$invoice->remaining_amount <= 0 || $invoice->status === 'carried')>Pagar fatura</button>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm js-invoice-action" data-action="charge" @disabled($locked)>Adicionar encargo</button>
            <button type="button" class="btn btn-outline-secondary btn-sm js-invoice-action" data-action="credit" @disabled($locked || $invoice->movimentos->isEmpty())>Registar crédito</button>
            @if(! $locked)
                <button type="button" class="btn btn-outline-secondary btn-sm js-invoice-action" data-action="close" @disabled($invoice->closing_date->isFuture())>Fechar fatura</button>
            @endif
        </div>

        <section class="card border rounded-4 mb-3">
            <div class="card-body p-3">
                <h2 class="h6 fw-bold mb-2">Compras</h2>
                @forelse($invoice->movimentos as $movement)
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <span class="min-w-0 text-break">{{ $movement->description }}<span class="d-block small text-secondary">{{ $movement->movement_date->format('d/m/Y') }}</span></span>
                        <strong class="text-nowrap">{{ number_format((float)$movement->amount,2,',','.') }} {{ $currency }}</strong>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Ainda não existem compras nesta fatura.</p>
                @endforelse
                @if($invoice->saldosTransportados->isNotEmpty())
                    @foreach($invoice->saldosTransportados as $carried)
                        <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                            <span>Saldo da fatura {{ $carried->sourceInvoice?->reference_month?->translatedFormat('F Y') ?? 'anterior' }}</span>
                            <strong>{{ number_format((float)$carried->amount,2,',','.') }} {{ $currency }}</strong>
                        </div>
                    @endforeach
                @endif
                @foreach($invoice->creditos as $credit)
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <span>Crédito de compra<span class="d-block small text-secondary">{{ $credit->movement_date?->format('d/m/Y') }}</span></span>
                        <strong class="text-success">−{{ number_format((float)$credit->amount,2,',','.') }} {{ $currency }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card border rounded-4">
            <div class="card-body p-3">
                <h2 class="h6 fw-bold mb-2">Pagamentos</h2>
                @forelse($invoice->pagamentos as $payment)
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <span class="min-w-0">Pagamento<span class="d-block small text-secondary">{{ $payment->movement_date?->format('d/m/Y') }} · {{ $payment->contaOrigem?->name ?? 'Conta' }}</span></span>
                        <strong class="text-success text-nowrap">{{ number_format((float)$payment->amount,2,',','.') }} {{ $currency }}</strong>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Ainda não existem pagamentos.</p>
                @endforelse
            </div>
        </section>
    </section>
</div>

<div class="modal fade" id="invoiceActionModal" tabindex="-1" aria-labelledby="invoiceActionTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow rounded-4">
        <form id="invoiceActionForm">
            <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="invoiceActionTitle">Ação da fatura</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body p-4">
                <p id="invoiceActionContext" class="small text-secondary"></p>
                <div class="mb-3 d-none" data-field="description"><label class="form-label" for="invoiceDescription">Descrição</label><input class="form-control" id="invoiceDescription" maxlength="255"></div>
                <div class="mb-3 d-none" data-field="amount"><label class="form-label" for="invoiceAmount">Valor</label><input class="form-control" id="invoiceAmount" inputmode="decimal" placeholder="0,00"></div>
                <div class="mb-3 d-none" data-field="date"><label class="form-label" for="invoiceDate">Data efetiva</label><input class="form-control" id="invoiceDate" type="date" value="{{ today()->toDateString() }}"></div>
                <div class="mb-3 d-none" data-field="account"><label class="form-label" for="invoiceAccount">Conta de pagamento</label><select class="form-select" id="invoiceAccount"><option value="">Selecionar conta</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                <div class="mb-3 d-none" data-field="allocation">
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="allocationEnabled"><label class="form-check-label" for="allocationEnabled">O banco informou a decomposição</label></div>
                    <div id="allocationFields" class="row g-2 mt-1 d-none">
                        @foreach(['principal'=>'Capital','interest'=>'Juros','fees'=>'Comissões','taxes'=>'Impostos','other'=>'Outros'] as $key=>$label)
                            <div class="col-6"><label class="form-label small" for="allocation_{{ $key }}">{{ $label }}</label><input class="form-control form-control-sm" id="allocation_{{ $key }}" inputmode="decimal" placeholder="0,00"></div>
                        @endforeach
                    </div>
                </div>
                <div class="mb-3 d-none" data-field="chargeType"><label class="form-label" for="invoiceChargeType">Tipo de encargo</label><select class="form-select" id="invoiceChargeType"><option value="interest">Juros</option><option value="late_fee">Multa</option><option value="commission">Comissão</option><option value="tax">Imposto</option><option value="other">Outro</option></select></div>
                <div class="mb-3 d-none" data-field="purchase"><label class="form-label" for="invoicePurchase">Compra a creditar</label><select class="form-select" id="invoicePurchase"></select></div>
                <div class="mb-3 d-none" data-field="statement"><label class="form-label" for="invoiceStatement">Total do extrato</label><input class="form-control" id="invoiceStatement" inputmode="decimal" placeholder="0,00"></div>
                <div class="alert alert-danger d-none mb-0" id="invoiceActionError" role="alert"></div>
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Confirmar</button></div>
        </form>
    </div></div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const modalEl=document.getElementById('invoiceActionModal'),form=document.getElementById('invoiceActionForm');
    if(!modalEl||!form)return;
    const modal=bootstrap.Modal.getOrCreateInstance(modalEl),csrf=document.querySelector('meta[name="csrf-token"]')?.content;
    const context=document.querySelector('.invoice-detail-context');
    let invoiceId=context?.dataset.invoiceId,action=null;
    const fieldMap={pay:['amount','date','account','allocation'],charge:['description','amount','date','chargeType'],credit:['description','amount','date','purchase'],close:['statement']};
    const titleMap={pay:'Pagar fatura',charge:'Adicionar encargo',credit:'Registar crédito',close:'Fechar fatura'};
    const money=v=>{const s=String(v??'').trim().replace(',','.');return /^\d+(\.\d{1,2})?$/.test(s)?s:null;};
    document.querySelectorAll('.js-invoice-action').forEach(button=>button.addEventListener('click',()=>{
        action=button.dataset.action;form.reset();
        document.querySelectorAll('[data-field]').forEach(el=>el.classList.add('d-none'));
        (fieldMap[action]||[]).forEach(key=>document.querySelector('[data-field="'+key+'"]').classList.remove('d-none'));
        document.getElementById('invoiceActionTitle').textContent=titleMap[action];
        document.getElementById('invoiceActionContext').textContent='Em falta: '+context.dataset.remaining+' '+context.dataset.currency;
        document.getElementById('invoiceActionError').classList.add('d-none');
        if(action==='credit'){
            const select=document.getElementById('invoicePurchase');select.replaceChildren();
            fetch('/api/financial/invoices',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.json()).then(result=>{
                const current=(result.data?.data||[]).find(item=>item.id===invoiceId);
                (current?.movimentos||[]).forEach(m=>{const option=document.createElement('option');option.value=m.id;option.textContent=m.description+' · '+m.amount;select.append(option);});
            }).catch(()=>{});
        }
        modal.show();
    }));
    document.getElementById('allocationEnabled').addEventListener('change',e=>document.getElementById('allocationFields').classList.toggle('d-none',!e.target.checked));
    form.addEventListener('submit',async event=>{
        event.preventDefault();const submit=form.querySelector('[type="submit"]');submit.disabled=true;
        const value=id=>document.getElementById(id).value,payload={};let suffix='';
        if(action==='pay'){
            payload.amount=money(value('invoiceAmount'));payload.payment_date=value('invoiceDate');payload.account_id=value('invoiceAccount');suffix='pay';
            if(document.getElementById('allocationEnabled').checked){payload.allocation={};['principal','interest','fees','taxes','other'].forEach(k=>{const v=money(value('allocation_'+k));if(v!==null)payload.allocation[k]=v;});}
        }else if(action==='charge'){
            payload.description=value('invoiceDescription');payload.amount=money(value('invoiceAmount'));payload.movement_date=value('invoiceDate');payload.charge_type=value('invoiceChargeType');suffix='charges';
        }else if(action==='credit'){
            payload.description=value('invoiceDescription')||'Crédito de compra';payload.amount=money(value('invoiceAmount'));payload.movement_date=value('invoiceDate');payload.purchase_movement_id=value('invoicePurchase');suffix='credits';
        }else{payload.statement_amount=money(value('invoiceStatement'));suffix='close';}
        const amount=action==='close'?payload.statement_amount:payload.amount;
        if(amount===null||amount===undefined||(action==='pay'&&(!payload.account_id||!payload.payment_date))||(action==='charge'&&!payload.description)||(action==='credit'&&!payload.purchase_movement_id)){
            const error=document.getElementById('invoiceActionError');error.textContent='Preenche os campos obrigatórios com valores válidos.';error.classList.remove('d-none');submit.disabled=false;return;
        }
        try{
            const response=await fetch('/api/financial/invoices/'+encodeURIComponent(invoiceId)+'/'+suffix,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(payload)});
            const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível concluir a operação.');
            modal.hide();window.location.reload();
        }catch(error){const el=document.getElementById('invoiceActionError');el.textContent=error.message;el.classList.remove('d-none');}
        finally{submit.disabled=false;}
    });
})();
</script>
@endpush

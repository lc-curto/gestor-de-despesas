@extends('layouts.app')

@section('title', 'Fatura · '.$invoice->reference_month->translatedFormat('F Y'))

@section('content')
@php
    $currency = $card->currency;
    $cycleLabels = ['open' => 'Aberta', 'closed' => 'Fechada'];
    $cycleClasses = ['open' => 'text-bg-primary', 'closed' => 'text-bg-secondary'];
    $progress = (float) $invoice->total_amount > 0 ? min(100, (float) $invoice->paid_amount / (float) $invoice->total_amount * 100) : 100;
    $locked = $invoice->status === 'carried';
@endphp
<div class="app-content invoice-detail-page">
    <header class="mb-3">
        <a href="{{ $cardBackUrl }}" class="small text-secondary text-decoration-none">← Voltar</a>
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
        <div class="mb-3">
            <div class="d-flex align-items-baseline gap-2 mb-1">
                <strong class="fs-2">{{ number_format((float)$invoice->total_amount,2,',','.') }} {{ $currency }}</strong>
            </div>
            <div class="d-flex justify-content-between small text-secondary">
                <span>Pago {{ number_format((float)$invoice->paid_amount,2,',','.') }} {{ $currency }}</span>
                <span>Em falta {{ number_format((float)$invoice->remaining_amount,2,',','.') }} {{ $currency }}</span>
            </div>
            <div class="progress mt-2" role="progressbar" aria-label="Percentagem paga" aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100" style="height:5px">
                <div class="progress-bar" style="width:{{ $progress }}%"></div>
            </div>
            <div class="small text-secondary mt-2">Vencimento {{ $invoice->due_date->format('d/m/Y') }}</div>
            <button type="button" class="btn btn-primary w-100 mt-3 js-invoice-action" data-action="pay" @disabled((float)$invoice->remaining_amount <= 0 || $invoice->status === 'carried')>Pagar {{ number_format((float)$invoice->remaining_amount,2,',','.') }} {{ $currency }}</button>
        </div>

        <div class="row g-2 mb-3 invoice-secondary-actions">
            <div class="col-6">
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 h-100 js-invoice-action" data-action="charge" aria-label="Adicionar encargo" title="Adicionar encargo" @disabled($locked)>Encargo</button>
            </div>
            <div class="col-6">
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 h-100 js-invoice-action" data-action="credit" aria-label="Registar crédito" title="Registar crédito" @disabled($locked || $invoice->movimentos->isEmpty() || (float)$invoice->remaining_amount <= 0)>Crédito</button>
            </div>
        </div>

        <section class="mb-3">
            <h2 class="h6 fw-bold mb-2">Movimentos</h2>
                @forelse($invoice->movimentos as $movement)
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <span class="min-w-0 text-break">{{ $movement->description }}<span class="d-block small text-secondary">{{ $movement->movement_date->format('d/m/Y') }}</span></span>
                        <strong class="text-nowrap">{{ number_format((float)$movement->amount,2,',','.') }} {{ $currency }}</strong>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Ainda não existem movimentos nesta fatura.</p>
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
        </section>

        <section class="border-top pt-3">
                <h2 class="h6 fw-bold mb-2">Pagamentos</h2>
                @forelse($invoice->pagamentos as $payment)
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <span class="min-w-0">Pagamento<span class="d-block small text-secondary">{{ $payment->movement_date?->format('d/m/Y') }} · {{ $payment->contaOrigem?->name ?? 'Conta' }}</span></span>
                        <strong class="text-success text-nowrap">{{ number_format((float)$payment->amount,2,',','.') }} {{ $currency }}</strong>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Ainda não existem pagamentos.</p>
                @endforelse
        </section>
    </section>
</div>

<div class="modal fade" id="invoiceActionModal" tabindex="-1" aria-labelledby="invoiceActionTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow rounded-4">
        <form id="invoiceActionForm">
            <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="invoiceActionTitle">Ação da fatura</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body p-4">
                <p id="invoiceActionContext" class="small text-secondary"></p>
                <p class="small text-secondary d-none" data-field="chargeHelp">Regista aqui juros, comissões, impostos ou multas cobrados pelo banco.</p>
                <div class="mb-3 d-none" data-field="description"><label class="form-label" for="invoiceDescription">Descrição</label><input class="form-control" id="invoiceDescription" maxlength="255"></div>
                <div class="mb-3 d-none" data-field="amount"><label class="form-label" for="invoiceAmount">Valor</label><input class="form-control" id="invoiceAmount" inputmode="decimal" placeholder="0,00"></div>
                <div class="mb-3 d-none" data-field="date"><label class="form-label" for="invoiceDate">Data</label><input class="form-control" id="invoiceDate" type="date" value="{{ today()->toDateString() }}"></div>
                <div class="mb-3 d-none" data-field="account"><label class="form-label" for="invoiceAccount">Conta de pagamento</label><select class="form-select" id="invoiceAccount"><option value="">Selecionar conta</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                <div class="mb-3 d-none" data-field="chargeType"><label class="form-label" for="invoiceChargeType">Tipo de encargo</label><select class="form-select" id="invoiceChargeType"><option value="interest">Juros</option><option value="late_fee">Multa</option><option value="commission">Comissão</option><option value="tax">Imposto</option><option value="other">Outro</option></select></div>
                <div class="mb-3 d-none" data-field="purchase"><label class="form-label" for="invoicePurchase">Compra a creditar</label><select class="form-select" id="invoicePurchase">@foreach($invoice->movimentos as $purchase)<option value="{{ $purchase->id }}">{{ $purchase->description }} · {{ $purchase->amount }} {{ $currency }}</option>@endforeach</select></div>
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
    const fieldMap={pay:['amount','date','account'],charge:['chargeHelp','description','amount','date','chargeType'],credit:['description','amount','date','purchase']};
    const titleMap={pay:'Pagar fatura',charge:'Adicionar encargo',credit:'Registar crédito'};
    const money=v=>{const s=String(v??'').trim().replace(',','.');return /^\d+(\.\d{1,2})?$/.test(s)?s:null;};
    document.querySelectorAll('.js-invoice-action').forEach(button=>button.addEventListener('click',()=>{
        action=button.dataset.action;form.reset();
        document.querySelectorAll('[data-field]').forEach(el=>el.classList.add('d-none'));
        (fieldMap[action]||[]).forEach(key=>document.querySelector('[data-field="'+key+'"]').classList.remove('d-none'));
        document.getElementById('invoiceActionTitle').textContent=titleMap[action];
        document.getElementById('invoiceActionContext').textContent='Em falta: '+context.dataset.remaining+' '+context.dataset.currency;
        if(action==='pay')document.getElementById('invoiceAmount').value=context.dataset.remaining;
        document.getElementById('invoiceActionError').classList.add('d-none');
        modal.show();
    }));
    form.addEventListener('submit',async event=>{
        event.preventDefault();const submit=form.querySelector('[type="submit"]');submit.disabled=true;
        const value=id=>document.getElementById(id).value,payload={};let suffix='';
        if(action==='pay'){
            payload.amount=money(value('invoiceAmount'));payload.payment_date=value('invoiceDate');payload.account_id=value('invoiceAccount');suffix='pay';
        }else if(action==='charge'){
            payload.description=value('invoiceDescription');payload.amount=money(value('invoiceAmount'));payload.movement_date=value('invoiceDate');payload.charge_type=value('invoiceChargeType');suffix='charges';
        }else if(action==='credit'){
            payload.description=value('invoiceDescription')||'Crédito de compra';payload.amount=money(value('invoiceAmount'));payload.movement_date=value('invoiceDate');payload.purchase_movement_id=value('invoicePurchase');suffix='credits';
        }
        const amount=payload.amount;
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

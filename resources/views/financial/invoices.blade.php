@extends('layouts.app')

@section('title', 'Faturas')

@section('content')
<div class="app-content invoice-page">
    <header class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <a href="{{ route('financial.cards.page') }}" class="small text-secondary text-decoration-none">← Cartões</a>
            <h1 class="h2 fw-bold mb-1 mt-2">Faturas</h1>
            <p class="text-secondary mb-0">Compras, pagamentos, encargos, créditos e reconciliação.</p>
        </div>
    </header>
    <div id="invoiceFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    @if($items->isEmpty())
        <div class="card border-0 rounded-4"><div class="card-body text-center p-4">
            <i data-lucide="receipt" class="text-secondary mb-3" style="width:32px;height:32px"></i>
            <h2 class="h5">Ainda não existem faturas</h2>
            <p class="text-secondary mb-0">As faturas aparecem aqui quando registares compras num cartão de crédito.</p>
        </div></div>
    @else
        <section class="invoice-list" aria-label="Faturas dos cartões">
        @foreach($items as $invoice)
            @php
                $labels = ['open'=>'Aberta','closed'=>'Fechada','partial'=>'Parcialmente paga','overdue'=>'Vencida','paid'=>'Paga','carried'=>'Transportada'];
                $classes = ['open'=>'text-bg-primary','closed'=>'text-bg-secondary','partial'=>'text-bg-warning','overdue'=>'text-bg-danger','paid'=>'text-bg-success','carried'=>'text-bg-secondary'];
                $progress = (float)$invoice->total_amount > 0 ? min(100,(float)$invoice->paid_amount/(float)$invoice->total_amount*100) : 100;
                $locked = in_array($invoice->status,['closed','paid','carried'],true);
            @endphp
            <article class="card invoice-card border-0 rounded-4" data-invoice-id="{{ $invoice->id }}" data-status="{{ $invoice->display_status }}" data-remaining="{{ $invoice->remaining_amount }}" data-currency="{{ $invoice->conta?->currency ?? auth()->user()->currency }}" data-closing="{{ $invoice->closing_date->toDateString() }}">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div class="min-w-0">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h2 class="h5 fw-bold mb-0">{{ $invoice->conta?->name ?? 'Cartão' }} · {{ $invoice->reference_month->translatedFormat('F Y') }}</h2>
                                <span class="badge {{ $classes[$invoice->display_status] ?? 'text-bg-secondary' }}">{{ $labels[$invoice->display_status] ?? $invoice->display_status }}</span>
                            </div>
                            <p class="small text-secondary mb-0 mt-1">Fecho: {{ $invoice->closing_date->format('d/m/Y') }} · Vencimento: {{ $invoice->due_date->format('d/m/Y') }}</p>
                        </div>
                        <div class="text-end ms-auto"><strong class="fs-5">{{ number_format((float)$invoice->remaining_amount,2,',','.') }} {{ $invoice->conta?->currency ?? auth()->user()->currency }}</strong><div class="small text-secondary">por pagar</div></div>
                    </div>
                    <div class="invoice-progress mt-3"><span style="width:{{ $progress }}%"></span></div>
                    <div class="d-flex justify-content-between flex-wrap gap-2 small text-secondary mt-2">
                        <span>Total: {{ number_format((float)$invoice->total_amount,2,',','.') }}</span>
                        <span>Pago: {{ number_format((float)$invoice->paid_amount,2,',','.') }}</span>
                        @if((float)$invoice->carried_balance > 0)<span>Saldo transportado: {{ number_format((float)$invoice->carried_balance,2,',','.') }}</span>@endif
                    </div>
                    <div class="invoice-actions d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-primary btn-sm js-invoice-action" data-action="pay" @disabled((float)$invoice->remaining_amount <= 0 || $invoice->status === 'carried')>Registar pagamento</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm js-invoice-action" data-action="charge" @disabled($locked)>Adicionar encargo</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm js-invoice-action" data-action="credit" @disabled($locked || $invoice->movimentos->isEmpty())>Registar crédito</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm js-invoice-action" data-action="close" @disabled($locked || $invoice->closing_date->isFuture())>Fechar e reconciliar</button>
                    </div>
                    <details class="invoice-details mt-3 pt-3 border-top">
                        <summary class="fw-semibold">Ver movimentos da fatura</summary>
                        <div class="mt-3">
                            <h3 class="h6 fw-semibold">Compras e encargos</h3>
                            @forelse($invoice->movimentos as $movement)
                                <div class="invoice-line"><span>{{ $movement->description }} <small class="text-secondary">{{ $movement->movement_date->format('d/m/Y') }}</small></span><strong>{{ number_format((float)$movement->amount,2,',','.') }}</strong></div>
                            @empty <p class="small text-secondary">Sem compras ou encargos.</p> @endforelse
                            @if($invoice->saldosTransportados->isNotEmpty())
                                <h3 class="h6 fw-semibold mt-3">Saldos transportados</h3>
                                @foreach($invoice->saldosTransportados as $carried)
                                    <div class="invoice-line"><span>Fatura {{ $carried->sourceInvoice?->reference_month?->translatedFormat('F Y') ?? 'anterior' }}</span><strong>{{ number_format((float)$carried->amount,2,',','.') }}</strong></div>
                                @endforeach
                            @endif
                            <h3 class="h6 fw-semibold mt-3">Créditos</h3>
                            @forelse($invoice->creditos as $credit)
                                <div class="invoice-line"><span>Crédito associado a compra <small class="text-secondary">{{ $credit->movement_date?->format('d/m/Y') }}</small></span><strong>−{{ number_format((float)$credit->amount,2,',','.') }}</strong></div>
                            @empty <p class="small text-secondary">Sem créditos registados.</p> @endforelse
                            <h3 class="h6 fw-semibold mt-3">Pagamentos</h3>
                            @forelse($invoice->pagamentos as $payment)
                                <div class="invoice-line"><span>{{ $payment->reversal_of_id ? 'Correção de pagamento' : 'Pagamento' }} <small class="text-secondary">{{ $payment->movement_date?->format('d/m/Y') }} · {{ $payment->contaOrigem?->name ?? 'Conta' }}</small></span><strong>{{ number_format((float)$payment->amount,2,',','.') }}</strong></div>
                            @empty <p class="small text-secondary">Sem pagamentos registados.</p> @endforelse
                        </div>
                    </details>
                </div>
            </article>
        @endforeach
        </section>
        <div class="mt-3">{{ $items->links() }}</div>
    @endif
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
                    </div><small class="text-secondary">A soma tem de coincidir exatamente com o pagamento.</small>
                </div>
                <div class="mb-3 d-none" data-field="chargeType"><label class="form-label" for="invoiceChargeType">Tipo de encargo</label><select class="form-select" id="invoiceChargeType"><option value="interest">Juros</option><option value="late_fee">Multa</option><option value="commission">Comissão</option><option value="tax">Imposto</option><option value="other">Outro</option></select></div>
                <div class="mb-3 d-none" data-field="purchase"><label class="form-label" for="invoicePurchase">Compra a creditar</label><select class="form-select" id="invoicePurchase"></select></div>
                <div class="mb-3 d-none" data-field="statement"><label class="form-label" for="invoiceStatement">Total do extrato</label><input class="form-control" id="invoiceStatement" inputmode="decimal" placeholder="0,00"><small class="text-secondary">O valor tem de coincidir com os lançamentos líquidos da fatura.</small></div>
                <div class="alert alert-danger d-none mb-0" id="invoiceActionError" role="alert"></div>
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Confirmar</button></div>
        </form>
    </div></div>
</div>
@endsection

@push('styles')
<style>
.invoice-page{min-width:0}.invoice-list{display:grid;gap:1rem}.invoice-card{min-width:0;border:1px solid var(--app-border)!important;box-shadow:var(--app-shadow)}.invoice-progress{height:7px;border-radius:99px;background:var(--bs-tertiary-bg);overflow:hidden}.invoice-progress span{display:block;height:100%;background:var(--app-primary);border-radius:inherit}.invoice-details{overflow-wrap:anywhere}.invoice-details summary{cursor:pointer;min-height:36px}.invoice-line{display:flex;justify-content:space-between;gap:1rem;padding:.45rem 0;border-bottom:1px solid var(--app-border)}.invoice-line>span{min-width:0;overflow-wrap:anywhere}.invoice-line>strong{white-space:nowrap;font-variant-numeric:tabular-nums}.invoice-line:last-child{border-bottom:0}
@media(max-width:575.98px){.invoice-card .card-body{padding:1rem!important}.invoice-actions{display:grid!important;grid-template-columns:1fr 1fr}.invoice-actions .btn{min-height:40px;white-space:normal}.invoice-line{font-size:.875rem}}
</style>
@endpush

@push('scripts')
<script>
(() => {
    const modalEl=document.getElementById('invoiceActionModal'),form=document.getElementById('invoiceActionForm');
    if(!modalEl||!form)return;
    const modal=bootstrap.Modal.getOrCreateInstance(modalEl),csrf=document.querySelector('meta[name="csrf-token"]')?.content;
    let invoiceId=null,action=null;
    const fieldMap={pay:['amount','date','account','allocation'],charge:['description','amount','date','chargeType'],credit:['description','amount','date','purchase'],close:['statement']};
    const titleMap={pay:'Registar pagamento',charge:'Adicionar encargo',credit:'Registar crédito',close:'Fechar e reconciliar'};
    const money=v=>{const s=String(v??'').trim().replace(',','.');return /^\d+(\.\d{1,2})?$/.test(s)?s:null;};
    const feedback=(msg,type='danger')=>{const el=document.getElementById('invoiceFeedback');el.className='alert alert-'+type;el.textContent=msg;};
    function open(card,kind){
        invoiceId=card.dataset.invoiceId;action=kind;form.reset();
        document.querySelectorAll('[data-field]').forEach(el=>el.classList.add('d-none'));
        (fieldMap[kind]||[]).forEach(key=>document.querySelector('[data-field="'+key+'"]').classList.remove('d-none'));
        document.getElementById('invoiceActionTitle').textContent=titleMap[kind];
        document.getElementById('invoiceActionContext').textContent='Saldo por pagar: '+card.dataset.remaining+' '+card.dataset.currency;
        document.getElementById('invoiceActionError').classList.add('d-none');
        if(kind==='credit'){
            const select=document.getElementById('invoicePurchase');select.replaceChildren();
            fetch('/api/financial/invoices',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.json()).then(result=>{
                const current=(result.data?.data||[]).find(item=>item.id===invoiceId);
                (current?.movimentos||[]).forEach(m=>{const option=document.createElement('option');option.value=m.id;option.textContent=m.description+' · '+m.amount;select.append(option);});
            }).catch(()=>{});
        }
        modal.show();
    }
    document.querySelectorAll('.js-invoice-action').forEach(button=>button.addEventListener('click',()=>open(button.closest('[data-invoice-id]'),button.dataset.action)));
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
            modal.hide();feedback(result.message||'Operação registada.','success');window.location.reload();
        }catch(error){const el=document.getElementById('invoiceActionError');el.textContent=error.message;el.classList.remove('d-none');}
        finally{submit.disabled=false;}
    });
})();
</script>
@endpush

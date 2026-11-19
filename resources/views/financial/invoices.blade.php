@extends('layouts.app')

@section('title', 'Faturas')

@section('content')
<div class="app-content invoice-page">
    <header class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <a href="{{ route('financial.cards.page') }}" class="small text-secondary text-decoration-none">← Cartões</a>
            <h1 class="h2 fw-bold mb-1 mt-2">Faturas</h1>
            
        </div>
    </header>
    <div id="invoiceFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    @if($items->isEmpty())
        <div class="card border-0 rounded-4"><div class="card-body text-center p-4">
            <i data-lucide="receipt" class="text-secondary mb-3" style="width:32px;height:32px"></i>
            <h2 class="h5">Ainda não existem faturas</h2>
            
        </div></div>
    @else
        <section class="d-grid gap-3" aria-label="Faturas dos cartões">
        @foreach($items as $invoice)
            @php
                $cycleLabels = ['open'=>'Aberta','closed'=>'Fechada'];
                $cycleClasses = ['open'=>'text-bg-primary','closed'=>'text-bg-secondary'];
                $paymentLabels = ['unpaid'=>'Por pagar','partial'=>'Parcialmente paga','paid'=>'Paga','overdue'=>'Em atraso','carried'=>'Saldo transportado'];
                $paymentClasses = ['unpaid'=>'text-bg-light text-dark border','partial'=>'text-bg-warning','paid'=>'text-bg-success','overdue'=>'text-bg-danger','carried'=>'text-bg-secondary'];
                $progress = (float)$invoice->total_amount > 0 ? min(100,(float)$invoice->paid_amount/(float)$invoice->total_amount*100) : 100;
                $locked = in_array($invoice->status,['closed','paid','carried'],true);
                $currency = $invoice->conta?->currency ?? auth()->user()->currency;
                $detailModalId = 'invoiceDetails-'.$invoice->id;
            @endphp
            <article class="card border rounded-4 shadow-sm" data-invoice-id="{{ $invoice->id }}" data-status="{{ $invoice->payment_status }}" data-remaining="{{ $invoice->remaining_amount }}" data-currency="{{ $currency }}" data-closing="{{ $invoice->closing_date->toDateString() }}">
                <div class="card-body p-0">
                    <button type="button" class="btn btn-light text-start w-100 rounded-4 p-2 p-md-3 border-0" data-bs-toggle="modal" data-bs-target="#{{ $detailModalId }}" aria-label="Ver fatura de {{ $invoice->conta?->name ?? 'Cartão' }}, {{ $invoice->reference_month->translatedFormat('F Y') }}">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="min-w-0">
                                <h2 class="h6 fw-semibold text-body mb-0 text-break">{{ $invoice->conta?->name ?? 'Cartão' }}</h2>
                                <p class="small text-secondary mb-0 text-capitalize">{{ $invoice->reference_month->translatedFormat('F Y') }}</p>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <strong class="fs-5 text-body d-block">{{ number_format((float)$invoice->remaining_amount,2,',','.') }} {{ $currency }}</strong>
                                <span class="small text-secondary">Por pagar</span>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                            <span class="badge {{ $cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary' }}">{{ $cycleLabels[$invoice->cycle_status] ?? $invoice->cycle_status }}</span>
                            <span class="badge {{ $paymentClasses[$invoice->payment_status] ?? 'text-bg-secondary' }}">{{ $paymentLabels[$invoice->payment_status] ?? $invoice->payment_status }}</span>
                        </div>
                        <div class="progress mt-2" role="progressbar" aria-label="Percentagem paga" aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100" style="height:4px">
                            <div class="progress-bar" style="width:{{ $progress }}%"></div>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mt-1 small text-secondary">
                            <span>Total: {{ number_format((float)$invoice->total_amount,2,',','.') }} {{ $currency }}</span>
                            <span>Pago: {{ number_format((float)$invoice->paid_amount,2,',','.') }} {{ $currency }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center border-top mt-2 pt-2">
                            <span class="small fw-semibold text-primary">Ver detalhes</span>
                            <i data-lucide="chevron-right" class="text-secondary" aria-hidden="true"></i>
                        </div>
                    </button>
                </div>
            </article>

            <div class="modal fade" id="{{ $detailModalId }}" tabindex="-1" aria-labelledby="{{ $detailModalId }}Title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
                    <div class="modal-content border-0 rounded-4 shadow" data-invoice-id="{{ $invoice->id }}" data-remaining="{{ $invoice->remaining_amount }}" data-currency="{{ $currency }}">
                        <div class="modal-header border-0 pb-2">
                            <div class="min-w-0">
                                <h2 class="modal-title h5 fw-bold" id="{{ $detailModalId }}Title">{{ $invoice->conta?->name ?? 'Cartão' }}</h2>
                                <p class="small text-secondary mb-0 text-capitalize">{{ $invoice->reference_month->translatedFormat('F Y') }}</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body pt-2">
                            <div class="mb-3"><span class="badge {{ $cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary' }}">{{ $cycleLabels[$invoice->cycle_status] ?? $invoice->cycle_status }}</span>
                                    <span class="badge {{ $paymentClasses[$invoice->payment_status] ?? 'text-bg-secondary' }}">{{ $paymentLabels[$invoice->payment_status] ?? $invoice->payment_status }}</span></div>
                            <div class="rounded-4 bg-body-tertiary p-3 mb-3">
                                <div class="small text-secondary mb-1">Valor por pagar</div>
                                <div class="display-6 fw-bold text-body">{{ number_format((float)$invoice->remaining_amount,2,',','.') }} <span class="fs-5">{{ $currency }}</span></div>
                                <div class="progress mt-3" role="progressbar" aria-label="Percentagem paga" aria-valuenow="{{ round($progress) }}" aria-valuemin="0" aria-valuemax="100" style="height:7px">
                                    <div class="progress-bar" style="width:{{ $progress }}%"></div>
                                </div>
                                <div class="row g-2 mt-2 small">
                                    <div class="col-6"><span class="text-secondary d-block">Total</span><strong>{{ number_format((float)$invoice->total_amount,2,',','.') }} {{ $currency }}</strong></div>
                                    <div class="col-6"><span class="text-secondary d-block">Pago</span><strong>{{ number_format((float)$invoice->paid_amount,2,',','.') }} {{ $currency }}</strong></div>
                                    @if((float)$invoice->carried_balance > 0)
                                        <div class="col-12"><span class="text-secondary d-block">Saldo transportado</span><strong>{{ number_format((float)$invoice->carried_balance,2,',','.') }} {{ $currency }}</strong></div>
                                    @endif
                                </div>
                            </div>
                            <div class="row g-2 small mb-4">
                                <div class="col-6"><span class="text-secondary d-block">Fecho</span><strong>{{ $invoice->closing_date->format('d/m/Y') }}</strong></div>
                                <div class="col-6"><span class="text-secondary d-block">Vencimento</span><strong>{{ $invoice->due_date->format('d/m/Y') }}</strong></div>
                            </div>
                            <h3 class="h6 fw-bold mb-2">Ações</h3>
                            <div class="row g-2 mb-4">
                                <div class="col-12"><button type="button" class="btn btn-primary w-100 js-invoice-action" data-action="pay" @disabled((float)$invoice->remaining_amount <= 0 || $invoice->status === 'carried')>Registar pagamento</button></div>
                                <div class="col-6"><button type="button" class="btn btn-outline-secondary w-100 h-100 js-invoice-action" data-action="charge" @disabled($locked)>Adicionar encargo</button></div>
                                <div class="col-6"><button type="button" class="btn btn-outline-secondary w-100 h-100 js-invoice-action" data-action="credit" @disabled($locked || $invoice->movimentos->isEmpty())>Registar crédito</button></div>
                                <div class="col-12"><button type="button" class="btn btn-outline-secondary w-100 js-invoice-action" data-action="close" @disabled($locked || $invoice->closing_date->isFuture())>Fechar e reconciliar</button></div>
                            </div>
                            <h3 class="h6 fw-bold mb-2">Movimentos da fatura</h3>
                            <div class="accordion" id="{{ $detailModalId }}Movements">
                                <div class="accordion-item">
                                    <h4 class="accordion-header"><button class="accordion-button py-2" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $detailModalId }}Purchases" aria-expanded="true">Compras e encargos</button></h4>
                                    <div id="{{ $detailModalId }}Purchases" class="accordion-collapse collapse show"><div class="accordion-body px-2 py-2">
                                        @forelse($invoice->movimentos as $movement)
                                            <div class="d-flex justify-content-between gap-3 py-2 border-bottom small"><span class="min-w-0 text-break">{{ $movement->description }}<span class="d-block text-secondary">{{ $movement->movement_date->format('d/m/Y') }}</span></span><strong class="text-nowrap">{{ number_format((float)$movement->amount,2,',','.') }} {{ $currency }}</strong></div>
                                        @empty <p class="small text-secondary mb-0">Sem compras ou encargos.</p> @endforelse
                                    </div></div>
                                </div>
                                @if($invoice->saldosTransportados->isNotEmpty())
                                    <div class="accordion-item"><h4 class="accordion-header"><button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $detailModalId }}Carried">Saldos transportados</button></h4><div id="{{ $detailModalId }}Carried" class="accordion-collapse collapse"><div class="accordion-body px-2 py-2">
                                        @foreach($invoice->saldosTransportados as $carried)
                                            <div class="d-flex justify-content-between gap-3 py-2 border-bottom small"><span>Fatura {{ $carried->sourceInvoice?->reference_month?->translatedFormat('F Y') ?? 'anterior' }}</span><strong>{{ number_format((float)$carried->amount,2,',','.') }} {{ $currency }}</strong></div>
                                        @endforeach
                                    </div></div></div>
                                @endif
                                <div class="accordion-item"><h4 class="accordion-header"><button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $detailModalId }}Credits">Créditos</button></h4><div id="{{ $detailModalId }}Credits" class="accordion-collapse collapse"><div class="accordion-body px-2 py-2">
                                    @forelse($invoice->creditos as $credit)
                                        <div class="d-flex justify-content-between gap-3 py-2 border-bottom small"><span>Crédito associado a compra<span class="d-block text-secondary">{{ $credit->movement_date?->format('d/m/Y') }}</span></span><strong>−{{ number_format((float)$credit->amount,2,',','.') }} {{ $currency }}</strong></div>
                                    @empty <p class="small text-secondary mb-0">Sem créditos registados.</p> @endforelse
                                </div></div></div>
                                <div class="accordion-item"><h4 class="accordion-header"><button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $detailModalId }}Payments">Pagamentos</button></h4><div id="{{ $detailModalId }}Payments" class="accordion-collapse collapse"><div class="accordion-body px-2 py-2">
                                    @forelse($invoice->pagamentos as $payment)
                                        <div class="d-flex justify-content-between gap-3 py-2 border-bottom small"><span>{{ $payment->reversal_of_id ? 'Correção de pagamento' : 'Pagamento' }}<span class="d-block text-secondary">{{ $payment->movement_date?->format('d/m/Y') }} · {{ $payment->contaOrigem?->name ?? 'Conta' }}</span></span><strong>{{ number_format((float)$payment->amount,2,',','.') }} {{ $currency }}</strong></div>
                                    @empty <p class="small text-secondary mb-0">Sem pagamentos registados.</p> @endforelse
                                </div></div></div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">Fechar</button></div>
                    </div>
                </div>
            </div>
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

@push('styles')
<style>
.invoice-page{min-width:0}.invoice-page .card>div>button{white-space:normal}.invoice-page .card>div>button:hover{background:var(--bs-tertiary-bg)!important}.invoice-page .card>div>button:focus-visible{outline:2px solid var(--bs-primary);outline-offset:2px}.invoice-page .modal-content{min-width:0}.invoice-page .accordion-button{font-size:.925rem}
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
    let pendingAction = null;
    document.querySelectorAll('.js-invoice-action').forEach(button=>button.addEventListener('click',()=>{
        const detailsModal=button.closest('.modal');
        const card=detailsModal?.querySelector('.modal-content[data-invoice-id]');
        if(!card)return;
        pendingAction={card,kind:button.dataset.action,detailsModal};
        bootstrap.Modal.getOrCreateInstance(detailsModal).hide();
    }));
    document.querySelectorAll('[id^="invoiceDetails-"]').forEach(detailsModal=>detailsModal.addEventListener('hidden.bs.modal',()=>{
        if(!pendingAction||pendingAction.detailsModal!==detailsModal)return;
        const next=pendingAction;pendingAction=null;open(next.card,next.kind);
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
            modal.hide();feedback(result.message||'Operação registada.','success');window.location.reload();
        }catch(error){const el=document.getElementById('invoiceActionError');el.textContent=error.message;el.classList.remove('d-none');}
        finally{submit.disabled=false;}
    });
})();
</script>
@endpush

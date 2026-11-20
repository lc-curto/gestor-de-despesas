@extends('layouts.app')

@section('title', $card->name)

@section('content')
<div class="app-content card-detail-page">
    <header class="mb-3">
        <a href="{{ route('financial.cards.page') }}" class="small text-secondary text-decoration-none">← Cartões</a>
        <div class="d-flex align-items-center justify-content-between gap-2 mt-2">
            <div class="min-w-0">
                <h1 class="h3 fw-bold mb-0 text-break">{{ $card->name }}</h1>
                <span class="small text-secondary">Cartão de crédito</span>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Gerir cartão"><i data-lucide="ellipsis"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @if($card->status !== 'closed' && $card->is_operational)
                        <li><button class="dropdown-item js-edit-card" type="button" data-name="{{ $card->name }}" data-update-url="{{ route('financial.accounts.update',$card->id) }}" data-settings-url="{{ route('financial.cards.settings',$card->id) }}" data-limit="{{ $card->credit_limit }}" data-closing="{{ $card->closing_day }}" data-due="{{ $card->due_day }}" data-opening-debt="{{ $card->opening_debt_amount }}">Editar cartão</button></li>
                    @endif
                    @if($card->status === 'active' && $card->is_operational)
                        <li><button class="dropdown-item js-card-status" type="button" data-status="suspended" data-status-url="{{ route('financial.cards.status',$card->id) }}" data-name="{{ $card->name }}">Suspender cartão</button></li>
                    @elseif($card->status === 'suspended' && $card->is_operational)
                        <li><button class="dropdown-item js-card-status" type="button" data-status="active" data-status-url="{{ route('financial.cards.status',$card->id) }}" data-name="{{ $card->name }}">Reativar cartão</button></li>
                    @endif
                    @if($card->status === 'closed' || ! $card->is_operational)
                        <li><button class="dropdown-item js-reopen-card" type="button" data-url="{{ route('financial.cards.reopen',$card->id) }}">Reabrir cartão</button></li>
                    @endif
                    @if($card->status === 'active' && $card->is_operational && $card->used_limit <= 0.00001)
                        <li><button class="dropdown-item js-close-card text-danger" type="button" data-close-url="{{ route('financial.accounts.close',$card->id) }}" data-name="{{ $card->name }}">Encerrar cartão</button></li>
                    @endif
                    @if($card->can_delete)
                        <li><hr class="dropdown-divider"></li>
                        <li><button class="dropdown-item js-delete-card text-danger" type="button" data-url="{{ route('financial.cards.destroy',$card->id) }}">Excluir cartão</button></li>
                    @endif
                </ul>
            </div>
        </div>
    </header>

    <div id="cardFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    <section class="card border rounded-4 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-end gap-2">
                <div>
                    <span class="small text-secondary d-block">Disponível</span>
                    <strong class="fs-2 text-body">{{ number_format($card->available_limit,2,',','.') }} <span class="fs-6">{{ $card->currency }}</span></strong>
                </div>
                <div class="text-end">
                    <span class="small text-secondary d-block">Limite total</span>
                    <strong>{{ number_format((float)$card->credit_limit,2,',','.') }} {{ $card->currency }}</strong>
                </div>
            </div>
            <div class="progress mt-2" role="progressbar" aria-label="Limite utilizado" aria-valuenow="{{ round($card->usage_percent) }}" aria-valuemin="0" aria-valuemax="100" style="height:7px">
                <div class="progress-bar" style="width:{{ $card->usage_percent }}%"></div>
            </div>
            <div class="d-flex justify-content-between small text-secondary mt-1">
                <span>Utilizado: {{ number_format($card->used_limit,2,',','.') }} {{ $card->currency }}</span>
                <span>{{ number_format($card->usage_percent,0) }}%</span>
            </div>
            <div class="row g-2 border-top mt-3 pt-3 small">
                <div class="col-6"><span class="text-secondary d-block">Fecho</span><strong>Dia {{ $card->closing_day ?? '—' }}</strong></div>
                <div class="col-6"><span class="text-secondary d-block">Vencimento</span><strong>Dia {{ $card->due_day ?? '—' }}</strong></div>
            </div>
        </div>
    </section>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 fw-bold mb-0">Faturas</h2>
    </div>
    @if($invoices->isEmpty())
        <div class="card border rounded-4"><div class="card-body text-center p-4">
            <i data-lucide="receipt" class="text-secondary mb-2" style="width:28px;height:28px"></i>
            <p class="mb-0 text-secondary">Ainda não existem faturas para este cartão.</p>
        </div></div>
    @else
        <section class="d-grid gap-2" aria-label="Faturas deste cartão">
            @foreach($invoices as $invoice)
                @php
                    $cycleLabels = ['open' => 'Aberta', 'closed' => 'Fechada'];
                    $cycleClasses = ['open' => 'text-bg-primary', 'closed' => 'text-bg-secondary'];
                    $currency = $card->currency;
                @endphp
                <a href="{{ route('financial.invoices.show', $invoice->id) }}" class="card border rounded-4 text-decoration-none text-body">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="min-w-0">
                                <h3 class="h6 fw-semibold mb-1 text-capitalize">{{ $invoice->reference_month->translatedFormat('F Y') }}</h3>
                                <span class="small text-secondary">Total: {{ number_format((float)$invoice->total_amount,2,',','.') }} {{ $currency }}</span>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <span class="badge {{ $cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary' }}">{{ $cycleLabels[$invoice->cycle_status] ?? 'Fechada' }}</span>
                                <div class="small text-secondary mt-1">Ver fatura <i data-lucide="chevron-right" class="ms-1" style="width:14px;height:14px"></i></div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between small text-secondary mt-2">
                            <span>Fecho: {{ $invoice->closing_date->format('d/m/Y') }}</span>
                            <span>Vencimento: {{ $invoice->due_date->format('d/m/Y') }}</span>
                        </div>
                    </div>
                </a>
            @endforeach
        </section>
        <div class="mt-3">{{ $invoices->links() }}</div>
    @endif
</div>

<div class="modal fade" id="editCardModal" tabindex="-1" aria-labelledby="editCardTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow rounded-4">
        <form id="editCardForm">
            <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="editCardTitle">Editar cartão</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body p-4">
                <label class="form-label" for="editCardName">Nome</label><input class="form-control mb-3" id="editCardName" name="name" maxlength="120" required>
                <label class="form-label" for="editCardLimit">Limite total (€)</label><input class="form-control mb-3" id="editCardLimit" name="credit_limit" inputmode="decimal" required>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label" for="editCardClosing">Dia de fecho</label><input class="form-control" id="editCardClosing" name="closing_day" type="number" min="1" max="31" required></div>
                    <div class="col-6"><label class="form-label" for="editCardDue">Dia de vencimento</label><input class="form-control" id="editCardDue" name="due_day" type="number" min="1" max="31" required></div>
                </div>
                <div class="mt-3"><label class="form-label" for="editCardOpeningDebt">Limite já utilizado (€)</label><input class="form-control" id="editCardOpeningDebt" name="opening_debt_amount" inputmode="decimal" required></div>
                <div class="small text-danger mt-3" id="editCardError" role="alert"></div>
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
        </form>
    </div></div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const csrf=document.querySelector('meta[name="csrf-token"]')?.content;
    const headers={'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf};
    const feedback=document.getElementById('cardFeedback');
    const fail=message=>{feedback.className='alert alert-danger';feedback.textContent=message;};
    document.querySelectorAll('.js-edit-card').forEach(button=>button.addEventListener('click',()=>{
        const form=document.getElementById('editCardForm');
        form.dataset.updateUrl=button.dataset.updateUrl;form.dataset.settingsUrl=button.dataset.settingsUrl;
        form.elements.name.value=button.dataset.name;form.elements.credit_limit.value=button.dataset.limit||'';
        form.elements.closing_day.value=button.dataset.closing||'';form.elements.due_day.value=button.dataset.due||'';
        form.elements.opening_debt_amount.value=button.dataset.openingDebt||'';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editCardModal')).show();
    }));
    document.getElementById('editCardForm')?.addEventListener('submit',async event=>{
        event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type="submit"]');button.disabled=true;
        const normalize=value=>{const raw=String(value).trim().replace(/\./g,'').replace(',','.');return /^\d+(\.\d{1,2})?$/.test(raw)?raw:null;};
        const limit=normalize(form.elements.credit_limit.value),debt=normalize(form.elements.opening_debt_amount.value);
        if(!limit||!debt){document.getElementById('editCardError').textContent='Indica valores monetários válidos.';button.disabled=false;return;}
        try{
            let response=await fetch(form.dataset.settingsUrl,{method:'PUT',credentials:'same-origin',headers,body:JSON.stringify({credit_limit:limit,opening_debt_amount:debt,closing_day:form.elements.closing_day.value,due_day:form.elements.due_day.value})});
            let result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o cartão.');
            response=await fetch(form.dataset.updateUrl,{method:'PUT',credentials:'same-origin',headers,body:JSON.stringify({name:form.elements.name.value})});result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o nome.');
            window.location.reload();
        }catch(error){document.getElementById('editCardError').textContent=error.message;button.disabled=false;}
    });
    document.querySelectorAll('.js-card-status').forEach(button=>button.addEventListener('click',async()=>{
        const active=button.dataset.status==='active';
        if(!confirm((active?'Reativar':'Suspender')+' este cartão?'))return;
        const response=await fetch(button.dataset.statusUrl,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({status:button.dataset.status})});
        if(!response.ok){const result=await response.json().catch(()=>({}));fail(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível alterar o cartão.');return;}
        window.location.reload();
    }));
    document.querySelectorAll('.js-reopen-card').forEach(button=>button.addEventListener('click',async()=>{
        if(!confirm('Reabrir este cartão?'))return;
        const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({})});
        if(!response.ok){const result=await response.json().catch(()=>({}));fail(result.message||'Não foi possível reabrir o cartão.');return;}
        window.location.reload();
    }));
    document.querySelectorAll('.js-close-card').forEach(button=>button.addEventListener('click',async()=>{
        if(!confirm('Encerrar este cartão?'))return;
        const response=await fetch(button.dataset.closeUrl,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({})});
        if(!response.ok){const result=await response.json().catch(()=>({}));fail(result.message||'Não foi possível encerrar o cartão.');return;}
        window.location.reload();
    }));
    document.querySelectorAll('.js-delete-card').forEach(button=>button.addEventListener('click',async()=>{
        if(!confirm('Excluir definitivamente este cartão?'))return;
        const response=await fetch(button.dataset.url,{method:'DELETE',credentials:'same-origin',headers});
        if(!response.ok){const result=await response.json().catch(()=>({}));fail(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível excluir o cartão.');return;}
        window.location.href='{{ route('financial.cards.page') }}';
    }));
})();
</script>
@endpush

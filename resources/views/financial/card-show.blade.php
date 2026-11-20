@extends('layouts.app')

@section('title', $card->name)

@section('content')
<div class="app-content card-detail-page">
    <header class="mb-3">
        <a href="{{ $backUrl }}" class="small text-secondary text-decoration-none">← Voltar</a>
        <div class="d-flex align-items-center justify-content-between gap-2 mt-2">
            <div class="min-w-0">
                <h1 class="h3 fw-bold mb-0 text-break">{{ $card->name }}</h1>
                <span class="small text-secondary">Cartão de crédito</span>
            </div>

        </div>
    </header>

    <div id="cardFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    <section class="card border rounded-4 mb-3">
        <div class="card-body p-3">
            <strong class="d-block fs-2 lh-1 text-success">{{ number_format($card->available_limit,2,',','.') }} {{ $card->currency }}</strong>
            <span class="small text-secondary">Disponível · Limite {{ number_format((float)$card->credit_limit,2,',','.') }} {{ $card->currency }}</span>
            <div class="progress mt-2" role="progressbar" aria-label="Limite utilizado" aria-valuenow="{{ round($card->usage_percent) }}" aria-valuemin="0" aria-valuemax="100" style="height:5px">
                <div class="progress-bar" style="width:{{ $card->usage_percent }}%"></div>
            </div>
            <div class="d-flex justify-content-between gap-2 mt-2 small text-secondary">
                <span>Utilizado {{ number_format($card->used_limit,2,',','.') }} {{ $card->currency }}</span>
                <span class="text-end">Fecho {{ $card->closing_day ?? '—' }} · Vence {{ $card->due_day ?? '—' }}</span>
            </div>
        </div>
    </section>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 fw-bold mb-0">Faturas</h2>
        @if($invoices->total() > $invoices->perPage())
            <a href="{{ route('financial.invoices.page', ['account_id' => $card->id, 'return_to' => request()->getRequestUri()]) }}" class="small text-decoration-none">Ver todas</a>
        @endif
    </div>
    @if($invoices->isEmpty())
        <div class="card border rounded-4"><div class="card-body text-center p-4">
            <i data-lucide="receipt" class="text-secondary mb-2" style="width:28px;height:28px"></i>
            <p class="mb-0 text-secondary">Ainda não existem faturas para este cartão.</p>
        </div></div>
    @else
        <section class="card border-0 shadow-sm rounded-4 overflow-hidden" aria-label="Faturas deste cartão"><div class="list-group list-group-flush">
            @foreach($invoices as $invoice)
                @php
                    $cycleLabels = ['open' => 'Aberta', 'closed' => 'Fechada'];
                    $cycleClasses = ['open' => 'text-bg-primary', 'closed' => 'text-bg-secondary'];
                    $currency = $card->currency;
                @endphp
                <a href="{{ route('financial.invoices.show', ['id' => $invoice->id, 'card_page' => $invoices->currentPage(), 'return_to' => request()->getRequestUri()]) }}" class="list-group-item list-group-item-action text-decoration-none py-2 px-3">
                    <x-financial.history-row
                        icon="receipt"
                        icon-class="bg-body-secondary text-secondary"
                        :title="$invoice->reference_month->translatedFormat('F Y')"
                        :subtitle="'Vence ' . $invoice->due_date->format('d/m/Y')"
                        :amount="number_format((float) $invoice->total_amount, 2, ',', '.') . ' ' . $currency"
                        :status="$cycleLabels[$invoice->cycle_status] ?? 'Fechada'"
                        :status-class="$cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary'"
                    />
                </a>
            @endforeach
        </div></section>
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

<div class="modal fade" id="cardStatusConfirmModal" tabindex="-1" aria-labelledby="cardStatusConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow rounded-4">
        <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="cardStatusConfirmTitle">Suspender cartão</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><p class="mb-0" id="cardStatusConfirmMessage"></p><div class="small text-danger mt-3 d-none" id="cardStatusConfirmError" role="alert"></div></div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="cardStatusConfirmSubmit">Confirmar</button></div>
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
                const normalize=value=>{const raw=String(value).trim();let parts;if(/^[0-9]{1,3}(?:\\.[0-9]{3})+,[0-9]{1,2}$/.test(raw)){parts=raw.replace(/\\./g,'').split(',');}else if(/^[0-9]+(?:[.,][0-9]{1,2})?$/.test(raw)){parts=raw.split(/[.,]/);}else{return null;}return parts[0]+'.'+(parts[1]||'').padEnd(2,'0');};
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
    const statusModalElement=document.getElementById('cardStatusConfirmModal');
    const statusModal=statusModalElement?bootstrap.Modal.getOrCreateInstance(statusModalElement):null;
    let pendingStatusButton=null;
    document.querySelectorAll('.js-card-status').forEach(button=>button.addEventListener('click',()=>{
        pendingStatusButton=button;
        const activating=button.dataset.status==='active';
        document.getElementById('cardStatusConfirmTitle').textContent=activating?'Reativar cartão':'Suspender cartão';
        document.getElementById('cardStatusConfirmMessage').textContent=activating
            ?'Queres reativar o cartão '+button.dataset.name+'?'
            :'Queres suspender o cartão '+button.dataset.name+'? Não será possível registar novas compras enquanto estiver suspenso.';
        document.getElementById('cardStatusConfirmError').classList.add('d-none');
        statusModal?.show();
    }));
    document.getElementById('cardStatusConfirmSubmit')?.addEventListener('click',async()=>{
        if(!pendingStatusButton)return;
        const submit=document.getElementById('cardStatusConfirmSubmit');submit.disabled=true;
        try{
            const response=await fetch(pendingStatusButton.dataset.statusUrl,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({status:pendingStatusButton.dataset.status})});
            const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível alterar o cartão.');
            window.location.reload();
        }catch(error){const el=document.getElementById('cardStatusConfirmError');el.textContent=error.message;el.classList.remove('d-none');}
        finally{submit.disabled=false;}
    });
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

@push('styles')
<style>
.financial-history-row { min-width: 0; }
.financial-history-row-icon { width: 2.25rem; height: 2.25rem; }
.financial-history-row-icon svg { width: 1rem; height: 1rem; }
.financial-history-row-chevron { width: .9rem; height: .9rem; }
@media (max-width: 575.98px) {
    .financial-history-row-icon { width: 2rem; height: 2rem; }
    .financial-history-row { gap: .55rem; }
}
</style>
@endpush

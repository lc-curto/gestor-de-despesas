@extends('layouts.app')

@section('title', 'Cartões')

@section('content')
<div class="app-content credit-cards-page">
    <x-financial.page-header title="Meus cartões">
        <x-slot:actions>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createCardModal">
                <i data-lucide="plus" aria-hidden="true"></i><span class="ms-1">Adicionar</span>
            </button>
        </x-slot:actions>
    </x-financial.page-header>
    <div id="cardsFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    <section class="d-grid gap-3" aria-label="Os meus cartões">
        @forelse($cards as $card)
            @php $isInactive = $card->status !== 'active' || ! $card->is_operational; @endphp
            <article class="card border rounded-4 credit-card-item {{ $isInactive ? 'credit-card-inactive' : '' }}" @if($isInactive) hidden @endif>
                <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                        <a href="{{ route('financial.cards.show', ['id' => $card->id, 'return_to' => request()->getRequestUri()]) }}" class="text-decoration-none text-body flex-grow-1 min-w-0" aria-label="Abrir {{ $card->name }}">
                            <span class="fw-semibold text-break">{{ $card->name }}</span>
                        </a>
                        <div class="dropdown flex-shrink-0">
                            <button class="btn btn-sm card-actions-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações de {{ $card->name }}">
                                <i data-lucide="ellipsis" aria-hidden="true"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                @if($card->status !== 'closed' && $card->is_operational)
                                    <li><button class="dropdown-item js-edit-card" type="button"
                                        data-name="{{ $card->name }}" data-update-url="{{ route('financial.accounts.update',$card->id) }}"
                                        data-settings-url="{{ route('financial.cards.settings',$card->id) }}"
                                        data-limit="{{ $card->credit_limit }}" data-closing="{{ $card->closing_day }}"
                                        data-due="{{ $card->due_day }}" data-opening-debt="{{ $card->opening_debt_amount }}">Editar</button></li>
                                @endif
                                @if($card->status === 'active' && $card->is_operational)
                                    <li><button class="dropdown-item js-card-status" type="button" data-status="suspended" data-status-url="{{ route('financial.cards.status',$card->id) }}" data-name="{{ $card->name }}">Suspender</button></li>
                                @elseif($card->status === 'suspended' && $card->is_operational)
                                    <li><button class="dropdown-item js-card-status" type="button" data-status="active" data-status-url="{{ route('financial.cards.status',$card->id) }}" data-name="{{ $card->name }}">Reativar</button></li>
                                @endif
                                @if($card->status === 'closed' || ! $card->is_operational)
                                    <li><button class="dropdown-item js-reopen-card" type="button" data-url="{{ route('financial.cards.reopen',$card->id) }}">Reabrir</button></li>
                                @endif
                                @if($card->status === 'active' && $card->is_operational && $card->used_limit <= 0.00001)
                                    <li><button class="dropdown-item js-close-card text-danger" type="button" data-close-url="{{ route('financial.accounts.close',$card->id) }}">Encerrar</button></li>
                                @endif
                                @if($card->can_delete)
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item js-delete-card text-danger" type="button" data-url="{{ route('financial.cards.destroy',$card->id) }}">Eliminar</button></li>
                                @endif
                            </ul>
                        </div>
                    </div>
                    <a href="{{ route('financial.cards.show', ['id' => $card->id, 'return_to' => request()->getRequestUri()]) }}" class="text-decoration-none text-body d-block">
                        <span class="badge {{ $isInactive ? 'text-bg-secondary' : 'text-bg-success' }} mb-2">{{ $isInactive ? 'Suspenso' : 'Ativo' }}</span>
                        <strong class="d-block fs-3 lh-1 mb-1 {{ $isInactive ? 'text-body' : 'text-success' }}">{{ number_format($card->available_limit,2,',','.') }} {{ $card->currency }}</strong>
                        <span class="small text-secondary">Disponível de {{ number_format((float)$card->credit_limit,2,',','.') }} {{ $card->currency }}</span>
                        <div class="progress mt-2" role="progressbar" aria-label="Percentagem do limite utilizado" aria-valuenow="{{ round($card->usage_percent) }}" aria-valuemin="0" aria-valuemax="100" style="height:5px">
                            <div class="progress-bar" style="width:{{ $card->usage_percent }}%"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center gap-2 mt-2 small text-secondary">
                            <span>Utilizado {{ number_format($card->used_limit,2,',','.') }} {{ $card->currency }}</span>
                            <span class="text-end">Fecho {{ $card->closing_day ?? '—' }} · Vence {{ $card->due_day ?? '—' }}</span>
                        </div>
                    </a>
                </div>
            </article>
        @empty
            <div class="card border-0 rounded-4 text-center p-4"><div class="card-body"><i data-lucide="credit-card" class="mb-3 text-secondary" style="width:32px;height:32px"></i><h2 class="h5">Ainda não tens cartões</h2><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCardModal">Adicionar</button></div></div>
        @endforelse
    </section>
    @if($inactiveCards->isNotEmpty())
        <div class="mt-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleInactiveCards" aria-expanded="false">
                <i data-lucide="archive" aria-hidden="true"></i>
                <span>Inativos ({{ $inactiveCards->count() }})</span>
            </button>
        </div>
    @endif
</div>

<div class="modal fade" id="createCardModal" tabindex="-1" aria-labelledby="createCardTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow rounded-4">
<form id="createCardForm">
    <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="createCardTitle">Novo cartão</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body p-4">
        <label class="form-label" for="newCardName">Nome</label><input class="form-control mb-3" id="newCardName" name="name" maxlength="120" placeholder="Ex.: Novo Banco Crédito" required>
        <label class="form-label" for="newCardLimit">Limite total (€)</label><input class="form-control" id="newCardLimit" name="credit_limit" type="text" inputmode="decimal" placeholder="1.000,00" aria-describedby="newCardLimitHelp" required><small class="text-secondary d-block mt-1" id="newCardLimitHelp">Formato: 1.000,00.</small>
        
        <div class="rounded-3 p-3 bg-body-tertiary mb-3"><label class="form-label" for="newCardUsed">Limite já utilizado (€)</label><input class="form-control" id="newCardUsed" name="opening_debt_amount" type="text" inputmode="decimal" value="0,00" aria-describedby="newCardUsedHelp" required><small class="text-secondary d-block mt-1" id="newCardUsedHelp">Formato: 1.000,00.</small></div>
        <div class="row g-3"><div class="col-6"><label class="form-label" for="newCardClosing">Dia de fecho</label><input class="form-control" id="newCardClosing" name="closing_day" type="number" min="1" max="31" value="10" required></div><div class="col-6"><label class="form-label" for="newCardDue">Dia de vencimento</label><input class="form-control" id="newCardDue" name="due_day" type="number" min="1" max="31" value="1" required></div></div>
        <div class="small text-danger mt-3" id="createCardError" role="alert"></div>
    </div>
    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
</form></div></div></div>

<div class="modal fade" id="editCardModal" tabindex="-1" aria-labelledby="editCardTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow rounded-4">
<form id="editCardForm">
    <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="editCardTitle">Editar cartão</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body p-4">
        <label class="form-label" for="editCardName">Nome</label><input class="form-control mb-3" id="editCardName" name="name" maxlength="120" required>
        <label class="form-label" for="editCardLimit">Limite total (€)</label><input class="form-control" id="editCardLimit" name="credit_limit" type="text" inputmode="decimal" aria-describedby="editCardLimitHelp" required><small class="text-secondary d-block mt-1" id="editCardLimitHelp">Formato: 1.000,00.</small>
        
        <div class="row g-3"><div class="col-6"><label class="form-label" for="editCardClosing">Dia de fecho</label><input class="form-control" id="editCardClosing" name="closing_day" type="number" min="1" max="31" required></div><div class="col-6"><label class="form-label" for="editCardDue">Dia de vencimento</label><input class="form-control" id="editCardDue" name="due_day" type="number" min="1" max="31" required></div></div>
        <div class="rounded-3 p-3 bg-body-tertiary mt-3"><label class="form-label" for="editCardOpeningDebt">Dívida inicial / limite já utilizado (€)</label><input class="form-control" id="editCardOpeningDebt" name="opening_debt_amount" type="text" inputmode="decimal" aria-describedby="editCardOpeningDebtHelp" required><small class="text-secondary d-block mt-1" id="editCardOpeningDebtHelp">Formato: 1.000,00.</small></div><div class="small text-danger mt-3" id="editCardError" role="alert"></div>
    </div>
    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
</form></div></div></div>

<div class="modal fade" id="cardStatusConfirmModal" tabindex="-1" aria-labelledby="cardStatusConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow rounded-4">
        <div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="cardStatusConfirmTitle">Suspender cartão</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><p class="mb-0" id="cardStatusConfirmMessage"></p><div class="small text-danger mt-3 d-none" id="cardStatusConfirmError" role="alert"></div></div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="cardStatusConfirmSubmit">Confirmar</button></div>
    </div></div>
</div>

@endsection

@push('styles')
<style>
.credit-card-item { transition: border-color .15s ease, background-color .15s ease; }
.card-actions-trigger { width: 40px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--bs-border-color); border-radius: .65rem; color: var(--bs-secondary-color); background: var(--bs-body-bg); }
.card-actions-trigger:hover, .card-actions-trigger:focus-visible { color: var(--bs-body-color); border-color: var(--bs-secondary-color); }
.credit-card-item:hover { border-color: var(--bs-primary); background-color: var(--bs-tertiary-bg); }
.credit-card-item:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
.credit-card-item .progress { background-color: var(--bs-secondary-bg); }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const token=document.querySelector('meta[name="csrf-token"]')?.content;
    const feedback=document.getElementById('cardsFeedback');
    const headers={'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token};
    const showError=(element,message)=>{element.textContent=message;};
    // Do not use Number or type=number here: both can silently reinterpret a localized value.
    const normalizeMoney=(value,allowZero)=>{
        const text=value.trim();
        let parts;
        if (/^[0-9]{1,3}(?:\.[0-9]{3})+,[0-9]{1,2}$/.test(text)) {
            parts=text.replace(/\./g,'').split(',');
        } else if (/^[0-9]+(?:[.,][0-9]{1,2})?$/.test(text)) {
            parts=text.split(/[.,]/);
        } else return null;
        const whole=parts[0].replace(/^0+(?=\d)/,'');
        const fraction=(parts[1]||'').padEnd(2,'0');
        if (whole.length>10 || (!allowZero && whole==='0' && fraction==='00')) return null;
        return whole+'.'+fraction;
    };
    const moneyFields=['credit_limit','opening_debt_amount'];
    const moneyValues=(form,errorElement)=>{
        const result={};
        for (const name of moneyFields) {
            const input=form.elements[name];
            const value=normalizeMoney(input.value,name==='opening_debt_amount');
            if (value===null) {
                const minimum=name==='credit_limit'?'O limite total deve ser superior a zero.':'O limite já utilizado pode ser zero, mas não negativo.';
                const message=(name==='credit_limit'?'Limite total':'Limite já utilizado')+': valor inválido. Usa até duas casas decimais; para milhares escreve, por exemplo, 1.000,00. '+minimum+' O máximo é 9.999.999.999,99.';
                input.setCustomValidity(message);showError(errorElement,message);input.reportValidity();return null;
            }
            result[name]=value;
        }
        return result;
    };
    for (const [formId,errorId] of [['createCardForm','createCardError'],['editCardForm','editCardError']]) {
        const form=document.getElementById(formId);
        moneyFields.forEach(name=>form.elements[name].addEventListener('input',()=>{
            form.elements[name].setCustomValidity('');showError(document.getElementById(errorId),'');
        }));
    }
    document.getElementById('createCardForm')?.addEventListener('submit',async(event)=>{
        event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type="submit"]'),errorElement=document.getElementById('createCardError');showError(errorElement,'');
        const amounts=moneyValues(form,errorElement);if(!amounts)return;button.disabled=true;
        try{const payload=Object.fromEntries(new FormData(form).entries());Object.assign(payload,amounts);payload.type='credit_card';
            const response=await fetch('{{ route('financial.accounts.store') }}',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify(payload)});
            const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível criar o cartão.');window.location.reload();
        }catch(error){showError(document.getElementById('createCardError'),error.message);button.disabled=false;}
    });
    document.querySelectorAll('.js-edit-card').forEach(button=>button.addEventListener('click',()=>{
        const form=document.getElementById('editCardForm');form.dataset.updateUrl=button.dataset.updateUrl;form.dataset.settingsUrl=button.dataset.settingsUrl;
        form.elements.name.value=button.dataset.name;form.elements.credit_limit.value=button.dataset.limit||'';form.elements.closing_day.value=button.dataset.closing||'';form.elements.due_day.value=button.dataset.due||'';form.elements.opening_debt_amount.value=button.dataset.openingDebt||'';
        moneyFields.forEach(name=>form.elements[name].setCustomValidity(''));
        showError(document.getElementById('editCardError'),'');
        const detailsModal=typeof button.closest==='function' ? button.closest('.modal') : null;
        if(detailsModal){
            detailsModal.addEventListener('hidden.bs.modal',()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('editCardModal')).show(),{once:true});
            bootstrap.Modal.getOrCreateInstance(detailsModal).hide();
        } else {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editCardModal')).show();
        }
    }));
    document.getElementById('editCardForm')?.addEventListener('submit',async(event)=>{
        event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type="submit"]'),errorElement=document.getElementById('editCardError');showError(errorElement,'');
        const amounts=moneyValues(form,errorElement);if(!amounts)return;button.disabled=true;
        try{let response=await fetch(form.dataset.settingsUrl,{method:'PUT',credentials:'same-origin',headers,body:JSON.stringify({...amounts,closing_day:form.elements.closing_day.value,due_day:form.elements.due_day.value})});
            let result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o cartão.');
            response=await fetch(form.dataset.updateUrl,{method:'PUT',credentials:'same-origin',headers,body:JSON.stringify({name:form.elements.name.value})});result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o nome.');window.location.reload();
        }catch(error){showError(document.getElementById('editCardError'),error.message);button.disabled=false;}
    });
    document.getElementById('toggleInactiveCards')?.addEventListener('click', (event) => {
        const button=event.currentTarget, show=button.getAttribute('aria-expanded')!=='true';
        document.querySelectorAll('.credit-card-inactive').forEach(card=>card.hidden=!show);
        button.setAttribute('aria-expanded',String(show));
        button.querySelector('span').textContent=(show?'Ocultar':'Inativos')+' ({{ $inactiveCards->count() }})';
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
        const button=document.getElementById('cardStatusConfirmSubmit');
        button.disabled=true;
        try{
            const response=await fetch(pendingStatusButton.dataset.statusUrl,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({status:pendingStatusButton.dataset.status})});
            const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível alterar o estado.');
            window.location.reload();
        }catch(error){
            const el=document.getElementById('cardStatusConfirmError');el.textContent=error.message;el.classList.remove('d-none');
        }finally{button.disabled=false;}
    });
    document.querySelectorAll('.js-reopen-card').forEach(button=>button.addEventListener('click',async()=>{
        if(!window.confirm('Reabrir cartão? Limite, dívida e histórico preservados.'))return;
        button.disabled=true;
        try{const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({})});const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível reabrir o cartão.');window.location.reload();
        }catch(error){feedback.className='alert alert-danger';feedback.textContent=error.message;button.disabled=false;}
    }));
    document.querySelectorAll('.js-delete-card').forEach(button=>button.addEventListener('click',async()=>{
        if(!window.confirm('Excluir definitivamente? Ação irreversível.'))return;
        button.disabled=true;
        try{const response=await fetch(button.dataset.url,{method:'DELETE',credentials:'same-origin',headers});const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível excluir o cartão.');window.location.reload();
        }catch(error){feedback.className='alert alert-danger';feedback.textContent=error.message;feedback.classList.remove('d-none');button.disabled=false;}
    }));
    document.querySelectorAll('.js-close-card').forEach(button=>button.addEventListener('click',async()=>{
        if(!window.confirm('Encerrar cartão? Histórico preservado.'))return;button.disabled=true;
        try{const response=await fetch(button.dataset.closeUrl,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({})});const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(result.message||'Não foi possível encerrar o cartão.');window.location.reload();
        }catch(error){feedback.className='alert alert-danger';feedback.textContent=error.message;button.disabled=false;}
    }));
})();
</script>
@endpush

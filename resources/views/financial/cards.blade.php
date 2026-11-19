@extends('layouts.app')

@section('title', 'Cartões')

@section('content')
<div class="app-content credit-cards-page">
    <header class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div><h1 class="h2 fw-bold mb-1">Cartões</h1></div>
        <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createCardModal"><i data-lucide="plus" aria-hidden="true"></i><span class="ms-1">Novo cartão</span></button></div>
    </header>
    <div id="cardsFeedback" class="alert d-none" role="status" aria-live="polite"></div>
    <section class="row row-cols-3 g-2 mb-4 cards-kpi-grid" aria-label="Resumo dos cartões">
        <div class="col"><div class="card border-0 rounded-4 h-100"><div class="card-body p-3"><span class="small text-secondary">Limite utilizado</span><strong class="d-block fs-4 mt-1">{{ number_format($summary['used'],2,',','.') }} {{ auth()->user()->currency }}</strong></div></div></div>
        <div class="col"><div class="card border-0 rounded-4 h-100"><div class="card-body p-3"><span class="small text-secondary">Crédito disponível</span><strong class="d-block fs-4 mt-1 text-success">{{ number_format($summary['available'],2,',','.') }} {{ auth()->user()->currency }}</strong></div></div></div>
        <div class="col"><div class="card border-0 rounded-4 h-100"><div class="card-body p-3"><span class="small text-secondary">Cartões ativos</span><strong class="d-block fs-4 mt-1">{{ $summary['count'] }}</strong></div></div></div>
    </section>
    <section class="cards-grid" aria-label="Os meus cartões">
        @forelse($cards as $card)
            @php($isInactive = $card->status !== 'active' || !$card->is_operational)
            <article class="credit-card-item {{ $isInactive ? 'credit-card-inactive' : '' }}" @if($isInactive) hidden @endif>
                <div class="credit-card-top">
                    <a href="{{ route('financial.accounts.show',$card->id) }}" class="credit-card-title-link"><span class="credit-card-icon"><i data-lucide="credit-card" aria-hidden="true"></i></span><span><strong>{{ $card->name }}</strong><small>Cartão de crédito</small></span></a>
                    <div class="dropdown"><button class="credit-card-more" type="button" data-bs-toggle="dropdown" aria-label="Ações do cartão"><i data-lucide="more-horizontal"></i></button><ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        @if($card->status!=='closed' && $card->is_operational)<li><button class="dropdown-item js-edit-card" type="button" data-bs-toggle="modal" data-bs-target="#editCardModal" data-name="{{ $card->name }}" data-update-url="{{ route('financial.accounts.update',$card->id) }}" data-settings-url="{{ route('financial.cards.settings',$card->id) }}" data-close-url="{{ route('financial.accounts.close',$card->id) }}" data-limit="{{ $card->credit_limit }}" data-closing="{{ $card->closing_day }}" data-due="{{ $card->due_day }}" data-opening-debt="{{ $card->opening_debt_amount }}" data-used="{{ number_format($card->used_limit,2,'.','') }}">Editar cartão</button></li>@endif
                        @if($card->status==='active' && $card->is_operational)<li><button class="dropdown-item js-card-status" data-status="suspended" data-status-url="{{ route('financial.cards.status',$card->id) }}" data-name="{{ $card->name }}">Suspender cartão</button></li>@elseif($card->status==='suspended' && $card->is_operational)<li><button class="dropdown-item js-card-status" data-status="active" data-status-url="{{ route('financial.cards.status',$card->id) }}" data-name="{{ $card->name }}">Reativar cartão</button></li>@endif
                        @if($card->status==='closed' || !$card->is_operational)<li><button class="dropdown-item js-reopen-card" data-url="{{ route('financial.cards.reopen',$card->id) }}" data-name="{{ $card->name }}">Reabrir cartão</button></li>@endif
                        @if($card->can_delete)<li><button class="dropdown-item text-danger js-delete-card" data-url="{{ route('financial.cards.destroy',$card->id) }}" data-name="{{ $card->name }}">Excluir definitivamente</button></li>@endif
                        @if($card->status==='active' && $card->is_operational && $card->used_limit<=0.00001)<li><button class="dropdown-item text-danger js-close-card" type="button" data-close-url="{{ route('financial.accounts.close',$card->id) }}" data-name="{{ $card->name }}">Encerrar cartão</button></li>@endif
                    </ul></div>
                </div>
                <div class="credit-card-values"><div><small>Utilizado</small><strong>{{ number_format($card->used_limit,2,',','.') }} {{ $card->currency }}</strong></div><div class="text-end"><small>Disponível</small><strong class="text-success">{{ number_format($card->available_limit,2,',','.') }} {{ $card->currency }}</strong></div></div>
                <div class="credit-card-progress"><span style="width:{{ $card->usage_percent }}%"></span></div>
                <div class="credit-card-usage"><small>Utilização: {{ number_format($card->usage_percent,0) }}%</small><small>Limite: {{ number_format((float)$card->credit_limit,2,',','.') }} {{ $card->currency }}</small></div>
                <div class="credit-card-cycle"><span>Fecho: <strong>dia {{ $card->closing_day??'—' }}</strong></span><span>Vencimento: <strong>dia {{ $card->due_day??'—' }}</strong></span></div>
                @if($card->status!=='active' || !$card->is_operational)<div class="mt-3"><span class="badge text-bg-secondary">{{ $card->status === 'suspended' ? 'Suspenso' : 'Encerrado' }}</span></div>@endif
                <div class="d-grid gap-2 mt-3"><a href="{{ route('financial.invoices.page', ['account_id' => $card->id]) }}" class="btn btn-outline-primary btn-sm"><i data-lucide="receipt" aria-hidden="true"></i><span class="ms-1">Faturas</span></a><a href="{{ route('financial.accounts.show',$card->id) }}" class="btn btn-outline-secondary btn-sm">Ver cartão e movimentos</a></div>
            </article>
        @empty
            <div class="card border-0 rounded-4 text-center p-4"><div class="card-body"><i data-lucide="credit-card" class="mb-3 text-secondary" style="width:32px;height:32px"></i><h2 class="h5">Ainda não tens cartões</h2><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCardModal">Adicionar cartão</button></div></div>
        @endforelse
    </section>
    @if($inactiveCards->isNotEmpty())
        <div class="mt-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleInactiveCards" aria-expanded="false">
                <i data-lucide="archive" aria-hidden="true"></i>
                <span>Mostrar cartões inativos ({{ $inactiveCards->count() }})</span>
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
    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar cartão</button></div>
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
    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar alterações</button></div>
</form></div></div></div>
@endsection

@push('styles')
<style>

.cards-kpi-grid>.col{min-width:0}.cards-kpi-grid .card-body{min-width:0;padding:.85rem!important}.cards-kpi-grid .card-body>span{display:block;min-height:2.2em;font-size:.82rem;line-height:1.15}.cards-kpi-grid .card-body>strong{font-size:clamp(.78rem,2.5vw,1.35rem)!important;line-height:1.2;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
@media(max-width:640px){.cards-kpi-grid{--bs-gutter-x:.5rem;--bs-gutter-y:.5rem}.cards-kpi-grid .card-body{padding:.7rem!important}.cards-kpi-grid .card-body>span{font-size:.7rem;min-height:2.3em}.cards-kpi-grid .card-body>strong{font-size:clamp(.72rem,2.7vw,.95rem)!important;letter-spacing:-.025em}}
.cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr));gap:1rem}
.credit-card-item[hidden]{display:none!important}.credit-card-item{min-width:0;padding:1.15rem;border:1px solid var(--app-border);border-radius:1rem;background:var(--bs-body-bg);box-shadow:var(--app-shadow)}
.credit-card-top,.credit-card-title-link,.credit-card-values,.credit-card-usage,.credit-card-cycle{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.credit-card-title-link{justify-content:flex-start;min-width:0;color:inherit;text-decoration:none}.credit-card-title-link>span:last-child{display:grid;gap:.2rem;min-width:0}.credit-card-title-link strong{overflow-wrap:anywhere}
.credit-card-title-link small,.credit-card-values small,.credit-card-usage small{color:var(--bs-secondary-color);font-size:.8rem}
.credit-card-icon{display:grid;place-items:center;width:44px;height:44px;flex:0 0 44px;border-radius:.85rem;background:rgba(99,91,255,.1);color:var(--app-primary)}.credit-card-icon svg{width:21px}
.credit-card-more{border:0;background:transparent;color:var(--bs-secondary-color);padding:.4rem}.credit-card-values{margin:1.15rem 0 .7rem;align-items:flex-end}.credit-card-values>div{display:grid;gap:.2rem}
.credit-card-values strong{font-size:1.1rem;white-space:nowrap;font-variant-numeric:tabular-nums}.credit-card-progress{height:8px;border-radius:99px;background:var(--bs-tertiary-bg);overflow:hidden}.credit-card-progress span{display:block;height:100%;border-radius:inherit;background:var(--app-primary)}
.credit-card-usage{margin-top:.4rem}.credit-card-cycle{justify-content:flex-start;gap:1.5rem;margin-top:1rem;padding-top:.85rem;border-top:1px solid var(--app-border);font-size:.88rem;color:var(--bs-secondary-color)}.credit-card-cycle strong{color:var(--bs-body-color)}
@media(max-width:640px){.credit-card-item{padding:1rem}.credit-card-values strong{font-size:1rem}}
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
        button.querySelector('span').textContent=(show?'Ocultar':'Mostrar')+' cartões inativos ({{ $inactiveCards->count() }})';
    });
    document.querySelectorAll('.js-card-status').forEach(button=>button.addEventListener('click',async()=>{
        const next=button.dataset.status, verb=next==='active'?'reativar':'suspender';
        if(!window.confirm('Queres '+verb+' o cartão '+button.dataset.name+'?'))return;
        button.disabled=true;
        try{const response=await fetch(button.dataset.statusUrl,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({status:next})});const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível alterar o estado.');
            window.location.reload();
        }catch(error){feedback.className='alert alert-danger';feedback.textContent=error.message;button.disabled=false;}
    }));
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

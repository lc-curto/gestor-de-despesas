@extends('layouts.app')

@section('title', 'Parcelamentos')

@section('content')
<div class="app-content commitments-page">
    <header class="page-heading commitments-heading mb-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h1 class="h2 fw-bold mb-1">Parcelamentos</h1>
            <p class="text-secondary mb-0">Acompanha compras parceladas não relacionadas com cartões.</p>
        </div>
    </header>

    <div id="commitmentFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <section class="row g-3 mb-3 commitment-summary" aria-label="Resumo financeiro">
        <div class="col-md-4"><article class="card border-0 rounded-4 h-100 commitment-summary-card"><div class="card-body p-3 p-md-4">
            <p class="text-secondary small mb-1">Em aberto</p>
            <p class="h3 fw-bold mb-0">{{ number_format($summary['open'], 2, ',', '.') }} {{ auth()->user()->currency }}</p>
        </div></article></div>
        <div class="col-md-4"><article class="card border-0 rounded-4 h-100 commitment-summary-card"><div class="card-body p-3 p-md-4">
            <p class="text-secondary small mb-1">A vencer</p>
            <p class="h3 fw-bold mb-0">{{ number_format($summary['upcoming'], 2, ',', '.') }} {{ auth()->user()->currency }}</p>
        </div></article></div>
        <div class="col-md-4"><article class="card border-0 rounded-4 h-100 commitment-summary-card"><div class="card-body p-3 p-md-4">
            <p class="text-secondary small mb-1">Em atraso</p>
            <p class="h3 fw-bold mb-0 {{ $summary['overdue'] > 0 ? 'text-danger' : '' }}">{{ number_format($summary['overdue'], 2, ',', '.') }} {{ auth()->user()->currency }}</p>
        </div></article></div>
    </section>

    @php
        $paymentItems = collect();
        foreach ($commitments as $commitment) {
            foreach ($commitment->parcelas as $installment) {
                $remaining = max(0, (float) $installment->amount - (float) $installment->paid_amount);
                if ($remaining > 0) {
                    $paymentItems->push((object) ['kind' => 'installment', 'due_date' => $installment->due_date, 'record' => $installment, 'commitment' => $commitment, 'remaining' => $remaining]);
                }
            }
        }
        $paymentItems = $paymentItems->sortBy('due_date')->values();
        $filter = request('filter', 'all');
        if ($filter === 'overdue') $paymentItems = $paymentItems->filter(fn ($item) => \Illuminate\Support\Carbon::parse($item->due_date)->lt(today()))->values();
        if ($filter === 'upcoming') $paymentItems = $paymentItems->filter(fn ($item) => !\Illuminate\Support\Carbon::parse($item->due_date)->lt(today()))->values();
        if ($filter === 'paid') $paymentItems = collect();
        $paymentGroups = $paymentItems->groupBy(fn ($item) => $item->commitment->id);
        $historyItems = collect();
        foreach ($commitments as $commitment) foreach ($commitment->parcelas as $part) if ((float) $part->paid_amount > 0) $historyItems->push((object) ['name' => $commitment->name.' · Parcela '.$part->installment_number, 'date' => $part->updated_at, 'amount' => (float) $part->paid_amount, 'detail' => 'Pagamento de prestação']);
        $historyItems = $historyItems->sortByDesc('date')->values();
        $statusLabels = ['open' => 'Aberta', 'closed' => 'Fechada', 'partial' => 'Parcialmente paga', 'paid' => 'Paga', 'overdue' => 'Em atraso'];
        $statusClasses = ['open' => 'text-bg-info', 'closed' => 'text-bg-secondary', 'partial' => 'text-bg-warning', 'paid' => 'text-bg-success', 'overdue' => 'text-bg-danger'];
    @endphp

    <section class="commitment-section commitment-payments-section mb-4 commitment-mobile-list" aria-labelledby="paymentsSectionTitle">
        <div class="commitment-mobile-header">
            <div><h2 class="h5 fw-bold mb-0" id="paymentsSectionTitle">Parcelamentos<span class="visually-hidden"> · Próximas prestações</span></h2><span class="small text-secondary">{{ $paymentItems->count() }} em aberto</span></div>
            <nav class="commitment-filters" aria-label="Filtrar parcelamentos">
                @foreach (['all'=>'Todos','upcoming'=>'Próximos','overdue'=>'Atrasados','paid'=>'Pagos'] as $key=>$label)
                    <a class="btn btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('financial.commitments.page',['filter'=>$key]) }}">{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        @forelse ($paymentGroups as $group)
            @php
                $groupFirst = $group->first();
                $groupCommitment = $groupFirst->commitment;
                $totalParts = $groupCommitment->parcelas->count();
                $paidParts = $groupCommitment->parcelas->filter(fn($part) => (float)$part->paid_amount >= (float)$part->amount)->count();
                $progress = $totalParts ? min(100, round(($paidParts / $totalParts) * 100)) : 0;
                $next = $groupFirst->record;
            @endphp
            <article class="commitment-card">
                <div class="commitment-card-main">
                    <div class="commitment-card-title"><strong>{{ $groupCommitment->name }}</strong><span class="commitment-card-progress">{{ $paidParts }}/{{ $totalParts }}</span></div>
                    <div class="commitment-card-meta"><span>Próxima {{ $next->due_date->format('d M') }}<span class="visually-hidden"> · Compra a prazo</span></span><strong>{{ number_format($groupFirst->remaining, 2, ',', '.') }} {{ auth()->user()->currency }}</strong></div>
                    <div class="progress commitment-progress" role="progressbar" aria-label="{{ $paidParts }} de {{ $totalParts }} parcelas pagas" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $progress }}%"></div></div>
                    <div class="commitment-card-footer"><span class="small text-secondary">{{ number_format($group->sum('remaining'), 2, ',', '.') }} {{ auth()->user()->currency }} restante</span><details class="commitment-card-details"><summary>Detalhes</summary><div class="commitment-installments">
                        @foreach ($group as $item)
                            @php $installment=$item->record; $remaining=$item->remaining; $isOverdue=$installment->due_date->lt(today()); @endphp
                            <div class="commitment-installment-row"><div><strong>{{ $installment->installment_number }}/{{ $totalParts }}</strong><span>{{ $installment->due_date->format('d/m/Y') }}</span></div><div class="text-end"><strong>{{ number_format($remaining,2,',','.') }} {{ auth()->user()->currency }}</strong><span class="badge {{ $isOverdue ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $isOverdue ? 'Atrasada' : 'Próxima' }}</span></div></div>
                            @if ($remaining > 0 && $paymentAccounts->isNotEmpty())
                                <details class="commitment-pay-drawer commitment-payment-disclosure"><summary class="btn btn-primary btn-sm">{{ $loop->first ? 'Pagar próxima' : 'Pagar' }}</summary><form class="js-api-form commitment-pay-form" aria-label="Pagamento da prestação {{ $installment->installment_number }} de {{ $totalParts }} de {{ $groupCommitment->name }}" data-endpoint="{{ route('financial.commitments.installments.pay', [$groupCommitment->id, $installment->id]) }}" data-success="Pagamento registado."><label for="installment-amount-{{ $installment->id }}">Valor</label><input id="installment-amount-{{ $installment->id }}" type="text" inputmode="decimal" name="amount" data-payment-amount data-max-amount="{{ number_format($remaining, 2, '.', '') }}" value="{{ number_format($remaining, 2, ',', '.') }}" required><label for="installment-account-{{ $installment->id }}">Conta</label><select id="installment-account-{{ $installment->id }}" name="account_id" required><option value="">Selecionar</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select><label for="installment-date-{{ $installment->id }}">Data</label><input id="installment-date-{{ $installment->id }}" type="date" name="payment_date" value="{{ now()->toDateString() }}" required><button class="btn btn-primary" type="submit" disabled>Confirmar</button><div class="small text-danger" data-payment-error role="alert"></div></form></details>
                            @endif
                        @endforeach
                    </div></details></div>
                </div>
            </article>
        @empty
            <div class="commitment-empty-state"><strong>Sem parcelamentos pendentes</strong></div>
        @endforelse
    </section>

</div>

<div class="modal fade" id="editCommitmentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0"><form id="editCommitmentForm">
    <div class="modal-header border-0"><h2 class="modal-title h5 fw-bold">Editar compromisso</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body"><label class="form-label" for="editCommitmentName">Descrição</label><input class="form-control mb-3" id="editCommitmentName" name="name" required maxlength="160"><label class="form-label" for="editCommitmentDue">Próximo vencimento</label><input class="form-control" id="editCommitmentDue" name="due_date" type="date" required><div id="editCommitmentError" class="small text-danger mt-2"></div></div>
    <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
</form></div></div></div>

@push('styles')
<style>
.commitments-page .commitment-summary{display:none}
.commitments-page .commitment-mobile-header{display:flex;flex-direction:column;gap:.65rem;margin-bottom:1rem}
.commitments-page .commitment-mobile-header h2{font-size:1.1rem}
.commitments-page .commitment-filters{display:flex;gap:.4rem;overflow-x:auto;padding-bottom:.15rem}
.commitments-page .commitment-filters .btn{white-space:nowrap;border-radius:999px;padding:.35rem .7rem;font-size:.78rem}
.commitments-page .commitment-card{background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:1rem;margin-bottom:.65rem;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.commitments-page .commitment-card-main{padding:1rem}
.commitments-page .commitment-card-title,.commitments-page .commitment-card-meta,.commitments-page .commitment-card-footer{display:flex;justify-content:space-between;align-items:center;gap:.65rem}
.commitments-page .commitment-card-title strong{font-size:1rem;overflow-wrap:anywhere}
.commitments-page .commitment-card-progress{font-weight:700;color:var(--bs-primary);white-space:nowrap}
.commitments-page .commitment-card-meta{margin-top:.45rem;font-size:.82rem}
.commitments-page .commitment-card-meta strong{font-size:1rem}
.commitments-page .commitment-progress{height:.4rem;margin:.7rem 0;border-radius:99px;background:var(--bs-tertiary-bg)}
.commitments-page .commitment-progress .progress-bar{border-radius:99px;background:var(--bs-primary)}
.commitments-page .commitment-card-footer{align-items:flex-start}
.commitments-page .commitment-secondary-section{border-top:1px solid var(--bs-border-color);padding:.8rem 0}
.commitments-page .commitment-secondary-section>summary{display:flex;justify-content:space-between;align-items:center;cursor:pointer;font-size:.85rem;font-weight:600;list-style:none}
.commitments-page .commitment-secondary-section>summary::-webkit-details-marker{display:none}
.commitments-page .commitment-secondary-section>summary:after{content:'＋';color:var(--bs-primary);font-size:1.1rem}
.commitments-page .commitment-secondary-section[open]>summary:after{content:'−'}
.commitments-page .commitment-secondary-section .commitment-section{padding-top:.75rem}
.commitments-page .commitment-card-details{flex:0 0 auto}
.commitments-page .commitment-card-details>summary{color:var(--bs-primary);font-size:.8rem;font-weight:600;cursor:pointer;list-style:none}
.commitments-page .commitment-card-details>summary::-webkit-details-marker{display:none}
.commitments-page .commitment-card-details>summary:after{content:' ▾'}
.commitments-page .commitment-card-details[open]>summary:after{content:' ▴'}
.commitments-page .commitment-installments{margin-top:.8rem;border-top:1px solid var(--bs-border-color);padding-top:.25rem}
.commitments-page .commitment-installment-row{display:flex;justify-content:space-between;align-items:center;padding:.65rem 0;border-bottom:1px solid var(--bs-border-color);font-size:.8rem;gap:.5rem}
.commitments-page .commitment-installment-row div:first-child{display:flex;gap:.5rem;align-items:center}.commitments-page .commitment-installment-row span:not(.badge){color:var(--bs-secondary-color)}
.commitments-page .commitment-installment-row .badge{display:block;margin-top:.2rem;font-size:.65rem}
.commitments-page .commitment-pay-drawer{margin:.7rem 0}.commitments-page .commitment-pay-drawer>summary{display:block;text-align:center;cursor:pointer}
.commitments-page .commitment-pay-form{display:grid;grid-template-columns:1fr 1fr;gap:.45rem;margin-top:.65rem;padding:.7rem;background:var(--bs-tertiary-bg);border-radius:.75rem}
.commitments-page .commitment-pay-form label{font-size:.72rem;font-weight:600;align-self:end}.commitments-page .commitment-pay-form input,.commitments-page .commitment-pay-form select{width:100%;min-width:0;min-height:38px;border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.4rem;background:var(--bs-body-bg)}
.commitments-page .commitment-pay-form button,.commitments-page .commitment-pay-form [data-payment-error]{grid-column:1/-1}
.commitments-page .commitment-history,.commitments-page #manageSectionTitle{margin-top:1.5rem}
.commitments-page .commitment-history>summary{cursor:pointer;color:var(--bs-primary);font-size:.85rem;font-weight:600}
.commitments-page [aria-labelledby="manageSectionTitle"]>p{display:none}
.commitments-page [aria-labelledby="manageSectionTitle"]>article{padding:.75rem 0!important}
.commitments-page .commitment-empty-state{padding:1rem;border:1px dashed var(--bs-border-color);border-radius:.8rem}
@media (min-width:768px){.commitments-page .commitment-mobile-header{flex-direction:row;align-items:center;justify-content:space-between}.commitments-page .commitment-card{max-width:720px}.commitments-page .commitment-card-main{padding:1.15rem}}
</style>
@endpush
@endsection

@push('scripts')
<script src="{{ asset('js/commitments-payment.js') }}?v={{ filemtime(public_path('js/commitments-payment.js')) }}"></script>
<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const feedback = document.getElementById('commitmentFeedback');
    const modal = (id) => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    const showError = (element, message) => { element.className = 'alert alert-danger'; element.textContent = message; };

    document.querySelectorAll('.js-edit-commitment').forEach((button) => button.addEventListener('click', () => {
        const form=document.getElementById('editCommitmentForm'); form.dataset.url=button.dataset.url; form.elements.name.value=button.dataset.name; form.elements.due_date.value=button.dataset.due||'';
        document.getElementById('editCommitmentError').textContent=''; bootstrap.Modal.getOrCreateInstance(document.getElementById('editCommitmentModal')).show();
    }));
    document.getElementById('editCommitmentForm')?.addEventListener('submit',async(event)=>{
        event.preventDefault();const form=event.currentTarget,button=form.querySelector('[type="submit"]');button.disabled=true;
        try{const response=await fetch(form.dataset.url,{method:'PUT',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({name:form.elements.name.value,due_date:form.elements.due_date.value})});const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar.');window.location.reload();
        }catch(error){document.getElementById('editCommitmentError').textContent=error.message;button.disabled=false;}
    });
    document.querySelectorAll('.js-cancel-commitment').forEach(button=>button.addEventListener('click',async()=>{
        if(!window.confirm('Encerrar "'+button.dataset.name+'"? Os pagamentos já realizados serão preservados.'))return;button.disabled=true;
        try{const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({})});const result=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(result.message||'Não foi possível encerrar.');window.location.reload();
        }catch(error){showError(feedback,error.message);button.disabled=false;}
    }));

    document.querySelectorAll('.js-api-form').forEach((form) => {
        if (!window.CommitmentPayment || !window.CommitmentPayment.bind(form, token, feedback, showError)) {
            showError(feedback, 'Não foi possível carregar a validação de valores. Atualiza a página antes de pagar.');
        }
    });

})();
</script>
@endpush

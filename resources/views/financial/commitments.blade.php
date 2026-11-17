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

    <section class="commitment-section commitment-payments-section mb-4 commitment-compact-list" aria-labelledby="paymentsSectionTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <div>
                <h2 class="h5 fw-bold mb-1" id="paymentsSectionTitle">Próximas prestações</h2>
                <p class="small text-secondary mb-0">Agrupadas por compra. Abre um parcelamento para consultar ou pagar as prestações.</p>
            </div>
            <span class="badge text-bg-secondary">{{ $paymentItems->count() }}</span>
        </div>

        <nav class="d-flex flex-wrap gap-2 mb-2" aria-label="Filtrar compromissos">
            @foreach (['all'=>'Todos','upcoming'=>'A vencer','overdue'=>'Em atraso','paid'=>'Pagos'] as $key=>$label)
                <a class="btn btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('financial.commitments.page',['filter'=>$key]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @forelse ($paymentGroups as $group)
            @php $groupFirst = $group->first(); $groupCommitment = $groupFirst->commitment; @endphp
            <details class="commitment-group mb-2">
                <summary class="commitment-group-summary">
                    <span class="min-w-0"><strong class="d-block">{{ $groupCommitment->name }}</strong><span class="small text-secondary">Próxima: {{ $groupFirst->record->due_date->format('d/m/Y') }} · {{ $group->count() }} {{ $group->count() === 1 ? 'prestação neste filtro' : 'prestações neste filtro' }}</span></span>
                    <span class="text-end flex-shrink-0"><strong class="d-block">{{ number_format($group->sum('remaining'), 2, ',', '.') }} {{ auth()->user()->currency }}</strong><span class="small text-secondary">Em falta neste filtro</span></span>
                </summary>
                <div class="commitment-group-items">
                @foreach ($group as $item)
                    @if ($loop->index === 3)
                        <details class="commitment-more-items"><summary>Ver mais {{ $group->count() - 3 }} {{ $group->count() - 3 === 1 ? 'prestação' : 'prestações' }}</summary><div>
                    @endif
            @if ($item->kind === 'invoice')
                @php $invoice = $item->record; @endphp
                <article class="commitment-payment-item commitment-compact-row border-bottom py-2">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0">
                            <h3 class="small fw-semibold mb-0">{{ $invoice->conta->name ?? 'Cartão' }} · {{ $invoice->reference_month->format('m/Y') }}</h3>
                            <span class="small text-secondary">Fatura · Vence {{ $invoice->due_date->format('d/m/Y') }}</span>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <strong class="d-block">{{ number_format((float) $invoice->remaining_amount, 2, ',', '.') }} {{ $invoice->conta->currency ?? auth()->user()->currency }}</strong>
                            <span class="badge {{ $statusClasses[$invoice->display_status] ?? 'text-bg-secondary' }}">{{ $statusLabels[$invoice->display_status] ?? $invoice->display_status }}</span>
                        </div>
                    </div>
                    @if ((float) $invoice->remaining_amount > 0 && $paymentAccounts->isNotEmpty() && $invoice->display_status !== 'open')
                        <form class="js-api-form row g-2 align-items-end mt-2" data-endpoint="{{ route('financial.invoices.pay', $invoice->id) }}" data-success="Pagamento da fatura registado.">
                            <div class="col-12 col-md-4"><label class="form-label small" for="invoice-amount-{{ $invoice->id }}">Valor a pagar</label><input class="form-control form-control-sm" id="invoice-amount-{{ $invoice->id }}" type="text" inputmode="decimal" name="amount" data-payment-amount data-max-amount="{{ number_format((float) $invoice->remaining_amount, 2, '.', '') }}" value="{{ number_format((float) $invoice->remaining_amount, 2, ',', '.') }}" aria-describedby="invoice-amount-help-{{ $invoice->id }} invoice-amount-error-{{ $invoice->id }}" required><div class="form-text" id="invoice-amount-help-{{ $invoice->id }}">Usa vírgula para os cêntimos, por exemplo 90,50.</div><div class="small text-danger" id="invoice-amount-error-{{ $invoice->id }}" data-payment-error role="alert"></div></div>
                            <div class="col-12 col-md-4"><label class="form-label small" for="invoice-account-{{ $invoice->id }}">Conta de origem</label><select class="form-select form-select-sm" id="invoice-account-{{ $invoice->id }}" name="account_id" required><option value="">Selecionar conta</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-2"><label class="form-label small" for="invoice-date-{{ $invoice->id }}">Data</label><input class="form-control form-control-sm" id="invoice-date-{{ $invoice->id }}" type="date" name="payment_date" value="{{ now()->toDateString() }}" required></div>
                            <div class="col-12 col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit" disabled>Pagar</button></div>
                            <noscript><p class="small text-danger mb-0">Ativa o JavaScript para registar o pagamento.</p></noscript>
                        </form>
                    @elseif ((float) $invoice->remaining_amount > 0 && $invoice->display_status === 'open')
                        <p class="small text-secondary mt-2 mb-0">Fatura ainda aberta. O pagamento estará disponível após o fecho.</p>
                    @elseif ((float) $invoice->remaining_amount > 0)
                        <p class="small text-secondary mt-2 mb-0">Adiciona uma conta bancária ou de dinheiro para pagar.</p>
                    @endif
                    <details class="mt-1"><summary class="small text-secondary">Ver compras e pagamentos</summary>
                        <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Compra</th><th>Data</th><th class="text-end">Valor</th></tr></thead>
                            <tbody>@foreach ($invoice->movimentos as $purchase)<tr><td>{{ $purchase->description }}</td><td>{{ $purchase->movement_date->format('d/m/Y') }}</td><td class="text-end">{{ number_format((float) $purchase->amount, 2, ',', '.') }}</td></tr>@endforeach</tbody>
                            <tfoot><tr><th colspan="2">Total pago</th><th class="text-end">{{ number_format((float) ($invoice->paid_amount ?? 0), 2, ',', '.') }}</th></tr></tfoot>
                        </table></div>
                    </details>
                </article>
            @else
                @php $installment = $item->record; $remaining = $item->remaining; $commitment = $item->commitment; $isOverdue = $installment->due_date->lt(today()); @endphp
                <article class="commitment-payment-item border rounded-4 p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <h3 class="small fw-semibold mb-0">{{ $commitment->name }}</h3>
                            <span class="small text-secondary">{{ $commitment->payment_condition === 'installments' ? 'Compra parcelada · '.$installment->installment_number.'/'.$commitment->installments_count : 'Compra a prazo' }} · Vence {{ $installment->due_date->format('d/m/Y') }}</span>
                        </div>
                        <div class="text-end flex-shrink-0 commitment-installment-amount">
                            <strong class="d-block"><span class="small text-secondary fw-normal">Em falta: </span>{{ number_format($remaining, 2, ',', '.') }} {{ auth()->user()->currency }}</strong>
                            <span class="badge {{ $isOverdue ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $isOverdue ? 'Em atraso' : 'A vencer' }}</span>
                        </div>
                    </div>
                    <div class="commitment-item-actions mt-2">
                        <details class="commitment-details-disclosure">
                            <summary class="small text-secondary"><span class="commitment-disclosure-marker" aria-hidden="true">▸</span>Ver detalhe da prestação<span class="visually-hidden"> {{ $installment->installment_number }} de {{ $commitment->installments_count }} de {{ $commitment->name }}</span></summary>
                            <p class="small text-secondary mt-2 mb-0">Original: {{ number_format((float)$installment->amount,2,',','.') }} · Pago: {{ number_format((float)$installment->paid_amount,2,',','.') }} · Em falta: {{ number_format($remaining,2,',','.') }} {{ auth()->user()->currency }}</p>
                            <p class="small text-secondary mb-0">{{ $commitment->installments_count }} prestações no total</p>
                        </details>
                        @if ($remaining > 0 && $paymentAccounts->isNotEmpty())
                            <details class="commitment-payment-disclosure">
                                <summary class="btn btn-outline-primary btn-sm">
                                    <span class="commitment-payment-open">Registar pagamento</span><span class="commitment-payment-close">Fechar formulário</span><span class="visually-hidden"> da prestação {{ $installment->installment_number }} de {{ $commitment->installments_count }} de {{ $commitment->name }}</span>
                                </summary>
                                <form class="js-api-form row g-2 align-items-end mt-2" aria-label="Pagamento da prestação {{ $installment->installment_number }} de {{ $commitment->installments_count }} de {{ $commitment->name }}" data-endpoint="{{ route('financial.commitments.installments.pay', [$commitment->id, $installment->id]) }}" data-success="Pagamento da parcela registado.">
                                    <div class="col-12 col-md-4"><label class="form-label small" for="installment-amount-{{ $installment->id }}">Valor a pagar</label><input class="form-control form-control-sm" id="installment-amount-{{ $installment->id }}" type="text" inputmode="decimal" name="amount" data-payment-amount data-max-amount="{{ number_format($remaining, 2, '.', '') }}" value="{{ number_format($remaining, 2, ',', '.') }}" aria-describedby="installment-amount-help-{{ $installment->id }} installment-amount-error-{{ $installment->id }}" required><div class="form-text" id="installment-amount-help-{{ $installment->id }}">Usa vírgula para os cêntimos, por exemplo 90,50.</div><div class="small text-danger" id="installment-amount-error-{{ $installment->id }}" data-payment-error role="alert"></div></div>
                                    <div class="col-12 col-md-4"><label class="form-label small" for="installment-account-{{ $installment->id }}">Conta de origem</label><select class="form-select form-select-sm" id="installment-account-{{ $installment->id }}" name="account_id" required><option value="">Selecionar conta</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                                    <div class="col-12 col-md-2"><label class="form-label small" for="installment-date-{{ $installment->id }}">Data</label><input class="form-control form-control-sm" id="installment-date-{{ $installment->id }}" type="date" name="payment_date" value="{{ now()->toDateString() }}" required></div>
                                    <div class="col-12 col-md-2"><button class="btn btn-outline-primary btn-sm w-100" type="submit" disabled>Pagar<span class="visually-hidden"> prestação {{ $installment->installment_number }} de {{ $commitment->installments_count }} de {{ $commitment->name }}</span></button></div>
                                    <noscript><p class="small text-danger mb-0">Ativa o JavaScript para registar o pagamento.</p></noscript>
                                </form>
                            </details>
                        @elseif ($remaining > 0)
                            <p class="small text-secondary mb-0">Adiciona uma conta bancária ou de dinheiro para pagar. <a href="{{ route('financial.accounts.page') }}">Ir para Contas</a></p>
                        @endif
                    </div>
                </article>
            @endif
                @endforeach
                @if ($group->count() > 3)</div></details>@endif
                </div>
            </details>
        @empty
            <div class="commitment-empty-state d-flex align-items-center justify-content-between gap-3">
                <div class="min-w-0"><strong class="d-block small">Não existem pagamentos pendentes</strong><span class="small text-secondary">As novas faturas e prestações aparecerão aqui.</span></div>
            </div>
        @endforelse
        <div class="d-flex flex-wrap gap-3 mt-2 small">
            {{ $commitments->links() }}
        </div>
    </section>

    <section class="commitment-section mb-4" aria-labelledby="historySectionTitle">
        <div class="d-flex justify-content-between align-items-center mb-2"><div><h2 class="h5 fw-bold mb-1" id="historySectionTitle">Histórico de pagamentos</h2><p class="small text-secondary mb-0">Pagamentos já registados.</p></div><span class="badge text-bg-secondary">{{ $historyItems->count() }}</span></div>
        @if($historyItems->isNotEmpty()) <details class="commitment-history"><summary>Ver histórico de pagamentos ({{ $historyItems->count() }})</summary> @endif
        @forelse($historyItems as $history)
            @if($loop->index === 10) <details class="commitment-more-items"><summary>Ver mais {{ $historyItems->count() - 10 }} pagamentos</summary><div> @endif
            <article class="d-flex justify-content-between align-items-center gap-3 border-bottom py-3"><div><strong class="d-block">{{ $history->name }}</strong><span class="small text-secondary">{{ $history->detail }} · {{ \Illuminate\Support\Carbon::parse($history->date)->format('d/m/Y') }}</span></div><strong class="text-success text-nowrap">{{ number_format($history->amount,2,',','.') }} {{ auth()->user()->currency }}</strong></article>
        @empty <p class="small text-secondary">Ainda não existem pagamentos no histórico.</p> @endforelse
        @if($historyItems->count() > 10)</div></details>@endif
        @if($historyItems->isNotEmpty()) </details> @endif
    </section>

    <section class="commitment-section mb-4" aria-labelledby="manageSectionTitle">
        <h2 class="h5 fw-bold mb-1" id="manageSectionTitle">Gerir parcelamentos</h2><p class="small text-secondary">Editar a descrição e a próxima prestação ou encerrar o parcelamento.</p>
        @foreach($commitments as $commitment)
            @if(!in_array($commitment->status,['paid','cancelled'],true))
                <article class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom py-3"><div><strong>{{ $commitment->name }}</strong><div class="small text-secondary">{{ $commitment->payment_condition === 'installments' ? $commitment->installments_count.' prestações' : 'Pagamento único' }} · {{ number_format((float)$commitment->total_amount,2,',','.') }} {{ auth()->user()->currency }}</div></div>
                    <div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary js-edit-commitment" data-url="{{ route('financial.commitments.update',$commitment->id) }}" data-name="{{ $commitment->name }}" data-due="{{ optional($commitment->due_date)->format('Y-m-d') }}">Editar</button><button class="btn btn-sm btn-outline-danger js-cancel-commitment" data-url="{{ route('financial.commitments.cancel',$commitment->id) }}" data-name="{{ $commitment->name }}">Encerrar</button></div>
                </article>
            @endif
        @endforeach
    </section>
</div>

<div class="modal fade" id="editCommitmentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0"><form id="editCommitmentForm">
    <div class="modal-header border-0"><h2 class="modal-title h5 fw-bold">Editar compromisso</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body"><label class="form-label" for="editCommitmentName">Descrição</label><input class="form-control mb-3" id="editCommitmentName" name="name" required maxlength="160"><label class="form-label" for="editCommitmentDue">Próximo vencimento</label><input class="form-control" id="editCommitmentDue" name="due_date" type="date" required><div id="editCommitmentError" class="small text-danger mt-2"></div></div>
    <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
</form></div></div></div>

@push('styles')
<style>
.commitments-page .commitment-summary-card .card-body{padding:1rem!important}
.commitments-page .commitment-summary-card .h3{font-size:1.15rem;margin:0}
.commitments-page .commitment-group{border:1px solid var(--bs-border-color);border-radius:.8rem;background:var(--bs-body-bg)}
.commitments-page .commitment-group-summary{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.85rem 1rem;cursor:pointer;list-style:none}
.commitments-page .commitment-group-summary::-webkit-details-marker{display:none}
.commitments-page .commitment-group-summary:focus-visible,.commitments-page .commitment-more-items>summary:focus-visible,.commitments-page .commitment-history>summary:focus-visible{outline:3px solid var(--bs-primary);outline-offset:2px}
.commitments-page .commitment-group-summary strong{overflow-wrap:anywhere}
.commitments-page .commitment-group-summary:after{content:'▸';color:var(--bs-primary);flex:none}
.commitments-page .commitment-group[open]>.commitment-group-summary:after{content:'▾'}
.commitments-page .commitment-group-items{padding:0 1rem .75rem}
.commitments-page .commitment-more-items>summary,.commitments-page .commitment-history>summary{cursor:pointer;color:var(--bs-primary);font-size:.85rem;font-weight:600;padding:.65rem 0}
.commitments-page .commitment-payment-item{border-left:0!important;border-right:0!important;border-top:0!important;border-radius:0!important;padding:.55rem .15rem!important;margin:0!important}
.commitments-page .commitment-payment-item h3{font-size:.9rem;overflow-wrap:anywhere}
.commitments-page .commitment-installment-amount{max-width:50%;overflow-wrap:anywhere}
.commitments-page .commitment-payment-item .badge{font-size:.68rem;font-weight:500;padding:.28rem .45rem}
.commitments-page .commitment-payment-item .form-control,.commitments-page .commitment-payment-item .form-select{min-height:34px}
.commitments-page .commitment-payment-item details summary{font-size:.75rem}
.commitments-page .commitment-item-actions{display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .85rem}
.commitments-page .commitment-item-actions details{padding-top:0;border-top:0;min-width:0}
.commitments-page .commitment-item-actions details[open]{flex:1 0 100%}
.commitments-page .commitment-item-actions summary{display:inline-flex;align-items:center;gap:.3rem;max-width:100%}
.commitments-page .commitment-item-actions summary:focus-visible{outline:3px solid var(--bs-primary);outline-offset:2px}
.commitments-page .commitment-item-actions .commitment-disclosure-marker{display:inline-block;transition:transform .15s ease}
.commitments-page .commitment-details-disclosure[open] .commitment-disclosure-marker{transform:rotate(90deg)}
.commitments-page .commitment-payment-close,.commitments-page .commitment-payment-disclosure[open] .commitment-payment-open{display:none}
.commitments-page .commitment-payment-disclosure[open] .commitment-payment-close{display:inline}
.commitments-page .commitment-section nav .btn{padding:.25rem .6rem;font-size:.78rem}
.commitments-page .commitment-compact-list{max-width:100%}
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

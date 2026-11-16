@extends('layouts.app')

@section('title', 'Cartões e compromissos')

@section('content')
<div class="app-content commitments-page">
    <header class="page-heading commitments-heading mb-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h1 class="h2 fw-bold mb-1">Cartões e compromissos</h1>
            <p class="text-secondary mb-0">Acompanha o que tens por pagar, os próximos vencimentos e os pagamentos realizados.</p>
        </div>
    </header>

    <div id="commitmentFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <div class="modal fade" id="cardModal" tabindex="-1" aria-labelledby="cardModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="cardModalTitle">Adicionar cartão</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <div id="cardModalFeedback" class="alert d-none" role="alert"></div>
                <h3 class="h6 fw-bold mb-3">Dados do cartão</h3>
                <form id="createCardForm" class="row g-3">
                    <input type="hidden" name="type" value="credit_card">
                    <div class="col-12"><label class="form-label" for="newCardName">Nome do cartão</label><input class="form-control" id="newCardName" name="name" maxlength="120" placeholder="Ex.: Visa Santander" required></div>
                    <div class="col-md-4"><label class="form-label" for="newCardLimit">Limite de crédito</label><input class="form-control" id="newCardLimit" name="credit_limit" type="number" min="0.01" step="0.01" placeholder="Opcional"></div>
                    <div class="col-md-4"><label class="form-label" for="newCardClosing">Dia de fecho</label><input class="form-control" id="newCardClosing" name="closing_day" type="number" min="1" max="28" required></div>
                    <div class="col-md-4"><label class="form-label" for="newCardDue">Dia de vencimento</label><input class="form-control" id="newCardDue" name="due_day" type="number" min="1" max="28" required></div>
                    <div class="col-12 text-end"><button class="btn btn-primary" type="submit">Adicionar cartão</button></div>
                </form>
            </div>
        </div></div>
    </div>
    <div class="modal fade" id="expenseModal" tabindex="-1" aria-labelledby="expenseModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="expenseModalTitle">Nova despesa</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body" id="expenseModalBody"><p class="text-secondary">A carregar formulário…</p></div>
        </div></div>
    </div>

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

    <section class="commitment-section commitment-cards-section mb-4" aria-labelledby="cardsSectionTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1" id="cardsSectionTitle">Os meus cartões</h2>
                <p class="small text-secondary mb-0">Limite, utilização e ciclo de pagamento.</p>
            </div>
            @if ($cards->isNotEmpty())
                <button class="btn btn-primary btn-sm" type="button" id="openExpenseModal">Nova despesa</button>
            @endif
        </div>

        @forelse ($cards as $card)
            @php
                $used = (float) $card->used_limit;
                $limit = (float) $card->credit_limit;
                $usage = $limit > 0 ? min(100, ($used / $limit) * 100) : 0;
                $latestInvoice = $invoicePage->getCollection()->where('account_id', $card->id)->sortByDesc('reference_month')->first();
            @endphp
            <article class="commitment-card-item border rounded-4 p-3 mb-2">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <h3 class="h6 fw-bold mb-1">{{ $card->name }}</h3>
                        <span class="small text-secondary">Cartão de crédito</span>
                    </div>
                    <div class="text-end">
                        <strong class="d-block">{{ number_format($used, 2, ',', '.') }} {{ $card->currency }}</strong>
                        <span class="small text-secondary">Utilizado</span>
                    </div>
                </div>
                @if ($card->credit_limit)
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-secondary mb-1">
                            <span>Utilização do limite</span>
                            <span>{{ number_format($usage, 0) }}%</span>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Utilização do limite" aria-valuenow="{{ $usage }}" aria-valuemin="0" aria-valuemax="100" style="height:7px">
                            <div class="progress-bar" style="width:{{ $usage }}%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-1 small">
                            <span class="text-secondary">Disponível</span>
                            <strong>{{ number_format((float) $card->available_limit, 2, ',', '.') }} {{ $card->currency }}</strong>
                        </div>
                    </div>
                @else
                    <p class="small text-secondary mt-2 mb-0">Limite de crédito não configurado.</p>
                @endif
                <div class="d-flex flex-wrap gap-3 mt-3 small">
                    <span><span class="text-secondary">Fecho:</span> {{ $card->closing_day ? 'dia '.$card->closing_day : '—' }}</span>
                    <span><span class="text-secondary">Vencimento:</span> {{ $card->due_day ? 'dia '.$card->due_day : '—' }}</span>
                </div>
                @if ($latestInvoice)
                    <p class="small text-secondary mt-2 mb-0">Última fatura: <strong>{{ number_format((float) $latestInvoice->remaining_amount, 2, ',', '.') }} {{ $card->currency }}</strong> por pagar</p>
                @endif
                <details class="mt-3">
                    <summary class="small fw-semibold">Configurar cartão</summary>
                    <form class="js-api-form row g-2 mt-2" data-endpoint="{{ route('financial.cards.settings', $card->id) }}" data-method="PUT" data-success="Configuração do cartão atualizada.">
                        <div class="col-4"><label class="form-label small">Dia de fecho</label><input class="form-control form-control-sm" type="number" name="closing_day" min="1" max="28" value="{{ $card->closing_day }}" required></div>
                        <div class="col-4"><label class="form-label small">Dia de vencimento</label><input class="form-control form-control-sm" type="number" name="due_day" min="1" max="28" value="{{ $card->due_day }}" required></div>
                        <div class="col-4"><label class="form-label small">Limite</label><input class="form-control form-control-sm" type="number" name="credit_limit" min="0.01" step="0.01" value="{{ $card->credit_limit }}"></div>
                        <div class="col-12"><button class="btn btn-outline-secondary btn-sm" type="submit">Guardar configuração</button></div>
                    </form>
                </details>
            </article>
        @empty
            <div class="commitment-empty-state d-flex align-items-center justify-content-between gap-3">
                <div class="min-w-0"><strong class="d-block small">Ainda não tens cartões</strong><span class="small text-secondary">Adiciona um cartão para acompanhar limites e faturas.</span></div>
                <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" data-bs-toggle="modal" data-bs-target="#cardModal">Adicionar</button>
            </div>
        @endforelse
        @if ($cards->isNotEmpty())
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#cardModal"><i data-lucide="plus" aria-hidden="true"></i> Adicionar cartão</button>
        @endif
    </section>

    @php
        $paymentItems = collect();
        foreach ($invoicePage as $invoice) {
            if ((float) $invoice->remaining_amount > 0) {
                $paymentItems->push((object) ['kind' => 'invoice', 'due_date' => $invoice->due_date, 'record' => $invoice]);
            }
        }
        foreach ($commitments as $commitment) {
            foreach ($commitment->parcelas as $installment) {
                $remaining = max(0, (float) $installment->amount - (float) $installment->paid_amount);
                if ($remaining > 0) {
                    $paymentItems->push((object) ['kind' => 'installment', 'due_date' => $installment->due_date, 'record' => $installment, 'commitment' => $commitment, 'remaining' => $remaining]);
                }
            }
        }
        $paymentItems = $paymentItems->sortBy('due_date')->values();
        $statusLabels = ['open' => 'Aberta', 'closed' => 'Fechada', 'partial' => 'Parcialmente paga', 'paid' => 'Paga', 'overdue' => 'Em atraso'];
        $statusClasses = ['open' => 'text-bg-info', 'closed' => 'text-bg-secondary', 'partial' => 'text-bg-warning', 'paid' => 'text-bg-success', 'overdue' => 'text-bg-danger'];
    @endphp

    <section class="commitment-section commitment-payments-section mb-4" aria-labelledby="paymentsSectionTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1" id="paymentsSectionTitle">Próximos pagamentos</h2>
                <p class="small text-secondary mb-0">Faturas e prestações ordenadas por vencimento.</p>
            </div>
            <span class="badge text-bg-secondary">{{ $paymentItems->count() }}</span>
        </div>

        @forelse ($paymentItems as $item)
            @if ($item->kind === 'invoice')
                @php $invoice = $item->record; @endphp
                <article class="commitment-payment-item border rounded-4 p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <h3 class="h6 fw-bold mb-1">{{ $invoice->conta->name ?? 'Cartão' }} · {{ $invoice->reference_month->format('m/Y') }}</h3>
                            <span class="small text-secondary">Fatura · Vencimento {{ $invoice->due_date->format('d/m/Y') }}</span>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <strong class="d-block">{{ number_format((float) $invoice->remaining_amount, 2, ',', '.') }} {{ $invoice->conta->currency ?? auth()->user()->currency }}</strong>
                            <span class="badge {{ $statusClasses[$invoice->display_status] ?? 'text-bg-secondary' }}">{{ $statusLabels[$invoice->display_status] ?? $invoice->display_status }}</span>
                        </div>
                    </div>
                    @if ((float) $invoice->remaining_amount > 0 && $paymentAccounts->isNotEmpty() && $invoice->display_status !== 'open')
                        <form class="js-api-form row g-2 align-items-end mt-2" data-endpoint="{{ route('financial.invoices.pay', $invoice->id) }}" data-success="Pagamento da fatura registado.">
                            <div class="col-12 col-md-4"><label class="form-label small">Valor a pagar</label><input class="form-control form-control-sm" type="number" name="amount" min="0.01" max="{{ $invoice->remaining_amount }}" step="0.01" value="{{ $invoice->remaining_amount }}" required></div>
                            <div class="col-12 col-md-4"><label class="form-label small">Conta de origem</label><select class="form-select form-select-sm" name="account_id" required><option value="">Selecionar conta</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-2"><label class="form-label small">Data</label><input class="form-control form-control-sm" type="date" name="payment_date" value="{{ now()->toDateString() }}" required></div>
                            <div class="col-12 col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit">Pagar</button></div>
                        </form>
                    @elseif ((float) $invoice->remaining_amount > 0 && $invoice->display_status === 'open')
                        <p class="small text-secondary mt-2 mb-0">Fatura ainda aberta. O pagamento estará disponível após o fecho.</p>
                    @elseif ((float) $invoice->remaining_amount > 0)
                        <p class="small text-secondary mt-2 mb-0">Adiciona uma conta bancária ou de dinheiro para pagar.</p>
                    @endif
                    <details class="mt-2"><summary class="small text-secondary">Ver compras e pagamentos</summary>
                        <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Compra</th><th>Data</th><th class="text-end">Valor</th></tr></thead>
                            <tbody>@foreach ($invoice->movimentos as $purchase)<tr><td>{{ $purchase->description }}</td><td>{{ $purchase->movement_date->format('d/m/Y') }}</td><td class="text-end">{{ number_format((float) $purchase->amount, 2, ',', '.') }}</td></tr>@endforeach</tbody>
                            <tfoot><tr><th colspan="2">Total pago</th><th class="text-end">{{ number_format((float) ($invoice->paid_amount ?? 0), 2, ',', '.') }}</th></tr></tfoot>
                        </table></div>
                    </details>
                </article>
            @else
                @php $installment = $item->record; $remaining = $item->remaining; $commitment = $item->commitment; @endphp
                <article class="commitment-payment-item border rounded-4 p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <h3 class="h6 fw-bold mb-1">{{ $commitment->name }}</h3>
                            <span class="small text-secondary">Parcela {{ $installment->installment_number }} de {{ $commitment->installments_count }} · {{ $installment->due_date->format('d/m/Y') }}</span>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <strong class="d-block">{{ number_format($remaining, 2, ',', '.') }} {{ auth()->user()->currency }}</strong>
                            <span class="badge {{ $installment->due_date->isPast() ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $installment->due_date->isPast() ? 'Em atraso' : 'A vencer' }}</span>
                        </div>
                    </div>
                    @if ($remaining > 0 && $paymentAccounts->isNotEmpty())
                        <form class="js-api-form row g-2 align-items-end mt-2" data-endpoint="{{ route('financial.commitments.installments.pay', [$commitment->id, $installment->id]) }}" data-success="Pagamento da parcela registado.">
                            <div class="col-12 col-md-4"><label class="form-label small">Valor a pagar</label><input class="form-control form-control-sm" type="number" name="amount" min="0.01" max="{{ number_format($remaining, 2, '.', '') }}" step="0.01" value="{{ number_format($remaining, 2, '.', '') }}" required></div>
                            <div class="col-12 col-md-4"><label class="form-label small">Conta de origem</label><select class="form-select form-select-sm" name="account_id" required><option value="">Selecionar conta</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-2"><label class="form-label small">Data</label><input class="form-control form-control-sm" type="date" name="payment_date" value="{{ now()->toDateString() }}" required></div>
                            <div class="col-12 col-md-2"><button class="btn btn-outline-primary btn-sm w-100" type="submit">Pagar</button></div>
                        </form>
                    @elseif ($remaining > 0)
                        <p class="small text-secondary mt-2 mb-0">Adiciona uma conta bancária ou de dinheiro para pagar.</p>
                    @endif
                </article>
            @endif
        @empty
            <div class="commitment-empty-state d-flex align-items-center justify-content-between gap-3">
                <div class="min-w-0"><strong class="d-block small">Não existem pagamentos pendentes</strong><span class="small text-secondary">As novas faturas e prestações aparecerão aqui.</span></div>
            </div>
        @endforelse
        <div class="d-flex flex-wrap gap-3 mt-2 small">
            {{ $invoicePage->links() }}
            {{ $commitments->links() }}
        </div>
    </section>

</div>
@endsection

@push('scripts')
<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const feedback = document.getElementById('commitmentFeedback');
    const modal = (id) => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    const showError = (element, message) => { element.className = 'alert alert-danger'; element.textContent = message; };

    document.querySelectorAll('.js-api-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]'); button.disabled = true;
            try {
                const response = await fetch(form.dataset.endpoint, { method: form.dataset.method || 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(Object.fromEntries(new FormData(form).entries())) });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível guardar.');
                window.location.reload();
            } catch (error) { showError(feedback, error.message); feedback.scrollIntoView({behavior:'smooth',block:'center'}); button.disabled = false; }
        });
    });

    const cardFeedback = document.getElementById('cardModalFeedback');
    document.getElementById('createCardForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('[type=submit]');
        button.disabled = true;
        cardFeedback.className = 'alert d-none';
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            if (!payload.credit_limit) delete payload.credit_limit;
            const response = await fetch('{{ route('financial.accounts.store') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível adicionar o cartão.');
            window.location.reload();
        } catch (error) {
            showError(cardFeedback, error.message);
            button.disabled = false;
        }
    });

    document.getElementById('openExpenseModal')?.addEventListener('click', async () => {
        const body=document.getElementById('expenseModalBody'); body.innerHTML='<p class="text-secondary">A carregar formulário…</p>'; modal('expenseModal').show();
        try {
            const response=await fetch('{{ route('despesas.create') }}?modal=1&card_only=1',{credentials:'same-origin',headers:{Accept:'text/html'}});
            if(!response.ok) throw new Error('Não foi possível carregar o formulário de despesa.');
            const html=await response.text(), doc=new DOMParser().parseFromString(html,'text/html'), form=doc.querySelector('form[action*="/despesas"]');
            if(!form) throw new Error('O formulário de despesa não foi encontrado.');
            form.dataset.modal='1'; form.classList.add('js-expense-modal-form');
            body.innerHTML=''; body.append(form);
            const heading=doc.querySelector('.page-heading'); if(heading) document.getElementById('expenseModalTitle').textContent=heading.querySelector('h1')?.textContent || 'Nova despesa';
            doc.querySelectorAll('script:not([src])').forEach(old => { const script=document.createElement('script'); script.textContent=old.textContent; body.append(script); });
            form.addEventListener('submit', async event => {
                if(form.dataset.submitting==='1') return;
                event.preventDefault(); const button=form.querySelector('[type=submit]'); button.disabled=true;
                try {
                    const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':token},body:new FormData(form)});
                    const result=await response.json().catch(()=>({}));
                    if(!response.ok) throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível registar a despesa.');
                    window.location.reload();
                } catch(error) { let alert=form.querySelector('.alert-danger'); if(!alert){alert=document.createElement('div');alert.className='alert alert-danger';form.prepend(alert);} alert.textContent=error.message;button.disabled=false; }
            });
        } catch(error){body.innerHTML='<div class="alert alert-danger"></div>';body.querySelector('.alert').textContent=error.message;}
    });
})();
</script>
@endpush
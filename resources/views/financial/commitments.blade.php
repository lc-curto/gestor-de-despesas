@extends('layouts.app')

@section('title', 'Compromissos')

@section('content')
<div class="app-content commitments-page">
    <header class="page-heading commitments-heading mb-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h1 class="h2 fw-bold mb-1">Compromissos</h1>
            <p class="text-secondary mb-0">Acompanha prestações, faturas e próximos vencimentos.</p>
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

})();
</script>
@endpush
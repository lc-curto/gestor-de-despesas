@extends('layouts.app')
@section('title', 'Cartões e compromissos')
@section('content')
<div class="app-content">
    <header class="page-heading mb-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <p class="eyebrow mb-1">Fase 2 · Acompanhamento</p>
            <h1 class="h2 fw-bold mb-1">Cartões e compromissos</h1>
            <p class="text-secondary mb-0">Regista em Despesas. Aqui acompanha faturas, saldos e parcelas.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-primary" href="{{ route('despesas.create') }}"><i data-lucide="plus" aria-hidden="true"></i><span class="ms-1">Nova despesa</span></a>
            <a class="btn btn-outline-secondary" href="{{ route('financial.accounts.page') }}">Gerir contas/cartões</a>
        </div>
    </header>
    <div id="commitmentFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold">Cartões de crédito</h2>
            @if ($cards->isEmpty())
                <p class="text-secondary mb-2">Ainda não há cartões configurados.</p>
                <a href="{{ route('financial.accounts.page') }}" class="btn btn-outline-primary btn-sm">Criar ou configurar cartão</a>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Cartão</th><th>Fecho</th><th>Vencimento</th><th>Limite</th><th>Utilização</th></tr></thead>
                        <tbody>
                        @foreach ($cards as $card)
                            <tr>
                                <td class="fw-semibold">{{ $card->name }}</td>
                                <td>{{ $card->closing_day ? 'Dia '.$card->closing_day : 'Por configurar' }}</td>
                                <td>{{ $card->due_day ? 'Dia '.$card->due_day : 'Por configurar' }}</td>
                                <td>{{ $card->credit_limit ? number_format((float)$card->credit_limit, 2, ',', '.').' '.$card->currency : 'Sem limite definido' }}</td>
                                <td>{{ number_format(max(0, -(float)$card->current_balance), 2, ',', '.') }} {{ $card->currency }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info mt-3 mb-3">
                    <strong>Para lançar uma compra no cartão:</strong> abre <a href="{{ route('despesas.create') }}">Nova despesa</a> e escolhe o cartão como conta. A compra entra na fatura automaticamente.
                    Para uma compra a prazo ou parcelada, escolhe essa condição no mesmo formulário; não precisas de a registar outra vez aqui.
                </div>
                <div class="row g-3">
                    @foreach ($cards as $card)
                        <div class="col-md-6">
                            <details>
                                <summary class="small fw-semibold">Configurar {{ $card->name }}</summary>
                                <form class="js-api-form row g-2 mt-2" data-endpoint="{{ route('financial.cards.settings', $card->id) }}" data-method="PUT" data-success="Configuração do cartão atualizada.">
                                    <div class="col-4"><label class="form-label small">Fecho</label><input class="form-control form-control-sm" type="number" name="closing_day" min="1" max="28" value="{{ $card->closing_day }}" required></div>
                                    <div class="col-4"><label class="form-label small">Vencimento</label><input class="form-control form-control-sm" type="number" name="due_day" min="1" max="28" value="{{ $card->due_day }}" required></div>
                                    <div class="col-4"><label class="form-label small">Limite</label><input class="form-control form-control-sm" type="number" name="credit_limit" min="0.01" step="0.01" value="{{ $card->credit_limit }}"></div>
                                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm" type="submit">Guardar configuração</button></div>
                                </form>
                            </details>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold">Faturas</h2>
            @if ($invoicePage->isEmpty())
                <p class="text-secondary mb-2">Ainda não há faturas. Regista uma despesa e escolhe um cartão como conta para criar a primeira.</p>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('despesas.create') }}">Registar despesa</a>
            @else
                <div class="vstack gap-3">
                    @foreach ($invoicePage as $invoice)
                        <article class="border rounded-3 p-3">
                            <div class="d-flex justify-content-between gap-2">
                                <div><strong>{{ $invoice->conta->name ?? 'Cartão' }} · {{ $invoice->reference_month->format('m/Y') }}</strong><div class="small text-secondary">Vence {{ $invoice->due_date->format('d/m/Y') }} · {{ $invoice->movimentos_count }} compras</div></div>
                                <div class="text-end"><strong>{{ number_format((float)$invoice->total_amount, 2, ',', '.') }} {{ $invoice->conta->currency ?? 'EUR' }}</strong><div class="small">{{ $invoice->status === 'paid' ? 'Paga' : 'Por pagar' }}</div></div>
                            </div>
                            <details class="mt-2">
                                <summary class="small text-secondary">Ver compras da fatura</summary>
                                <ul class="list-group list-group-flush mt-2">
                                    @foreach ($invoice->movimentos as $purchase)
                                        <li class="list-group-item px-0 py-2 d-flex justify-content-between gap-2"><span>{{ $purchase->movement_date->format('d/m/Y') }} · {{ $purchase->description }}</span><strong>{{ number_format((float)$purchase->amount, 2, ',', '.') }}</strong></li>
                                    @endforeach
                                </ul>
                            </details>
                            @if ($invoice->status === 'open' && (float)$invoice->total_amount > 0 && $paymentAccounts->isNotEmpty())
                                <form class="js-api-form mt-3 d-flex flex-wrap gap-2 align-items-end" data-endpoint="{{ route('financial.invoices.pay', $invoice->id) }}" data-method="POST" data-success="Fatura liquidada por transferência.">
                                    <div class="flex-grow-1"><label class="form-label small" for="payAccount{{ $invoice->id }}">Pagar a partir de</label><select id="payAccount{{ $invoice->id }}" class="form-select form-select-sm" name="account_id" required><option value="">Selecionar conta bancária/dinheiro</option>@foreach ($paymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                                    <input type="hidden" name="payment_date" value="{{ now()->toDateString() }}"><button class="btn btn-outline-primary btn-sm" type="submit">Pagar fatura</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
                {{ $invoicePage->links() }}
            @endif
        </div>
    </section>

    <section class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div><h2 class="h5 fw-bold mb-1">Compromissos e parcelas</h2><p class="small text-secondary mb-0">Cria-os em <a href="{{ route('despesas.create') }}">Nova despesa</a>, escolhendo “A prazo” ou “Parcelado”.</p></div>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('despesas.create') }}">Criar compromisso</a>
            </div>
            @forelse ($commitments as $debt)
                <article class="border rounded-3 p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2"><div><strong>{{ $debt->name }}</strong><div class="small text-secondary">{{ $debt->payment_condition === 'installments' ? $debt->installments_count.' parcelas' : 'Diferido' }} · {{ $debt->status === 'paid' ? 'Liquidado' : 'Em aberto' }}</div></div><strong>{{ number_format((float)$debt->total_amount, 2, ',', '.') }} {{ auth()->user()->currency }}</strong></div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Pago / em falta</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($debt->parcelas as $installment)
                                <tr>
                                    <td>{{ $installment->installment_number }}</td><td>{{ $installment->due_date->format('d/m/Y') }}</td><td>{{ number_format((float)$installment->amount, 2, ',', '.') }}</td>
                                    <td>{{ number_format((float)$installment->paid_amount, 2, ',', '.') }} / {{ number_format(max(0, (float)$installment->amount - (float)$installment->paid_amount), 2, ',', '.') }}</td>
                                    <td>{{ $installment->status === 'paid' ? 'Paga' : ($installment->status === 'partial' ? 'Parcial' : 'Pendente') }}</td>
                                    <td>
                                        @if ($installment->status !== 'paid' && $commitmentPaymentAccounts->isNotEmpty())
                                            <form class="js-api-form d-flex gap-2" data-endpoint="{{ route('financial.commitments.installments.pay', [$debt->id, $installment->id]) }}" data-success="Pagamento registado.">
                                                <select class="form-select form-select-sm" name="account_id" aria-label="Conta de pagamento" required><option value="">Conta</option>@foreach ($commitmentPaymentAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}{{ $account->type === 'credit_card' ? ' · Cartão' : '' }}</option>@endforeach</select>
                                                <input class="form-control form-control-sm" name="amount" type="number" min="0.01" step="0.01" placeholder="Total em falta"><input type="hidden" name="payment_date" value="{{ now()->toDateString() }}"><button type="submit" class="btn btn-outline-primary btn-sm">Pagar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @empty
                <p class="text-secondary mb-0">Ainda não existem compromissos.</p>
            @endforelse
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
    document.querySelectorAll('.js-api-form').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            const response = await fetch(form.dataset.endpoint, {
                method: form.dataset.method || 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível guardar.');
            feedback.className = 'alert alert-success';
            feedback.textContent = form.dataset.success || 'Alteração guardada.';
            window.location.reload();
        } catch (error) {
            feedback.className = 'alert alert-danger';
            feedback.textContent = error.message;
            feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
            button.disabled = false;
        }
    }));
})();
</script>
@endpush

@extends('layouts.app')

@section('title', 'Cartões e compromissos')

@section('content')
<div class="app-content">
    <header class="page-heading mb-4 d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <p class="eyebrow mb-1">Fase 2 · Acompanhamento</p>
            <h1 class="h2 fw-bold mb-1">Cartões e compromissos</h1>
            <p class="text-secondary mb-0">Acompanha o que tens por pagar, os próximos vencimentos e os pagamentos realizados.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('financial.accounts.page') }}">Gerir contas e cartões</a>
    </header>

    <div id="commitmentFeedback" class="alert d-none" role="status" aria-live="polite"></div>

    <section class="row g-3 mb-4" aria-label="Resumo financeiro">
        <div class="col-md-4">
            <article class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <p class="text-secondary small mb-1">Total em aberto</p>
                    <p class="h3 fw-bold mb-0">{{ number_format($summary['open'], 2, ',', '.') }} {{ auth()->user()->currency }}</p>
                </div>
            </article>
        </div>
        <div class="col-md-4">
            <article class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <p class="text-secondary small mb-1">A vencer</p>
                    <p class="h3 fw-bold mb-0">{{ number_format($summary['upcoming'], 2, ',', '.') }} {{ auth()->user()->currency }}</p>
                </div>
            </article>
        </div>
        <div class="col-md-4">
            <article class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <p class="text-secondary small mb-1">Em atraso</p>
                    <p class="h3 fw-bold mb-0 {{ $summary['overdue'] > 0 ? 'text-danger' : '' }}">{{ number_format($summary['overdue'], 2, ',', '.') }} {{ auth()->user()->currency }}</p>
                </div>
            </article>
        </div>
    </section>

    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1">Cartões de crédito</h2>
                    <p class="small text-secondary mb-0">As compras são registadas em Despesas e associadas automaticamente à fatura do cartão.</p>
                </div>
                <a class="btn btn-primary btn-sm" href="{{ route('despesas.create') }}">Nova despesa</a>
            </div>

            @forelse ($cards as $card)
                @php
                    $cardInvoices = $invoicePage->getCollection()->where('account_id', $card->id);
                    $latestInvoice = $cardInvoices->sortByDesc('reference_month')->first();
                    $used = (float) $card->used_limit;
                    $limit = (float) $card->credit_limit;
                    $usage = $limit > 0 ? min(100, ($used / $limit) * 100) : 0;
                @endphp
                <article class="border rounded-4 p-3 p-md-4 mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1">{{ $card->name }}</h3>
                            <span class="small text-secondary">Cartão de crédito</span>
                        </div>
                        <div class="text-md-end">
                            <p class="h4 fw-bold mb-0">{{ number_format($used, 2, ',', '.') }} {{ $card->currency }}</p>
                            <span class="small text-secondary">Valor utilizado</span>
                        </div>
                    </div>

                    @if ($card->credit_limit)
                        <div class="mt-3">
                            <div class="d-flex justify-content-between small text-secondary mb-1">
                                <span>Limite utilizado</span>
                                <span>{{ number_format($used, 2, ',', '.') }} / {{ number_format($limit, 2, ',', '.') }} {{ $card->currency }}</span>
                            </div>
                            <div class="progress" role="progressbar" aria-label="Utilização do limite" aria-valuenow="{{ $usage }}" aria-valuemin="0" aria-valuemax="100" style="height: 7px;">
                                <div class="progress-bar" style="width: {{ $usage }}%"></div>
                            </div>
                            <p class="small text-secondary mt-1 mb-0">Disponível: {{ number_format((float) $card->available_limit, 2, ',', '.') }} {{ $card->currency }}</p>
                        </div>
                    @else
                        <p class="small text-secondary mt-2 mb-0">Limite de crédito não configurado.</p>
                    @endif

                    <div class="row g-3 mt-2">
                        <div class="col-6 col-md-3">
                            <span class="small text-secondary d-block">Fecho</span>
                            <strong>{{ $card->closing_day ? 'Dia '.$card->closing_day : 'Por configurar' }}</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="small text-secondary d-block">Vencimento</span>
                            <strong>{{ $card->due_day ? 'Dia '.$card->due_day : 'Por configurar' }}</strong>
                        </div>
                        <div class="col-12 col-md-6">
                            <span class="small text-secondary d-block">Fatura mais recente</span>
                            @if ($latestInvoice)
                                <strong>{{ number_format((float) $latestInvoice->remaining_amount, 2, ',', '.') }} {{ $card->currency }} por pagar</strong>
                                <span class="small text-secondary"> · Vence {{ $latestInvoice->due_date->format('d/m/Y') }}</span>
                            @else
                                <span class="small text-secondary">Ainda sem compras faturadas.</span>
                            @endif
                        </div>
                    </div>

                    <details class="mt-3">
                        <summary class="small fw-semibold">Configurar cartão</summary>
                        <form class="js-api-form row g-2 mt-2" data-endpoint="{{ route('financial.cards.settings', $card->id) }}" data-method="PUT" data-success="Configuração do cartão atualizada.">
                            <div class="col-4">
                                <label class="form-label small">Dia de fecho</label>
                                <input class="form-control form-control-sm" type="number" name="closing_day" min="1" max="28" value="{{ $card->closing_day }}" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label small">Dia de vencimento</label>
                                <input class="form-control form-control-sm" type="number" name="due_day" min="1" max="28" value="{{ $card->due_day }}" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label small">Limite</label>
                                <input class="form-control form-control-sm" type="number" name="credit_limit" min="0.01" step="0.01" value="{{ $card->credit_limit }}">
                            </div>
                            <div class="col-12"><button class="btn btn-outline-secondary btn-sm" type="submit">Guardar configuração</button></div>
                        </form>
                    </details>
                </article>
            @empty
                <div class="text-center py-4">
                    <p class="fw-semibold mb-1">Ainda não tens cartões configurados.</p>
                    <p class="text-secondary small mb-3">Adiciona um cartão nas contas para começares a registar compras e acompanhar faturas.</p>
                    <a href="{{ route('financial.accounts.page') }}" class="btn btn-outline-primary btn-sm">Adicionar cartão</a>
                </div>
            @endforelse
        </div>
    </section>

    <section class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="mb-3">
                <h2 class="h5 fw-bold mb-1">Faturas</h2>
                <p class="small text-secondary mb-0">Consulta as compras incluídas e regista pagamentos totais ou parciais.</p>
            </div>

            @forelse ($invoicePage as $invoice)
                @php
                    $statusLabels = ['open' => 'Aberta', 'closed' => 'Fechada', 'partial' => 'Parcialmente paga', 'paid' => 'Paga', 'overdue' => 'Em atraso'];
                    $statusClasses = ['open' => 'text-bg-info', 'closed' => 'text-bg-secondary', 'partial' => 'text-bg-warning', 'paid' => 'text-bg-success', 'overdue' => 'text-bg-danger'];
                @endphp
                <article class="border rounded-4 p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1">{{ $invoice->conta->name ?? 'Cartão' }} · {{ $invoice->reference_month->format('m/Y') }}</h3>
                            <p class="small text-secondary mb-1">{{ $invoice->movimentos_count }} compras · Fecho {{ $invoice->closing_date?->format('d/m/Y') ?? '—' }} · Vencimento {{ $invoice->due_date->format('d/m/Y') }}</p>
                            <span class="badge {{ $statusClasses[$invoice->display_status] ?? 'text-bg-secondary' }}">{{ $statusLabels[$invoice->display_status] ?? $invoice->display_status }}</span>
                        </div>
                        <div class="text-md-end">
                            <p class="h5 fw-bold mb-0">{{ number_format((float) $invoice->remaining_amount, 2, ',', '.') }} {{ $invoice->conta->currency ?? auth()->user()->currency }}</p>
                            <span class="small text-secondary">Por pagar de {{ number_format((float) $invoice->total_amount, 2, ',', '.') }}</span>
                        </div>
                    </div>

                    <details class="mt-3">
                        <summary class="small text-secondary">Ver compras e pagamentos</summary>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Compra</th><th>Data</th><th class="text-end">Valor</th></tr></thead>
                                <tbody>
                                @foreach ($invoice->movimentos as $purchase)
                                    <tr><td>{{ $purchase->description }}</td><td>{{ $purchase->movement_date->format('d/m/Y') }}</td><td class="text-end">{{ number_format((float) $purchase->amount, 2, ',', '.') }}</td></tr>
                                @endforeach
                                </tbody>
                                <tfoot><tr><th colspan="2">Total pago</th><th class="text-end">{{ number_format((float) ($invoice->paid_amount ?? 0), 2, ',', '.') }}</th></tr></tfoot>
                            </table>
                        </div>
                    </details>

                    @if ((float) $invoice->remaining_amount > 0 && $paymentAccounts->isNotEmpty() && $invoice->display_status !== 'open')
                        <form class="js-api-form row g-2 align-items-end mt-3" data-endpoint="{{ route('financial.invoices.pay', $invoice->id) }}" data-success="Pagamento da fatura registado.">
                            <div class="col-12 col-md-4">
                                <label class="form-label small">Valor a pagar</label>
                                <input class="form-control form-control-sm" type="number" name="amount" min="0.01" max="{{ $invoice->remaining_amount }}" step="0.01" value="{{ $invoice->remaining_amount }}" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small">Conta de origem</label>
                                <select class="form-select form-select-sm" name="account_id" required>
                                    <option value="">Selecionar conta</option>
                                    @foreach ($paymentAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label small">Data</label>
                                <input class="form-control form-control-sm" type="date" name="payment_date" value="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-12 col-md-2">
                                <button class="btn btn-primary btn-sm w-100" type="submit">Registar pagamento</button>
                            </div>
                        </form>
                    @elseif ((float) $invoice->remaining_amount > 0 && $invoice->display_status === 'open')
                        <p class="small text-secondary mt-3 mb-0">Esta fatura ainda está aberta. Poderás liquidá-la depois do fecho.</p>
                    @elseif ((float) $invoice->remaining_amount > 0)
                        <p class="small text-secondary mt-3 mb-0">Adiciona uma conta bancária ou de dinheiro para registar o pagamento.</p>
                    @endif
                </article>
            @empty
                <p class="text-secondary mb-0">Ainda não existem faturas. Regista uma despesa e seleciona um cartão de crédito como conta.</p>
            @endforelse
            {{ $invoicePage->links() }}
        </div>
    </section>

    <section class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="mb-3">
                <h2 class="h5 fw-bold mb-1">Compromissos financeiros</h2>
                <p class="small text-secondary mb-0">Compras a prazo ou parceladas registadas através do formulário de despesas.</p>
            </div>

            @forelse ($commitments as $commitment)
                <article class="border rounded-4 p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1">{{ $commitment->name }}</h3>
                            <span class="small text-secondary">{{ $commitment->payment_condition === 'installments' ? $commitment->installments_count.' parcelas' : 'Pagamento a prazo' }}</span>
                        </div>
                        <strong>{{ number_format((float) $commitment->total_amount, 2, ',', '.') }} {{ auth()->user()->currency }}</strong>
                    </div>

                    @foreach ($commitment->parcelas as $installment)
                        @php $remaining = max(0, (float) $installment->amount - (float) $installment->paid_amount); @endphp
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 border-top">
                            <div>
                                <strong class="small">Parcela {{ $installment->installment_number }}</strong>
                                <div class="small text-secondary">Vencimento {{ $installment->due_date->format('d/m/Y') }} · {{ number_format((float) $installment->paid_amount, 2, ',', '.') }} pago</div>
                            </div>
                            <div class="text-end">
                                <strong>{{ number_format($remaining, 2, ',', '.') }} {{ auth()->user()->currency }}</strong>
                                <div class="small text-secondary">{{ $installment->due_date->isPast() ? 'Em atraso' : 'Pendente' }}</div>
                            </div>
                        </div>
                        @if ($remaining > 0 && $paymentAccounts->isNotEmpty())
                            <form class="js-api-form row g-2 align-items-end py-2" data-endpoint="{{ route('financial.commitments.installments.pay', [$commitment->id, $installment->id]) }}" data-success="Pagamento da parcela registado.">
                                <div class="col-12 col-md-4">
                                    <label class="form-label small">Valor a pagar</label>
                                    <input class="form-control form-control-sm" type="number" name="amount" min="0.01" max="{{ number_format($remaining, 2, '.', '') }}" step="0.01" value="{{ number_format($remaining, 2, '.', '') }}" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small">Conta de origem</label>
                                    <select class="form-select form-select-sm" name="account_id" required>
                                        <option value="">Selecionar conta</option>
                                        @foreach ($paymentAccounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label small">Data</label>
                                    <input class="form-control form-control-sm" type="date" name="payment_date" value="{{ now()->toDateString() }}" required>
                                </div>
                                <div class="col-12 col-md-2"><button class="btn btn-outline-primary btn-sm w-100" type="submit">Pagar</button></div>
                            </form>
                        @endif
                    @endforeach
                </article>
            @empty
                <p class="text-secondary mb-0">Não existem compromissos pendentes.</p>
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

    document.querySelectorAll('.js-api-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;

            try {
                const payload = Object.fromEntries(new FormData(form).entries());
                const response = await fetch(form.dataset.endpoint, {
                    method: form.dataset.method || 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    body: JSON.stringify(payload),
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível guardar.');
                }
                window.location.reload();
            } catch (error) {
                feedback.className = 'alert alert-danger';
                feedback.textContent = error.message;
                feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
                button.disabled = false;
            }
        });
    });
})();
</script>
@endpush

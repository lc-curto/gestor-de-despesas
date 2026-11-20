@extends('layouts.app')

@section('title', 'Faturas · '.$card->name)

@section('content')
<div class="app-content invoice-page">
    <header class="mb-3">
        <a href="{{ $backUrl }}" class="small text-secondary text-decoration-none">← Voltar</a>
        <h1 class="h3 fw-bold mb-0 mt-2">Faturas</h1>
        <p class="small text-secondary mb-0">Cartão · {{ $card->name }}</p>
    </header>

    @if($items->isEmpty())
        <div class="card border rounded-4"><div class="card-body text-center p-4">
            <i data-lucide="receipt" class="text-secondary mb-2" style="width:28px;height:28px"></i>
            <p class="mb-0 text-secondary">Ainda não existem faturas para este cartão.</p>
        </div></div>
    @else
        <section class="d-grid gap-2" aria-label="Faturas do cartão">
            @foreach($items as $invoice)
                @php
                    $cycleLabels = ['open' => 'Aberta', 'closed' => 'Fechada'];
                    $cycleClasses = ['open' => 'text-bg-primary', 'closed' => 'text-bg-secondary'];
                @endphp
                <a href="{{ route('financial.invoices.show', ['id' => $invoice->id, 'return_to' => request()->getRequestUri()]) }}" class="card border rounded-4 text-decoration-none text-body">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="min-w-0">
                                <h2 class="h6 fw-semibold mb-1 text-capitalize">{{ $invoice->reference_month->translatedFormat('F Y') }}</h2>
                                <span class="small text-secondary">Total: {{ number_format((float)$invoice->total_amount,2,',','.') }} {{ $card->currency }}</span>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <span class="badge {{ $cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary' }}">{{ $cycleLabels[$invoice->cycle_status] ?? 'Fechada' }}</span>
                                <div class="small text-primary mt-1">Ver fatura <i data-lucide="chevron-right" style="width:14px;height:14px"></i></div>
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
        <div class="mt-3">{{ $items->links() }}</div>
    @endif
</div>
@endsection

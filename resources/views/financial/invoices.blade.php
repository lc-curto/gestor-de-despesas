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
        <section class="d-grid gap-3" aria-label="Faturas do cartão">
            @foreach($items as $invoice)
                @php
                    $cycleLabels = ['open' => 'Aberta', 'closed' => 'Fechada'];
                    $cycleClasses = ['open' => 'text-bg-primary', 'closed' => 'text-bg-secondary'];
                @endphp
                <a href="{{ route('financial.invoices.show', ['id' => $invoice->id, 'return_to' => request()->getRequestUri()]) }}" class="d-flex justify-content-between align-items-center gap-3 text-body text-decoration-none border-bottom pb-3">
                    <span class="min-w-0">
                        <strong class="d-block text-capitalize">{{ $invoice->reference_month->translatedFormat('F Y') }}</strong>
                        <span class="badge mt-1 {{ $cycleClasses[$invoice->cycle_status] ?? 'text-bg-secondary' }}">{{ $cycleLabels[$invoice->cycle_status] ?? 'Fechada' }}</span>
                    </span>
                    <span class="d-flex align-items-center gap-2 flex-shrink-0">
                        <strong>{{ number_format((float)$invoice->total_amount,2,',','.') }} {{ $card->currency }}</strong>
                        <i data-lucide="chevron-right" class="text-secondary" aria-hidden="true"></i>
                    </span>
                </a>
            @endforeach
        </section>
        <div class="mt-3">{{ $items->links() }}</div>
    @endif
</div>
@endsection

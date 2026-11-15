@extends('layouts.app')

@section('title', 'Início')

@section('content')
@php
    $simbolos = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $recentMovements = $movimentos->take(4);
@endphp

<div class="home-dashboard">
    <header class="dashboard-intro home-dashboard-intro">
        <div class="dashboard-intro-copy">
            <h1 class="h2 fw-bold mb-1" id="greetingTitle">Bom dia, {{ auth()->user()->name }}!</h1>
            <p class="text-secondary mb-0">O teu resumo financeiro</p>
        </div>
        <div class="date-time-card" aria-label="Data e hora locais do utilizador">
            <span class="date-time-label">Agora</span>
            <time id="currentDateTime" datetime="{{ now()->toIso8601String() }}">{{ now()->locale('pt_PT')->translatedFormat('l, d \\d\\e F \\d\\e Y') }} às {{ now()->format('H:i') }}</time>
        </div>
    </header>

    <section class="home-balance-card mb-3">
        <span class="small text-secondary">Saldo disponível</span>
        <p class="home-balance-value mb-1">{{ number_format($availableBalance, 2, ',', '.') }} {{ $moeda }}</p>
        <div class="d-flex flex-wrap gap-3 small">
            <span class="text-success">↑ {{ number_format($totalReceitas, 2, ',', '.') }} {{ $moeda }} recebidos</span>
            <span class="text-danger">↓ {{ number_format($totalDespesas, 2, ',', '.') }} {{ $moeda }} gastos</span>
        </div>
    </section>

    <section class="row g-3 mb-4 home-month-summary" aria-label="Resumo do mês">
        <div class="col-6"><article class="summary-card summary-card-income h-100">
            <span class="summary-icon" aria-hidden="true"><i data-lucide="arrow-down-left"></i></span>
            <p class="small text-secondary mb-1">Receitas</p>
            <p class="summary-value mb-1 text-success">{{ number_format($totalReceitas, 2, ',', '.') }} {{ $moeda }}</p>
            <span class="small text-secondary">Este mês</span>
        </article></div>
        <div class="col-6"><article class="summary-card summary-card-expense h-100">
            <span class="summary-icon" aria-hidden="true"><i data-lucide="arrow-up-right"></i></span>
            <p class="small text-secondary mb-1">Despesas</p>
            <p class="summary-value mb-1 text-danger">{{ number_format($totalDespesas, 2, ',', '.') }} {{ $moeda }}</p>
            <span class="small text-secondary">Este mês</span>
        </article></div>
    </section>

    <section class="home-quick-actions mb-4" aria-label="Ações rápidas">
        <h2 class="h6 fw-bold mb-2">Ações rápidas</h2>
        <div class="row g-2">
            <div class="col-6"><a class="home-quick-action" href="{{ route('despesas.create') }}"><i data-lucide="plus-circle" aria-hidden="true"></i><strong>Nova despesa</strong></a></div>
            <div class="col-6"><a class="home-quick-action" href="{{ route('receitas.create') }}"><i data-lucide="trending-up" aria-hidden="true"></i><strong>Nova receita</strong></a></div>
            <div class="col-12">
                <button type="button" class="home-quick-action home-converter-trigger" data-bs-toggle="modal" data-bs-target="#currencyConverterModal">
                    <i data-lucide="arrow-left-right" aria-hidden="true"></i>
                    <span><strong>Conversor de moedas</strong><small>Consultar taxas de câmbio</small></span>
                    <i data-lucide="chevron-right" class="ms-auto" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </section>

    <section class="home-section-card mb-4" aria-labelledby="homePaymentsTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h6 fw-bold mb-0" id="homePaymentsTitle">A tua situação</h2>
            <span class="badge text-bg-warning">{{ $pendingPaymentCount }}</span>
        </div>
        <div class="d-flex justify-content-between gap-2 py-1"><span class="small text-secondary">Pagamentos pendentes</span></div>
        <div class="d-flex justify-content-between gap-2 py-1"><span class="small text-secondary">Total pendente</span><strong>{{ number_format($pendingPaymentTotal, 2, ',', '.') }} {{ $moeda }}</strong></div>
        <a class="small text-decoration-none" href="{{ route('financial.commitments.page') }}">Consultar compromissos →</a>
    </section>

    <section class="home-section-card" aria-labelledby="homeRecentTitle">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <h2 class="h6 fw-bold mb-0" id="homeRecentTitle">Atividade recente</h2>
            <a class="small text-decoration-none" href="{{ route('movements.page') }}">Ver todos os movimentos →</a>
        </div>
        @forelse ($recentMovements as $movement)
            @php $isExpense = $movement->tipo === 'despesa'; $movementDate = \\Illuminate\\Support\\Carbon::parse($movement->data_movimento); @endphp
            <div class="home-recent-item py-2">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <strong class="small text-break">{{ $movement->description }}</strong>
                    <strong class="small text-nowrap {{ $isExpense ? 'text-danger' : 'text-success' }}">{{ $isExpense ? '−' : '+' }}{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->currency }}</strong>
                </div>
                <span class="small text-secondary">{{ $isExpense ? 'Despesa' : 'Receita' }} · {{ $movementDate->format('d/m') }}</span>
            </div>
        @empty
            <p class="small text-secondary mb-0">Ainda não existem movimentos neste período.</p>
        @endforelse
    </section>
</div>

<div class="modal fade" id="currencyConverterModal" tabindex="-1" aria-labelledby="currencyConverterTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-2">
                <div><h2 class="modal-title h5 fw-bold" id="currencyConverterTitle">Conversor de moedas</h2><p class="small text-secondary mb-0">Taxas de câmbio</p></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body pt-2">
                <div class="mb-3"><label for="conversionAmount" class="form-label">Valor</label><input type="number" class="form-control form-control-lg" id="conversionAmount" placeholder="100,00" min="0.01" step="0.01" value="100"></div>
                <div class="row g-2 align-items-end">
                    <div class="col-5"><label for="conversionFrom" class="form-label">De</label><select class="form-select" id="conversionFrom">@foreach (\\App\\Support\\Categorias::MOEDAS as $currency)<option value="{{ $currency }}">{{ $currency }} ({{ $simbolos[$currency] ?? $currency }})</option>@endforeach</select></div>
                    <div class="col-2 text-center pb-2"><button type="button" class="btn btn-outline-secondary" id="swapCurrencies" aria-label="Inverter moedas"><i data-lucide="arrow-left-right"></i></button></div>
                    <div class="col-5"><label for="conversionTo" class="form-label">Para</label><select class="form-select" id="conversionTo">@foreach (\\App\\Support\\Categorias::MOEDAS as $currency)<option value="{{ $currency }}">{{ $currency }} ({{ $simbolos[$currency] ?? $currency }})</option>@endforeach</select></div>
                </div>
                <div class="conversion-result mt-3" aria-live="polite"><span class="small text-secondary">Resultado</span><strong id="convertedValue">—</strong><span id="conversionRate" class="small text-secondary">Introduz o valor e seleciona as moedas.</span></div>
                <button type="button" class="btn btn-primary w-100 mt-3" id="convertButton">Converter</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>window.meuBolsoHome = { currency: @json($moeda) };</script>
<script src="{{ asset('js/dashboard-home.js') }}?v={{ filemtime(public_path('js/dashboard-home.js')) }}" defer></script>
@endpush

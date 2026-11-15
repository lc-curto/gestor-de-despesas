@extends('layouts.app')

@section('title', 'Rota Financeira')

@section('content')
@php
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $symbol = $symbols[$currency] ?? $currency;
    $format = fn ($value) => number_format($value, 2, ',', '.') . ' ' . $symbol;
@endphp

<section class="financial-route-page">
    <header class="financial-route-header">
        <div>
            <h1>Rota Financeira</h1>
            <p>Visão diária das entradas, despesas e saldo acumulado.</p>
        </div>
        <div class="financial-route-controls">
            <form method="GET" action="{{ route('financial.route') }}" class="financial-route-navigation">
                <input type="hidden" name="modo" value="{{ $mode }}">
                <button class="btn btn-outline-secondary btn-sm" name="mes" value="{{ $months->first()->copy()->subMonths($mode)->format('Y-m') }}" aria-label="Período anterior"><i data-lucide="chevron-left"></i></button>
                <input class="form-control form-control-sm" type="month" name="mes" value="{{ $month }}" aria-label="Mês inicial">
                <button class="btn btn-outline-secondary btn-sm" name="mes" value="{{ $months->first()->copy()->addMonths($mode)->format('Y-m') }}" aria-label="Período seguinte"><i data-lucide="chevron-right"></i></button>
            </form>
            <div class="btn-group btn-group-sm" role="group" aria-label="Visualização">
                <a class="btn {{ $mode === 3 ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('financial.route', ['mes' => $month, 'modo' => 3]) }}">3 meses</a>
                <a class="btn {{ $mode === 1 ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('financial.route', ['mes' => $month, 'modo' => 1]) }}">1 mês</a>
            </div>
        </div>
    </header>

    <div class="financial-route-months mode-{{ $mode }}">
        @foreach ($months as $monthDate)
            @php
                $monthKey = $monthDate->format('Y-m');
                $monthDays = $days[$monthKey];
                $fixedTotal = collect($monthDays)->sum('fixed');
                $variableTotal = collect($monthDays)->sum('variable');
                $running = $openingBalance;
            @endphp
            <section class="financial-route-month" aria-label="{{ $monthDate->locale('pt_PT')->translatedFormat('F Y') }}">
                <h2>{{ $monthDate->locale('pt_PT')->translatedFormat('F Y') }}</h2>
                <div class="financial-route-table-wrap">
                    <table class="financial-route-table">
                        <thead><tr><th>Data</th><th>Entrada</th><th>Saída</th><th>G. Fixo</th><th>Saldo</th></tr></thead>
                        <tbody>
                        @foreach ($monthDays as $date => $values)
                            @php
                                $running += $values['income'] - $values['variable'] - $values['fixed'];
                                $status = $running <= 0 ? 'risk' : ($running <= $fixedTotal ? 'attention' : ($running < ($fixedTotal + $variableTotal) ? 'protected' : 'comfortable'));
                            @endphp
                            <tr>
                                <th scope="row">{{ \Illuminate\\Support\\Carbon::parse($date)->format("d/m") }}</th>
                                <td>{{ $values['income'] > 0 ? $format($values['income']) : '—' }}</td>
                                <td>{{ $values['variable'] > 0 ? $format($values['variable']) : '—' }}</td>
                                <td>{{ $values['fixed'] > 0 ? $format($values['fixed']) : '—' }}</td>
                                <td class="financial-route-balance {{ $status }}">{{ $format($running) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="4">Saldo final de {{ $monthDate->locale('pt_PT')->translatedFormat('F') }}</th><td class="financial-route-balance {{ $running <= 0 ? 'risk' : ($running <= $fixedTotal ? 'attention' : ($running < ($fixedTotal + $variableTotal) ? 'protected' : 'comfortable')) }}">{{ $format($running) }}</td></tr></tfoot>
                    </table>
                </div>
            </section>
            @php $openingBalance = $running; @endphp
        @endforeach
    </div>
</section>
@endsection

@push('styles')
<style>
.financial-route-page{width:100%;min-width:0}.financial-route-header{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;margin-bottom:1.5rem}.financial-route-header h1{font-size:1.65rem;font-weight:750;letter-spacing:-.035em;margin:0}.financial-route-header p{color:var(--bs-secondary-color);margin:.35rem 0 0}.financial-route-controls,.financial-route-navigation{display:flex;align-items:center;gap:.5rem}.financial-route-navigation input{width:9.5rem}.financial-route-months{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}.financial-route-months.mode-1{grid-template-columns:minmax(0,1fr)}.financial-route-month h2{font-size:1rem;font-weight:700;text-transform:capitalize;margin:0 0 .65rem}.financial-route-table-wrap{width:100%;overflow-x:auto;border:1px solid var(--bs-border-color);border-radius:.7rem;background:var(--bs-body-bg)}.financial-route-table{width:100%;border-collapse:collapse;font-size:.76rem;font-variant-numeric:tabular-nums;white-space:nowrap}.financial-route-table th,.financial-route-table td{padding:.48rem .42rem;text-align:right;border-bottom:1px solid var(--bs-border-color-translucent)}.financial-route-table thead th{font-size:.68rem;color:var(--bs-secondary-color);font-weight:700;background:var(--bs-tertiary-bg)}.financial-route-table th:first-child,.financial-route-table td:first-child{text-align:left}.financial-route-table tbody tr:last-child th,.financial-route-table tbody tr:last-child td{border-bottom:0}.financial-route-day{border:0;padding:0;background:transparent;color:var(--bs-body-color);font:inherit;font-weight:650}.financial-route-balance{font-weight:750;background:color-mix(in srgb,var(--bs-secondary-bg) 55%,transparent)}.financial-route-balance.risk{background:#fce8e8;color:#a1262e}.financial-route-balance.attention{background:#fff3cd;color:#805b00}.financial-route-balance.protected{background:#e5f3e9;color:#24633c}.financial-route-balance.comfortable{background:#ccebd6;color:#155b32}.financial-route-table tfoot th,.financial-route-table tfoot td{font-weight:750;border-top:2px solid var(--bs-border-color);border-bottom:0}.financial-route-table tfoot th{text-align:left}.financial-route-months.mode-1 .financial-route-table{font-size:.85rem}.financial-route-months.mode-1 .financial-route-table th,.financial-route-months.mode-1 .financial-route-table td{padding:.58rem .7rem}@media(max-width:1199.98px){.financial-route-months{grid-template-columns:1fr}.financial-route-months.mode-3{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:767.98px){.financial-route-header{align-items:flex-start;flex-direction:column}.financial-route-controls{width:100%;justify-content:space-between;flex-wrap:wrap}.financial-route-months,.financial-route-months.mode-3{grid-template-columns:1fr}.financial-route-table{min-width:38rem}.financial-route-table-wrap{position:relative}.financial-route-month{min-width:0}}
</style>
@endpush

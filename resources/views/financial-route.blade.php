@extends('layouts.app')

@section('title', 'Rota Financeira')

@section('content')
@php
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $symbol = $symbols[$currency] ?? $currency;
    $format = fn ($value) => number_format($value, 2, ',', '.');
    $today = now()->format('Y-m-d');
    $accountsById = $accounts->keyBy('id');
@endphp

<section class="financial-route-page" id="financialRoute"
    data-update-url="{{ url('/api/financial/movements') }}"
    data-delete-url="{{ url('/api/financial/movements') }}"
    data-reverse-url="{{ url('/api/financial/movements') }}"
    data-currency="{{ $currency }}"
    data-today="{{ $today }}">
    <header class="financial-route-header">
        <div>
            
            <h1>Rota Financeira</h1>
        </div>
    </header>

    <div class="financial-route-toolbar">
        <div class="financial-route-period">
            <button type="button" class="btn btn-sm btn-light" id="routePrevious" aria-label="Período anterior"><i data-lucide="chevron-left"></i></button>
            <strong id="routePeriodLabel">{{ $mode === 3 ? $months->first()->locale('pt_PT')->translatedFormat('F') . ' — ' . $months->last()->locale('pt_PT')->translatedFormat('F Y') : $months->first()->locale('pt_PT')->translatedFormat('F Y') }}</strong>
            <button type="button" class="btn btn-sm btn-light" id="routeNext" aria-label="Período seguinte"><i data-lucide="chevron-right"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="routeToday">Hoje</button>
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Visualização">
            <button type="button" class="btn {{ $mode === 3 ? 'btn-primary' : 'btn-outline-secondary' }}" data-route-mode="3">Trimestre</button>
            <button type="button" class="btn {{ $mode === 1 ? 'btn-primary' : 'btn-outline-secondary' }}" data-route-mode="1">Mês</button>
        </div>
    </div>

    <div class="financial-route-months mode-{{ $mode }}" id="routeMonths">
        @foreach ($months as $monthDate)
            @php
                $monthKey = $monthDate->format('Y-m');
                $monthDays = $days[$monthKey];
                $fixedTotal = collect($monthDays)->sum('fixed');
                $variableTotal = collect($monthDays)->sum('variable');
                $running = $openingBalance;
            @endphp
            <section class="financial-route-month" data-month="{{ $monthKey }}" aria-label="{{ $monthDate->locale('pt_PT')->translatedFormat('F Y') }}">
                <h2>{{ mb_strtoupper($monthDate->locale('pt_PT')->translatedFormat('F Y')) }}</h2>
                <div class="financial-route-table-wrap">
                    <table class="financial-route-table">
                        <thead><tr><th>Data</th><th><span class="route-desktop-label">{{ $mode === 1 ? 'Entradas' : 'Entr.' }}</span><span class="route-mobile-label">Entr.</span></th><th><span class="route-desktop-label">{{ $mode === 1 ? 'Gasto fixo' : 'Fixo' }}</span><span class="route-mobile-label">Fixo</span></th><th><span class="route-desktop-label">{{ $mode === 1 ? 'Variável' : 'Var.' }}</span><span class="route-mobile-label">Variável</span></th><th>Saldo</th></tr></thead>
                        <tbody>
                        @foreach ($monthDays as $date => $values)
                            @php
                                $running += $values['cash_delta'];
                                $status = $running <= 0 ? 'risk' : ($running <= $fixedTotal ? 'attention' : ($running < ($fixedTotal + $variableTotal) ? 'protected' : 'comfortable'));
                                $dayItems = $values['items']->map(function ($item) use ($accountsById) {
                                    // O sinal identifica o lado do movimento relevante para a projeção.
                                    $delta = (float) $item->projection_delta;
                                    $accountId = $delta < 0 ? $item->source_account_id : $item->destination_account_id;

                                    return [
                                        'id' => $item->id, 'financial_movement_id' => $item->id, 'type' => $item->type, 'status' => $item->status,
                                        'invoice_id' => $item->invoice_id, 'installment_id' => $item->installment_id, 'debt_id' => $item->debt_id,
                                        'settles_movement_id' => $item->settles_movement_id, 'is_settlement_parent' => (bool) $item->is_settlement_parent,
                                        'description' => $item->description, 'amount' => (float) $item->amount,
                                        'projection_delta' => $delta, 'date' => $item->movement_date->toDateString(),
                                        'expense_behavior' => $item->expense_behavior,
                                        'category' => $item->categoria?->name ?? 'Sem categoria', 'category_id' => $item->category_id,
                                        'account_id' => $accountId,
                                        'account_name' => $accountId ? ($accountsById->get($accountId)?->name ?? 'Conta indisponível') : 'Sem conta associada',
                                        'recurrence_id' => $item->recurrence_id,
                                        'notes' => $item->notes ?? '',
                                        'paid_amount' => (float) ($item->paid_amount ?? 0),
                                        'remaining_amount' => (float) ($item->remaining_amount ?? 0),
                                        'is_partial_summary' => (bool) ($item->is_partial_summary ?? false),
                                    ];
                                })->values();
                            @endphp
                            <tr class="{{ $date === $today ? 'is-today' : '' }}">
                                <th scope="row"><button type="button" class="route-cell route-date" data-date="{{ $date }}">{{ \Illuminate\Support\Carbon::parse($date)->format('d') }}</button></th>
                                <td><button type="button" class="route-cell" data-date="{{ $date }}" data-filter="income">{{ $values['income'] > 0 ? $format($values['income']) : '—' }}</button></td>
                                <td><button type="button" class="route-cell" data-date="{{ $date }}" data-filter="fixed">{{ $values['fixed'] > 0 ? $format($values['fixed']) : '—' }}</button></td>
                                <td><button type="button" class="route-cell" data-date="{{ $date }}" data-filter="variable">{{ $values['variable'] > 0 ? $format($values['variable']) : '—' }}</button></td>
                                <td><button type="button" class="route-cell route-balance {{ $status }}" data-date="{{ $date }}" data-filter="balance" title="{{ ucfirst($status) }}">{{ $format($running) }}</button></td>
                            </tr>
                            <script type="application/json" id="route-day-{{ $date }}">{!! json_encode(['items' => $dayItems, 'balance' => $running, 'status' => $status], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) !!}</script>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="4">Saldo final</th><td class="route-balance {{ $running <= 0 ? 'risk' : ($running <= $fixedTotal ? 'attention' : ($running < ($fixedTotal + $variableTotal) ? 'protected' : 'comfortable')) }}"><span>{{ $format($running) }}</span><span>{{ $symbol }}</span></td></tr></tfoot>
                    </table>
                </div>
            </section>
            @php $openingBalance = $running; @endphp
        @endforeach
    </div>
</section>

<div class="modal fade" id="routeDayModal" tabindex="-1" aria-labelledby="routeDayTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content route-sheet-content">
        <div class="modal-header"><div><h2 class="modal-title h5 fw-bold" id="routeDayTitle">Detalhes do dia</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><div class="route-day-balance" id="routeDayBalance"><span class="route-day-balance__label">Saldo previsto no fim do dia</span><strong id="routeDayBalanceValue"></strong></div><div class="route-filter-tabs" id="routeFilterTabs">
            <button type="button" class="active" data-day-filter="all">Todos</button><button type="button" data-day-filter="income">Entradas</button><button type="button" data-day-filter="fixed">Fixo</button><button type="button" data-day-filter="variable">Variável</button>
        </div><section id="routeOverdueSection" class="route-overdue-section" hidden>
            <div class="route-overdue-heading"><strong>Vencidas</strong><span id="routeOverdueCount"></span></div>
            <div id="routeOverdueMovements" class="route-overdue-list"></div>
        </section>
        <div id="routeDayMovements" class="route-movement-list"></div></div>
        
    </div></div>
</div>
    <script type="application/json" id="route-overdue-data">{!! json_encode($overdueItems, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) !!}</script>

<select id="routeCategory" class="d-none" aria-hidden="true" tabindex="-1">
    <option value="">Selecionar categoria</option>
    @foreach($categories as $category)<option value="{{ $category->id }}" data-type="{{ $category->type }}">{{ $category->name }}</option>@endforeach
</select>



@include('partials.movement-edit-modal')

<x-modal id="routeMovementDetailModal" aria-labelledby="routeMovementDetailTitle" :scrollable="true">
    <x-modal-header title="Detalhes do movimento" title-id="routeMovementDetailTitle" />
    <x-modal-body>
        <x-financial.movement-details-panel prefix="routeMovement" related-label="Conta" destructive-label="Eliminar" :show-description="true" :show-notes="true" />
        <div class="small text-danger mt-3" id="routeDetailError" role="alert"></div>
        <p class="visually-hidden" id="routeEditHelp" role="status"></p>
    </x-modal-body>
</x-modal>

<x-modal id="routeConfirmModal" aria-labelledby="routeConfirmTitle">
    <x-modal-header title="Eliminar movimento?" title-id="routeConfirmTitle" />
    <x-modal-body>
            <p class="mb-1" id="routeConfirmText">Este movimento será eliminado e os saldos das suas contas serão atualizados.</p>
            <small class="text-secondary" id="routeConfirmHint">Esta ação não pode ser desfeita.</small>
    </x-modal-body>
    <x-modal-footer class="d-flex flex-row gap-2">
        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger flex-fill" id="routeConfirmAction">Eliminar</button>
    </x-modal-footer>
</x-modal>
@endsection

@push('styles')
<style>
.financial-route-page{width:100%;min-width:0}.financial-route-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.25rem}.financial-route-eyebrow{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--bs-secondary-color);font-weight:700;margin:0 0 .3rem}.financial-route-header h1{font-size:1.65rem;font-weight:750;letter-spacing:-.035em;margin:0}.financial-route-actions .btn{display:flex;align-items:center;gap:.4rem}.financial-route-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}.financial-route-period{display:flex;align-items:center;gap:.5rem}.financial-route-period strong{text-transform:capitalize;font-size:.95rem;min-width:12rem;text-align:center}.financial-route-months{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}.financial-route-months.mode-1{grid-template-columns:minmax(0,1fr)}.financial-route-month h2{font-size:.78rem;letter-spacing:.07em;font-weight:800;margin:0 0 .55rem}.financial-route-table-wrap{width:100%;border:1px solid var(--bs-border-color);border-radius:.7rem;background:var(--bs-body-bg);overflow:hidden}.financial-route-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:.76rem;font-variant-numeric:tabular-nums}.financial-route-table th,.financial-route-table td{padding:.43rem .35rem;text-align:right;border-bottom:1px solid var(--bs-border-color-translucent);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.financial-route-table th:first-child{width:15%;text-align:left}.financial-route-table th:nth-child(2),.financial-route-table th:nth-child(3),.financial-route-table th:nth-child(4){width:19%}.financial-route-table th:last-child{width:28%}.route-mobile-label{display:none}.financial-route-table thead th{font-size:.66rem;color:var(--bs-secondary-color);font-weight:700;background:var(--bs-tertiary-bg)}.route-cell{display:block;width:100%;border:0;padding:0;background:transparent;color:var(--bs-body-color);font:inherit;text-align:right;font-variant-numeric:tabular-nums}.route-date{text-align:left;font-weight:700}.route-cell:not(:disabled){cursor:pointer}.route-cell:hover{text-decoration:underline;text-underline-offset:2px}.route-balance{font-weight:800;padding:.38rem .25rem;border-radius:.25rem}.route-balance.risk{background:#fce8e8;color:#a1262e}.route-balance.attention{background:#fff3cd;color:#805b00}.route-balance.protected{background:#e5f3e9;color:#24633c}.route-balance.comfortable{background:#ccebd6;color:#155b32}.financial-route-table tfoot th,.financial-route-table tfoot td{font-weight:800;border-top:2px solid var(--bs-border-color);border-bottom:0}.financial-route-table tfoot th{text-align:left}.financial-route-table tfoot td{white-space:normal}.route-filter-tabs{display:flex;gap:.4rem;flex-wrap:wrap;margin:1rem 0}.route-filter-tabs button{border:1px solid var(--bs-border-color);border-radius:2rem;background:transparent;padding:.38rem .7rem;font-size:.78rem}.route-filter-tabs button.active{background:var(--bs-primary);border-color:var(--bs-primary);color:#fff}.route-day-balance{display:flex;flex-direction:column;gap:.15rem}.route-day-balance__label{font-size:.76rem;font-weight:600;color:var(--bs-secondary-color)}.route-day-balance strong{font-size:1.35rem;font-weight:800;font-variant-numeric:tabular-nums}.route-movement-list{display:grid;gap:.5rem}.route-movement-item--partial{flex-direction:column;align-items:stretch}.route-movement-item__row{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;width:100%}.route-movement-item__content{display:grid;gap:.35rem;min-width:0;flex:1}.route-movement-item__payment{display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;font-size:.75rem}.route-movement-item__payment .is-paid{color:var(--bs-success)}.route-movement-item__progress{height:4px;border-radius:999px;background:var(--bs-tertiary-bg);overflow:hidden}.route-movement-item__progress span{display:block;height:100%;border-radius:inherit;background:var(--bs-success-border-subtle,#86b99a)}.route-movement-item{width:100%;display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.8rem;border:1px solid var(--bs-border-color);border-radius:.65rem;background:var(--bs-body-bg);text-align:left}.route-movement-item small{display:block;color:var(--bs-secondary-color)}.route-movement-item strong{white-space:nowrap;font-variant-numeric:tabular-nums}.route-detail-amount{font-size:1.8rem;font-weight:800;margin:.2rem 0 1.2rem}.route-detail-list{margin:0}.route-detail-list div{display:flex;justify-content:space-between;gap:1rem;padding:.7rem 0;border-bottom:1px solid var(--bs-border-color-translucent)}.route-detail-list dt{color:var(--bs-secondary-color);font-weight:500}.route-detail-list dd{margin:0;text-align:right;font-weight:650}.route-type-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.45rem}.route-type-options label{margin:0}.route-type-options input{position:absolute;opacity:0}.route-type-options span{display:block;text-align:center;border:1px solid var(--bs-border-color);border-radius:.55rem;padding:.65rem .35rem;font-size:.84rem}.route-type-options input:checked+span{border-color:var(--bs-primary);background:var(--bs-primary-bg-subtle);color:var(--bs-primary);font-weight:700}.route-sheet-content{border:0;border-radius:1rem;overflow:hidden}.route-sheet-content .modal-header,.route-sheet-content .modal-footer{border-color:var(--bs-border-color-translucent)}@media(max-width:1199.98px) and (min-width:768px){.financial-route-months{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:767.98px){.route-desktop-label{display:none}.route-mobile-label{display:inline}.financial-route-months.mode-3 .financial-route-month:nth-child(n+2){display:none}.financial-route-toolbar .btn-group{display:none}.financial-route-header{align-items:flex-start}.financial-route-header h1{font-size:1.35rem}.financial-route-toolbar{gap:.5rem;flex-wrap:wrap}.financial-route-period{width:100%;justify-content:space-between}.financial-route-period strong{min-width:0;flex:1}.financial-route-months{grid-template-columns:1fr;gap:1.1rem}.financial-route-table{font-size:clamp(.59rem,2.5vw,.72rem)}.financial-route-table th,.financial-route-table td{padding:.48rem .12rem}.financial-route-table th:first-child{width:17%}.financial-route-table th:nth-child(2),.financial-route-table th:nth-child(3),.financial-route-table th:nth-child(4){width:18%}.financial-route-table th:last-child{width:29%}.financial-route-table thead th{font-size:clamp(.54rem,2.25vw,.65rem)}.route-balance{padding:.35rem .05rem}.route-sheet-content{border-radius:1.1rem 1.1rem 0 0}.modal-dialog{align-items:flex-end;margin:0;min-height:100%}.modal-dialog .modal-content{border-radius:1.1rem 1.1rem 0 0;max-height:88vh;overflow:auto}.route-type-options span{font-size:.75rem;padding:.6rem .15rem}.route-detail-list div{padding:.6rem 0}}

/* Modal de detalhes do dia: contenção e leitura em mobile */
@media(max-width:767.98px){
 #routeDayModal .modal-dialog{
  width:100%;
  max-width:100%;
  min-width:0;
  margin:0;
  padding:0;
  align-items:flex-end;
 }
 #routeDayModal .modal-content{
  width:100%;
  max-width:100%;
  min-width:0;
  max-height:calc(100dvh - .75rem);
  margin:0;
  overflow:hidden;
  border-radius:1.1rem 1.1rem 0 0;
  box-sizing:border-box;
 }
 #routeDayModal .modal-header,
 #routeDayModal .modal-footer{
  flex:0 0 auto;
  min-width:0;
  padding:1rem;
 }
 #routeDayModal .modal-header .modal-title{
  min-width:0;
  overflow-wrap:anywhere;
 }
 #routeDayModal .modal-body{
  min-width:0;
  overflow-x:hidden;
  overflow-y:auto;
  padding:1rem;
 }
 #routeDayModal .route-day-balance{
  font-size:1.25rem;
  overflow-wrap:anywhere;
 }
 #routeDayModal .route-filter-tabs{
  gap:.4rem;
  margin:.85rem 0;
 }
 #routeDayModal .route-filter-tabs button{
  padding:.45rem .7rem;
  font-size:.82rem;
 }
 #routeDayModal .route-movement-list{
  width:100%;
  min-width:0;
 }
 #routeDayModal .route-movement-item{
  width:100%;
  min-width:0;
  gap:.65rem;
  padding:.75rem;
  box-sizing:border-box;
 }
 #routeDayModal .route-movement-item span{
  flex:1 1 auto;
  min-width:0;
  overflow-wrap:anywhere;
 }
 #routeDayModal .route-movement-item span strong{
  display:block;
  white-space:normal;
  overflow-wrap:anywhere;
 }
 #routeDayModal .route-movement-item small{
  overflow-wrap:anywhere;
 }
 #routeDayModal .route-movement-item>strong{
  flex:0 0 auto;
  max-width:42%;
  white-space:normal;
  text-align:right;
  overflow-wrap:anywhere;
 }
 #routeDayModal .modal-footer{
  display:flex;
  flex-direction:row;
  gap:.5rem;
 }
 #routeDayModal .modal-footer .btn{
  flex:1 1 0;
  min-width:0;
  white-space:normal;
 }
}

/* Refinamento visual da Rota Financeira — mobile */
@media(max-width:767.98px){
 .financial-route-page{padding-bottom:.5rem}
 .financial-route-header{align-items:center;gap:.65rem;margin:0 0 1rem}
 .financial-route-eyebrow{font-size:.62rem;letter-spacing:.11em;margin-bottom:.22rem}
 .financial-route-header h1{font-size:1.28rem;line-height:1.15;letter-spacing:-.04em}
 .financial-route-actions{flex:0 0 auto}
 .financial-route-actions .btn{min-height:42px;padding:.55rem .8rem;border-radius:.7rem;gap:.35rem;font-size:.82rem;font-weight:650;box-shadow:0 2px 7px rgba(89,78,220,.14)}
 .financial-route-actions .btn svg{width:17px;height:17px}
 .financial-route-toolbar{margin:0 0 1rem}
 .financial-route-period{gap:.35rem}
 .financial-route-period>button{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--bs-border-color);border-radius:.65rem;background:var(--bs-body-bg)}
 .financial-route-period strong{font-size:.91rem;font-weight:700;color:var(--bs-emphasis-color)}
 #routeToday{width:auto;padding:0 .65rem;font-size:.72rem;border-radius:.6rem;white-space:nowrap}
 .financial-route-months{gap:.75rem}
 .financial-route-month h2{display:flex;align-items:center;justify-content:space-between;font-size:.75rem;letter-spacing:.075em;margin:0;padding:.85rem .9rem .65rem}

 .financial-route-table-wrap{border:1px solid var(--bs-border-color);border-radius:.85rem;box-shadow:0 2px 10px rgba(25,25,45,.035)}
 .financial-route-table{width:100%;table-layout:fixed;font-size:clamp(.62rem,2.55vw,.76rem);line-height:1.25;font-variant-numeric:tabular-nums}
 .financial-route-table th,.financial-route-table td{padding:.38rem .12rem;border-bottom:1px solid rgba(120,120,140,.13);white-space:nowrap;overflow:visible;text-overflow:clip}
 .financial-route-table thead th{padding:.62rem .08rem;background:#f5f4fa;color:#666579;font-size:clamp(.57rem,2.25vw,.68rem);letter-spacing:0;white-space:nowrap}
 .financial-route-table th:first-child{width:9%;padding-left:.45rem}
 .financial-route-table th:nth-child(2){width:21%}
 .financial-route-table th:nth-child(3){width:19%}
 .financial-route-table th:nth-child(4){width:23%}
 .financial-route-table th:last-child{width:28%;padding-right:.45rem}
 .route-cell{white-space:nowrap;overflow:visible;text-overflow:clip;font-variant-numeric:tabular-nums;letter-spacing:-.025em}
 .route-date{padding-left:0!important}
 .route-cell{min-height:24px;font-size:inherit;letter-spacing:-.015em}
 .route-date{font-weight:650;color:var(--bs-emphasis-color)}
 .route-balance{display:block;margin-left:auto;width:100%;padding:.31rem .25rem;border-radius:.35rem;background:transparent!important;font-weight:750}
 .route-balance.risk{color:#c0444c}
 .route-balance.attention{color:#a87917}
 .route-balance.protected{color:#397b56}
 .route-balance.comfortable{color:#267348}
 .financial-route-table tbody tr:last-child th,.financial-route-table tbody tr:last-child td{border-bottom:1px solid rgba(120,120,140,.13)}
 .financial-route-table tbody tr.is-today>th,.financial-route-table tbody tr.is-today>td{background:#e9e9eb!important}
 .financial-route-table tfoot th,.financial-route-table tfoot td{padding:.8rem .65rem;background:#f7f6fb;border-top:1px solid var(--bs-border-color);font-size:.82rem}
 .financial-route-table tfoot th{border-radius:0 0 0 .75rem;text-align:left}
 .financial-route-table tfoot td{display:flex;align-items:center;justify-content:flex-end;gap:.22rem;flex-wrap:nowrap;white-space:nowrap;border-radius:0 0 .75rem 0;font-size:.96rem;font-variant-numeric:tabular-nums}
 .financial-route-table tfoot .route-balance{background:transparent!important;padding:0;white-space:nowrap}
 .route-sheet-content{box-shadow:0 -8px 30px rgba(20,20,40,.12)}
}
@media(max-width:380px){
 .financial-route-header h1{font-size:1.16rem}
 .financial-route-eyebrow{font-size:.57rem}
 .financial-route-actions .btn{padding:.48rem .62rem;font-size:.76rem}
 .financial-route-table{font-size:clamp(.59rem,2.3vw,.69rem)}
 .financial-route-table th,.financial-route-table td{padding-left:.08rem;padding-right:.08rem}
 .financial-route-table th:first-child{padding-left:.35rem}
 .financial-route-table th:last-child{padding-right:.35rem}
 .financial-route-table thead th{font-size:clamp(.49rem,1.8vw,.56rem)}
}


.route-overdue-section{margin:0 0 1rem}
.route-overdue-heading{display:flex;align-items:center;justify-content:space-between;margin:0 0 .55rem;font-size:.82rem;font-weight:700;color:var(--bs-emphasis-color)}
.route-overdue-heading span{font-size:.72rem;font-weight:650;color:#a52b35}
.route-overdue-list{display:grid;gap:.5rem}
.route-overdue-item{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.8rem .9rem;border:1px solid #f2caca;border-radius:.75rem;background:#fff1f1;color:#7f1d1d}
.route-overdue-item__details{display:grid;gap:.18rem;min-width:0}
.route-overdue-item__name{font-size:.86rem;font-weight:650;overflow-wrap:anywhere}
.route-overdue-item__date{font-size:.72rem;color:#9b343b}
.route-overdue-item__amount{flex:0 0 auto;font-size:.86rem;font-weight:750;color:#b4232d;font-variant-numeric:tabular-nums}
</style>
@endpush

@push('scripts')
<script>
(() => {
 const root=document.getElementById('financialRoute'); if(!root)return;
 const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
 const currency=root.dataset.currency||'EUR';
 const urls={update:root.dataset.updateUrl,remove:root.dataset.deleteUrl,reverse:root.dataset.reverseUrl};
 const modal=id=>bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
 const dayModal=modal('routeDayModal'), detailModal=modal('routeMovementDetailModal'), confirmModal=modal('routeConfirmModal');
 const detailElement=document.getElementById('routeMovementDetailModal'), confirmElement=document.getElementById('routeConfirmModal');
 let activeDate=root.dataset.today, activeFilter='all', activeMovement=null, confirmCallback=null, returnToRouteDetails=false, routeDetailsNext='', mode={{ $mode }}, month={{ Illuminate\Support\Js::from($month) }};
 const fmt=value=>new Intl.NumberFormat('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value)+' '+({EUR:'€',USD:'$',GBP:'£',BRL:'R$'}[currency]||currency);
 const dateLabel=value=>new Intl.DateTimeFormat('pt-PT',{day:'2-digit',month:'long',year:'numeric'}).format(new Date(value+'T12:00:00'));
 const movementKind=item=>item.type==='adjustment'?'Ajuste de saldo':item.type==='transfer'?'Transferência':item.type==='income'?'Entrada':item.expense_behavior==='fixed'?'Gasto fixo':'Variável';
 const signedAmount=item=>['adjustment','transfer'].includes(item.type)?(item.projection_delta>0?'+':item.projection_delta<0?'−':'')+fmt(Math.abs(item.projection_delta)):(item.type==='income'?'+':'−')+fmt(item.amount);
 const canEditMovement=item=>window.meuBolsoMovementEditRules?.canEdit({
   type:item?.type,status:item?.status,financialMovementId:item?.financial_movement_id??item?.id,
   invoiceId:item?.invoice_id,installmentId:item?.installment_id,debtId:item?.debt_id,
   isSettlementEvent:Boolean(item?.settles_movement_id || item?.is_settlement_parent),
   settlesMovementId:item?.settles_movement_id,isSettlementParent:item?.is_settlement_parent,
   isInvoicePayment:item?.type==='transfer'
 })===true;
 const dayData=date=>{const el=document.getElementById('route-day-'+date);return el?JSON.parse(el.textContent):null};
 const overdueItems=JSON.parse(document.getElementById('route-overdue-data')?.textContent||'[]');
 const setPeriod=()=>{const url=new URL(window.location.href);url.searchParams.set('mes',month);url.searchParams.set('modo',mode);window.location.href=url.toString()};
 const periodStep=()=>window.matchMedia('(max-width: 767.98px)').matches?1:mode;
 const shiftMonth=(value,count)=>{const [y,m]=value.split('-').map(Number);const d=new Date(y,m-1+count,1);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')};
 document.getElementById('routePrevious').addEventListener('click',()=>{month=shiftMonth(month,-periodStep());setPeriod()});
 document.getElementById('routeNext').addEventListener('click',()=>{month=shiftMonth(month,periodStep());setPeriod()});
 document.getElementById('routeToday').addEventListener('click',()=>{month=root.dataset.today.slice(0,7);window.location.hash='route-today';setPeriod()});
 document.querySelectorAll('[data-route-mode]').forEach(b=>b.addEventListener('click',()=>{mode=Number(b.dataset.routeMode);setPeriod()}));
 if(window.matchMedia('(max-width: 767.98px)').matches){const [year,monthNumber]=month.split('-').map(Number);document.getElementById('routePeriodLabel').textContent=new Intl.DateTimeFormat('pt-PT',{month:'long',year:'numeric'}).format(new Date(year,monthNumber-1,1));}
 if(window.location.hash==='#route-today'){requestAnimationFrame(()=>document.querySelector('[data-date="'+root.dataset.today+'"]')?.scrollIntoView({behavior:'smooth',block:'center'}));window.history.replaceState(null,'',window.location.pathname+window.location.search);}
 // Bootstrap ignora hide() enquanto o modal ainda está a abrir.
 // Aguarda shown.bs.modal antes de fechar e só abre o próximo após hidden.bs.modal.
 function hideModalThen(element,instance,callback){
   const transition=window.meuBolsoMovementEditRules?.hideThen;
   if(transition){transition(element,instance,callback);return}
   if(!element||!instance)return;
   if(instance._isTransitioning){
     const eventName=element.classList.contains('show')?'shown.bs.modal':'hidden.bs.modal';
     element.addEventListener(eventName,()=>hideModalThen(element,instance,callback),{once:true});
     return;
   }
   if(!element.classList.contains('show')){callback();return}
   element.addEventListener('hidden.bs.modal',callback,{once:true});
   instance.hide();
 }
 function renderOverdueItems(){
   const section=document.getElementById('routeOverdueSection');
   const list=document.getElementById('routeOverdueMovements');
   const count=document.getElementById('routeOverdueCount');
   list.innerHTML='';
   section.hidden=overdueItems.length===0;
   if(!overdueItems.length)return;
   count.textContent=overdueItems.length+' em aberto';
   overdueItems.forEach(item=>{
     const row=document.createElement('div');row.className='route-overdue-item';
     const details=document.createElement('span');details.className='route-overdue-item__details';
     const name=document.createElement('strong');name.className='route-overdue-item__name';name.textContent=item.description;
     const due=document.createElement('small');due.className='route-overdue-item__date';
     due.textContent='Vencimento: '+dateLabel(String(item.movement_date).slice(0,10));
     const amount=document.createElement('strong');amount.className='route-overdue-item__amount';amount.textContent=fmt(Number(item.amount));
     details.append(name,due);row.append(details,amount);list.append(row);
   });
 }
 function openDay(date){
   activeDate=date;activeFilter='all';const data=dayData(date);if(!data)return;
   document.getElementById('routeDayTitle').textContent=dateLabel(date);
   document.getElementById('routeDayBalanceValue').textContent=fmt(data.balance);
   document.querySelectorAll('[data-day-filter]').forEach(b=>b.classList.toggle('active',b.dataset.dayFilter==='all'));
   renderOverdueItems();renderDayItems(data);dayModal.show();
 }
 function renderDayItems(data){
   const list=document.getElementById('routeDayMovements');list.innerHTML='';
   const items=data.items.filter(item=>activeFilter==='all'||activeFilter==='balance'||(activeFilter==='income'?item.type==='income':item.type==='expense'&&item.expense_behavior===activeFilter));
   if(!items.length){list.innerHTML='<p class="text-secondary small mb-0 py-3">Sem movimentos.</p>';return}
   items.forEach(item=>{
     const b=document.createElement('button');b.type='button';b.className='route-movement-item';
     const left=document.createElement('span'),name=document.createElement('strong'),meta=document.createElement('small'),amount=document.createElement('strong');
     name.textContent=item.description;meta.textContent=movementKind(item)+' · '+item.category;amount.textContent=signedAmount(item);
     left.append(name,meta);
     if(item.is_partial_summary){
       b.classList.add('route-movement-item--partial');
       const row=document.createElement('span');row.className='route-movement-item__row';
       const content=document.createElement('span');content.className='route-movement-item__content';content.append(left);
       const payment=document.createElement('span');payment.className='route-movement-item__payment';
       const paid=document.createElement('span');paid.className='is-paid';paid.textContent='Pago: '+fmt(item.paid_amount);
       const pending=document.createElement('span');pending.textContent='Pendente: '+fmt(item.remaining_amount);
       payment.append(paid,pending);
       const progress=document.createElement('span');progress.className='route-movement-item__progress';progress.setAttribute('role','progressbar');progress.setAttribute('aria-label','Percentagem do movimento paga');progress.setAttribute('aria-valuemin','0');progress.setAttribute('aria-valuemax','100');
       const fill=document.createElement('span');const percent=item.amount>0?Math.min(100,Math.max(0,(item.paid_amount/item.amount)*100)):0;
       progress.setAttribute('aria-valuenow',String(Math.round(percent)));fill.style.width=percent+'%';progress.append(fill);
       row.append(content,amount);b.append(row,payment,progress);
     }else{b.append(left,amount);}
     b.addEventListener('click',()=>hideModalThen(document.getElementById('routeDayModal'),dayModal,()=>openMovementDetail(item)));list.append(b);
   });
 }
 document.querySelectorAll('[data-day-filter]').forEach(b=>b.addEventListener('click',()=>{activeFilter=b.dataset.dayFilter;document.querySelectorAll('[data-day-filter]').forEach(x=>x.classList.toggle('active',x===b));renderDayItems(dayData(activeDate))}));
 root.querySelectorAll('[data-date]').forEach(b=>b.addEventListener('click',()=>openDay(b.dataset.date)));
 function openMovementDetail(item){
   activeMovement=item;
   document.getElementById('routeMovementDetailsName').textContent=item.description;
   document.getElementById('routeMovementDetailsKind').textContent=movementKind(item);
   document.getElementById('routeMovementDetailsAmount').textContent=['adjustment','transfer'].includes(item.type)?signedAmount(item):fmt(item.amount);
   document.getElementById('routeMovementDetailsCategory').textContent=item.category;
   document.getElementById('routeMovementDetailsRelated').textContent=item.account_name;
   document.getElementById('routeMovementDetailsRelatedRow').hidden=false;
   document.getElementById('routeMovementDetailsDate').textContent=dateLabel(item.date);
   document.getElementById('routeMovementDetailsDescription').textContent=item.description;
   document.getElementById('routeMovementDetailsNotes').textContent=item.notes||'—';
   document.getElementById('routeDetailError').textContent='';
   document.getElementById('routeMovementDetailsEdit').hidden=!canEditMovement(item);
   const reverseButton=document.getElementById('routeMovementDetailsReverse');
   reverseButton.hidden=!(item.is_settlement_parent&&['income','expense'].includes(item.type)&&['partial','partially_settled','settled'].includes(item.status)&&!item.invoice_id&&!item.installment_id&&!item.debt_id&&!item.settles_movement_id);
   document.getElementById('routeEditHelp').textContent=item.type==='adjustment'
     ?'Ajustes não editáveis. Elimina o movimento.'
     :item.type==='transfer'
       ?'Transferências não editáveis. Elimina e recria.'
       :!canEditMovement(item)
         ?(item.invoice_id?'Movimento associado a fatura. Gere em Cartões.':item.installment_id||item.debt_id?'Movimento associado a parcelamento. Gere em Parcelamentos.':item.status==='settled'?'Este movimento não admite edição direta.':'Previsão gerada automaticamente. Gere em Faturas.')
         :'';
   const deleteButton=document.getElementById('routeMovementDetailsDelete');
   deleteButton.textContent=item.recurrence_id?'Eliminar ocorrência':'Eliminar';
   deleteButton.hidden=item.status!=='pending';
   detailModal.show();
 }
 const editElement=document.getElementById('movementEditModal');
 if(editElement&&editElement.parentElement!==document.body)document.body.appendChild(editElement);
 const editModal=bootstrap.Modal.getOrCreateInstance(editElement);
 const editForm=document.getElementById('movementEditForm');
 let editingRouteMovement=null;

 function openRouteMovementEdit(item){
   if(!item||!canEditMovement(item)||!editForm)return;
   editingRouteMovement=item;
   const isExpense=item.type==='expense';
   document.getElementById('movementEditTitle').textContent=isExpense?'Editar despesa':'Editar receita';
   document.getElementById('movementEditDescription').value=item.description||'';
   document.getElementById('movementEditAmount').value=String(item.amount??'').replace('.',',');
   document.getElementById('movementEditDate').value=item.date||'';
   document.getElementById('movementEditNotes').value=item.notes||'';
   document.getElementById('movementEditExpenseBehavior').value=item.expense_behavior||'variable';
   const category=document.getElementById('movementEditCategory');
   const sourceCategory=document.getElementById('routeCategory');
   category.replaceChildren(new Option('Seleciona uma categoria',''));
   [...sourceCategory.options].filter(option=>option.value&&option.dataset.type===(isExpense?'despesa':'receita'))
     .forEach(option=>category.add(new Option(option.textContent,option.value)));
   category.value=item.category_id||'';
   document.getElementById('movementEditError').classList.add('d-none');
   document.getElementById('movementEditError').textContent='';
 }

 document.getElementById('routeMovementDetailsEdit').addEventListener('click',()=>{
   if(!activeMovement||!canEditMovement(activeMovement))return;
   const item=activeMovement;
   openRouteMovementEdit(item);
   returnToRouteDetails=true;routeDetailsNext='edit';
   hideModalThen(detailElement,detailModal,()=>editModal.show());
 });

 editForm?.addEventListener('submit',async event=>{
   event.preventDefault();
   if(!editingRouteMovement||editForm.dataset.submitting==='1'||!editForm.reportValidity())return;
   const error=document.getElementById('movementEditError');
   const submit=document.getElementById('movementEditSubmit');
   const amountText=String(document.getElementById('movementEditAmount').value||'').trim().replace(/[\u00a0\s]/g,'');
   let amount='';
   try{
     if(!/^\d+(?:,\d{1,2}|\.\d{1,2})?$/.test(amountText)&&!/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/.test(amountText))throw new Error('Usa o formato 90,50 ou 1.000,00.');
     if(amountText.includes(',')){const parts=amountText.split(',');amount=parts[0].replace(/\./g,'')+'.'+parts[1].padEnd(2,'0');}
     else if(amountText.includes('.')){const parts=amountText.split('.');amount=parts[0]+'.'+parts[1].padEnd(2,'0');}
     else amount=amountText+'.00';
     if(!(Number(amount)>0))throw new Error('O valor deve ser maior que zero.');
   }catch(validationError){error.textContent=validationError.message;error.classList.remove('d-none');return}
   editForm.dataset.submitting='1';submit.disabled=true;submit.textContent='A guardar…';error.classList.add('d-none');
   const isExpense=editingRouteMovement.type==='expense';
   const payload={
     description:document.getElementById('movementEditDescription').value.trim(),
     notes:document.getElementById('movementEditNotes').value.trim()||null,
     amount,
     movement_date:document.getElementById('movementEditDate').value,
     category_id:document.getElementById('movementEditCategory').value||null,
     ...(isExpense?{expense_behavior:document.getElementById('movementEditExpenseBehavior').value}:{})
   };
   try{
     const response=await fetch(urls.update+'/'+encodeURIComponent(editingRouteMovement.id),{
       method:'PUT',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(payload)
     });
     const result=await response.json().catch(()=>({}));
     if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível atualizar o movimento.');
     returnToRouteDetails=false;routeDetailsNext='';editingRouteMovement=null;editModal.hide();window.location.reload();
   }catch(saveError){error.textContent=saveError.message||'Não foi possível atualizar o movimento.';error.classList.remove('d-none')}
   finally{editForm.dataset.submitting='0';submit.disabled=false;submit.textContent='Guardar alterações'}
 });

 document.getElementById('routeMovementDetailsReverse').addEventListener('click',async()=>{
   const item=activeMovement;
   if(!item||!confirm('Reverter todos os pagamentos registados deste movimento? Cada pagamento será revertido pelo valor original.'))return;
   const button=document.getElementById('routeMovementDetailsReverse');button.disabled=true;
   try{
     const response=await fetch(urls.reverse+'/'+encodeURIComponent(item.id)+'/reverse-settlement',{method:'PUT',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});
     const result=await response.json().catch(()=>({}));
     if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Não foi possível reverter os pagamentos.');
     window.location.reload();
   }catch(error){document.getElementById('routeDetailError').textContent=error.message;button.disabled=false;}
 });
 document.getElementById('routeMovementDetailsDelete').addEventListener('click',()=>{
   const item=activeMovement;const recurring=!!item.recurrence_id;
   document.getElementById('routeConfirmTitle').textContent=recurring?'Eliminar ocorrência?':'Eliminar movimento?';
   document.getElementById('routeConfirmText').textContent='Este movimento será eliminado e os saldos das suas contas serão atualizados.';
   document.getElementById('routeConfirmHint').hidden=false;
   document.getElementById('routeConfirmAction').textContent='Eliminar';
   confirmCallback=async()=>{const endpoint=urls.remove+'/'+item.id;const response=await fetch(endpoint,{method:'DELETE',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});const json=await response.json();if(!response.ok)throw new Error(json.message||'Não foi possível concluir a operação.');window.location.reload()};
   returnToRouteDetails=true;routeDetailsNext='delete';detailModal.hide();
 });
 detailElement.addEventListener('hidden.bs.modal',()=>{if(!returnToRouteDetails)return;if(routeDetailsNext==='delete')confirmModal.show()});
 editElement.addEventListener('hidden.bs.modal',()=>{if(!returnToRouteDetails||routeDetailsNext!=='edit')return;returnToRouteDetails=false;routeDetailsNext='';editingRouteMovement=null;detailModal.show()});
 confirmElement.addEventListener('hidden.bs.modal',()=>{if(returnToRouteDetails){returnToRouteDetails=false;routeDetailsNext='';detailModal.show()}});
 document.getElementById('routeConfirmAction').addEventListener('click',async()=>{try{if(confirmCallback){returnToRouteDetails=false;routeDetailsNext='';await confirmCallback()}}catch(e){returnToRouteDetails=true;routeDetailsNext='delete';document.getElementById('routeConfirmText').textContent=e.message;document.getElementById('routeConfirmHint').hidden=true} });

})();
</script>
@endpush

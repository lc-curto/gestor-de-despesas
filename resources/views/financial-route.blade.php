@extends('layouts.app')

@section('title', 'Rota Financeira')

@section('content')
@php
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'BRL' => 'R$'];
    $symbol = $symbols[$currency] ?? $currency;
    $format = fn ($value) => number_format($value, 0, ',', '.');
    $today = now()->format('Y-m-d');
@endphp

<section class="financial-route-page" id="financialRoute"
    data-store-url="{{ route('financial.movements.store') }}"
    data-update-url="{{ url('/api/financial/movements') }}"
    data-delete-url="{{ url('/api/financial/movements') }}"
    data-reverse-url="{{ url('/api/financial/movements') }}"
    data-currency="{{ $currency }}"
    data-today="{{ $today }}">
    <header class="financial-route-header">
        <div>
            <p class="financial-route-eyebrow">Planeamento financeiro</p>
            <h1>Rota Financeira</h1>
        </div>
        <div class="financial-route-actions">
            <button type="button" class="btn btn-primary" id="routeNewMovement"><i data-lucide="plus" aria-hidden="true"></i> Novo movimento</button>
        </div>
    </header>

    <div class="financial-route-toolbar">
        <div class="financial-route-period">
            <button type="button" class="btn btn-sm btn-light" id="routePrevious" aria-label="Período anterior"><i data-lucide="chevron-left"></i></button>
            <strong id="routePeriodLabel">{{ $mode === 3 ? $months->first()->locale('pt_PT')->translatedFormat('F') . ' — ' . $months->last()->locale('pt_PT')->translatedFormat('F Y') : $months->first()->locale('pt_PT')->translatedFormat('F Y') }}</strong>
            <button type="button" class="btn btn-sm btn-light" id="routeNext" aria-label="Período seguinte"><i data-lucide="chevron-right"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="routeToday">Ir para hoje</button>
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Visualização">
            <button type="button" class="btn {{ $mode === 3 ? 'btn-primary' : 'btn-outline-secondary' }}" data-route-mode="3">3 meses</button>
            <button type="button" class="btn {{ $mode === 1 ? 'btn-primary' : 'btn-outline-secondary' }}" data-route-mode="1">1 mês</button>
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
                        <thead><tr><th>Data</th><th>Entr.</th><th>Fixo</th><th>Var.</th><th>Saldo</th></tr></thead>
                        <tbody>
                        @foreach ($monthDays as $date => $values)
                            @php
                                $running += $values['income'] - $values['variable'] - $values['fixed'];
                                $status = $running <= 0 ? 'risk' : ($running <= $fixedTotal ? 'attention' : ($running < ($fixedTotal + $variableTotal) ? 'protected' : 'comfortable'));
                                $dayItems = $values['items']->map(fn ($item) => [
                                    'id' => $item->id, 'type' => $item->type, 'status' => $item->status,
                                    'description' => $item->description, 'amount' => (float) $item->amount,
                                    'date' => $item->movement_date->toDateString(),
                                    'expense_behavior' => $item->expense_behavior,
                                    'category' => $item->categoria?->name ?? 'Sem categoria', 'category_id' => $item->category_id,
                                    'account_id' => $item->type === 'expense' ? $item->source_account_id : $item->destination_account_id,
                                    'notes' => $item->notes ?? '',
                                ])->values();
                            @endphp
                            <tr>
                                <th scope="row"><button type="button" class="route-cell route-date" data-date="{{ $date }}">{{ \Illuminate\Support\Carbon::parse($date)->format('d/m') }}</button></th>
                                <td><button type="button" class="route-cell" data-date="{{ $date }}" data-filter="income">{{ $values['income'] > 0 ? $format($values['income']) : '—' }}</button></td>
                                <td><button type="button" class="route-cell" data-date="{{ $date }}" data-filter="fixed">{{ $values['fixed'] > 0 ? $format($values['fixed']) : '—' }}</button></td>
                                <td><button type="button" class="route-cell" data-date="{{ $date }}" data-filter="variable">{{ $values['variable'] > 0 ? $format($values['variable']) : '—' }}</button></td>
                                <td><button type="button" class="route-cell route-balance {{ $status }}" data-date="{{ $date }}" data-filter="balance" title="{{ ucfirst($status) }}">{{ $format($running) }}</button></td>
                            </tr>
                            <script type="application/json" id="route-day-{{ $date }}">{!! json_encode(['items' => $dayItems, 'balance' => $running, 'status' => $status], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) !!}</script>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="4">Saldo final</th><td class="route-balance {{ $running <= 0 ? 'risk' : ($running <= $fixedTotal ? 'attention' : ($running < ($fixedTotal + $variableTotal) ? 'protected' : 'comfortable')) }}">{{ $format($running) }} {{ $symbol }}</td></tr></tfoot>
                    </table>
                </div>
            </section>
            @php $openingBalance = $running; @endphp
        @endforeach
    </div>
</section>

<div class="modal fade" id="routeDayModal" tabindex="-1" aria-labelledby="routeDayTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content route-sheet-content">
        <div class="modal-header"><div><p class="text-secondary small mb-1">Movimentos do dia</p><h2 class="modal-title h5 fw-bold" id="routeDayTitle">Detalhes do dia</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><div class="route-day-balance" id="routeDayBalance"></div><div class="route-filter-tabs" id="routeFilterTabs">
            <button type="button" class="active" data-day-filter="all">Todos</button><button type="button" data-day-filter="income">Entradas</button><button type="button" data-day-filter="fixed">Gasto fixo</button><button type="button" data-day-filter="variable">Variável</button>
        </div><div id="routeDayMovements" class="route-movement-list"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button><button type="button" class="btn btn-primary" id="routeAddFromDay">Adicionar movimento</button></div>
    </div></div>
</div>

<div class="modal fade" id="routeMovementModal" tabindex="-1" aria-labelledby="routeMovementTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content route-sheet-content">
        <form id="routeMovementForm">
            <div class="modal-header"><h2 class="modal-title h5 fw-bold" id="routeMovementTitle">Novo movimento</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Tipo de movimento</label><div class="route-type-options">
                    <label><input type="radio" name="route_type" value="income"><span>Entrada</span></label>
                    <label><input type="radio" name="route_type" value="fixed"><span>Gasto fixo</span></label>
                    <label><input type="radio" name="route_type" value="variable" checked><span>Variável</span></label>
                </div></div>
                <div class="mb-3"><label for="routeDescription" class="form-label">Descrição</label><input id="routeDescription" name="description" class="form-control" maxlength="255" placeholder="Ex.: Supermercado" required></div>
                <div class="row g-3"><div class="col-6"><label for="routeAmount" class="form-label">Valor ({{ $symbol }})</label><input id="routeAmount" name="amount" type="number" min="0.01" step="0.01" class="form-control" placeholder="0,00" required></div><div class="col-6"><label for="routeDate" class="form-label">Data</label><input id="routeDate" name="movement_date" type="date" class="form-control" required></div>
                    <div class="col-12"><label for="routeCategory" class="form-label">Categoria</label><select id="routeCategory" name="category_id" class="form-select"><option value="">Selecionar categoria</option>@foreach($categories as $category)<option value="{{ $category->id }}" data-type="{{ $category->type }}">{{ $category->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label for="routeAccount" class="form-label">Conta</label><select id="routeAccount" name="account_id" class="form-select" required><option value="">Selecionar conta</option>@foreach($accounts as $account)<option value="{{ $account->id }}" data-type="{{ $account->type }}">{{ $account->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label for="routeNotes" class="form-label">Observação (opcional)</label><textarea id="routeNotes" name="notes" class="form-control" rows="2" placeholder="Adicionar observação..."></textarea></div>
                </div><div class="small text-danger mt-3" id="routeFormError" role="alert"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar movimento</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="routeMovementDetailModal" tabindex="-1" aria-labelledby="routeMovementDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content route-sheet-content">
        <div class="modal-header"><div><p class="small text-secondary mb-1" id="routeMovementKind"></p><h2 class="modal-title h5 fw-bold" id="routeMovementDetailTitle">Movimento</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><div class="route-detail-amount" id="routeMovementAmount"></div><dl class="route-detail-list"><div><dt>Categoria</dt><dd id="routeMovementCategory"></dd></div><div><dt>Data</dt><dd id="routeMovementDate"></dd></div><div><dt>Descrição</dt><dd id="routeMovementDescription"></dd></div></dl><div class="small text-danger" id="routeDetailError" role="alert"></div></div>
        <div class="modal-footer justify-content-between"><button type="button" class="btn btn-outline-danger" id="routeDeleteMovement">Excluir</button><div><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button><button type="button" class="btn btn-primary" id="routeEditMovement">Editar</button></div></div>
    </div></div>
</div>

<div class="modal fade" id="routeConfirmModal" tabindex="-1" aria-labelledby="routeConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content route-sheet-content"><div class="modal-header"><h2 class="modal-title h5 fw-bold" id="routeConfirmTitle">Confirmar ação</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body" id="routeConfirmText">Tens a certeza?</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-danger" id="routeConfirmAction">Confirmar</button></div></div></div>
</div>
@endsection

@push('styles')
<style>
.financial-route-page{width:100%;min-width:0}.financial-route-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.25rem}.financial-route-eyebrow{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--bs-secondary-color);font-weight:700;margin:0 0 .3rem}.financial-route-header h1{font-size:1.65rem;font-weight:750;letter-spacing:-.035em;margin:0}.financial-route-actions .btn{display:flex;align-items:center;gap:.4rem}.financial-route-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}.financial-route-period{display:flex;align-items:center;gap:.5rem}.financial-route-period strong{text-transform:capitalize;font-size:.95rem;min-width:12rem;text-align:center}.financial-route-months{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}.financial-route-month h2{font-size:.78rem;letter-spacing:.07em;font-weight:800;margin:0 0 .55rem}.financial-route-table-wrap{width:100%;border:1px solid var(--bs-border-color);border-radius:.7rem;background:var(--bs-body-bg);overflow:hidden}.financial-route-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:.76rem;font-variant-numeric:tabular-nums}.financial-route-table th,.financial-route-table td{padding:.43rem .35rem;text-align:right;border-bottom:1px solid var(--bs-border-color-translucent);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.financial-route-table th:first-child{width:15%;text-align:left}.financial-route-table th:nth-child(2),.financial-route-table th:nth-child(3),.financial-route-table th:nth-child(4){width:19%}.financial-route-table th:last-child{width:28%}.financial-route-table thead th{font-size:.66rem;color:var(--bs-secondary-color);font-weight:700;background:var(--bs-tertiary-bg)}.route-cell{display:block;width:100%;border:0;padding:0;background:transparent;color:var(--bs-body-color);font:inherit;text-align:right;font-variant-numeric:tabular-nums}.route-date{text-align:left;font-weight:700}.route-cell:not(:disabled){cursor:pointer}.route-cell:hover{text-decoration:underline;text-underline-offset:2px}.route-balance{font-weight:800;padding:.38rem .25rem;border-radius:.25rem}.route-balance.risk{background:#fce8e8;color:#a1262e}.route-balance.attention{background:#fff3cd;color:#805b00}.route-balance.protected{background:#e5f3e9;color:#24633c}.route-balance.comfortable{background:#ccebd6;color:#155b32}.financial-route-table tfoot th,.financial-route-table tfoot td{font-weight:800;border-top:2px solid var(--bs-border-color);border-bottom:0}.financial-route-table tfoot th{text-align:left}.financial-route-table tfoot td{white-space:normal}.route-filter-tabs{display:flex;gap:.4rem;flex-wrap:wrap;margin:1rem 0}.route-filter-tabs button{border:1px solid var(--bs-border-color);border-radius:2rem;background:transparent;padding:.38rem .7rem;font-size:.78rem}.route-filter-tabs button.active{background:var(--bs-primary);border-color:var(--bs-primary);color:#fff}.route-day-balance{font-size:1.35rem;font-weight:800}.route-movement-list{display:grid;gap:.5rem}.route-movement-item{width:100%;display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.8rem;border:1px solid var(--bs-border-color);border-radius:.65rem;background:var(--bs-body-bg);text-align:left}.route-movement-item small{display:block;color:var(--bs-secondary-color)}.route-movement-item strong{white-space:nowrap;font-variant-numeric:tabular-nums}.route-detail-amount{font-size:1.8rem;font-weight:800;margin:.2rem 0 1.2rem}.route-detail-list{margin:0}.route-detail-list div{display:flex;justify-content:space-between;gap:1rem;padding:.7rem 0;border-bottom:1px solid var(--bs-border-color-translucent)}.route-detail-list dt{color:var(--bs-secondary-color);font-weight:500}.route-detail-list dd{margin:0;text-align:right;font-weight:650}.route-type-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.45rem}.route-type-options label{margin:0}.route-type-options input{position:absolute;opacity:0}.route-type-options span{display:block;text-align:center;border:1px solid var(--bs-border-color);border-radius:.55rem;padding:.65rem .35rem;font-size:.84rem}.route-type-options input:checked+span{border-color:var(--bs-primary);background:var(--bs-primary-bg-subtle);color:var(--bs-primary);font-weight:700}.route-sheet-content{border:0;border-radius:1rem;overflow:hidden}.route-sheet-content .modal-header,.route-sheet-content .modal-footer{border-color:var(--bs-border-color-translucent)}@media(max-width:1199.98px) and (min-width:768px){.financial-route-months{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:767.98px){.financial-route-months.mode-3 .financial-route-month:nth-child(n+2){display:none}.financial-route-toolbar .btn-group{display:none}.financial-route-header{align-items:flex-start}.financial-route-header h1{font-size:1.35rem}.financial-route-toolbar{gap:.5rem;flex-wrap:wrap}.financial-route-period{width:100%;justify-content:space-between}.financial-route-period strong{min-width:0;flex:1}.financial-route-months{grid-template-columns:1fr;gap:1.1rem}.financial-route-table{font-size:clamp(.59rem,2.5vw,.72rem)}.financial-route-table th,.financial-route-table td{padding:.48rem .12rem}.financial-route-table th:first-child{width:17%}.financial-route-table th:nth-child(2),.financial-route-table th:nth-child(3),.financial-route-table th:nth-child(4){width:18%}.financial-route-table th:last-child{width:29%}.financial-route-table thead th{font-size:clamp(.54rem,2.25vw,.65rem)}.route-balance{padding:.35rem .05rem}.route-sheet-content{border-radius:1.1rem 1.1rem 0 0}.modal-dialog{align-items:flex-end;margin:0;min-height:100%}.modal-dialog .modal-content{border-radius:1.1rem 1.1rem 0 0;max-height:88vh;overflow:auto}.route-type-options span{font-size:.75rem;padding:.6rem .15rem}.route-detail-list div{padding:.6rem 0}}
</style>
@endpush

@push('scripts')
<script>
(() => {
 const root=document.getElementById('financialRoute'); if(!root)return;
 const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
 const currency=root.dataset.currency||'EUR';
 const urls={store:root.dataset.storeUrl,update:root.dataset.updateUrl,remove:root.dataset.deleteUrl,reverse:root.dataset.reverseUrl};
 const modal=id=>bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
 const dayModal=modal('routeDayModal'), movementModal=modal('routeMovementModal'), detailModal=modal('routeMovementDetailModal'), confirmModal=modal('routeConfirmModal');
 let activeDate=root.dataset.today, activeFilter='all', activeMovement=null, editId=null, confirmCallback=null, mode={{ $mode }}, month={{ Illuminate\Support\Js::from($month) }};
 const form=document.getElementById('routeMovementForm');
 const fmt=value=>new Intl.NumberFormat('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value)+' '+({EUR:'€',USD:'$',GBP:'£',BRL:'R$'}[currency]||currency);
 const dateLabel=value=>new Intl.DateTimeFormat('pt-PT',{day:'2-digit',month:'long',year:'numeric'}).format(new Date(value+'T12:00:00'));
 const dayData=date=>{const el=document.getElementById('route-day-'+date);return el?JSON.parse(el.textContent):null};
 const setPeriod=()=>{const url=new URL(window.location.href);url.searchParams.set('mes',month);url.searchParams.set('modo',mode);window.location.href=url.toString()};
 const periodStep=()=>window.matchMedia('(max-width: 767.98px)').matches?1:mode;
 const shiftMonth=(value,count)=>{const [y,m]=value.split('-').map(Number);const d=new Date(y,m-1+count,1);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')};
 document.getElementById('routePrevious').addEventListener('click',()=>{month=shiftMonth(month,-periodStep());setPeriod()});
 document.getElementById('routeNext').addEventListener('click',()=>{month=shiftMonth(month,periodStep());setPeriod()});
 document.getElementById('routeToday').addEventListener('click',()=>{month=root.dataset.today.slice(0,7);setPeriod()});
 document.querySelectorAll('[data-route-mode]').forEach(b=>b.addEventListener('click',()=>{mode=Number(b.dataset.routeMode);setPeriod()}));
 document.getElementById('routeNewMovement').addEventListener('click',()=>openForm());
 document.getElementById('routeAddFromDay').addEventListener('click',()=>{dayModal.hide();openForm(activeDate)});
 function openForm(date=activeDate,movement=null){
   editId=movement?.id||null;form.reset();document.getElementById('routeMovementTitle').textContent=editId?'Editar movimento':'Novo movimento';
   document.getElementById('routeDate').value=movement?.date||date;document.getElementById('routeDescription').value=movement?.description||'';
   document.getElementById('routeAmount').value=movement?.amount||'';
   document.getElementById('routeCategory').value=movement?.category_id||'';
   document.getElementById('routeNotes').value=movement?.notes||'';
   document.getElementById('routeAccount').value=movement?.account_id||'';
   const type=movement?.type==='income'?'income':(movement?.expense_behavior==='fixed'?'fixed':'variable');
   form.querySelector('[name="route_type"][value="'+type+'"]').checked=true;
   document.getElementById('routeFormError').textContent='';
   movementModal.show();
 }
 function openDay(date,filter='all'){
   activeDate=date;activeFilter=filter;const data=dayData(date);if(!data)return;
   document.getElementById('routeDayTitle').textContent=dateLabel(date);
   document.getElementById('routeDayBalance').textContent=fmt(data.balance);
   document.querySelectorAll('[data-day-filter]').forEach(b=>b.classList.toggle('active',b.dataset.dayFilter===filter));
   renderDayItems(data);dayModal.show();
 }
 function renderDayItems(data){
   const list=document.getElementById('routeDayMovements');list.innerHTML='';
   const items=data.items.filter(item=>activeFilter==='all'||activeFilter==='balance'||(activeFilter==='income'?item.type==='income':item.type==='expense'&&item.expense_behavior===activeFilter));
   if(!items.length){list.innerHTML='<p class="text-secondary small mb-0 py-3">Não existem movimentos nesta seleção.</p>';return}
   items.forEach(item=>{
     const b=document.createElement('button');b.type='button';b.className='route-movement-item';
     const left=document.createElement('span'),name=document.createElement('strong'),meta=document.createElement('small'),amount=document.createElement('strong');
     name.textContent=item.description;meta.textContent=(item.type==='income'?'Entrada':item.expense_behavior==='fixed'?'Gasto fixo':'Variável')+' · '+item.category;
     left.append(name,meta);amount.textContent=(item.type==='income'?'+':'−')+fmt(item.amount);b.append(left,amount);
     b.addEventListener('click',()=>{dayModal.hide();openMovementDetail(item)});list.append(b);
   });
 }
 document.querySelectorAll('[data-day-filter]').forEach(b=>b.addEventListener('click',()=>{activeFilter=b.dataset.dayFilter;document.querySelectorAll('[data-day-filter]').forEach(x=>x.classList.toggle('active',x===b));renderDayItems(dayData(activeDate))}));
 root.querySelectorAll('[data-date]').forEach(b=>b.addEventListener('click',()=>openDay(b.dataset.date,b.dataset.filter==='income'?'income':b.dataset.filter||'all')));
 function openMovementDetail(item){
   activeMovement=item;document.getElementById('routeMovementDetailTitle').textContent=item.description;
   document.getElementById('routeMovementKind').textContent=item.type==='income'?'Entrada':item.expense_behavior==='fixed'?'Gasto fixo':'Variável';
   document.getElementById('routeMovementAmount').textContent=fmt(item.amount);
   document.getElementById('routeMovementCategory').textContent=item.category;
   document.getElementById('routeMovementDate').textContent=dateLabel(item.date);
   document.getElementById('routeMovementDescription').textContent=item.description;
   document.getElementById('routeDetailError').textContent='';
   const deleteButton=document.getElementById('routeDeleteMovement');
   deleteButton.textContent=item.status==='settled'?'Estornar':'Excluir';
   detailModal.show();
 }
 document.getElementById('routeEditMovement').addEventListener('click',()=>{detailModal.hide();openForm(activeMovement.date,activeMovement)});
 document.getElementById('routeDeleteMovement').addEventListener('click',()=>{
   const item=activeMovement;const settled=item.status==='settled';
   document.getElementById('routeConfirmTitle').textContent=settled?'Confirmar estorno':'Confirmar exclusão';
   document.getElementById('routeConfirmText').textContent=settled?'Este movimento já foi liquidado. Será criado um estorno para preservar o histórico.':'O movimento será eliminado. Esta ação não pode ser desfeita.';
   confirmCallback=async()=>{const endpoint=(settled?urls.reverse:urls.remove)+'/'+item.id+(settled?'/reverse':'');const response=await fetch(endpoint,{method:settled?'POST':'DELETE',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});const json=await response.json();if(!response.ok)throw new Error(json.message||'Não foi possível concluir a operação.');window.location.reload()};
   detailModal.hide();confirmModal.show();
 });
 document.getElementById('routeConfirmAction').addEventListener('click',async()=>{try{if(confirmCallback)await confirmCallback()}catch(e){document.getElementById('routeConfirmText').textContent=e.message} });
 form.addEventListener('submit',async e=>{
   e.preventDefault();const data=new FormData(form);const selected=data.get('route_type');
   const type=selected==='income'?'income':'expense';const behavior=selected==='fixed'?'fixed':'variable';
   const account=document.getElementById('routeAccount').value;
   const payload={type,expense_behavior:behavior,description:data.get('description'),amount:data.get('amount'),movement_date:data.get('movement_date'),category_id:data.get('category_id')||null,notes:data.get('notes')||null};
   if(type==='expense')payload.source_account_id=account;else payload.destination_account_id=account;
   const url=editId?urls.update+'/'+editId:urls.store;const response=await fetch(url,{method:editId?'PUT':'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(payload)});
   const json=await response.json();if(!response.ok){document.getElementById('routeFormError').textContent=Object.values(json.errors||{request:[json.message||'Não foi possível guardar.']}).flat().join(' ');return}
   window.location.reload();
 });
})();
</script>
@endpush
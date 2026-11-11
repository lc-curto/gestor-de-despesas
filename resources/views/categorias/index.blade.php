@extends('layouts.app')

@section('title', 'Categorias')

@section('content')
<header class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
    <div>
        <p class="eyebrow mb-2">Organização financeira</p>
        <h1 class="h2 fw-bold mb-1">Categorias</h1>
        <p class="text-secondary mb-0">Organiza as tuas despesas e receitas.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" data-mode="create">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nova categoria
    </button>
</header>

<section class="row g-3 mb-4" aria-label="Resumo de categorias">
    <div class="col-4"><article class="summary-card h-100"><p class="small text-secondary mb-1">Total</p><p class="summary-value mb-0">{{ $total }}</p></article></div>
    <div class="col-4"><article class="summary-card h-100"><p class="small text-secondary mb-1">Despesas</p><p class="summary-value mb-0">{{ $totalDespesas }}</p></article></div>
    <div class="col-4"><article class="summary-card h-100"><p class="small text-secondary mb-1">Receitas</p><p class="summary-value mb-0">{{ $totalReceitas }}</p></article></div>
</section>

@if ($errors->has('categoria'))
    <div class="alert alert-warning" role="alert">{{ $errors->first('categoria') }}</div>
@endif

<div class="d-flex flex-column flex-md-row gap-2 align-items-md-center justify-content-between mb-3">
    <ul class="nav nav-pills category-tabs" id="categoryTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="expenses-tab" data-bs-toggle="pill" data-bs-target="#expenseCategories" type="button" role="tab" aria-controls="expenseCategories" aria-selected="true">Despesas</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="income-tab" data-bs-toggle="pill" data-bs-target="#incomeCategories" type="button" role="tab" aria-controls="incomeCategories" aria-selected="false">Receitas</button></li>
    </ul>
    <div class="category-search">
        <label for="categorySearch" class="visually-hidden">Pesquisar categorias</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" id="categorySearch" class="form-control" placeholder="Pesquisar categoria..." autocomplete="off">
        </div>
    </div>
</div>

<div class="tab-content">
    @foreach (['despesa' => 'expenseCategories', 'receita' => 'incomeCategories'] as $type => $pane)
        <section class="tab-pane fade {{ $type === 'despesa' ? 'show active' : '' }}" id="{{ $type === 'despesa' ? 'expenseCategories' : 'incomeCategories' }}" role="tabpanel" aria-labelledby="{{ $type === 'despesa' ? 'expenses-tab' : 'income-tab' }}" tabindex="0">
            @php($items = $categorias->where('type', $type))
            @if ($items->isEmpty())
                <div class="card border-0 p-4 text-center category-empty-state">
                    <i class="bi bi-tags fs-2 text-secondary mb-2" aria-hidden="true"></i>
                    <h2 class="h5">Ainda não existem categorias</h2>
                    <p class="text-secondary mb-3">Cria a primeira categoria para organizar os teus movimentos.</p>
                    <div><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" data-mode="create" data-type="{{ $type }}">Criar categoria</button></div>
                </div>
            @else
                <div class="row g-3 category-grid">
                    @foreach ($items as $item)
                        @php($movementCount = $type === 'despesa' ? $item->despesas_count : $item->receitas_count)
                        <div class="col-12 col-sm-6 col-xl-4 category-item" data-category-name="{{ mb_strtolower($item->name) }}">
                            <article class="card h-100 category-card {{ !$item->is_active ? 'category-card-inactive' : '' }}">
                                <div class="card-body d-flex flex-column gap-3">
                                    <div class="d-flex align-items-start gap-3">
                                        <span class="category-color-icon" style="--category-color: {{ $item->color }}" aria-hidden="true"><i data-lucide="{{ $item->icon }}"></i></span>
                                        <div class="min-w-0 flex-grow-1">
                                            <h2 class="h6 fw-bold mb-1 text-break">{{ $item->name }}</h2>
                                            <p class="small text-secondary mb-0">{{ $movementCount }} {{ $movementCount === 1 ? 'movimento' : 'movimentos' }}</p>
                                        </div>
                                        <span class="badge {{ $item->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $item->is_active ? 'Ativa' : 'Inativa' }}</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-auto pt-2 border-top">
                                        <span class="small text-secondary">{{ $type === 'despesa' ? 'Despesa' : 'Receita' }}</span>
                                        <div class="d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar {{ $item->name }}" aria-label="Editar {{ $item->name }}"
                                                data-bs-toggle="modal" data-bs-target="#categoryModal" data-mode="edit"
                                                data-id="{{ $item->id }}" data-name="{{ $item->name }}" data-type="{{ $item->type }}"
                                                data-icon="{{ $item->icon }}" data-color="{{ $item->color }}"
                                                data-update-url="{{ route('categorias.update', $item->id) }}">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                            <form method="POST" action="{{ route('categorias.toggle', $item->id) }}">
                                                @csrf @method('PATCH')
                                                <button class="btn btn-sm btn-outline-secondary" title="{{ $item->is_active ? 'Desativar' : 'Ativar' }} {{ $item->name }}" aria-label="{{ $item->is_active ? 'Desativar' : 'Ativar' }} {{ $item->name }}">
                                                    <i class="bi {{ $item->is_active ? 'bi-pause-circle' : 'bi-play-circle' }}" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            @if ($movementCount === 0)
                                                <form method="POST" action="{{ route('categorias.destroy', $item->id) }}" onsubmit="return confirm('Eliminar definitivamente a categoria {{ addslashes($item->name) }}?')">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar {{ $item->name }}" aria-label="Eliminar {{ $item->name }}"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    @endforeach
                </div>
                <p class="category-no-results text-secondary text-center py-4 d-none">Nenhuma categoria corresponde à pesquisa.</p>
            @endif
        </section>
    @endforeach
</div>

<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content category-modal-content">
            <form method="POST" action="{{ route('categorias.store') }}" id="categoryForm">
                @csrf
                <input type="hidden" name="_method" id="categoryMethod" value="POST">
                <input type="hidden" name="category_id" id="categoryId" value="">
                <div class="modal-header">
                    <div><h2 class="modal-title h5 fw-bold" id="categoryModalTitle">Nova categoria</h2><p class="small text-secondary mb-0">Define como queres identificar esta categoria.</p></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="categoryName" class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="categoryName" name="name" maxlength="100" required placeholder="Ex.: Alimentação">
                        @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="categoryType" class="form-label">Tipo <span class="text-danger">*</span></label>
                        <select class="form-select" id="categoryType" name="type" required>
                            <option value="despesa">Despesa</option><option value="receita">Receita</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="categoryIconSearch" class="form-label">Ícone</label>
                        <input type="hidden" id="categoryIcon" name="icon" value="tag" required>
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <span class="category-icon-preview" id="categoryIconPreview" style="--category-color:#635BFF"><i data-lucide="tag"></i></span>
                            <input type="search" class="form-control" id="categoryIconSearch" placeholder="Pesquisar ícone..." autocomplete="off">
                        </div>
                        <div class="category-icon-picker" id="categoryIconPicker" role="group" aria-label="Escolher ícone da categoria"></div>
                        <small class="text-secondary">Escolhe um ícone para identificar visualmente esta categoria.</small>
                    </div>
                    <fieldset>
                        <legend class="form-label">Cor</legend>
                        <div class="category-color-options">
                            @foreach (['#635BFF','#2563EB','#0D9488','#16A34A','#D97706','#DC2626','#9333EA','#64748B'] as $color)
                                <label class="category-color-choice" style="--choice-color: {{ $color }}">
                                    <input type="radio" name="color" value="{{ $color }}" required {{ $loop->first ? 'checked' : '' }}>
                                    <span aria-label="Cor {{ $color }}"></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="categorySubmitButton">Guardar categoria</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const modal = document.getElementById('categoryModal');
    const form = document.getElementById('categoryForm');
    if (!modal || !form) return;
    const baseAction = @json(route('categorias.store'));
    const updateBase = @json(url('/categorias'));
    const categoryId = document.getElementById('categoryId');
    const title = document.getElementById('categoryModalTitle');
    const method = document.getElementById('categoryMethod');
    const submit = document.getElementById('categorySubmitButton');
    const iconInput = document.getElementById('categoryIcon');
    const iconSearch = document.getElementById('categoryIconSearch');
    const iconGrid = document.getElementById('categoryIconPicker');
    const iconPreview = document.getElementById('categoryIconPreview');
    const colorInputs = [...form.querySelectorAll('input[name="color"]')];
    const iconCatalog = ['tag','tags','house','building-2','bed-double','sofa','lamp','key-round','utensils','chef-hat','coffee','pizza','sandwich','apple','milk','shopping-basket','car-front','bus-front','train-front','plane','bike','fuel','map-pin','ticket','heart-pulse','pill','stethoscope','dumbbell','activity','shield-check','paw-print','baby','gamepad-2','clapperboard','music','headphones','book-open','graduation-cap','school','laptop','smartphone','monitor','wifi','briefcase-business','factory','store','shopping-bag','shirt','wallet','credit-card','banknote','coins','piggy-bank','chart-no-axes-combined','trending-up','receipt','badge-euro','hand-coins','handshake','gift','calendar-days','plane-takeoff','church','leaf','sprout','droplets','zap','sun','moon','cloud','umbrella','wrench','hammer','package','truck','recycle','ellipsis','circle-help','landmark','globe'];
    const iconKeywords = {'house':'casa lar moradia','building-2':'edificio apartamento','bed-double':'quarto hotel','sofa':'mobilia','utensils':'alimentacao comida restaurante','chef-hat':'cozinha','coffee':'cafe','pizza':'comida','apple':'fruta','shopping-basket':'supermercado','car-front':'carro transporte','bus-front':'autocarro','train-front':'comboio','plane':'aviao viagem','bike':'bicicleta','fuel':'combustivel gasolina','heart-pulse':'saude','pill':'medicamento farmacia','stethoscope':'medico','dumbbell':'ginasio desporto','paw-print':'animal estimacao','baby':'bebe crianca','gamepad-2':'jogos lazer','music':'musica','headphones':'audio','book-open':'livro leitura','graduation-cap':'educacao estudo','laptop':'computador tecnologia','smartphone':'telemovel telefone','briefcase-business':'trabalho emprego','store':'vendas negocio','shopping-bag':'compras loja','shirt':'roupa vestuario','wallet':'carteira dinheiro','credit-card':'cartao banco','banknote':'dinheiro notas','coins':'moedas poupanca','piggy-bank':'poupanca','trending-up':'investimento crescimento','receipt':'fatura recibo','hand-coins':'pagamento','gift':'presente oferta','calendar-days':'calendario','leaf':'natureza','sprout':'plantas jardim','droplets':'agua','wrench':'reparacao','hammer':'construcao','package':'encomenda','truck':'entrega','landmark':'banco instituicao'};
    function renderIconCatalog(filter='') {
        const query=filter.trim().toLocaleLowerCase('pt-PT');
        const visible=iconCatalog.filter(name=>name.includes(query)||(iconKeywords[name]||'').includes(query));
        iconGrid.innerHTML=visible.map(name=>`<button type="button" class="category-icon-option ${name===iconInput.value?'is-selected':''}" data-icon-name="${name}" aria-label="Selecionar ícone ${name}" title="${name}"><i data-lucide="${name}" aria-hidden="true"></i></button>`).join('') || '<p class="small text-secondary mb-0">Nenhum ícone encontrado.</p>';
        window.lucide?.createIcons();
    }
    function selectIcon(name) {
        iconInput.value=name;
        iconPreview.innerHTML=`<i data-lucide="${name}" aria-hidden="true"></i>`;
        iconPreview.style.setProperty('--category-color',form.querySelector('input[name="color"]:checked')?.value||'#635BFF');
        iconGrid.querySelectorAll('[data-icon-name]').forEach(button=>button.classList.toggle('is-selected',button.dataset.iconName===name));
        window.lucide?.createIcons();
    }
    iconGrid.addEventListener('click',event=>{const button=event.target.closest('[data-icon-name]');if(button)selectIcon(button.dataset.iconName);});
    iconSearch.addEventListener('input',()=>renderIconCatalog(iconSearch.value));
    colorInputs.forEach(input=>input.addEventListener('change',()=>iconPreview.style.setProperty('--category-color',input.value)));
    modal.addEventListener('show.bs.modal',event=>{
        const trigger=event.relatedTarget,edit=trigger?.dataset.mode==='edit';
        form.reset();form.action=edit?trigger.dataset.updateUrl:baseAction;method.value=edit?'PUT':'POST';
        categoryId.value=edit?trigger.dataset.id:'';title.textContent=edit?'Editar categoria':'Nova categoria';
        submit.textContent=edit?'Guardar alterações':'Guardar categoria';
        document.getElementById('categoryName').value=edit?trigger.dataset.name:'';
        document.getElementById('categoryType').value=edit?trigger.dataset.type:(trigger?.dataset.type||'despesa');
        const color=edit?trigger.dataset.color:'#635BFF';
        const selected=colorInputs.find(input=>input.value.toUpperCase()===color.toUpperCase());if(selected)selected.checked=true;
        iconSearch.value='';selectIcon(edit?trigger.dataset.icon:'tag');renderIconCatalog();
    });
    const search=document.getElementById('categorySearch');
    document.querySelectorAll('[data-bs-toggle="pill"]').forEach(tab=>tab.addEventListener('shown.bs.tab',()=>search?.dispatchEvent(new Event('input'))));
    const oldCategoryId=@json(old('category_id'));
    @if ($errors->hasAny(['name','type','icon','color']))
    document.addEventListener('DOMContentLoaded',()=>{
        const editId=oldCategoryId;categoryId.value=editId||'';method.value=editId?'PUT':'POST';
        form.action=editId?updateBase+'/'+encodeURIComponent(editId):baseAction;
        title.textContent=editId?'Editar categoria':'Nova categoria';submit.textContent=editId?'Guardar alterações':'Guardar categoria';
        document.getElementById('categoryName').value=@json(old('name',''));
        document.getElementById('categoryType').value=@json(old('type','despesa'));
        const oldColor=@json(old('color','#635BFF'));
        const selected=colorInputs.find(input=>input.value.toUpperCase()===oldColor.toUpperCase());if(selected)selected.checked=true;
        iconInput.value=@json(old('icon','tag'));renderIconCatalog();selectIcon(iconInput.value);
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    @endif
    search?.addEventListener('input',()=>{
        const query=search.value.trim().toLocaleLowerCase('pt-PT');
        document.querySelectorAll('.tab-pane.active .category-item').forEach(item=>item.classList.toggle('d-none',!item.dataset.categoryName.includes(query)));
        document.querySelectorAll('.tab-pane.active .category-no-results').forEach(empty=>{
            const visible=document.querySelectorAll('.tab-pane.active .category-item:not(.d-none)').length;
            empty.classList.toggle('d-none',visible>0);
        });
    });
    window.lucide?.createIcons();
})();
</script>
@endpush

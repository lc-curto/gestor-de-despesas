@props([
    'prefix',
    'relatedLabel' => 'Relacionado',
    'destructiveLabel' => 'Excluir',
    'showActions' => true,
    'showDescription' => false,
    'showNotes' => false,
])

<div id="{{ $prefix }}DetailsContent">
    <div class="mb-3">
        <h3 class="h6 fw-semibold mb-1" id="{{ $prefix }}DetailsName"></h3>
        <p class="small text-secondary mb-0" id="{{ $prefix }}DetailsCategory"></p>
    </div>
    <strong class="fs-3 d-block mb-1" id="{{ $prefix }}DetailsAmount"></strong>
    <hr>
    <h3 class="small fw-semibold mb-3">Informações</h3>
    <div class="d-flex justify-content-between gap-3 mb-2">
        <span class="small text-secondary">Data</span>
        <span class="small fw-medium" id="{{ $prefix }}DetailsDate"></span>
    </div>
    <div class="d-flex justify-content-between gap-3 mb-2">
        <span class="small text-secondary">Tipo</span>
        <span class="small fw-medium" id="{{ $prefix }}DetailsKind"></span>
    </div>
    <div class="d-flex justify-content-between gap-3" id="{{ $prefix }}DetailsRelatedRow" hidden>
        <span class="small text-secondary">{{ $relatedLabel }}</span>
        <span class="small fw-medium text-end" id="{{ $prefix }}DetailsRelated"></span>
    </div>
    @if ($showDescription)
        <div class="d-flex justify-content-between gap-3 mt-2">
            <span class="small text-secondary">Descrição</span>
            <span class="small fw-medium text-end" id="{{ $prefix }}DetailsDescription"></span>
        </div>
    @endif
    @if ($showNotes)
        <div class="d-flex justify-content-between gap-3 mt-2">
            <span class="small text-secondary">Observação</span>
            <span class="small fw-medium text-end" id="{{ $prefix }}DetailsNotes"></span>
        </div>
    @endif
    @if ($showActions)
        <hr>
        <h3 class="small fw-semibold mb-3">Ações</h3>
        <div class="list-group list-group-flush border rounded-3 overflow-hidden">
            <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" id="{{ $prefix }}DetailsEdit">
                <i data-lucide="pencil" aria-hidden="true"></i>
                <span>Editar</span>
                <i data-lucide="chevron-right" class="ms-auto text-secondary" aria-hidden="true"></i>
            </button>
            <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 text-danger" id="{{ $prefix }}DetailsDelete">
                <i data-lucide="x-circle" aria-hidden="true"></i>
                <span>{{ $destructiveLabel }}</span>
                <i data-lucide="chevron-right" class="ms-auto text-secondary" aria-hidden="true"></i>
            </button>
        </div>
    @endif
    <p class="small text-secondary mt-3 mb-0" id="{{ $prefix }}DetailsActionNote" hidden></p>
</div>

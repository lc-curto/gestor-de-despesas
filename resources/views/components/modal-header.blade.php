@props([
    'title',
    'titleId',
    'description' => null,
    'showClose' => true,
])

<div {{ $attributes->class(['modal-header', 'border-0', 'pb-0']) }}>
    <div>
        <h2 class="modal-title h5 fw-bold" id="{{ $titleId }}">{{ $title }}</h2>
        @if ($description)
            <p class="small text-secondary mb-0">{{ $description }}</p>
        @endif
        {{ $slot }}
    </div>

    @if ($showClose)
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
    @endif
</div>

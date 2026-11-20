@props([
    'title',
])

<header {{ $attributes->class(['d-flex', 'flex-nowrap', 'align-items-center', 'justify-content-between', 'gap-2', 'mb-4']) }}>
    <div class="min-w-0">
        <h1 class="h2 fw-bold mb-0">{{ $title }}</h1>
        @if (isset($subtitle))
            <div class="small text-secondary">{{ $subtitle }}</div>
        @endif
    </div>
    <div class="d-flex flex-shrink-0 align-items-center gap-2">
        {{ $actions ?? '' }}
    </div>
</header>

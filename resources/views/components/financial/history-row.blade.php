@props([
    'icon',
    'title',
    'subtitle',
    'amount',
    'amountClass' => 'text-body',
    'iconClass' => 'bg-body-secondary text-secondary',
    'status' => null,
    'statusClass' => 'text-bg-secondary',
    'counterpart' => null,
])

<div class="financial-history-row d-flex align-items-center gap-3 w-100 py-1">
    <span class="financial-history-row-icon rounded-3 flex-shrink-0 d-inline-flex align-items-center justify-content-center {{ $iconClass }}" aria-hidden="true">
        <i data-lucide="{{ $icon }}"></i>
    </span>
    <span class="min-w-0 flex-grow-1">
        <span class="d-block small fw-semibold text-body text-truncate" title="{{ $title }}">{{ $title }}</span>
        <span class="d-block small text-secondary text-truncate">{{ $subtitle }}</span>
        @if ($counterpart)
            <span class="d-block small text-secondary text-truncate">{{ $counterpart }}</span>
        @endif
        @if ($status)
            <span class="badge rounded-pill {{ $statusClass }} mt-1">{{ $status }}</span>
        @endif
    </span>
    <span class="d-flex align-items-center gap-1 flex-shrink-0">
        <strong class="small text-nowrap {{ $amountClass }}">{{ $amount }}</strong>
        <i data-lucide="chevron-right" class="text-secondary financial-history-row-chevron" aria-hidden="true"></i>
    </span>
</div>

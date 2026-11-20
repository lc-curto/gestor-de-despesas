@props([
    'label',
    'buttonClass' => '',
    'wrapperClass' => '',
])

<div {{ $attributes->class(['dropdown', 'flex-shrink-0', $wrapperClass]) }}>
    <button
        class="btn btn-sm {{ $buttonClass }}"
        type="button"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="{{ $label }}"
    >
        <i data-lucide="ellipsis" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        {{ $slot }}
    </ul>
</div>

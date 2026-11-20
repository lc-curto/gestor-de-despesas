@props([
    'id',
    'size' => null,
    'centered' => true,
    'scrollable' => false,
])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true" {{ $attributes }}>
    <div @class([
        'modal-dialog',
        'modal-dialog-centered' => $centered,
        'modal-dialog-scrollable' => $scrollable,
        'modal-' . $size => filled($size),
    ])>
        <div class="modal-content border-0 shadow rounded-4">
            {{ $slot }}
        </div>
    </div>
</div>

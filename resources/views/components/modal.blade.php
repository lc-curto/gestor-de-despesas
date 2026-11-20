@props([
    'id',
    'size' => null,
    'centered' => true,
    'scrollable' => false,
    'fullscreenOnMobile' => false,
    'contentClass' => null,
    'rounded' => true,
])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true" {{ $attributes }}>
    <div @class([
        'modal-dialog',
        'modal-dialog-centered' => $centered,
        'modal-dialog-scrollable' => $scrollable,
        'modal-' . $size => filled($size),
        'modal-fullscreen-sm-down' => $fullscreenOnMobile,
    ])>
        <div @class(['modal-content border-0 shadow',
            'rounded-4' => $rounded, $contentClass])>
            {{ $slot }}
        </div>
    </div>
</div>

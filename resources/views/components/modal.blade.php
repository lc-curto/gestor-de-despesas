{{-- Estrutura base reutilizável para modais Bootstrap do Meu Bolso. --}}
@props([
    'id',
    'dialogClass' => '',
])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true" {{ $attributes }}>
    <div class="modal-dialog modal-dialog-centered {{ $dialogClass }}">
        <div class="modal-content border-0 shadow rounded-4">
            {{ $slot }}
        </div>
    </div>
</div>

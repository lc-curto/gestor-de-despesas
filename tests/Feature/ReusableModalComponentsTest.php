<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReusableModalComponentsTest extends TestCase
{
    public function test_shared_modal_components_render_a_consistent_bootstrap_structure(): void
    {
        $this->blade(<<<'BLADE'
            <x-modal id="testModal" aria-labelledby="testModalTitle" size="lg" :scrollable="true">
                <x-modal-header title="Editar conta" title-id="testModalTitle" description="Atualiza os dados." />
                <x-modal-body>
                    <label for="accountName">Nome da conta</label>
                    <input id="accountName" name="name">
                </x-modal-body>
                <x-modal-footer>
                    <button type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit">Guardar</button>
                </x-modal-footer>
            </x-modal>
        BLADE)
            ->assertSee('id="testModal"', false)
            ->assertSee('aria-labelledby="testModalTitle"', false)
            ->assertSee('modal-dialog-centered', false)
            ->assertSee('modal-dialog-scrollable', false)
            ->assertSee('modal-lg', false)
            ->assertSee('Editar conta')
            ->assertSee('Atualiza os dados.')
            ->assertSee('class="modal-body"', false)
            ->assertSee('class="modal-footer', false)
            ->assertSee('data-bs-dismiss="modal"', false);
    }
}

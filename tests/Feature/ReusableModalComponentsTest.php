<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReusableModalComponentsTest extends TestCase
{
    public function test_shared_modal_components_render_a_consistent_bootstrap_structure(): void
    {
        $this->blade(<<<'BLADE'
            <x-modal id="testModal" aria-labelledby="testModalTitle" size="lg" :scrollable="true" :fullscreen-on-mobile="true">
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
            ->assertSee('rounded-4', false)
            ->assertSee('modal-fullscreen-sm-down', false)
            ->assertSee('Editar conta')
            ->assertSee('Atualiza os dados.')
            ->assertSee('class="modal-body"', false)
            ->assertSee('class="modal-footer', false)
            ->assertSee('data-bs-dismiss="modal"', false);
    }
    public function test_financial_movement_details_panel_is_reusable_for_accounts_and_invoices(): void
    {
        $this->blade(<<<'BLADE'
            <x-financial.movement-details-panel prefix="accountMovement" related-label="Conta relacionada" destructive-label="Cancelar" />
            <x-financial.movement-details-panel prefix="invoiceMovement" related-label="Fatura" destructive-label="Excluir" />
        BLADE)
            ->assertSee('id="accountMovementDetailsName"', false)
            ->assertSee('id="accountMovementDetailsRelatedRow"', false)
            ->assertSee('id="accountMovementDetailsEdit"', false)
            ->assertSee('Cancelar')
            ->assertSee('id="invoiceMovementDetailsName"', false)
            ->assertSee('id="invoiceMovementDetailsRelatedRow"', false)
            ->assertSee('id="invoiceMovementDetailsEdit"', false)
            ->assertSee('Excluir')
            ->assertSee('Informações')
            ->assertSee('Ações');
    }

}

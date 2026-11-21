<div class="modal fade" id="movementEditModal" tabindex="-1" aria-labelledby="movementEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered movement-entry-dialog">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="movementEditForm">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold" id="movementEditTitle">Editar movimento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div id="movementEditError" class="alert alert-danger d-none" role="alert"></div>
                    <div class="mb-3">
                        <label for="movementEditDescription" class="form-label fw-semibold">Descrição</label>
                        <input id="movementEditDescription" type="text" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label for="movementEditAmount" class="form-label fw-semibold">Valor</label>
                        <input id="movementEditAmount" type="text" inputmode="decimal" class="form-control" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label for="movementEditDate" class="form-label fw-semibold">Data</label>
                            <input id="movementEditDate" type="date" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label for="movementEditCategory" class="form-label fw-semibold">Categoria</label>
                            <select id="movementEditCategory" class="form-select">
                                <option value="">Seleciona uma categoria</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label for="movementEditNotes" class="form-label fw-semibold">Observação (opcional)</label>
                        <textarea id="movementEditNotes" class="form-control" rows="2" maxlength="2000"></textarea>
                    </div>
                    <input type="hidden" id="movementEditExpenseBehavior" value="variable">
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary flex-fill" id="movementEditSubmit">Guardar alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    // Regra única de elegibilidade para o modal partilhado de edição.
    window.meuBolsoMovementEditRules = window.meuBolsoMovementEditRules || {};
    window.meuBolsoMovementEditRules.canEdit = function (movement) {
        if (!movement || !['income', 'expense', 'receita', 'despesa'].includes(movement.type)) return false;
        if (movement.status !== 'pending' || !movement.financialMovementId) return false;
        if (movement.invoiceId || movement.isInvoicePayment) return false;
        if (movement.installmentId && movement.debtId) return false;
        return !/^(debt|invoice|overdue-debt|overdue-invoice|settlement)-/.test(String(movement.financialMovementId));
    };

    // Encadeia modais sem depender de um único estado de transição do Bootstrap.
    // Se o modal estiver a abrir, espera por shown; se já estiver a fechar, espera por hidden.
    window.meuBolsoMovementEditRules.hideThen = function (element, instance, callback) {
        if (!element || !instance || typeof callback !== 'function') return;
        let completed = false;
        const finish = () => {
            if (completed) return;
            completed = true;
            callback();
        };
        const hideWhenReady = () => {
            if (instance._isTransitioning) {
                const transitionEvent = element.classList.contains('show') ? 'shown.bs.modal' : 'hidden.bs.modal';
                element.addEventListener(transitionEvent, () => {
                    if (element.classList.contains('show')) hideWhenReady();
                    else finish();
                }, { once: true });
                return;
            }
            if (!element.classList.contains('show')) {
                finish();
                return;
            }
            element.addEventListener('hidden.bs.modal', finish, { once: true });
            instance.hide();
        };
        hideWhenReady();
    };
</script>

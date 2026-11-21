import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const script = readFileSync(new URL('../../public/js/dashboard.js', import.meta.url), 'utf8');
const markup = `<!doctype html><meta name="csrf-token" content="test-token">
<div id="movementSaveFeedback" class="d-none"><span id="movementSaveFeedbackMessage"></span><a id="movementSaveFeedbackLink" class="d-none" href="/compromissos">Ver parcelamentos</a></div>
<div id="movementEntryModal"><form id="dashboardMovementForm" action="/despesas">
 <div id="movementFormError" class="d-none" tabindex="-1"></div><input id="movementMethod" name="_method" value="POST">
 <input id="movementDescription" name="description" required><input id="movementAmount" name="amount" type="text" inputmode="decimal" required>
 <input id="movementDate" name="expense_date" type="date" required>
  <select id="movementCategory" name="category_id" required><option value="">Seleciona</option><option value="casa-id">Casa</option></select>
 <input type="hidden" name="payment_condition" id="movementPaymentCondition" value="cash">
 <input type="hidden" name="payment_method" id="movementPlannedPaymentMethod" value="">
 <input type="hidden" name="expense_behavior" id="movementExpenseBehavior" value="variable">
 <input type="hidden" name="due_date" id="dashboardDueDate" value="">
 <input type="radio" name="paid_now" value="0" checked>
 <input type="radio" name="paid_now" value="1">
 <div id="movementSettlementFields" hidden>
  <select id="movementAccount" name="account_id"><option value="">Seleciona</option><option value="account-id" data-account-type="bank">Conta</option></select>
  <div id="movementPaymentMethodField"><select id="dashboardActualPaymentMethod" name="actual_payment_method"><option value="">Selecionar</option><option value="transfer" data-account-type="bank">Transferência</option><option value="credit" data-account-type="credit_card">Crédito</option></select></div>
 </div>
 <input type="checkbox" id="dashboardDueDateEnabled">
 <div id="dashboardDueDateField" hidden><input id="dashboardDueDateInput" type="date" value="2026-12-17"></div>
 <input type="checkbox" id="dashboardRecurrenceEnabled" name="recurrence_enabled" value="1">
 <div id="dashboardRecurrenceFields" hidden><input id="dashboardRecurrenceInterval" name="recurrence_interval" value="1"><select id="dashboardRecurrenceFrequency" name="recurrence_frequency"><option value="monthly">Mensal</option></select><input id="dashboardRecurrenceEnds" name="recurrence_ends_at"></div>
 <input type="checkbox" id="dashboardInstallmentsEnabled">
 <div id="dashboardInstallmentFields" hidden><input id="dashboardInstallments" name="installments_count" type="number" value="3"><select id="dashboardInstallmentFrequency" name="installment_frequency"><option value="monthly">Mensal</option></select><div id="dashboardInstallmentScheduleList"></div></div>
 <button type="submit" id="saveMovementButton">Guardar despesa</button><button type="button" id="cancelMovementEdit">Cancelar</button>
</form></div>
<div id="movementDetailsModal">
 <h2 id="movementDetailsTitle"></h2><span id="movementDetailsName"></span><span id="movementDetailsType"></span><span id="movementDetailsStatus"></span>
 <span id="movementDetailsAmount"></span><span id="movementDetailsPaid"></span><span id="movementDetailsPending"></span>
 <div id="movementDetailsProgressWrap"><div id="movementDetailsProgress"></div></div><span id="movementDetailsProgressLabel"></span>
 <div id="movementDetailsPaidLine"></div><div id="movementDetailsPendingLine"></div><div id="movementDetailsPaymentHeading" hidden></div>
 <span id="movementDetailsCategory"></span><span id="movementDetailsDate"></span><span id="movementDetailsKind"></span>
 <button id="movementDetailsSettle"></button><button id="movementDetailsEdit"></button><button id="movementDetailsDelete"></button>
</div>
<div id="movementEditModal"><form id="movementEditForm">
 <h2 id="movementEditTitle"></h2><div id="movementEditError" class="d-none"></div>
 <input id="movementEditDescription" required><input id="movementEditAmount" required>
 <input id="movementEditDate" required><select id="movementEditCategory"><option value="">Seleciona</option></select>
 <input id="movementEditExpenseBehavior"><button id="movementEditSubmit" type="submit">Guardar alterações</button>
</form></div>
<div id="settleMovementModal"><form id="settleMovementForm">
 <h2 id="settleMovementTitle"></h2><span id="settleMovementDescription"></span><div id="settleMovementError" class="d-none" tabindex="-1"></div>
 <input id="settleMovementAmount" name="amount" type="text" inputmode="decimal" required>
 <input id="settleMovementDate" name="movement_date" type="date" required>
 <label id="settleMovementAccountLabel"></label><select id="settleMovementAccount"><option value="account-id">Conta</option></select>
 <button id="settleMovementSubmit" type="submit"></button>
</form></div>
<div id="deleteMovementModal"><form id="deleteMovementForm">
 <h2 id="deleteMovementTitle"></h2><div id="deleteMovementDescription"></div><div id="deleteMovementNote" hidden></div>
 <div id="deleteSingleConfirmation"></div><div id="deleteRecurringOptions" hidden>
  <label><input id="deleteScopeOnly" type="radio"></label><label><input id="deleteScopeFuture" type="radio"></label>
 </div><button id="deleteMovementSubmit" type="submit"></button>
</form></div>
<button id="expenseTypeButton"></button><button id="incomeTypeButton"></button><h2 id="recordTitle"></h2>
<input type="month" id="periodMonth" value="2026-11"><div id="expensesList"></div>
<div id="totalAmount" data-total="0"></div><div id="totalIncome" data-total="0"></div>`;

function jsonResponse(status, body) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}

function setup(fetch, initialMovements = []) {
    const dom = new JSDOM(markup, { url: 'https://example.test/dashboard/movimentos', runScripts: 'outside-only' });
    const { window } = dom;
    window.matchMedia = () => ({ matches: false });
    window.fetch = fetch;
    window.alert = (message) => { throw new Error(`Unexpected alert: ${message}`); };
    window.meuBolsoDashboard = {
        type: 'todos', category: 'all', currency: 'EUR', initialMovements,
        expenseCategories: { 'casa-id': 'Casa' }, incomeCategories: {},
        commitmentStore: '/api/financial/commitments', expenseStore: '/despesas', incomeStore: '/receitas', movementsUrl: '/movimentos/dados',
        expenseUpdateBase: '/despesas', financialMovementBase: '/api/financial/movements',
    };
    const instances = new WeakMap();
    window.bootstrap = { Modal: { getOrCreateInstance(element) {
        if (!instances.has(element)) {
            instances.set(element, {
                open: false,
                show() { this.open = true; element.dispatchEvent(new window.Event('shown.bs.modal')); },
                hide() {
                    const event = new window.Event('hide.bs.modal', { cancelable: true });
                    if (!element.dispatchEvent(event)) return;
                    this.open = false;
                    element.dispatchEvent(new window.Event('hidden.bs.modal'));
                },
            });
        }
        return instances.get(element);
    } } };
    window.HTMLElement.prototype.scrollIntoView = () => {};
    window.eval(script);
    const element = (id) => window.document.getElementById(id);
    const form = element('dashboardMovementForm');
    const modal = window.bootstrap.Modal.getOrCreateInstance(element('movementEntryModal'));
    modal.show();
    element('movementDescription').value = 'QA PósDeploy Parcelamento 3x 20261117';
    element('movementAmount').value = '90';
    element('movementDate').value = '2026-11-17';
    element('movementCategory').value = 'casa-id';
    element('dashboardInstallmentsEnabled').checked = true;
    element('dashboardDueDateEnabled').checked = true;
    element('dashboardDueDateInput').value = '2026-12-17';
    element('dashboardInstallments').value = '3';
    element('dashboardInstallmentsEnabled').dispatchEvent(new window.Event('change'));
    element('dashboardDueDateEnabled').dispatchEvent(new window.Event('change'));
    const submit = () => form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    return { dom, window, element, form, modal, submit };
}

async function flush() {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
}

test('422 mostra o erro específico, preserva os dados, bloqueia fecho e envio duplicado enquanto pendente', async () => {
    let resolveFetch;
    let calls = 0;
    const ui = setup(() => { calls++; return new Promise((resolve) => { resolveFetch = resolve; }); });
    ui.submit();
    ui.submit();
    ui.modal.hide();
    assert.equal(calls, 1);
    assert.equal(ui.modal.open, true);
    resolveFetch(jsonResponse(422, { message: 'The given data was invalid.', errors: { due_date: ['A data de vencimento deve ser posterior à data inicial.'] } }));
    await flush();
    assert.equal(ui.modal.open, true);
    assert.equal(ui.element('movementDescription').value, 'QA PósDeploy Parcelamento 3x 20261117');
    assert.equal(ui.element('dashboardDueDate').value, '2026-12-17');
    assert.match(ui.element('movementFormError').textContent, /data de vencimento/);
    assert.equal(ui.element('movementFormError').classList.contains('d-none'), false);
    assert.equal(ui.element('saveMovementButton').disabled, false);
    ui.dom.window.close();
});

test('sucesso confirma o primeiro vencimento sem mudar silenciosamente o mês ou aplicar novo POST', async () => {
    const calls = [];
    const ui = setup(async (url, options = {}) => {
        calls.push({ url, options });
        if (options.method === 'POST') return jsonResponse(201, { data: { id: 'debt-id' } });
        return jsonResponse(200, { data: [], total_despesas_filtradas: 0, total_receitas_filtradas: 0 });
    });
    ui.submit();
    await flush();
    assert.equal(ui.modal.open, false);
    assert.equal(ui.element('periodMonth').value, '2026-11');
    assert.match(ui.element('movementSaveFeedbackMessage').textContent, /3 prestações.*17\/12\/2026.*mês do vencimento/);
    assert.equal(ui.element('movementSaveFeedbackLink').classList.contains('d-none'), false);
    assert.equal(calls.filter(({ options }) => options.method === 'POST').length, 1);
    const payload = JSON.parse(calls[0].options.body);
    assert.equal(payload.name, 'QA PósDeploy Parcelamento 3x 20261117');
    assert.equal(payload.payment_method, null);
    assert.equal(payload.category_id, 'casa-id');
    assert.equal(payload.due_date, '2026-12-17');
    ui.dom.window.close();
});

test('falha ao atualizar lista depois de POST confirmado não é apresentada como falha do registo', async () => {
    let posts = 0;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'POST') { posts++; return jsonResponse(201, { data: { id: 'debt-id' } }); }
        throw new TypeError('falha de rede');
    });
    ui.submit();
    await flush();
    assert.equal(posts, 1);
    assert.equal(ui.modal.open, false);
    assert.match(ui.element('movementSaveFeedbackMessage').textContent, /Parcelamento criado.*Não foi possível atualizar.*Não guardes novamente/);
    assert.equal(ui.element('movementSaveFeedback').classList.contains('alert-warning'), true);
    ui.dom.window.close();
});

test('resposta ambígua ou falha de rede mantém modal aberto e alerta para risco de duplicação', async (t) => {
    for (const [name, fetch] of [
        ['falha de rede', async () => { throw new TypeError('offline'); }],
        ['erro 500', async () => jsonResponse(500, { message: 'Server Error' })],
        ['sucesso sem identificador', async () => jsonResponse(201, {})],
    ]) {
        await t.test(name, async () => {
            const ui = setup(fetch);
            ui.submit();
            await flush();
            assert.equal(ui.modal.open, true);
            assert.match(ui.element('movementFormError').textContent, /Verifica Parcelamentos antes de tentar novamente/);
            assert.equal(ui.element('movementAmount').value, '90');
            ui.dom.window.close();
        });
    }
});

test('rejeição de parcelamento por cartão orienta para Cartões e mantém modal aberto', async () => {
    const ui = setup(async () => jsonResponse(422, { errors: { payment_method: ['Regista a compra em Cartões.'] } }));
    ui.element('movementPlannedPaymentMethod').value = 'credit';
    ui.submit();
    await flush();
    assert.equal(ui.modal.open, true);
    assert.match(ui.element('movementFormError').textContent, /Regista a compra em Cartões/);
    ui.dom.window.close();
});

test('método efetivo antigo é limpo quando a despesa volta a Pendente', async () => {
    let sent;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'POST') { sent = options.body; return jsonResponse(200, { ok: true }); }
        return jsonResponse(200, { data: [] });
    });
    ui.element('dashboardInstallmentsEnabled').checked = false;
    ui.element('dashboardDueDateEnabled').checked = true;
    ui.element('dashboardDueDateEnabled').dispatchEvent(new ui.window.Event('change'));
    ui.form.querySelector('[name="paid_now"][value="1"]').checked = true;
    ui.form.querySelector('[name="paid_now"][value="1"]').dispatchEvent(new ui.window.Event('change'));
    ui.element('movementAccount').value = 'account-id';
    ui.element('dashboardActualPaymentMethod').value = 'transfer';
    ui.element('dashboardActualPaymentMethod').dispatchEvent(new ui.window.Event('change'));
    assert.equal(ui.element('dashboardActualPaymentMethod').disabled, false);

    ui.form.querySelector('[name="paid_now"][value="0"]').checked = true;
    ui.form.querySelector('[name="paid_now"][value="0"]').dispatchEvent(new ui.window.Event('change'));
    ui.element('dashboardDueDateEnabled').checked = true;
    ui.element('dashboardDueDateEnabled').dispatchEvent(new ui.window.Event('change'));
    assert.equal(ui.element('dashboardActualPaymentMethod').disabled, true);
    assert.equal(ui.element('dashboardActualPaymentMethod').value, '');
    ui.submit();
    await flush();
    assert.equal(sent.has('actual_payment_method'), false);
    assert.equal(sent.get('payment_condition'), 'deferred');
    ui.dom.window.close();
});

const existingMovement = {
    id: 'movement-id', financial_movement_id: 'movement-id', tipo: 'despesa',
    description: 'Despesa de teste', amount: '90.50', currency: 'EUR', category: 'casa-id',
    data_movimento: '2026-11-17', account_id: 'account-id', movement_status: 'pending',
};

test('modal de movimento é movido para o body e não fica limitado pela coluna da página', () => {
    const dashboard = readFileSync(new URL('../../public/js/dashboard.js', import.meta.url), 'utf8');
    const shell = readFileSync(new URL('../../public/css/app-shell.css', import.meta.url), 'utf8');
    assert.match(dashboard, /document\.body\.appendChild\(editorModalElement\)/);
    assert.match(shell, /width: min\(calc\(100vw - 1rem\), 34rem\)/);
    assert.match(shell, /\.movement-editor-card \.record-type-buttons \.btn \{ min-width: 0; flex: 1 1 0;/);
    assert.match(shell, /grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/);
});

test('modal de novo movimento mantém largura integral no mobile para despesa e receita', () => {
    const shell = readFileSync(new URL('../../public/css/app-shell.css', import.meta.url), 'utf8');
    assert.ok(shell.includes('#movementEntryModal .movement-entry-dialog'));
    assert.ok(shell.includes('width: min(calc(100vw - 1rem), 34rem);'));
});

test('a vista usa entradas textuais com teclado decimal para todos os valores monetários de Movimentos', () => {
    const editor = readFileSync(new URL('../../resources/views/partials/movement-editor-modal.blade.php', import.meta.url), 'utf8');
    const movements = readFileSync(new URL('../../resources/views/movements.blade.php', import.meta.url), 'utf8');
    const fields = [
        { id: 'movementAmount', source: editor },
        { id: 'settleMovementAmount', source: movements },
    ];

    for (const { id, source } of fields) {
        const tag = source.match(new RegExp(`<input\\b[^>]*\\bid="${id}"[^>]*>`))?.[0];
        assert.ok(tag, `campo ${id} presente`);
        assert.match(tag, /type="text"/);
        assert.match(tag, /inputmode="decimal"/);
    }
});
test('vírgula e ponto são interpretados como cêntimos em vez de multiplicar o valor por 100', async (t) => {
    for (const [input, expected] of [
        ['90,50', '90.50'], ['90.50', '90.50'], ['90,5', '90.50'],
        ['90', '90.00'], [',50', '0.50'], ['1.234,56', '1234.56'], ['1,234.56', '1234.56'],
    ]) {
        await t.test(input, async () => {
            let sent;
            const ui = setup(async (_url, options = {}) => {
                if (options.method === 'POST') { sent = JSON.parse(options.body); return jsonResponse(201, { data: { id: 'debt-id' } }); }
                return jsonResponse(200, { data: [] });
            });
            ui.element('movementAmount').value = input;
            ui.element('movementAmount').dispatchEvent(new ui.window.Event('input'));
            ui.submit();
            await flush();
            assert.equal(sent.total_amount, expected);
            ui.dom.window.close();
        });
    }
});

test('previsão usa o montante com vírgula e conserva o arredondamento por prestação', () => {
    const ui = setup(async () => { throw new Error('não devia enviar'); });
    ui.element('movementAmount').value = '90,50';
    ui.element('movementAmount').dispatchEvent(new ui.window.Event('input'));
    const preview = ui.element('dashboardInstallmentScheduleList').textContent;
    assert.match(preview, /30,17/);
    assert.match(preview, /30,16/);
    assert.match(preview, /90,50/);
    ui.dom.window.close();
});

test('formatos ambíguos, precisão excessiva e valores fora dos limites são rejeitados sem enviar nem fechar', async (t) => {
    for (const input of ['1.234', '1,234', '90,501', '1.2.3', '0', '-1', '10000000000,00']) {
        await t.test(input, async () => {
            let calls = 0;
            const ui = setup(async () => { calls++; return jsonResponse(201, {}); });
            ui.element('movementAmount').value = input;
            ui.submit();
            await flush();
            assert.equal(calls, 0);
            assert.equal(ui.modal.open, true);
            assert.equal(ui.element('movementAmount').value, input);
            assert.match(ui.element('movementFormError').textContent, /Introduz um valor/);
            ui.dom.window.close();
        });
    }
});

test('movimento parcialmente pago mostra os valores liquidados e pendentes', () => {
    const partial = {
        ...existingMovement,
        amount: '120.00',
        remaining_amount: '80.00',
        movement_status: 'partial',
    };
    const ui = setup(async () => jsonResponse(200, { data: [] }), [partial]);
    const row = ui.element('expensesList').querySelector('[data-movement-id]');
    assert.ok(row);
    assert.match(row.textContent, /Pago: 40,00/);
    assert.match(row.textContent, /Pendente: 80,00/);
    assert.match(row.textContent, /Parcial/);
    assert.doesNotMatch(row.textContent, /Parcialmente pago/);
    const progress = row.querySelector('[role="progressbar"]');
    assert.ok(progress);
    assert.equal(progress.getAttribute('aria-valuenow'), '33');
    assert.equal(progress.style.height, '3px');
    ui.dom.window.close();
});

test('as opções de recorrência respeitam hidden mesmo com display grid do Bootstrap', () => {
    const blade = readFileSync(new URL('../../resources/views/movements.blade.php', import.meta.url), 'utf8');
    assert.ok(blade.includes('#deleteRecurringOptions[hidden] { display:none !important; }'));
    assert.match(blade, /id="deleteRecurringOptions"[^>]*hidden/);
});

test('a mesma ação mostra Pagar em pendentes e Reverter em movimentos liquidados', () => {
    const pendingUi = setup(async () => jsonResponse(200, { data: [] }), [existingMovement]);
    pendingUi.element('expensesList').querySelector('[data-movement-action="details"]').click();
    const pendingAction = pendingUi.element('movementDetailsSettle');
    assert.equal(pendingAction.hidden, false);
    assert.equal(pendingAction.querySelector('span').textContent, 'Pagar');
    pendingUi.dom.window.close();

    const settledUi = setup(async () => jsonResponse(200, { data: [] }), [{
        ...existingMovement,
        movement_status: 'settled',
        remaining_amount: '0.00',
    }]);
    settledUi.element('expensesList').querySelector('[data-movement-action="details"]').click();
    const settledAction = settledUi.element('movementDetailsSettle');
    assert.equal(settledAction.hidden, false);
    assert.equal(settledAction.querySelector('span').textContent, 'Reverter');
    settledUi.dom.window.close();
});

test('despesa simples mostra confirmação única, sem opções de recorrência', () => {
    const ui = setup(async () => jsonResponse(200, { data: [] }), [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    ui.element('movementDetailsDelete').click();
    assert.equal(ui.element('deleteRecurringOptions').hidden, true);
    assert.equal(ui.element('deleteSingleConfirmation').hidden, true);
    assert.equal(ui.element('deleteMovementTitle').textContent, 'Excluir movimento');
    ui.dom.window.close();
});

test('edição usa o modal próprio e envia o valor com vírgula em decimal canónico', async () => {
    let sent;
    let sentUrl;
    const ui = setup(async (url, options = {}) => {
        if (options.method === 'PUT') {
            sent = JSON.parse(options.body);
            sentUrl = url;
            return jsonResponse(200, { data: { id: 'movement-id' } });
        }
        return jsonResponse(200, { data: [] });
    }, [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    ui.element('movementDetailsEdit').click();
    assert.equal(ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('movementEditModal')).open, true);
    assert.equal(ui.element('movementEditAmount').value, '90,50');
    ui.element('movementEditAmount').value = '90,50';
    ui.element('movementEditForm').dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    assert.equal(sent.amount, '90.50');
    assert.equal(sentUrl, '/api/financial/movements/movement-id');
    ui.dom.window.close();
});

test('liquidação normaliza vírgula e rejeita valores ambíguos antes do pedido', async () => {
    let sent;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'PUT') { sent = JSON.parse(options.body); return jsonResponse(200, { data: { id: 'movement-id' } }); }
        return jsonResponse(200, { data: [] });
    }, [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    ui.element('movementDetailsSettle').click();
    const field = ui.element('settleMovementAmount');
    field.value = '1.234';
    ui.element('settleMovementForm').dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    assert.equal(sent, undefined);
    assert.equal(field.validity.customError, true);
    field.value = '90,50';
    field.dispatchEvent(new ui.window.Event('input'));
    ui.element('settleMovementForm').dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    assert.equal(sent.amount, '90.50');
    assert.equal(sent.expected_amount, '90.50');
    ui.dom.window.close();
});

test('despesa comum paga normaliza o valor e o pagamento antes de enviar o formulário', async () => {
    let sent;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'POST') { sent = options.body; return jsonResponse(200, { ok: true }); }
        return jsonResponse(200, { data: [] });
    });
    ui.element('dashboardInstallmentsEnabled').checked = false;
    ui.element('dashboardInstallmentsEnabled').dispatchEvent(new ui.window.Event('change'));
    ui.element('movementAmount').value = '90,50';
    ui.form.querySelector('[name="paid_now"][value="1"]').checked = true;
    ui.form.querySelector('[name="paid_now"][value="1"]').dispatchEvent(new ui.window.Event('change'));
    ui.element('movementAccount').value = 'account-id';
    ui.element('dashboardActualPaymentMethod').value = 'transfer';
    ui.element('dashboardActualPaymentMethod').dispatchEvent(new ui.window.Event('change'));
    ui.submit();
    await flush();
    assert.equal(sent.get('amount'), '90.50');
    assert.equal(sent.get('actual_payment_method'), 'transfer');
    assert.equal(sent.get('payment_account_id'), 'account-id');
    assert.equal(sent.has('account_id'), false);
    ui.dom.window.close();
});

test('liquidação pendente bloqueia repetição e fecho e mostra erro sem perder os dados', async () => {
    let resolveFetch;
    let calls = 0;
    const ui = setup(() => { calls++; return new Promise((resolve) => { resolveFetch = resolve; }); }, [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    ui.element('movementDetailsSettle').click();
    const modal = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('settleMovementModal'));
    const form = ui.element('settleMovementForm');
    form.dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    form.dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    modal.hide();
    assert.equal(calls, 1);
    assert.equal(modal.open, true);
    resolveFetch(jsonResponse(422, { errors: { amount: ['Valor superior ao saldo da prestação.'] } }));
    await flush();
    assert.equal(modal.open, true);
    assert.equal(ui.element('settleMovementAmount').value, '90.50');
    assert.match(ui.element('settleMovementError').textContent, /superior ao saldo/);
    ui.dom.window.close();
});

test('liquidação confirmada com falha de atualização mostra aviso para não pagar de novo', async () => {
    let calls = 0;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'PUT') { calls++; return jsonResponse(200, { data: { id: 'movement-id' }, installment: { status: 'paid' } }); }
        throw new TypeError('falha de rede');
    }, [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    ui.element('movementDetailsSettle').click();
    const modal = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('settleMovementModal'));
    ui.element('settleMovementForm').dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    assert.equal(calls, 1);
    assert.equal(modal.open, false);
    assert.match(ui.element('movementSaveFeedbackMessage').textContent, /prestação registado.*Não foi possível atualizar.*Não confirmes novamente/);
    assert.equal(ui.element('movementSaveFeedback').classList.contains('alert-warning'), true);
    ui.dom.window.close();
});

test('parcelamento só pode ser criado com pagamento pendente e recorrência continua disponível quando pago', () => {
    const dashboard = readFileSync(new URL('../../public/js/dashboard.js', import.meta.url), 'utf8');
    const controller = readFileSync(new URL('../../app/Http/Controllers/FinancialCoreController.php', import.meta.url), 'utf8');

    assert.match(dashboard, /installmentsToggle\.disabled = !isExpense \|\| isPaid \|\| Boolean\(recurrence\.checked\)/);
    assert.match(dashboard, /if \(isNewExpense && paidState !== '0' && \['deferred', 'installments'\]\.includes\(condition\)\)/);
    assert.match(dashboard, /paid_now: paidState/);
    assert.match(controller, /'paid_now' => \['required', Rule::in\(\['0'\]\)\]/);
});

test('cada movimento substitui os botões de ação por um único menu de detalhes', () => {
    const ui = setup(async () => jsonResponse(200, { data: [] }), [existingMovement]);
    const row = ui.element('expensesList').querySelector('[data-movement-id]');
    assert.ok(row.querySelector('[data-movement-action="details"]'));
    assert.equal(row.querySelectorAll('[data-acao="editar"], [data-movement-action="settle"], [data-movement-action="delete"]').length, 0);
    assert.equal(row.querySelectorAll('.expense-actions button').length, 1);
    ui.dom.window.close();
});

test('clicar na linha do movimento abre detalhes sem exigir o clique nos três pontos', () => {
    const ui = setup(async () => jsonResponse(200, { data: [] }), [existingMovement]);
    const row = ui.element('expensesList').querySelector('[data-movement-id]');
    const detailsModal = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('movementDetailsModal'));

    assert.equal(row.tabIndex, 0);
    row.querySelector('h3').dispatchEvent(new ui.window.MouseEvent('click', { bubbles: true }));
    assert.equal(detailsModal.open, true);
    assert.equal(ui.element('movementDetailsName').textContent, 'Despesa de teste');

    detailsModal.hide();
    row.focus();
    row.dispatchEvent(new ui.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    assert.equal(detailsModal.open, true);
    ui.dom.window.close();
});

test('modal de detalhes apresenta totais pagos e pendentes e mantém as ações correspondentes', () => {
    const partial = { ...existingMovement, amount: '120.00', remaining_amount: '80.00', movement_status: 'partial' };
    const ui = setup(async () => jsonResponse(200, { data: [] }), [partial]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    assert.equal(ui.element('movementDetailsName').textContent, 'Despesa de teste');
    assert.match(ui.element('movementDetailsAmount').textContent, /120,00\s*€/);
    assert.equal(ui.element('movementDetailsPaid').textContent, '40,00 €');
    assert.equal(ui.element('movementDetailsPending').textContent, '80,00 €');
    assert.equal(ui.element('movementDetailsSettle').hidden, false);
    assert.equal(ui.element('movementDetailsEdit').hidden, true);
    assert.equal(ui.element('movementDetailsDelete').hidden, false);
    ui.dom.window.close();
});

test('cancelar liquidação ou exclusão regressa ao modal de detalhes do movimento', () => {
    for (const action of ['settle', 'delete']) {
        const ui = setup(async () => jsonResponse(200, { data: [] }), [existingMovement]);
        ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
        const details = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('movementDetailsModal'));
        ui.element(action === 'settle' ? 'movementDetailsSettle' : 'movementDetailsDelete').click();
        const child = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element(action === 'settle' ? 'settleMovementModal' : 'deleteMovementModal'));
        assert.equal(child.open, true);
        child.hide();
        assert.equal(details.open, true, `detalhes devem reabrir após cancelar ${action}`);
        assert.equal(ui.element('movementDetailsName').textContent, 'Despesa de teste');
        ui.dom.window.close();
    }
});

test('cancelar edição regressa ao modal de detalhes do movimento', () => {
    const ui = setup(async () => jsonResponse(200, { data: [] }), [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="details"]').click();
    const details = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('movementDetailsModal'));
    ui.element('movementDetailsEdit').click();
    const editor = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('movementEntryModal'));
    assert.equal(editor.open, true);
    ui.element('cancelMovementEdit').click();
    assert.equal(editor.open, false);
    assert.equal(details.open, true);
    assert.equal(ui.element('movementDetailsName').textContent, 'Despesa de teste');
    ui.dom.window.close();
});

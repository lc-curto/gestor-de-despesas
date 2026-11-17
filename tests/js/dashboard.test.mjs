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
 <div class="mb-3"><select id="movementAccount" name="account_id"><option value="">Seleciona</option><option value="account-id">Conta</option></select></div>
 <select id="movementCategory" name="category_id" required></select>
 <input type="radio" name="payment_condition" value="cash" checked>
 <input type="radio" name="payment_condition" value="installments">
 <input type="radio" name="paid_now" value="0" checked>
 <input type="radio" name="paid_now" value="1">
 <input type="radio" name="paid_now" value="partial">
 <select name="payment_method" id="dashboardPaymentMethod"><option value="transfer">Transferência</option><option value="credit">Crédito</option></select>
 <input id="dashboardDueDate" name="due_date" type="date"><input id="dashboardInstallments" name="installments_count" type="number" value="3">
 <select id="dashboardInstallmentFrequency" name="installment_frequency"><option value="monthly">Mensal</option></select>
 <div id="dashboardInstallmentScheduleList"></div><input id="dashboardPaymentAmount" name="payment_amount" type="text" inputmode="decimal">
 <input id="dashboardPaymentDate" name="payment_date" type="date" value="2026-11-17">
 <select id="dashboardPaymentAccount" name="payment_account_id"><option value="account-id">Conta</option></select>
 <button type="submit" id="saveMovementButton">Guardar despesa</button>
</form></div>
<div id="settleMovementModal"><form id="settleMovementForm">
 <h2 id="settleMovementTitle"></h2><span id="settleMovementDescription"></span><div id="settleMovementError" class="d-none" tabindex="-1"></div>
 <input id="settleMovementAmount" name="amount" type="text" inputmode="decimal" required>
 <input id="settleMovementDate" name="movement_date" type="date" required>
 <label id="settleMovementAccountLabel"></label><select id="settleMovementAccount"><option value="account-id">Conta</option></select>
 <button id="settleMovementSubmit" type="submit"></button>
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
    form.querySelector('[name="payment_condition"][value="installments"]').checked = true;
    element('dashboardDueDate').value = '2026-12-17';
    element('dashboardInstallments').value = '3';
    form.querySelector('[name="payment_condition"][value="installments"]').dispatchEvent(new window.Event('change'));
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
    assert.equal(payload.payment_method, 'transfer');
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

test('pagamento inicial superior à primeira prestação é rejeitado antes de criar o parcelamento', async () => {
    let calls = 0;
    const ui = setup(async () => { calls++; return jsonResponse(201, { data: { id: 'debt-id' } }); });
    ui.form.querySelector('[name="paid_now"][value="partial"]').checked = true;
    ui.form.querySelector('[name="paid_now"][value="partial"]').dispatchEvent(new ui.window.Event('change'));
    ui.element('dashboardPaymentAmount').value = '31,00';
    ui.element('dashboardPaymentAccount').value = 'account-id';
    ui.submit();
    await flush();
    assert.equal(calls, 0);
    assert.equal(ui.modal.open, true);
    assert.match(ui.element('movementFormError').textContent, /não pode exceder o valor da primeira prestação/);
    ui.dom.window.close();
});

test('rejeição de parcelamento por cartão orienta para Cartões e mantém modal aberto', async () => {
    const ui = setup(async () => jsonResponse(422, { errors: { payment_method: ['Regista a compra em Cartões.'] } }));
    ui.element('dashboardPaymentMethod').value = 'credit';
    ui.submit();
    await flush();
    assert.equal(ui.modal.open, true);
    assert.match(ui.element('movementFormError').textContent, /Regista a compra em Cartões/);
    ui.dom.window.close();
});

const existingMovement = {
    id: 'movement-id', financial_movement_id: 'movement-id', tipo: 'despesa',
    description: 'Despesa de teste', amount: '90.50', currency: 'EUR', category: 'casa-id',
    data_movimento: '2026-11-17', account_id: 'account-id', movement_status: 'pending',
};

test('a vista usa entradas textuais com teclado decimal para todos os valores monetários de Movimentos', () => {
    const blade = readFileSync(new URL('../../resources/views/movements.blade.php', import.meta.url), 'utf8');
    for (const id of ['movementAmount', 'dashboardPaymentAmount', 'settleMovementAmount']) {
        const tag = blade.match(new RegExp(`<input\\b[^>]*\\bid="${id}"[^>]*>`))?.[0];
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

test('edição envia ao backend o valor com vírgula convertido em decimal canónico', async () => {
    let sent;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'POST') { sent = options.body; return jsonResponse(200, { ok: true }); }
        return jsonResponse(200, { data: [] });
    }, [existingMovement]);
    ui.form.querySelector('[name="payment_condition"][value="cash"]').checked = true;
    ui.element('expensesList').querySelector('[data-acao="editar"]').click();
    assert.equal(ui.element('movementAmount').value, '90.50');
    ui.element('movementAmount').value = '90,50';
    ui.submit();
    await flush();
    assert.equal(sent.get('amount'), '90.50');
    assert.equal(sent.get('_method'), 'PUT');
    ui.dom.window.close();
});

test('liquidação normaliza vírgula e rejeita valores ambíguos antes do pedido', async () => {
    let sent;
    const ui = setup(async (_url, options = {}) => {
        if (options.method === 'PUT') { sent = JSON.parse(options.body); return jsonResponse(200, { data: { id: 'movement-id' } }); }
        return jsonResponse(200, { data: [] });
    }, [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="settle"]').click();
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
    ui.form.querySelector('[name="payment_condition"][value="cash"]').checked = true;
    ui.form.querySelector('[name="payment_condition"][value="cash"]').dispatchEvent(new ui.window.Event('change'));
    ui.element('movementAmount').value = '90,50';
    ui.form.querySelector('[name="paid_now"][value="1"]').checked = true;
    ui.form.querySelector('[name="paid_now"][value="1"]').dispatchEvent(new ui.window.Event('change'));
    assert.equal(ui.element('dashboardPaymentAmount').value, '90,50');
    ui.submit();
    await flush();
    assert.equal(sent.get('amount'), '90.50');
    assert.equal(sent.get('payment_amount'), '90.50');
    ui.dom.window.close();
});

test('liquidação pendente bloqueia repetição e fecho e mostra erro sem perder os dados', async () => {
    let resolveFetch;
    let calls = 0;
    const ui = setup(() => { calls++; return new Promise((resolve) => { resolveFetch = resolve; }); }, [existingMovement]);
    ui.element('expensesList').querySelector('[data-movement-action="settle"]').click();
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
    ui.element('expensesList').querySelector('[data-movement-action="settle"]').click();
    const modal = ui.window.bootstrap.Modal.getOrCreateInstance(ui.element('settleMovementModal'));
    ui.element('settleMovementForm').dispatchEvent(new ui.window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    assert.equal(calls, 1);
    assert.equal(modal.open, false);
    assert.match(ui.element('movementSaveFeedbackMessage').textContent, /prestação registado.*Não foi possível atualizar.*Não confirmes novamente/);
    assert.equal(ui.element('movementSaveFeedback').classList.contains('alert-warning'), true);
    ui.dom.window.close();
});

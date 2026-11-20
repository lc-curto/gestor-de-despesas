import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/financial/cards.blade.php', import.meta.url), 'utf8');
const inlineScript = blade.match(/@push\('scripts'\)\s*<script>([\s\S]*?)<\/script>/)[1]
    .replace("{{ route('financial.accounts.store') }}", '/api/financial/accounts')
    .replace('{{ $inactiveCards->count() }}', '0');

// A minimal DOM double exercises the actual page script without adding dependencies.
class Element {
    constructor(value = '') { this.value = value; this.handlers = {}; this.dataset = {}; this.textContent = ''; this.disabled = false; this.validityMessage = ''; }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    setCustomValidity(message) { this.validityMessage = message; }
    reportValidity() { this.reported = true; return !this.validityMessage; }
    dispatch(type) { return this.handlers[type]?.({ currentTarget: this, preventDefault() {} }); }
    type(text) { this.value = ''; for (const character of text) { this.value += character; this.dispatch('input'); } }
}
class Form extends Element {
    constructor(values) {
        super();
        this.elements = Object.fromEntries(Object.entries(values).map(([name, value]) => [name, new Element(value)]));
        this.button = new Element();
    }
    querySelector(selector) { assert.equal(selector, '[type="submit"]'); return this.button; }
}
class FakeFormData {
    constructor(form) { this.form = form; }
    entries() { return Object.entries(this.form.elements).map(([name, field]) => [name, field.value])[Symbol.iterator](); }
}

function setup({ status = 200, result = {} } = {}) {
    const create = new Form({ name: 'Cartão novo', credit_limit: '', opening_debt_amount: '0,00', closing_day: '10', due_day: '1' });
    const edit = new Form({ name: '', credit_limit: '', opening_debt_amount: '', closing_day: '', due_day: '' });
    const errors = { createCardError: new Element(), editCardError: new Element(), cardsFeedback: new Element() };
    const editButton = new Element();
    editButton.dataset = { name: 'Cartão existente', limit: '1234.56', openingDebt: '90.50', closing: '15', due: '5', updateUrl: '/account/1', settingsUrl: '/account/1/card-settings' };
    const elements = { createCardForm: create, editCardForm: edit, ...errors };
    const document = {
        getElementById: (id) => elements[id] ?? null,
        querySelector: (selector) => selector === 'meta[name="csrf-token"]' ? { content: 'token' } : null,
        querySelectorAll: (selector) => selector === '.js-edit-card' ? [editButton] : [],
    };
    const requests = [];
    let reloads = 0;
    const fetch = async (url, options) => {
        requests.push({ url, ...options, payload: JSON.parse(options.body) });
        return { ok: status >= 200 && status < 300, json: async () => result };
    };
    const modalCalls = [];
    const bootstrap = {
        Modal: {
            getOrCreateInstance(element) {
                return {
                    show() { modalCalls.push({ action: 'show', element }); },
                    hide() { modalCalls.push({ action: 'hide', element }); },
                };
            },
        },
    };
    runInNewContext(inlineScript, { document, FormData: FakeFormData, fetch, bootstrap, window: { location: { reload() { reloads++; } } } });
    return { create, edit, editButton, errors, requests, modalCalls, get reloads() { return reloads; } };
}

test('campos monetários preservam texto digitado e têm instruções acessíveis', () => {
    for (const id of ['newCardLimit', 'newCardUsed', 'editCardLimit', 'editCardOpeningDebt']) {
        const input = blade.match(new RegExp(`<input[^>]*id="${id}"[^>]*>`))?.[0];
        assert.ok(input, `campo ${id} existe`);
        assert.match(input, /type="text"/);
        assert.match(input, /inputmode="decimal"/);
        assert.match(input, /\brequired\b/);
        const help = input.match(/aria-describedby="([^"]+)"/)?.[1];
        assert.ok(help);
        assert.match(blade, new RegExp(`<label[^>]*for="${id}"`));
        assert.match(blade, new RegExp(`id="${help}"[^>]*>[^<]*1\\.000,00`));
    }
});

test('criação envia formatos portugueses e decimais simples normalizados com precisão', async () => {
    for (const [input, expected] of [
        ['1.000,00', '1000.00'], ['90,50', '90.50'], ['1000', '1000.00'],
        ['1000,5', '1000.50'], ['1000,50', '1000.50'], ['1000.50', '1000.50'],
        ['0,01', '0.01'], ['0001.20', '1.20'], ['9.999.999.999,99', '9999999999.99'],
    ]) {
        const h = setup();
        h.create.elements.credit_limit.type(input);
        assert.equal(h.create.elements.credit_limit.value, input, `texto ${input} não é alterado`);
        await h.create.dispatch('submit');
        assert.equal(h.requests.length, 1, input);
        assert.equal(h.requests[0].url, '/api/financial/accounts');
        assert.equal(h.requests[0].method, 'POST');
        assert.equal(h.requests[0].headers['X-CSRF-TOKEN'], 'token');
        assert.equal(h.requests[0].payload.credit_limit, expected, input);
        assert.equal(h.requests[0].payload.opening_debt_amount, '0.00');
        assert.equal(h.requests[0].payload.type, 'credit_card');
        assert.equal(h.reloads, 1);
    }
});

test('limite já utilizado aceita vírgula e milhares, inclusive zero', async () => {
    for (const [input, expected] of [['90,50', '90.50'], ['1.000,00', '1000.00'], ['0', '0.00'], ['0.5', '0.50']]) {
        const h = setup();
        h.create.elements.credit_limit.value = '2000';
        h.create.elements.opening_debt_amount.type(input);
        await h.create.dispatch('submit');
        assert.equal(h.requests[0].payload.opening_debt_amount, expected, input);
    }
});

test('valores inválidos ou ambíguos são bloqueados antes da rede e podem ser corrigidos', async () => {
    for (const input of ['', '0', '0,00', '-1', '+2', '1.000', '1,000', '1.000.000', '1,000.00', '12.34,56', '1.000,000', '90,500', '1e3', '.50', '1,', '10000000000', '€ 90,50']) {
        const h = setup();
        const limit = h.create.elements.credit_limit;
        limit.type(input);
        await h.create.dispatch('submit');
        assert.equal(h.requests.length, 0, input);
        assert.match(limit.validityMessage, /valor inválido/, input);
        assert.equal(limit.reported, true, input);
        assert.match(h.errors.createCardError.textContent, /valor inválido/, input);
        assert.equal(h.create.button.disabled, false);
        limit.type('90,50');
        assert.equal(limit.validityMessage, '');
        await h.create.dispatch('submit');
        assert.equal(h.requests[0].payload.credit_limit, '90.50', input);
    }
    for (const input of ['-0,01', '1.000', '90,500', '10000000000']) {
        const h = setup();
        h.create.elements.credit_limit.value = '2000';
        h.create.elements.opening_debt_amount.type(input);
        await h.create.dispatch('submit');
        assert.equal(h.requests.length, 0, input);
        assert.match(h.create.elements.opening_debt_amount.validityMessage, /valor inválido/, input);
    }
});

test('edição preserva exatamente os montantes existentes ao alterar apenas o nome', async () => {
    const h = setup();
    h.editButton.dispatch('click');
    assert.equal(h.edit.elements.credit_limit.value, '1234.56');
    assert.equal(h.edit.elements.opening_debt_amount.value, '90.50');
    h.edit.elements.name.value = 'Nome corrigido';
    await h.edit.dispatch('submit');
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].url, '/account/1/card-settings');
    assert.equal(h.requests[0].method, 'PUT');
    assert.deepEqual(h.requests[0].payload, { credit_limit: '1234.56', opening_debt_amount: '90.50', closing_day: '15', due_day: '5' });
    assert.equal(h.requests[1].url, '/account/1');
    assert.deepEqual(h.requests[1].payload, { name: 'Nome corrigido' });
});

test('edição normaliza ambos os valores e não envia configurações nem nome com campos inválidos', async () => {
    const h = setup();
    h.editButton.dispatch('click');
    h.edit.elements.credit_limit.type('1.000');
    await h.edit.dispatch('submit');
    assert.equal(h.requests.length, 0);
    h.edit.elements.credit_limit.type('1.000,00');
    h.edit.elements.opening_debt_amount.type('90,500');
    await h.edit.dispatch('submit');
    assert.equal(h.requests.length, 0);
    h.edit.elements.opening_debt_amount.type('90,50');
    await h.edit.dispatch('submit');
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].payload.credit_limit, '1000.00');
    assert.equal(h.requests[0].payload.opening_debt_amount, '90.50');
});

test('erro de validação do servidor é apresentado sem descartar os valores', async () => {
    const h = setup({ status: 422, result: { errors: { opening_debt_amount: ['A utilização inicial não pode ultrapassar o limite total do cartão.'] } } });
    h.create.elements.credit_limit.type('90,50');
    h.create.elements.opening_debt_amount.type('100,00');
    await h.create.dispatch('submit');
    assert.match(h.errors.createCardError.textContent, /não pode ultrapassar/);
    assert.equal(h.create.button.disabled, false);
    assert.equal(h.create.elements.credit_limit.value, '90,50');
    assert.equal(h.create.elements.opening_debt_amount.value, '100,00');
    assert.equal(h.reloads, 0);
});

test('abrir outro cartão limpa erro do cartão anterior sem alterar os valores existentes', async () => {
    const h = setup();
    h.editButton.dispatch('click');
    h.edit.elements.credit_limit.type('1.000');
    await h.edit.dispatch('submit');
    assert.notEqual(h.edit.elements.credit_limit.validityMessage, '');
    h.editButton.dataset.limit = '50.05';
    h.editButton.dataset.openingDebt = '0.00';
    h.editButton.dispatch('click');
    assert.equal(h.edit.elements.credit_limit.validityMessage, '');
    assert.equal(h.edit.elements.credit_limit.value, '50.05');
    assert.equal(h.edit.elements.opening_debt_amount.value, '0.00');
    assert.equal(h.errors.editCardError.textContent, '');
});

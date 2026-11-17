import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/commitments-payment.js', import.meta.url), 'utf8');

function runtime(fetchImpl = async () => ({ ok: true, json: async () => ({}) })) {
    const requests = [];
    let reloads = 0;
    const context = {
        fetch: async (...args) => { requests.push(args); return fetchImpl(...args); },
        FormData: class {
            constructor(form) { this.fields = form.values; }
            entries() { return Object.entries(this.fields)[Symbol.iterator](); }
        },
        window: { location: { reload: () => { reloads++; } } },
    };
    vm.createContext(context);
    vm.runInContext(source, context);
    return { api: context.CommitmentPayment, requests, reloads: () => reloads };
}

function formFixture(value, maximum = '100.00') {
    const handlers = {};
    const inputHandlers = {};
    const attributes = {};
    const amountError = { textContent: '' };
    const input = {
        value, dataset: { maxAmount: maximum }, message: '', focused: false, reported: false,
        addEventListener: (event, handler) => { inputHandlers[event] = handler; },
        setCustomValidity(message) { this.message = message; },
        setAttribute(name, value) { attributes[name] = value; },
        removeAttribute(name) { delete attributes[name]; },
        reportValidity() { this.reported = true; },
        focus() { this.focused = true; },
    };
    const button = { disabled: true };
    const form = {
        dataset: { endpoint: '/api/financial/commitments/a/installments/b/pay' },
        values: { amount: value, account_id: 'account-1', payment_date: '2026-11-17' },
        querySelector: (selector) => selector === '[data-payment-amount]' ? input : selector === '[data-payment-error]' ? amountError : button,
        addEventListener: (event, handler) => { handlers[event] = handler; },
    };
    const feedback = { scrollIntoView() {} };
    const errors = [];
    return { form, input, button, handlers, inputHandlers, feedback, errors, attributes, amountError, showError: (_el, message) => errors.push(message) };
}

test('preserva vírgula decimal e envia o valor canónico sem alterar conta ou data', async () => {
    const env = runtime();
    const f = formFixture('90,50');
    assert.equal(env.api.bind(f.form, 'token-csrf', f.feedback, f.showError), true);
    assert.equal(f.button.disabled, false);
    let prevented = false;
    await f.handlers.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(env.requests.length, 1);
    const [url, request] = env.requests[0];
    assert.equal(url, f.form.dataset.endpoint);
    assert.equal(request.method, 'POST');
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'token-csrf');
    assert.deepEqual(JSON.parse(request.body), { amount: '90.50', account_id: 'account-1', payment_date: '2026-11-17' });
    assert.equal(env.reloads(), 1);
});

test('aceita formatos explícitos, converte cêntimos exatamente e compara o máximo', () => {
    const { api } = runtime();
    for (const [input, max, expected] of [
        ['90,50', '90.50', '90.50'], ['90.50', '90.50', '90.50'],
        [' 00090,5 ', '90.50', '90.50'], ['5', '5.00', '5.00'],
        ['0,01', '0.01', '0.01'], ['1.234,56', '1234.56', '1234.56'],
        ['9.999.999.999,99', '9999999999.99', '9999999999.99'],
    ]) {
        const result = api.validate(input, max);
        assert.equal(result.ok, true, input);
        assert.equal(result.value, expected, input);
    }
    for (const [input, max] of [['90,51', '90.50'], ['1.234,57', '1234.56'], ['0,02', '0.01']]) {
        const result = api.validate(input, max);
        assert.equal(result.ok, false, input);
        assert.match(result.error, /ultrapassar/);
    }
});

test('rejeita formatos incompletos ou ambíguos e limites ausentes', () => {
    const { api } = runtime();
    for (const value of ['', '0', '0,00', '-1,00', '+1,00', '1.234', '1,234', '1,234.56', '90,', '90,500', '1e2', '12 345,67', '€90,50', '9'.repeat(41)]) {
        assert.equal(api.validate(value, '10000.00').ok, false, value);
    }
    for (const maximum of ['', 'NaN', '10,00', '1e5']) {
        assert.equal(api.validate('1,00', maximum).ok, false, maximum);
    }
});

test('nenhum pedido é enviado para montantes inválidos ou superiores ao saldo', async () => {
    for (const value of ['1.234', '90,500', '-1', '0,00', '100,01', '1.000,00']) {
        const env = runtime();
        const f = formFixture(value);
        env.api.bind(f.form, 'token', f.feedback, f.showError);
        let prevented = false;
        await f.handlers.submit({ preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true, value);
        assert.equal(env.requests.length, 0, value);
        assert.equal(env.reloads(), 0, value);
        assert.notEqual(f.input.message, '', value);
        assert.equal(f.input.reported, true, value);
        assert.equal(f.input.focused, true, value);
        assert.equal(f.attributes['aria-invalid'], 'true', value);
        assert.equal(f.amountError.textContent, f.input.message, value);
        assert.equal(f.button.disabled, false, value);
        f.inputHandlers.input();
        assert.equal(f.input.message, '', value);
        assert.equal(f.attributes['aria-invalid'], undefined, value);
        assert.equal(f.amountError.textContent, '', value);
    }
});

test('em erro do servidor mantém os valores e permite nova tentativa', async () => {
    const env = runtime(async () => ({ ok: false, status: 422, json: async () => ({ errors: { amount: ['Saldo insuficiente.'] } }) }));
    const f = formFixture('90,50');
    env.api.bind(f.form, 'token', f.feedback, f.showError);
    await f.handlers.submit({ preventDefault() {} });
    assert.equal(env.requests.length, 1);
    assert.equal(env.reloads(), 0);
    assert.equal(f.input.value, '90,50');
    assert.equal(f.button.disabled, false);
    assert.deepEqual(f.errors, ['Saldo insuficiente.']);
});

test('mantém pagamento bloqueado quando a resposta é incerta', async () => {
    for (const fetchImpl of [
        async () => { throw new Error('Ligação interrompida'); },
        async () => ({ ok: false, status: 500, json: async () => ({ message: 'Erro interno' }) }),
    ]) {
        const env = runtime(fetchImpl);
        const f = formFixture('90,50');
        env.api.bind(f.form, 'token', f.feedback, f.showError);
        await f.handlers.submit({ preventDefault() {} });
        assert.equal(env.requests.length, 1);
        assert.equal(f.button.disabled, true);
        assert.match(f.errors[0], /verifica o histórico e o saldo/i);
        await f.handlers.submit({ preventDefault() {} });
        assert.equal(env.requests.length, 1);
        assert.equal(env.reloads(), 0);
    }
});

test('não envia novamente enquanto um pedido está pendente', async () => {
    let resolve;
    const env = runtime(() => new Promise((done) => { resolve = done; }));
    const f = formFixture('90,50');
    env.api.bind(f.form, 'token', f.feedback, f.showError);
    const first = f.handlers.submit({ preventDefault() {} });
    assert.equal(f.button.disabled, true);
    await f.handlers.submit({ preventDefault() {} });
    assert.equal(env.requests.length, 1);
    resolve({ ok: true, json: async () => ({}) });
    await first;
});

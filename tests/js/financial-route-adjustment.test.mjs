import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const blade = readFileSync(new URL('../../resources/views/financial-route.blade.php', import.meta.url), 'utf8');
const script = blade.match(/@push\('scripts'\)\s*<script>([\s\S]*?)<\/script>/)?.[1]
    .replace('{{ $mode }}', '1')
    .replace('{{ Illuminate\\Support\\Js::from($month) }}', '"2026-12"');
assert.ok(script, 'o script da rota deve existir');

const base = {
    status: 'settled', date: '2026-12-05', category: 'Sem categoria', category_id: null,
    recurrence_id: null, expense_behavior: 'variable', notes: '',
};
const items = [
    { ...base, id: 'plus', type: 'adjustment', description: 'Aumento', amount: 20.5, projection_delta: 20.5, account_id: 'bank', account_name: 'Conta principal', notes: 'Recontagem' },
    { ...base, id: 'minus', type: 'adjustment', description: 'Diminuição', amount: 7.25, projection_delta: -7.25, account_id: 'cash', account_name: 'Carteira' },
    { ...base, id: 'transfer-out', type: 'transfer', description: 'Transferência saída', amount: 10, projection_delta: -10, account_id: 'bank', account_name: 'Conta principal' },
    { ...base, id: 'transfer-in', type: 'transfer', description: 'Transferência entrada', amount: 10, projection_delta: 10, account_id: 'cash', account_name: 'Carteira' },
    { ...base, id: 'income', type: 'income', status: 'pending', description: 'Entrada', amount: 30.1, projection_delta: 30.1, account_id: 'bank', account_name: 'Conta principal' },
    { ...base, id: 'fixed', type: 'expense', status: 'pending', description: 'Fixo', amount: 12.4, projection_delta: -12.4, expense_behavior: 'fixed', account_id: 'bank', account_name: 'Conta principal' },
    { ...base, id: 'variable', type: 'expense', status: 'pending', description: 'Variável', amount: 6.3, projection_delta: -6.3, account_id: 'cash', account_name: 'Carteira' },
    { ...base, id: 'income-settled', type: 'income', description: 'Entrada liquidada', amount: 3, projection_delta: 3, account_id: 'bank', account_name: 'Conta principal' },
    { ...base, id: 'invoice-example', type: 'expense', status: 'pending', description: 'Fatura prevista', amount: 4, projection_delta: -4, expense_behavior: 'fixed', account_id: null, account_name: 'Sem conta associada' },
];

function setup(response = { ok: false, json: async () => ({ message: 'Operação não permitida' }) }) {
    const html = `<!doctype html><meta name="csrf-token" content="token">
      <section id="financialRoute" data-store-url="/store" data-update-url="/movements" data-delete-url="/movements" data-reverse-url="/movements" data-currency="EUR" data-today="2026-12-05">
        <button id="routePrevious"></button><button id="routeNext"></button><button id="routeToday"></button><strong id="routePeriodLabel"></strong>
        <button id="routeNewMovement"></button><button data-route-mode="1"></button><button data-date="2026-12-05"></button>
      </section>
      <script type="application/json" id="route-day-2026-12-05">${JSON.stringify({ items, balance: 174.65, status: 'comfortable' })}</script>
      <div id="routeDayModal"></div><div id="routeMovementModal"></div><div id="routeMovementDetailModal"></div><div id="routeConfirmModal"></div>
      <div id="routeDayTitle"></div><div id="routeDayBalance"></div><section id="routeOverdueSection" hidden><span id="routeOverdueCount"></span><div id="routeOverdueMovements"></div></section><div id="routeDayMovements"></div><button id="routeAddFromDay"></button>
      <script type="application/json" id="route-overdue-data">[]</script>
      <button data-day-filter="all"></button><button data-day-filter="income"></button><button data-day-filter="fixed"></button><button data-day-filter="variable"></button>
      <div id="routeMovementTitle"></div><form id="routeMovementForm">
        <input type="radio" name="route_type" value="income"><input type="radio" name="route_type" value="fixed"><input type="radio" name="route_type" value="variable" checked>
        <input id="routeDate" name="movement_date"><input id="routeDescription" name="description"><input id="routeAmount" name="amount">
        <select id="routeCategory" name="category_id"><option value="">Sem categoria</option><option value="income-category" data-type="receita">Receita</option><option value="expense-category" data-type="despesa">Despesa</option></select>
        <select id="routeAccount" name="account_id"><option value=""></option><option value="bank">Conta principal</option><option value="cash">Carteira</option></select>
        <textarea id="routeNotes" name="notes"></textarea><div id="routeFormError"></div>
      </form>
      <div id="routeMovementDetailTitle"></div><div id="routeMovementKind"></div><div id="routeMovementAmount"></div>
      <div id="routeMovementCategory"></div><div id="routeMovementAccount"></div><div id="routeMovementDate"></div><div id="routeMovementDescription"></div><div id="routeMovementNotes"></div>
      <div id="routeDetailError" role="alert"></div><p id="routeEditHelp" role="status"></p><button id="routeEditMovement"></button><button id="routeDeleteMovement"></button>
      <div id="routeConfirmTitle"></div><div id="routeConfirmText"></div><button id="routeConfirmAction"></button>`;
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://example.test/rota-financeira' });
    const { window } = dom;
    const modals = new Map();
    window.matchMedia = () => ({ matches: false });
    window.bootstrap = { Modal: { getOrCreateInstance(element) {
        if (!modals.has(element.id)) modals.set(element.id, { shown: 0, hidden: 0, show() { this.shown++; }, hide() { this.hidden++; } });
        return modals.get(element.id);
    } } };
    const requests = [];
    window.fetch = async (url, options) => { requests.push({ url, ...options }); return response; };
    window.eval(script);
    const el = id => window.document.getElementById(id);
    const openDay = () => window.document.querySelector('[data-date="2026-12-05"]').click();
    const rows = () => [...el('routeDayMovements').querySelectorAll('.route-movement-item')];
    const openItem = description => {
        openDay();
        const row = rows().find(button => button.querySelector('span strong').textContent === description);
        assert.ok(row, `item ${description} visível`);
        row.click();
        return row;
    };
    return { window, el, modals, requests, openDay, rows, openItem };
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));

test('ajustes apresentam tipo, sinal, conta e detalhes corretos e não entram nos filtros de receitas/despesas', () => {
    const h = setup();
    h.openDay();
    assert.equal(h.rows().length, items.length);
    assert.equal(h.rows()[0].querySelector('small').textContent, 'Ajuste de saldo · Sem categoria');
    assert.equal(h.rows()[0].lastElementChild.textContent, '+20,50 €');
    assert.equal(h.rows()[1].querySelector('small').textContent, 'Ajuste de saldo · Sem categoria');
    assert.equal(h.rows()[1].lastElementChild.textContent, '−7,25 €');

    for (const [description, amount, account, notes] of [
        ['Aumento', '+20,50 €', 'Conta principal', 'Recontagem'],
        ['Diminuição', '−7,25 €', 'Carteira', '—'],
    ]) {
        h.openItem(description);
        assert.equal(h.el('routeMovementKind').textContent, 'Ajuste de saldo');
        assert.equal(h.el('routeMovementAmount').textContent, amount);
        assert.equal(h.el('routeMovementAccount').textContent, account);
        assert.equal(h.el('routeMovementDescription').textContent, description);
        assert.equal(h.el('routeMovementNotes').textContent, notes);
        assert.equal(h.el('routeDeleteMovement').textContent, 'Eliminar');
        assert.equal(h.el('routeEditMovement').disabled, true);
        assert.match(h.el('routeEditHelp').textContent, /elimina o movimento/i);
        h.el('routeEditMovement').dispatchEvent(new h.window.MouseEvent('click', { bubbles: true }));
        assert.equal(h.modals.get('routeMovementModal').shown, 0, 'até um clique sintético não abre edição genérica');
    }

    for (const [filter, expected] of [['income', ['Entrada', 'Entrada liquidada']], ['fixed', ['Fixo', 'Fatura prevista']], ['variable', ['Variável']]]) {
        h.window.document.querySelector(`[data-day-filter="${filter}"]`).click();
        assert.deepEqual(h.rows().map(row => row.querySelector('span strong').textContent), expected);
    }
});

test('transferências são identificadas pelo delta e também não abrem edição genérica', () => {
    const h = setup();
    for (const [description, amount, account] of [['Transferência saída', '−10,00 €', 'Conta principal'], ['Transferência entrada', '+10,00 €', 'Carteira']]) {
        const row = h.openItem(description);
        assert.equal(row.querySelector('small').textContent, 'Transferência · Sem categoria');
        assert.equal(row.lastElementChild.textContent, amount);
        assert.equal(h.el('routeMovementKind').textContent, 'Transferência');
        assert.equal(h.el('routeMovementAmount').textContent, amount);
        assert.equal(h.el('routeMovementAccount').textContent, account);
        assert.equal(h.el('routeEditMovement').disabled, true);
        assert.match(h.el('routeEditHelp').textContent, /transferências/i);
        h.el('routeEditMovement').dispatchEvent(new h.window.MouseEvent('click', { bubbles: true }));
        assert.equal(h.modals.get('routeMovementModal').shown, 0);
    }
});

test('edição suportada mantém o tipo e o comportamento da receita ou despesa pendente', async () => {
    for (const [description, type, behavior, accountField] of [['Entrada', 'income', 'variable', 'destination_account_id'], ['Fixo', 'expense', 'fixed', 'source_account_id']]) {
        const h = setup();
        const row = h.openItem(description);
        assert.equal(row.lastElementChild.textContent, description === 'Entrada' ? '+30,10 €' : '−12,40 €');
        assert.equal(h.el('routeMovementAmount').textContent, description === 'Entrada' ? '30,10 €' : '12,40 €');
        assert.equal(h.el('routeEditMovement').disabled, false);
        assert.equal(h.el('routeEditHelp').textContent, '');
        h.el('routeEditMovement').click();
        assert.equal(h.modals.get('routeMovementModal').shown, 1);
        assert.equal(h.el('routeAccount').value, 'bank');
        assert.equal(h.el('routeAccount').disabled, true);
        assert.equal(h.window.document.querySelector('[name="route_type"]:checked').value, description === 'Entrada' ? 'income' : 'fixed');
        h.el('routeMovementForm').dispatchEvent(new h.window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();
        assert.equal(h.requests.length, 1);
        assert.equal(h.requests[0].url, `/movements/${description === 'Entrada' ? 'income' : 'fixed'}`);
        assert.equal(h.requests[0].method, 'PUT');
        const payload = JSON.parse(h.requests[0].body);
        assert.equal(payload.type, type);
        assert.equal(payload.expense_behavior, behavior);
        assert.equal(payload[accountField], 'bank');
        assert.match(h.el('routeFormError').textContent, /Operação não permitida/);
    }
});

test('edição de liquidado ou previsão virtual é bloqueada e exclusão preserva mensagens de erro', async () => {
    const h = setup();
    h.openItem('Entrada liquidada');
    assert.equal(h.el('routeEditMovement').disabled, true);
    assert.match(h.el('routeEditHelp').textContent, /liquidado/);
    h.openItem('Fatura prevista');
    assert.equal(h.el('routeEditMovement').disabled, true);
    assert.match(h.el('routeEditHelp').textContent, /automaticamente/);
    h.openItem('Diminuição');
    h.el('routeDeleteMovement').click();
    assert.equal(h.el('routeConfirmTitle').textContent, 'Confirmar exclusão');
    assert.match(h.el('routeConfirmText').textContent, /Histórico preservado/i);
    h.el('routeConfirmAction').click();
    await flush();
    assert.equal(h.requests[0].url, '/movements/minus');
    assert.equal(h.requests[0].method, 'DELETE');
    assert.equal(h.el('routeConfirmText').textContent, 'Operação não permitida');
});

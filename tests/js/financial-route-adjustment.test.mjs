import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const blade = readFileSync(new URL('../../resources/views/financial-route.blade.php', import.meta.url), 'utf8');
const editRules = readFileSync(new URL('../../resources/views/partials/movement-edit-modal.blade.php', import.meta.url), 'utf8')
    .match(/<script>([\s\S]*?)<\/script>/)?.[1];
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
    { ...base, id: 'partial-loan', type: 'expense', status: 'partial', description: 'Empréstimo', amount: 120, projection_delta: -80, expense_behavior: 'fixed', account_id: 'bank', account_name: 'Conta principal', paid_amount: 40, remaining_amount: 80, is_partial_summary: true },
    { ...base, id: 'variable', type: 'expense', status: 'pending', description: 'Variável', amount: 6.3, projection_delta: -6.3, account_id: 'cash', account_name: 'Carteira' },
    { ...base, id: 'income-settled', type: 'income', description: 'Entrada liquidada', amount: 3, projection_delta: 3, account_id: 'bank', account_name: 'Conta principal' },
    { ...base, id: 'settlement-income', type: 'income', description: 'Liquidação Freelance', amount: 100, projection_delta: 100, account_id: 'bank', account_name: 'Conta principal', is_settlement_parent: true },
    { ...base, id: 'invoice-example', type: 'expense', status: 'pending', description: 'Fatura prevista', amount: 4, projection_delta: -4, expense_behavior: 'fixed', account_id: null, account_name: 'Sem conta associada' },
];

function setup(response = { ok: false, json: async () => ({ message: 'Operação não permitida' }) }) {
    const html = `<!doctype html><meta name="csrf-token" content="token">
      <section id="financialRoute" data-store-url="/store" data-update-url="/movements" data-delete-url="/movements" data-reverse-url="/movements" data-currency="EUR" data-today="2026-12-05">
        <button id="routePrevious"></button><button id="routeNext"></button><button id="routeToday"></button><strong id="routePeriodLabel"></strong>
        <button data-route-mode="1"></button><button data-date="2026-12-05" data-filter="income"></button><button data-date="2026-12-05" data-filter="fixed"></button><button data-date="2026-12-05" data-filter="variable"></button><button data-date="2026-12-05" data-filter="balance"></button>
      </section>
      <script type="application/json" id="route-day-2026-12-05">${JSON.stringify({ items, balance: 174.65, status: 'comfortable' })}</script>
      <div id="routeDayModal"></div><div id="routeMovementDetailModal"></div><div id="routeConfirmModal"></div>
      <div id="movementEditModal"><form id="movementEditForm"><h2 id="movementEditTitle"></h2>
        <input id="movementEditDescription"><input id="movementEditAmount"><input id="movementEditDate">
        <select id="movementEditCategory"><option value="">Seleciona</option></select>
        <textarea id="movementEditNotes"></textarea><input id="movementEditExpenseBehavior">
        <div id="movementEditError" class="d-none"></div><button id="movementEditSubmit" type="submit">Guardar alterações</button>
      </form></div>
      <div id="routeDayTitle"></div><div id="routeDayBalance"><span class="route-day-balance__label"></span><strong id="routeDayBalanceValue"></strong></div><section id="routeOverdueSection" hidden><span id="routeOverdueCount"></span><div id="routeOverdueMovements"></div></section><div id="routeDayMovements"></div>
      <script type="application/json" id="route-overdue-data">[]</script>
      <button data-day-filter="all"></button><button data-day-filter="income"></button><button data-day-filter="fixed"></button><button data-day-filter="variable"></button>
      <select id="routeCategory" class="d-none"><option value="">Sem categoria</option><option value="income-category" data-type="receita">Receita</option><option value="expense-category" data-type="despesa">Despesa</option></select>
      <div id="routeMovementDetailTitle"></div>
      <div id="routeMovementDetailsContent">
        <div id="routeMovementDetailsName"></div><div id="routeMovementDetailsCategory"></div>
        <div id="routeMovementDetailsAmount"></div><div id="routeMovementDetailsDate"></div>
        <div id="routeMovementDetailsKind"></div>
        <div id="routeMovementDetailsRelatedRow" hidden><span id="routeMovementDetailsRelated"></span></div>
        <div id="routeMovementDetailsDescription"></div><div id="routeMovementDetailsNotes"></div>
        <button id="routeMovementDetailsEdit"></button><button id="routeMovementDetailsDelete"></button>
      </div>
      <div id="routeDetailError" role="alert"></div><p id="routeEditHelp" role="status"></p>
      <div id="routeConfirmTitle"></div><div id="routeConfirmText"></div><small id="routeConfirmHint">Esta ação não pode ser desfeita.</small><button id="routeConfirmAction"></button>`;
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://example.test/rota-financeira' });
    const { window } = dom;
    const modals = new Map();
    window.matchMedia = () => ({ matches: false });
    window.bootstrap = { Modal: { getOrCreateInstance(element) {
        if (!modals.has(element.id)) modals.set(element.id, { shown: 0, hidden: 0, show() { this.shown++; element.classList.add('show'); element.dispatchEvent(new window.Event('shown.bs.modal')); }, hide() { this.hidden++; element.classList.remove('show'); element.dispatchEvent(new window.Event('hidden.bs.modal')); } });
        return modals.get(element.id);
    } } };
    const requests = [];
    window.fetch = async (url, options) => { requests.push({ url, ...options }); return response; };
    window.eval(editRules);
    window.eval(script);
    const el = id => window.document.getElementById(id);
    const openDay = (filter = 'income') => window.document.querySelector(`[data-date="2026-12-05"][data-filter="${filter}"]`).click();
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
        assert.equal(h.el('routeMovementDetailsKind').textContent, 'Ajuste de saldo');
        assert.equal(h.el('routeMovementDetailsAmount').textContent, amount);
        assert.equal(h.el('routeMovementDetailsRelated').textContent, account);
        assert.equal(h.el('routeMovementDetailsDescription').textContent, description);
        assert.equal(h.el('routeMovementDetailsNotes').textContent, notes);
        assert.equal(h.el('routeMovementDetailsDelete').textContent, 'Eliminar');
        assert.equal(h.el('routeMovementDetailsEdit').disabled, true);
        assert.match(h.el('routeEditHelp').textContent, /elimina o movimento/i);
        h.el('routeMovementDetailsEdit').dispatchEvent(new h.window.MouseEvent('click', { bubbles: true }));
        assert.equal((h.modals.get('movementEditModal')?.shown || 0), 0, 'até um clique sintético não abre edição genérica');
    }

    for (const [filter, expected] of [['income', ['Entrada', 'Entrada liquidada', 'Liquidação Freelance']], ['fixed', ['Fixo', 'Empréstimo', 'Fatura prevista']], ['variable', ['Variável']]]) {
        h.window.document.querySelector(`[data-day-filter="${filter}"]`).click();
        assert.deepEqual(h.rows().map(row => row.querySelector('span strong').textContent), expected);
    }
});

test('movimento parcial aparece uma vez com valores pagos, pendentes e barra proporcional', () => {
    const h = setup();
    h.openDay();
    const row = h.openItem('Empréstimo');
    assert.equal(row.querySelector('.route-movement-item__row').lastElementChild.textContent, '−120,00 €');
    assert.equal(row.querySelector('.route-movement-item__payment .is-paid').textContent, 'Pago: 40,00 €');
    assert.equal(row.querySelector('.route-movement-item__payment span:last-child').textContent, 'Pendente: 80,00 €');
    assert.equal(row.querySelector('[role="progressbar"]').getAttribute('aria-valuenow'), '33');
    assert.equal(row.querySelector('.route-movement-item__progress span').style.width, '33.33333333333333%');
    assert.equal(row.querySelector('.route-movement-item__progress').parentElement, row);
    assert.equal(row.querySelector('.route-movement-item__row').parentElement, row);
});

test('abrir qualquer coluna da Rota Financeira repõe sempre o filtro Todos', () => {
    const h = setup();
    for (const filter of ['income', 'fixed', 'variable', 'balance']) {
        h.openDay(filter);
        assert.equal(h.window.document.querySelector('[data-day-filter="all"]').classList.contains('active'), true);
        assert.equal(h.rows().length, items.length);
    }
});

test('Rota Financeira não apresenta criação de movimentos e mantém o Novo global do bottom bar', () => {
    const h = setup();
    h.openDay();
    assert.equal(h.el('routeAddFromDay'), null);
    assert.equal(h.el('routeMovementModal'), null);
    const blade = readFileSync(new URL('../../resources/views/financial-route.blade.php', import.meta.url), 'utf8');
    assert.doesNotMatch(blade, /routeAddFromDay|routeMovementModal|routeMovementForm/);
    const layout = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    assert.match(layout, /class="mobile-bottom-link mobile-bottom-new"/);
    assert.match(layout, /data-bs-target="#mobileNewMenu"/);
    assert.match(layout, /data-dashboard-new-movement="despesa"/);
    assert.match(layout, /data-dashboard-new-movement="receita"/);
});
test('transferências são identificadas pelo delta e também não abrem edição genérica', () => {
    const h = setup();
    for (const [description, amount, account] of [['Transferência saída', '−10,00 €', 'Conta principal'], ['Transferência entrada', '+10,00 €', 'Carteira']]) {
        const row = h.openItem(description);
        assert.equal(row.querySelector('small').textContent, 'Transferência · Sem categoria');
        assert.equal(row.lastElementChild.textContent, amount);
        assert.equal(h.el('routeMovementDetailsKind').textContent, 'Transferência');
        assert.equal(h.el('routeMovementDetailsAmount').textContent, amount);
        assert.equal(h.el('routeMovementDetailsRelated').textContent, account);
        assert.equal(h.el('routeMovementDetailsEdit').disabled, true);
        assert.match(h.el('routeEditHelp').textContent, /transferências/i);
        h.el('routeMovementDetailsEdit').dispatchEvent(new h.window.MouseEvent('click', { bubbles: true }));
        assert.equal((h.modals.get('movementEditModal')?.shown || 0), 0);
    }
});

test('edição suportada mantém o tipo e o comportamento da receita ou despesa pendente', async () => {
    for (const [description, type, behavior, accountField] of [['Entrada', 'income', 'variable', 'destination_account_id'], ['Fixo', 'expense', 'fixed', 'source_account_id']]) {
        const h = setup();
        const row = h.openItem(description);
        assert.equal(row.lastElementChild.textContent, description === 'Entrada' ? '+30,10 €' : '−12,40 €');
        assert.equal(h.el('routeMovementDetailsAmount').textContent, description === 'Entrada' ? '30,10 €' : '12,40 €');
        assert.equal(h.el('routeMovementDetailsEdit').disabled, false);
        assert.equal(h.el('routeEditHelp').textContent, '');
        h.el('routeMovementDetailsEdit').click();
        assert.equal(h.modals.get('movementEditModal').shown, 1, 'a edição abre o modal partilhado');
        assert.equal(h.el('movementEditDescription').value, description);
        assert.equal(h.el('movementEditAmount').value, description === 'Entrada' ? '30,1' : '12,4');
        assert.equal(h.el('movementEditDate').value, '2026-12-05');
        assert.equal(h.el('movementEditExpenseBehavior').value, behavior);
        assert.equal(h.el('movementEditNotes').value, '');
        h.el('movementEditForm').dispatchEvent(new h.window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();
        assert.equal(h.requests.length, 1);
        assert.equal(h.requests[0].url, `/movements/${description === 'Entrada' ? 'income' : 'fixed'}`);
        assert.equal(h.requests[0].method, 'PUT');
        const payload = JSON.parse(h.requests[0].body);
        assert.equal(payload.description, description);
        assert.equal(payload.amount, description === 'Entrada' ? '30.10' : '12.40');
        assert.equal(payload.movement_date, '2026-12-05');
        if (type === 'expense') {
            assert.equal(payload.expense_behavior, behavior);
        } else {
            assert.equal('expense_behavior' in payload, false, 'o comportamento fixo/variável só se aplica a despesas');
        }
        assert.equal('source_account_id' in payload, false, 'a conta mantém-se inalterada');
        assert.equal('destination_account_id' in payload, false, 'a conta mantém-se inalterada');
        assert.match(h.el('movementEditError').textContent, /Operação não permitida/);
    }
});

test('movimento liquidado com obrigação reconciliável permite edição e liquidado sem origem continua bloqueado', async () => {
    const h = setup();
    h.openItem('Liquidação Freelance');
    assert.equal(h.el('routeMovementDetailsEdit').disabled, false);
    assert.equal(h.el('routeEditHelp').textContent, '');
    h.el('routeMovementDetailsEdit').click();
    assert.equal(h.modals.get('movementEditModal').shown, 1);
    assert.equal(h.el('movementEditDescription').value, 'Liquidação Freelance');
    h.openItem('Entrada liquidada');
    assert.equal(h.el('routeMovementDetailsEdit').disabled, true);
    assert.match(h.el('routeEditHelp').textContent, /não admite edição direta/);
    h.openItem('Fatura prevista');
    assert.equal(h.el('routeMovementDetailsEdit').disabled, true);
    assert.match(h.el('routeEditHelp').textContent, /automaticamente/);
    h.openItem('Diminuição');
    h.el('routeMovementDetailsDelete').click();
    assert.equal(h.el('routeConfirmTitle').textContent, 'Eliminar movimento?');
    assert.equal(h.el('routeConfirmText').textContent, 'Este movimento será eliminado e os saldos das suas contas serão atualizados.');
    assert.equal(h.el('routeConfirmHint').textContent, 'Esta ação não pode ser desfeita.');
    assert.equal(h.el('routeConfirmAction').textContent, 'Eliminar');
    h.el('routeConfirmAction').click();
    await flush();
    assert.equal(h.requests[0].url, '/movements/minus');
    assert.equal(h.requests[0].method, 'DELETE');
    assert.equal(h.el('routeConfirmText').textContent, 'Operação não permitida');
    assert.equal(h.el('routeConfirmHint').hidden, true);
});

test('a Rota Financeira fecha o modal do dia antes de abrir os detalhes', () => {
    const h = setup();
    h.openDay();
    const day = h.modals.get('routeDayModal');
    const details = h.modals.get('routeMovementDetailModal');
    const row = h.rows()[0];
    row.click();
    assert.equal(day.hidden, 1);
    assert.equal(details.shown, 1);
    assert.equal(h.el('routeDayModal').classList.contains('show'), false);
    assert.equal(h.el('routeMovementDetailModal').classList.contains('show'), true);
});

test('Rota Financeira usa a regra partilhada e transmite os metadados de origem', () => {
    const h = setup();
    const canEdit = h.window.meuBolsoMovementEditRules.canEdit;
    const valid = { type: 'expense', status: 'pending', financialMovementId: 'real-1' };
    assert.equal(canEdit({ ...valid, invoiceId: 'invoice-1' }), false);
    assert.equal(canEdit({ ...valid, installmentId: 'installment-1', debtId: 'debt-1' }), false);
    assert.equal(canEdit(valid), true);
    assert.match(blade, /invoiceId:item\?\.invoice_id/);
    assert.match(blade, /installmentId:item\?\.installment_id,debtId:item\?\.debt_id/);

    const movement = { ...base, id: 'real-2', type: 'expense', status: 'pending', description: 'Editável', amount: 8, projection_delta: -8, account_id: 'bank', account_name: 'Conta principal' };
    h.window.document.getElementById('route-day-2026-12-05').textContent = JSON.stringify({ items: [movement], balance: 1, status: 'comfortable' });
    h.openItem('Editável');
    assert.equal(h.el('routeMovementDetailsEdit').disabled, false);
    h.window.close();
});

test('Rota Financeira aguarda hidden quando os detalhes já estão em transição de fecho', () => {
    const h = setup();
    h.openItem('Entrada');
    const detailsElement = h.el('routeMovementDetailModal');
    const details = h.modals.get('routeMovementDetailModal');
    const editor = h.modals.get('movementEditModal');

    details._isTransitioning = true;
    detailsElement.classList.remove('show');
    h.el('routeMovementDetailsEdit').click();
    assert.equal(editor.shown, 0);

    details._isTransitioning = false;
    detailsElement.dispatchEvent(new h.window.Event('hidden.bs.modal'));
    assert.equal(editor.shown, 1);
    assert.equal(h.el('movementEditDescription').value, 'Entrada');
    h.window.close();
});

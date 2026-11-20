import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const script = readFileSync(new URL('../../public/js/dashboard-home.js', import.meta.url), 'utf8');

function setup() {
    const dom = new JSDOM(`<!doctype html>
        <form id="dashboardMonthForm">
            <input type="hidden" id="dashboardMonth" name="mes" value="2026-11">
        </form>
        <button id="openDashboardMonthPicker"></button>
        <div id="dashboardMonthPicker"></div>
        <strong id="dashboardMonthYear"></strong>
        <div id="dashboardMonthGrid"></div>
        <button id="dashboardMonthPreviousYear"></button>
        <button id="dashboardMonthNextYear"></button>
        <button id="applyDashboardMonth"></button>
        <span id="dashboardMonthLabel">Novembro 2026</span>
        <div id="mobileNewMenu" class="show"></div>
        <a id="mobileExpenseAction" data-dashboard-new-movement="despesa" href="/dashboard/movimentos"></a>
        <a id="mobileIncomeAction" data-dashboard-new-movement="receita" href="/dashboard/movimentos"></a>
        <button id="quickExpenseButton"></button>
        <button id="quickIncomeButton"></button>
        <div id="movementEntryModal"></div>`, {
        url: 'https://example.test/dashboard?mes=2026-11',
        runScripts: 'outside-only',
    });
    const { window } = dom;
    let hidden = 0;
    let submitted = false;
    let offcanvasHidden = 0;
    window.bootstrap = {
        Offcanvas: {
            getOrCreateInstance() {
                return { hide() {
                    offcanvasHidden++;
                    window.document.getElementById('mobileNewMenu').classList.remove('show');
                    window.document.getElementById('mobileNewMenu').dispatchEvent(new window.Event('hidden.bs.offcanvas'));
                } };
            },
        },
        Modal: {
            getOrCreateInstance() {
                return { hide() { hidden++; } };
            },
        },
    };
    const form = window.document.getElementById('dashboardMonthForm');
    form.requestSubmit = () => { submitted = true; };
    window.eval(script);
    return {
        dom,
        window,
        hidden: () => hidden,
        offcanvasHidden: () => offcanvasHidden,
        submitted: () => submitted,
        element: (id) => window.document.getElementById(id),
    };
}

test('month picker renders all 12 months and marks the current selection', () => {
    const ui = setup();
    ui.element('dashboardMonthPicker').dispatchEvent(new ui.window.Event('show.bs.modal'));
    assert.equal(ui.element('dashboardMonthGrid').children.length, 12);
    assert.equal(ui.element('dashboardMonthYear').textContent, '2026');
    const selected = [...ui.element('dashboardMonthGrid').children].filter(
        (button) => button.getAttribute('aria-pressed') === 'true',
    );
    assert.equal(selected.length, 1);
    assert.equal(selected[0].dataset.month, '11');
    ui.dom.window.close();
});

test('year navigation and month selection apply only after confirmation', () => {
    const ui = setup();
    ui.element('dashboardMonthPicker').dispatchEvent(new ui.window.Event('show.bs.modal'));
    ui.element('dashboardMonthPreviousYear').click();
    assert.equal(ui.element('dashboardMonthYear').textContent, '2025');

    const march = [...ui.element('dashboardMonthGrid').children]
        .find((button) => button.dataset.month === '03');
    march.click();

    // A seleção provisória não altera o filtro ativo antes de Aplicar.
    assert.equal(ui.element('dashboardMonth').value, '2026-11');
    ui.element('applyDashboardMonth').click();

    assert.equal(ui.element('dashboardMonth').value, '2025-03');
    assert.equal(ui.element('dashboardMonthLabel').textContent, 'Março de 2025');
    assert.equal(ui.submitted(), true);
    assert.equal(ui.hidden(), 1);
    ui.dom.window.close();
});

test('next-year navigation allows selecting a month in a future year', () => {
    const ui = setup();
    ui.element('dashboardMonthPicker').dispatchEvent(new ui.window.Event('show.bs.modal'));
    ui.element('dashboardMonthNextYear').click();
    assert.equal(ui.element('dashboardMonthYear').textContent, '2027');

    [...ui.element('dashboardMonthGrid').children]
        .find((button) => button.dataset.month === '01').click();
    ui.element('applyDashboardMonth').click();

    assert.equal(ui.element('dashboardMonth').value, '2027-01');
    assert.equal(ui.submitted(), true);
    ui.dom.window.close();
});

test('mobile bottom-bar menu opens the movement modal without navigating away', () => {
    const ui = setup();
    let expenseOpened = 0;
    ui.element('quickExpenseButton').addEventListener('click', () => { expenseOpened++; });
    const event = new ui.window.MouseEvent('click', { bubbles: true, cancelable: true });
    ui.element('mobileExpenseAction').dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(ui.offcanvasHidden(), 1);
    assert.equal(expenseOpened, 1);
    assert.equal(ui.window.location.pathname, '/dashboard');
    ui.dom.window.close();
});

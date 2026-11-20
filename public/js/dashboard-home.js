(() => {
    const monthInput = document.getElementById('dashboardMonth');
    const monthLabel = document.getElementById('dashboardMonthLabel');
    const monthGrid = document.getElementById('dashboardMonthGrid');
    const yearLabel = document.getElementById('dashboardMonthYear');
    const monthPickerElement = document.getElementById('dashboardMonthPicker');
    const monthPicker = monthPickerElement && window.bootstrap?.Modal
        ? window.bootstrap.Modal.getOrCreateInstance(monthPickerElement)
        : null;
    const monthNames = Array.from({ length: 12 }, (_, index) =>
        new Intl.DateTimeFormat('pt-PT', { month: 'long' }).format(new Date(2020, index, 1))
    );
    let draftMonth = monthInput?.value || new Date().toISOString().slice(0, 7);
    let draftYear = Number(draftMonth.slice(0, 4));

    function renderMonthPicker() {
        if (!monthGrid || !yearLabel) return;
        yearLabel.textContent = String(draftYear);
        monthGrid.replaceChildren();
        monthNames.forEach((name, index) => {
            const month = String(index + 1).padStart(2, '0');
            const value = String(draftYear) + '-' + month;
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'dashboard-month-option';
            option.textContent = name.charAt(0).toLocaleUpperCase('pt-PT') + name.slice(1);
            option.dataset.month = month;
            option.setAttribute('aria-pressed', String(value === draftMonth));
            option.classList.toggle('is-selected', value === draftMonth);
            option.addEventListener('click', () => {
                draftMonth = value;
                renderMonthPicker();
            });
            monthGrid.append(option);
        });
    }

    monthPickerElement?.addEventListener('show.bs.modal', () => {
        draftMonth = monthInput?.value || new Date().toISOString().slice(0, 7);
        draftYear = Number(draftMonth.slice(0, 4));
        renderMonthPicker();
    });
    document.getElementById('dashboardMonthPreviousYear')?.addEventListener('click', () => {
        draftYear -= 1;
        draftMonth = String(draftYear) + '-' + draftMonth.slice(5, 7);
        renderMonthPicker();
    });
    document.getElementById('dashboardMonthNextYear')?.addEventListener('click', () => {
        draftYear += 1;
        draftMonth = String(draftYear) + '-' + draftMonth.slice(5, 7);
        renderMonthPicker();
    });
    document.getElementById('applyDashboardMonth')?.addEventListener('click', () => {
        if (!monthInput || !/^\d{4}-(0[1-9]|1[0-2])$/.test(draftMonth)) return;
        monthInput.value = draftMonth;
        if (monthLabel) {
            const [year, month] = draftMonth.split('-').map(Number);
            const formatted = new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' })
                .format(new Date(year, month - 1, 1));
            monthLabel.textContent = formatted.charAt(0).toLocaleUpperCase('pt-PT') + formatted.slice(1);
        }
        monthPicker?.hide();
        monthInput.form?.requestSubmit();
    });
    renderMonthPicker();

    const balanceToggle = document.getElementById('toggleBalanceVisibility');
    balanceToggle?.addEventListener('click', () => {
        const hidden = balanceToggle.getAttribute('aria-pressed') !== 'true';
        document.querySelectorAll('.home-sensitive-value').forEach(value => {
            value.textContent = hidden ? value.dataset.hiddenValue : value.dataset.visibleValue;
        });
        balanceToggle.setAttribute('aria-pressed', String(hidden));
        balanceToggle.setAttribute('aria-label', hidden ? 'Mostrar saldos' : 'Ocultar saldos');
        balanceToggle.innerHTML = hidden
            ? '<i data-lucide="eye-off" aria-hidden="true"></i>'
            : '<i data-lucide="eye" aria-hidden="true"></i>';
        window.lucide?.createIcons?.();
    });

    const amount = document.getElementById('conversionAmount');
    const from = document.getElementById('conversionFrom');
    const to = document.getElementById('conversionTo');
    const button = document.getElementById('convertButton');
    const output = document.getElementById('convertedValue');
    const rateLabel = document.getElementById('conversionRate');
    const currency = window.meuBolsoHome?.currency || 'EUR';

    if (!amount || !from || !to || !button || !output || !rateLabel) return;

    to.value = currency;
    from.value = currency === 'USD' ? 'EUR' : 'USD';

    const format = (value, code) => new Intl.NumberFormat('pt-PT', {
        style: 'currency', currency: code
    }).format(value);

    async function convert() {
        const value = Number(amount.value);
        if (!Number.isFinite(value) || value <= 0) {
            output.textContent = '—';
            rateLabel.textContent = 'Introduz um valor válido.';
            return;
        }

        button.disabled = true;
        output.textContent = 'A calcular...';
        rateLabel.textContent = '';

        try {
            if (from.value === to.value) {
                output.textContent = format(value, to.value);
                rateLabel.textContent = 'As moedas selecionadas são iguais.';
                return;
            }

            const response = await fetch('https://api.frankfurter.dev/v2/rate/' + from.value.toLowerCase() + '/' + to.value.toLowerCase());
            if (!response.ok) throw new Error('Não foi possível consultar a taxa de câmbio.');
            const data = await response.json();
            const rate = Number(data.rate);
            if (!Number.isFinite(rate)) throw new Error('A API devolveu uma taxa inválida.');

            output.textContent = format(value * rate, to.value);
            rateLabel.textContent = '1 ' + from.value + ' = ' + rate.toFixed(4) + ' ' + to.value + (data.date ? ' · Taxa de ' + data.date : '');
        } catch (error) {
            output.textContent = 'Erro na conversão';
            rateLabel.textContent = error.message || 'Tenta novamente.';
        } finally {
            button.disabled = false;
        }
    }

    button.addEventListener('click', convert);
    amount.addEventListener('keydown', event => {
        if (event.key === 'Enter') convert();
    });
    document.querySelectorAll('[data-dashboard-new-movement]').forEach(action => {
        action.addEventListener('click', event => {
            const modal = document.getElementById('movementEntryModal');
            if (!modal) return;
            event.preventDefault();
            const type = action.dataset.dashboardNewMovement;
            const openEditor = () => {
                const trigger = document.getElementById(type === 'receita' ? 'quickIncomeButton' : 'quickExpenseButton');
                trigger?.click();
            };
            const menu = document.getElementById('mobileNewMenu');
            if (menu?.classList.contains('show')) {
                menu.addEventListener('hidden.bs.offcanvas', openEditor, { once: true });
                window.bootstrap.Offcanvas.getOrCreateInstance(menu).hide();
            } else {
                openEditor();
            }
        });
    });

    document.getElementById('swapCurrencies')?.addEventListener('click', () => {
        const current = from.value;
        from.value = to.value;
        to.value = current;
        output.textContent = '—';
        rateLabel.textContent = 'Moedas invertidas. Seleciona Converter.';
    });
})();

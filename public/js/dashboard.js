(() => {
    // Usa o fuso horário local do navegador, sem depender do fuso do servidor.
    const element = document.getElementById('currentDateTime');
    if (!element) return;

    const formatter = new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'full',
        timeStyle: 'short'
    });

    const updateClock = () => {
        const now = new Date();
        element.textContent = formatter.format(now);
        element.dateTime = now.toISOString();
        element.title = 'Fuso horário: ' + (Intl.DateTimeFormat().resolvedOptions().timeZone || 'local');
    };

    updateClock();
    window.setInterval(updateClock, 30_000);
})();

(() => {
    const form = document.getElementById('dashboardMovementForm');
    const list = document.getElementById('expensesList');
    if (!form || !list) return;

    const cfg = window.meuBolsoDashboard || {};
    const expenseButton = document.getElementById('expenseTypeButton');
    const incomeButton = document.getElementById('incomeTypeButton');
    const title = document.getElementById('recordTitle');
    const description = document.getElementById('movementDescription');
    const amount = document.getElementById('movementAmount');
    const date = document.getElementById('movementDate');
    const account = document.getElementById('movementAccount');
    const category = document.getElementById('movementCategory');
    const saveButton = document.getElementById('saveMovementButton');
    const method = document.getElementById('movementMethod');
    const cancelButton = document.getElementById('cancelMovementEdit');
    const editorPanel = document.getElementById('recordEditorPanel');
    const editorCard = document.getElementById('movementEntryCard');
    const editorModalElement = document.getElementById('movementEntryModal');
    // O modal fica diretamente no body para não herdar a largura/transformação de colunas ou contentores da página.
    if (editorModalElement && editorModalElement.parentElement !== document.body) {
        document.body.appendChild(editorModalElement);
    }
    const editorModal = editorModalElement ? bootstrap.Modal.getOrCreateInstance(editorModalElement) : null;
    const paymentFields = document.getElementById('dashboardPaymentFields');
    const commitmentOptions = document.getElementById('dashboardCommitmentOptions');
    const paidFields = document.getElementById('dashboardPaidFields');
    const paymentDetails = document.getElementById('dashboardPaymentDetails');
    const monthInput = document.getElementById('periodMonth');
    const movementSearch = document.getElementById('movementSearch');
    const accountFilter = document.getElementById('accountFilter');
    const periodCaption = document.getElementById('periodCaption');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const settleModalElement = document.getElementById('settleMovementModal');
    const settleModal = settleModalElement ? bootstrap.Modal.getOrCreateInstance(settleModalElement) : null;
    const settleForm = document.getElementById('settleMovementForm');
    const deleteModalElement = document.getElementById('deleteMovementModal');
    const deleteModal = deleteModalElement ? bootstrap.Modal.getOrCreateInstance(deleteModalElement) : null;
    const deleteForm = document.getElementById('deleteMovementForm');
    const movementDetailsModalElement = document.getElementById('movementDetailsModal');
    const movementDetailsModal = movementDetailsModalElement ? bootstrap.Modal.getOrCreateInstance(movementDetailsModalElement) : null;
    let actionMovement = null;
    let returnToDetailsAfterCancel = false;

    // O select mantém o valor submetido; o dropdown Bootstrap organiza nome e saldo.
    function enhanceAccountPicker(select) {
        if (!select || select.dataset.pickerEnhanced === '1') return null;
        select.dataset.pickerEnhanced = '1';
        const wrapper = document.createElement('div');
        wrapper.className = 'dropdown account-picker mb-3';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary dropdown-toggle w-100 d-flex align-items-center justify-content-between text-start';
        button.setAttribute('data-bs-toggle', 'dropdown');
        button.setAttribute('aria-expanded', 'false');
        const label = document.createElement('span');
        label.className = 'text-truncate';
        const menu = document.createElement('div');
        menu.className = 'dropdown-menu shadow-sm';
        button.append(label);
        wrapper.append(button, menu);
        select.insertAdjacentElement('afterend', wrapper);
        select.classList.add('account-picker-native');

        const addOption = (option) => {
            if (!option.value) return;
            const parts = option.textContent.split(' · ');
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'dropdown-item';
            item.disabled = option.disabled || option.hidden;
            const line = document.createElement('span');
            line.className = 'account-option';
            const name = document.createElement('span');
            name.className = 'account-name';
            name.textContent = [parts[0], parts[1]].filter(Boolean).join(' · ');
            const balance = document.createElement('span');
            balance.className = 'account-balance' + (/^-/.test(parts.slice(2).join(' · ').trim()) ? ' negative' : '');
            balance.textContent = parts.slice(2).join(' · ');
            line.append(name, balance);
            item.append(line);
            item.addEventListener('click', () => {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                render();
            });
            menu.append(item);
        };
        const render = () => {
            menu.replaceChildren();
            [...select.children].forEach((child) => {
                if (child.tagName === 'OPTGROUP') {
                    const heading = document.createElement('div');
                    heading.className = 'account-group';
                    heading.textContent = child.label;
                    menu.append(heading);
                    [...child.children].forEach(addOption);
                } else if (child.tagName === 'OPTION') addOption(child);
            });
            const selected = select.selectedOptions[0];
            label.textContent = selected?.value ? selected.textContent.split(' · ').slice(0, 2).join(' · ') : 'Selecionar conta';
        };
        select.addEventListener('change', render);
        render();
        return { refresh: render };
    }
    const accountPickers = [
        enhanceAccountPicker(document.getElementById('movementAccount')),
        enhanceAccountPicker(document.getElementById('settleMovementAccount'))
    ].filter(Boolean);

    category?.addEventListener('change', syncExpenseBehaviorFromCategory);
    document.getElementById('dashboardRecurrenceEnabled')?.addEventListener('change', function () {
        const fields = document.getElementById('dashboardRecurrenceFields');
        if (fields) fields.hidden = !this.checked;
        refreshPaymentFields();
    });

    const pageSize = () => window.matchMedia('(max-width: 991.98px)').matches ? 6 : 10;
    let page = 1;
    let visibleCount = pageSize();
    let hasMore = cfg.hasMore === true;
    let loadedMovements = Array.isArray(cfg.initialMovements) ? cfg.initialMovements : [];
    let totalExpenseCount = Number(cfg.initialExpenseCount) || 0;
    let totalIncomeCount = Number(cfg.initialIncomeCount) || 0;
    let offlineMode = false;
    let movementType = 'despesa';
    let typeBeforeEdit = 'despesa';
    let editing = false;
    let requestNumber = 0;
    let cachedMovements = [];
    let totalExpenses = Number(document.getElementById('totalAmount')?.dataset.total || 0);
    let totalIncome = Number(document.getElementById('totalIncome')?.dataset.total || 0);
    let submittingMovement = false;
    let settlingMovement = false;

    function refreshPaymentFields() {
        const isExpense = movementType === 'despesa';
        const paidState = form.querySelector('[name="paid_now"]:checked')?.value || '0';
        const isPaid = paidState === '1';
        const settlementFields = document.getElementById('movementSettlementFields');
        const paymentMethodField = document.getElementById('movementPaymentMethodField');
        const accountLabel = document.getElementById('movementAccountLabel');
        const accountField = document.getElementById('movementAccount');
        const methodField = document.getElementById('dashboardActualPaymentMethod');
        const recurrence = document.getElementById('dashboardRecurrenceEnabled');
        const recurrenceFields = document.getElementById('dashboardRecurrenceFields');
        const installmentsToggle = document.getElementById('dashboardInstallmentsEnabled');
        const installmentsFields = document.getElementById('dashboardInstallmentFields');
        const dueToggle = document.getElementById('dashboardDueDateEnabled');
        const dueField = document.getElementById('dashboardDueDateField');
        const dueInput = document.getElementById('dashboardDueDateInput');
        const hiddenDueDate = document.getElementById('dashboardDueDate');
        const condition = document.getElementById('movementPaymentCondition');
        const plannedMethod = document.getElementById('movementPlannedPaymentMethod');

        const needsAssignedAccount = isPaid || Boolean(recurrence?.checked);
        if (settlementFields) settlementFields.hidden = !needsAssignedAccount;
        if (paymentMethodField) paymentMethodField.hidden = !isPaid;
        if (accountField) accountField.required = false; // A validação é feita no formulário, pois o select nativo está visualmente oculto.
        if (methodField) methodField.required = isPaid;
        if (accountLabel) accountLabel.textContent = isExpense ? 'De onde saiu o dinheiro?' : 'Para onde entrou o dinheiro?';

        // Tipos de conta e métodos permitidos têm de corresponder:
        // banco -> débito/transferência/MB WAY/débito direto;
        // dinheiro -> dinheiro; cartão -> cartão de crédito.
        if (accountField) {
            const accountOptions = [...accountField.options].filter((option) => option.value);
            accountOptions.forEach((option) => {
                const isCreditCard = option.dataset.accountType === 'credit_card';
                option.hidden = !isExpense && isCreditCard;
                option.disabled = !isExpense && isCreditCard;
            });
            accountField.querySelectorAll('optgroup').forEach((group) => {
                group.hidden = [...group.querySelectorAll('option')].every((option) => option.hidden);
            });
            if (accountField.selectedOptions[0]?.disabled) accountField.value = '';
            accountPickers.forEach((picker) => picker.refresh());
        }
        if (methodField && accountField) {
            const selectedType = accountField.selectedOptions[0]?.dataset.accountType || '';
            [...methodField.options].forEach((option) => {
                if (!option.value) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }
                const matchesAccount = option.dataset.accountType === selectedType;
                option.hidden = !matchesAccount;
                option.disabled = !matchesAccount;
            });
            if (methodField.selectedOptions[0]?.disabled) methodField.value = '';
            if (selectedType === 'cash') methodField.value = 'cash';
            if (selectedType === 'credit_card') methodField.value = 'credit';
        }
        // Um método efetivo não existe enquanto a despesa está pendente.
        // Limpa e desativa o select oculto para não enviar uma seleção antiga.
        if (methodField) {
            methodField.disabled = !isPaid;
            if (!isPaid) methodField.value = '';
        }
        if (plannedMethod && methodField) plannedMethod.value = isPaid ? (methodField.value || '') : '';
        if (recurrenceFields) recurrenceFields.hidden = !recurrence?.checked;
        if (installmentsFields) installmentsFields.hidden = !installmentsToggle?.checked;
        if (dueField) dueField.hidden = !dueToggle?.checked;
        if (dueInput) dueInput.required = Boolean(dueToggle?.checked);
        if (hiddenDueDate) hiddenDueDate.value = dueToggle?.checked ? (dueInput?.value || '') : '';

        if (recurrence && installmentsToggle) {
            // Parcelamentos representam obrigações futuras: só podem ser criados
            // enquanto a despesa estiver pendente. Recorrência continua disponível
            // para movimentos pagos, sem permitir a combinação com prestações.
            installmentsToggle.disabled = !isExpense || isPaid || Boolean(recurrence.checked);
            recurrence.disabled = Boolean(installmentsToggle.checked);

            if (!isExpense || isPaid) {
                installmentsToggle.checked = false;
                if (installmentsFields) installmentsFields.hidden = true;
            }
            if (recurrence.checked && installmentsToggle.checked) {
                installmentsToggle.checked = false;
                if (installmentsFields) installmentsFields.hidden = true;
            }
        }
        // Uma data de vencimento só faz sentido para uma obrigação ainda pendente.
        if (dueToggle && isExpense) {
            dueToggle.disabled = isPaid;
            if (isPaid) {
                dueToggle.checked = false;
                if (dueField) dueField.hidden = true;
                if (dueInput) dueInput.required = false;
                if (hiddenDueDate) hiddenDueDate.value = '';
            }
        }
        if (condition) condition.value = recurrence?.checked ? 'cash' : (installmentsToggle?.checked ? 'installments' : (dueToggle?.checked ? 'deferred' : 'cash'));
        renderInstallmentSchedule();
    }

    function renderInstallmentSchedule() {
        const list = document.getElementById('dashboardInstallmentScheduleList');
        if (!list) return;
        const count = Math.min(120, Math.max(2, Number(document.getElementById('dashboardInstallments')?.value || 2)));
        const normalizedAmount = parseMoney(document.getElementById('movementAmount')?.value || '');
        const amount = normalizedAmount ? Math.round(Number(normalizedAmount) * 100) : 0;
        const dueValue = document.getElementById('dashboardDueDateInput')?.value || document.getElementById('movementDate')?.value;
        const frequency = document.getElementById('dashboardInstallmentFrequency')?.value || 'monthly';
        if (!dueValue || !amount) {
            list.replaceChildren();
            return;
        }

        const base = Math.floor(amount / count);
        const remainder = amount % count;
        const start = new Date(dueValue + 'T12:00:00');
        const formatter = new Intl.DateTimeFormat('pt-PT');
        const dueDateAt = (index) => {
            const due = new Date(start);
            if (frequency === 'weekly') due.setDate(due.getDate() + index * 7);
            else if (frequency === 'biweekly') due.setDate(due.getDate() + index * 14);
            else {
                const day = due.getDate();
                due.setDate(1);
                due.setMonth(due.getMonth() + index);
                const lastDay = new Date(due.getFullYear(), due.getMonth() + 1, 0).getDate();
                due.setDate(Math.min(day, lastDay));
            }
            return due;
        };
        const firstDate = dueDateAt(0);
        const lastDate = dueDateAt(count - 1);
        const firstCents = base + (remainder > 0 ? 1 : 0);
        const lastCents = base + (count - 1 < remainder ? 1 : 0);
        const summary = document.createElement('div');
        summary.className = 'small text-secondary';
        summary.innerHTML = '<strong>' + count + ' prestações</strong> · ' + formatter.format(firstDate) + ' a ' + formatter.format(lastDate)
            + '<br>Valor: ' + formatCurrency(firstCents / 100, cfg.currency || 'EUR')
            + (lastCents !== firstCents ? ' (última: ' + formatCurrency(lastCents / 100, cfg.currency || 'EUR') + ')' : '')
            + ' · Total: ' + formatCurrency(amount / 100, cfg.currency || 'EUR');
        list.replaceChildren(summary);
    }

    try {
        if (cfg.userId) localStorage.setItem('meu-bolso:active-user', String(cfg.userId));
    } catch (error) {
        console.warn('Não foi possível identificar a sessão neste dispositivo:', error);
    }

    function currentMonthRange() {
        const parts = (monthInput?.value || '').split('-').map(Number);
        const year = parts[0] || new Date().getFullYear();
        const month = parts[1] || new Date().getMonth() + 1;
        const first = new Date(year, month - 1, 1);
        const next = new Date(year, month, 1);
        const iso = (value) => value.getFullYear() + '-' + String(value.getMonth() + 1).padStart(2, '0') + '-' + String(value.getDate()).padStart(2, '0');
        return { start: iso(first), end: iso(next) };
    }

    function localDate() {
        const now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    }

    // Aceita os dois separadores decimais. Agrupamento exige um separador
    // decimal explícito, para não confundir 1.234 com 1,234 ou arredondar.
    function parseMoney(value) {
        const input = String(value || '').trim();
        if (input.length > 24) return null;
        let normalized;
        if (/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/.test(input)) {
            normalized = input.replaceAll('.', '').replace(',', '.');
        } else if (/^\d{1,3}(?:,\d{3})+\.\d{1,2}$/.test(input)) {
            normalized = input.replaceAll(',', '');
        } else if (/^(?:\d+(?:[.,]\d{1,2})?|[.,]\d{1,2})$/.test(input)) {
            normalized = input.replace(',', '.');
        } else {
            return null;
        }
        const [whole, fraction = ''] = normalized.split('.');
        const cents = BigInt(whole || '0') * 100n + BigInt(fraction.padEnd(2, '0') || '0');
        if (cents <= 0n || cents > 999999999999n) return null;
        return String(cents / 100n) + '.' + String(cents % 100n).padStart(2, '0');
    }

    function formatCurrency(value, currency) {
        return new Intl.NumberFormat('pt-PT', {
            style: 'currency',
            currency: currency || cfg.currency || 'EUR'
        }).format(Number(value) || 0);
    }

    function updateSummary(expenses, income) {
        totalExpenses = Number(expenses) || 0;
        totalIncome = Number(income) || 0;
        const currency = cfg.currency || 'EUR';
        const expenseElement = document.getElementById('totalAmount');
        const incomeElement = document.getElementById('totalIncome');
        const balanceElement = document.getElementById('balanceAmount');
        if (expenseElement) expenseElement.textContent = formatCurrency(totalExpenses, currency);
        if (incomeElement) incomeElement.textContent = formatCurrency(totalIncome, currency);
        if (balanceElement) balanceElement.textContent = formatCurrency(totalIncome - totalExpenses, currency);
    }

    function updatePeriodCaption() {
        const parts = monthInput.value.split('-').map(Number);
        if (!parts[0] || !parts[1]) return;

        const formattedPeriod = new Intl.DateTimeFormat('pt-PT', {
            month: 'long',
            year: 'numeric'
        }).format(new Date(parts[0], parts[1] - 1, 1));

        if (periodCaption) periodCaption.textContent = formattedPeriod;

        const summaryPeriod = document.getElementById('movementsSummaryPeriod');
        if (summaryPeriod) summaryPeriod.textContent = 'Resumo de ' + formattedPeriod;
    }

    function showStatus(message) {
        const status = document.getElementById('offlineStatus');
        if (!status) return;
        status.textContent = message || '';
        status.classList.toggle('d-none', !message);
    }

    function showMovementError(message) {
        const errorBox = document.getElementById('movementFormError');
        if (!errorBox) {
            if (message) alert(message);
            return;
        }
        errorBox.textContent = message || '';
        errorBox.classList.toggle('d-none', !message);
        if (message) errorBox.focus();
    }

    function showMovementConfirmation(message, isInstallment, warning = false) {
        const feedback = document.getElementById('movementSaveFeedback');
        const feedbackMessage = document.getElementById('movementSaveFeedbackMessage');
        const link = document.getElementById('movementSaveFeedbackLink');
        if (!feedback || !feedbackMessage) return;
        feedbackMessage.textContent = message + ' ';
        feedback.classList.remove('d-none', 'alert-success', 'alert-warning');
        feedback.classList.add(warning ? 'alert-warning' : 'alert-success');
        link?.classList.toggle('d-none', !isInstallment);
    }

    function installmentConfirmation(values) {
        const due = String(values.due_date || values.expense_date || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        const dateLabel = due ? due[3] + '/' + due[2] + '/' + due[1] : 'a data indicada';
        const prefix = 'Parcelamento criado com ' + values.installments_count + ' prestações. Primeira prestação: ' + dateLabel + '. ';
        return prefix + 'Em Movimentos, procura pelo mês do vencimento e verifica os filtros.';
    }

    function cacheKey() {
        const range = currentMonthRange();
        return 'meu-bolso:v2:' + cfg.userId + ':' + range.start + ':' + range.end + ':' + (cfg.category || 'all');
    }

    function saveCache() {
        if (!cfg.userId || !navigator.onLine) return;
        const expenses = loadedMovements.filter((item) => item.tipo === 'despesa').map((item) => ({
            id: item.id,
            description: item.description,
            amount: Number(item.amount),
            currency: item.currency,
            category: item.category,
            expense_date: item.data_movimento
        }));
        const incomes = loadedMovements.filter((item) => item.tipo === 'receita').map((item) => ({
            id: item.id,
            description: item.description,
            amount: Number(item.amount),
            currency: item.currency,
            category: item.category,
            income_date: item.data_movimento
        }));

        try {
            localStorage.setItem(cacheKey(), JSON.stringify({
                guardadoEm: new Date().toISOString(),
                despesas: expenses,
                receitas: incomes,
                totalDespesasPeriodo: totalExpenses,
                totalReceitasPeriodo: totalIncome,
                quantidadeDespesasFiltradas: totalExpenseCount,
                quantidadeReceitasFiltradas: totalIncomeCount,
                moeda: cfg.currency || 'EUR',
                nome: cfg.name || 'Utilizador'
            }));
        } catch (error) {
            console.warn('Não foi possível guardar os dados localmente:', error);
        }
    }

    function categoryName(item) {
        const map = item.tipo === 'receita' ? cfg.incomeCategories : cfg.expenseCategories;
        return item.category_label || (map && map[item.category]) || 'Outros';
    }

    function renderItem(item) {
        const isExpense = item.tipo === 'despesa';
        const movementDate = item.data_movimento || item.expense_date || item.income_date;
        const dateMatch = String(movementDate || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        let normalizedDate = '';
        let displayDate = 'Data não disponível';

        if (dateMatch) {
            const year = Number(dateMatch[1]);
            const month = Number(dateMatch[2]);
            const day = Number(dateMatch[3]);
            const calendarDate = new Date(year, month - 1, day);
            if (calendarDate.getFullYear() === year && calendarDate.getMonth() === month - 1 && calendarDate.getDate() === day) {
                normalizedDate = dateMatch[1] + '-' + dateMatch[2] + '-' + dateMatch[3];
                displayDate = new Intl.DateTimeFormat('pt-PT').format(calendarDate);
            }
        }

        const article = document.createElement('article');
        article.className = 'expense-item movement-entry py-3 border-bottom';
        article.tabIndex = 0;
        article.setAttribute('role', 'group');
        article.setAttribute('aria-label', 'Abrir detalhes de ' + item.description);
        article.dataset.movementId = item.id;
        article.dataset.financialMovementId = item.financial_movement_id || '';
        article.dataset.movementType = item.tipo;
        article.dataset.editUrl = (isExpense ? cfg.expenseUpdateBase : cfg.incomeUpdateBase) + '/' + encodeURIComponent(item.id);
        article.dataset.date = normalizedDate;
        article.dataset.description = item.description;
        article.dataset.amount = item.amount;
        article.dataset.remainingAmount = item.remaining_amount ?? item.amount;
        article.dataset.currency = item.currency || cfg.currency || 'EUR';
        article.dataset.categoryId = item.category;
        article.dataset.accountId = item.account_id || '';
        article.dataset.expenseBehavior = item.expense_behavior || 'variable';
        article.dataset.movementStatus = item.movement_status || '';
        article.dataset.isInvoicePayment = item.is_invoice_payment ? '1' : '0';
        article.dataset.isSettlementEvent = item.is_settlement_event ? '1' : '0';
        article.dataset.dueDate = item.due_date || '';
        article.dataset.recurrenceId = item.recurrence_id || '';
        article.dataset.recurrenceFrequency = item.recurrence_frequency || '';
        article.dataset.recurrenceInterval = item.recurrence_interval || '';
        article.dataset.recurrenceEndsAt = item.recurrence_ends_at || '';
        article.dataset.installmentId = item.installment_id || '';
        article.dataset.debtId = item.debt_id || '';
        article.dataset.invoiceId = item.invoice_id || '';
        article.dataset.invoiceReferenceMonth = item.invoice_reference_month || '';
        article.dataset.invoiceDueDate = item.invoice_due_date || '';
        article.dataset.invoiceStatus = item.invoice_status || '';
        article.dataset.invoiceCardName = item.invoice_card_name || '';
        article.dataset.invoiceUrl = item.invoice_id ? '/faturas/' + encodeURIComponent(item.invoice_id) : '';
        article.dataset.isInvoiceCredit = item.category_label === 'Crédito de compra' ? '1' : '0';

        const info = document.createElement('div');
        info.className = 'movement-entry-main';
        const icon = document.createElement('div');
        icon.className = isExpense ? 'expense-icon flex-shrink-0' : 'expense-icon income-icon flex-shrink-0';
        icon.setAttribute('aria-hidden', 'true');
        icon.style.setProperty('--category-color', item.category_color || '#635BFF');
        icon.innerHTML = '<i data-lucide="' + (item.category_icon || 'tag') + '"></i>';

        const details = document.createElement('div');
        details.className = 'movement-entry-copy';
        const name = document.createElement('h3');
        name.className = 'movement-entry-title';
        name.textContent = item.description;
        const metadata = document.createElement('p');
        metadata.className = 'movement-entry-meta';
        metadata.textContent = categoryName(item) + ' · ' + displayDate;
        details.append(name, metadata);

        const originalAmount = Math.max(0, Number(item.amount) || 0);
        const remainingAmount = Math.max(0, Number(item.remaining_amount ?? item.amount) || 0);
        const paidAmount = Math.max(0, originalAmount - remainingAmount);
        const isPartial = ['partial', 'partially_settled'].includes(item.movement_status);
        if (isPartial) {
            const settlementSummary = document.createElement('div');
            settlementSummary.className = 'movement-settlement-summary';
            const paidLabel = document.createElement('span');
            paidLabel.textContent = (isExpense ? 'Pago: ' : 'Recebido: ') + formatCurrency(paidAmount, item.currency || cfg.currency || 'EUR');
            const pendingLabel = document.createElement('span');
            pendingLabel.textContent = (isExpense ? 'Pendente: ' : 'Por receber: ') + formatCurrency(remainingAmount, item.currency || cfg.currency || 'EUR');
            settlementSummary.append(paidLabel, pendingLabel);

            const progress = document.createElement('div');
            progress.className = 'movement-progress';
            progress.style.height = '3px';
            progress.setAttribute('role', 'progressbar');
            progress.setAttribute('aria-label', 'Percentagem do movimento paga');
            progress.setAttribute('aria-valuemin', '0');
            progress.setAttribute('aria-valuemax', '100');
            const paidPercentage = originalAmount > 0 ? Math.min(100, Math.max(0, paidAmount / originalAmount * 100)) : 0;
            progress.setAttribute('aria-valuenow', String(Math.round(paidPercentage)));
            const fill = document.createElement('span');
            fill.style.width = paidPercentage + '%';
            progress.append(fill);
            details.append(settlementSummary, progress);
        }

        const tags = document.createElement('div');
        tags.className = 'movement-entry-tags';
        const dueDateMatch = String(item.due_date || '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        let isOverdue = false;
        if (isExpense && dueDateMatch && item.movement_status !== 'settled') {
            const dueDate = new Date(Number(dueDateMatch[1]), Number(dueDateMatch[2]) - 1, Number(dueDateMatch[3]));
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            dueDate.setHours(0, 0, 0, 0);
            isOverdue = dueDate < today;
        }
        const statusLabels = {
            settled: { text: isExpense ? 'Pago' : 'Recebido', className: 'is-paid' },
            partial: { text: isExpense ? 'Parcial' : 'Recebido parcial', className: 'is-partial' },
            partially_settled: { text: isExpense ? 'Parcial' : 'Recebido parcial', className: 'is-partial' },
            pending: { text: isExpense ? 'Pendente' : 'Por receber', className: 'is-pending' }
        };
        // Créditos/devoluções e encargos de fatura já são identificados pela sua natureza.
        // Mostrar também "Pago" não acrescenta informação à listagem.
        const invoiceAdjustmentLabel = [item.category_label, item.description].filter(Boolean).join(' ');
        const isSelfExplanatoryInvoiceAdjustment = Boolean(item.invoice_id)
            && /cr[eé]dito|devolu[cç][aã]o|juros|encargos?/i.test(invoiceAdjustmentLabel);
        const status = isOverdue
            ? { text: 'Em atraso', className: 'is-overdue' }
            : (item.movement_status === 'settled' && isSelfExplanatoryInvoiceAdjustment
                ? null
                : statusLabels[item.movement_status]);
        if (status) {
            const badge = document.createElement('span');
            badge.className = 'movement-status-badge ' + status.className;
            badge.textContent = status.text;
            tags.append(badge);
        }
        if (item.recurrence_id) {
            const recurrence = document.createElement('span');
            recurrence.className = 'movement-meta-tag';
            const frequencies = { weekly: 'Semanal', monthly: 'Mensal', yearly: 'Anual' };
            const frequencyLabel = frequencies[item.recurrence_frequency] || 'Recorrente';
            const pluralFrequencies = { weekly: 'semanas', monthly: 'meses', yearly: 'anos' };
            const interval = Number(item.recurrence_interval) || 1;
            recurrence.innerHTML = '<i data-lucide="repeat" aria-hidden="true"></i><span>' + (interval > 1 ? 'A cada ' + interval + ' ' + (pluralFrequencies[item.recurrence_frequency] || 'períodos') : frequencyLabel) + '</span>';
            tags.append(recurrence);
        }
        if (item.installment_id) {
            const installment = document.createElement('span');
            installment.className = 'movement-meta-tag';
            installment.innerHTML = '<i data-lucide="layers" aria-hidden="true"></i><span>Prestação</span>';
            tags.append(installment);
        }
        if (tags.childElementCount) details.append(tags);
        info.append(icon, details);

        const trailing = document.createElement('div');
        trailing.className = 'movement-entry-trailing expense-actions';
        const value = document.createElement('strong');
        const isInvoiceCredit = item.category_label === 'Crédito de compra';
        value.className = 'movement-entry-amount ' + (isExpense && !isInvoiceCredit ? 'is-expense' : 'is-income');
        value.dataset.value = item.amount;
        value.dataset.currency = item.currency || cfg.currency || 'EUR';
        value.textContent = (isExpense && !isInvoiceCredit ? '−' : '+') + formatCurrency(originalAmount, item.currency || cfg.currency || 'EUR');

        const detailsButton = document.createElement('button');
        detailsButton.type = 'button';
        detailsButton.className = 'movement-details-trigger';
        detailsButton.dataset.movementAction = 'details';
        detailsButton.setAttribute('aria-label', 'Detalhes de ' + item.description);
        detailsButton.setAttribute('title', 'Detalhes');
        detailsButton.innerHTML = '<i data-lucide="more-horizontal" aria-hidden="true"></i>';
        trailing.append(value, detailsButton);
        article.append(info, trailing);
        return article;
    }

    function renderMovements() {
        const searchTerm = (movementSearch?.value || '').trim().toLocaleLowerCase('pt-PT');
        const filtered = loadedMovements
            .filter((item) => cfg.type === 'todos' || item.tipo === cfg.type)
            .filter((item) => !accountFilter || accountFilter.value === 'all' || String(item.account_id || '') === accountFilter.value)
            .filter((item) => !searchTerm || [
                item.description, item.category_label, item.category, item.currency
            ].some(value => String(value || '').toLocaleLowerCase('pt-PT').includes(searchTerm)))
            .sort((a, b) =>
                String(b.data_movimento).localeCompare(String(a.data_movimento)) ||
                String(a.id).localeCompare(String(b.id))
            );
        const total = cfg.type === 'despesa'
            ? totalExpenseCount
            : cfg.type === 'receita'
                ? totalIncomeCount
                : totalExpenseCount + totalIncomeCount;
        const visible = filtered.slice(0, offlineMode ? pageSize() : visibleCount);
        hasMore = !offlineMode && visible.length < total;

        list.replaceChildren();
        if (visible.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state text-center py-4';
            empty.innerHTML = '<div class="empty-icon mb-3" aria-hidden="true">◎</div><h3 class="h6">Sem movimentos</h3><p class="small text-secondary mb-0"></p>';
            list.append(empty);
        } else {
            let currentDay = '';
            visible.forEach((item) => {
                const day = String(item.data_movimento || '').slice(0, 10);
                if (day !== currentDay) {
                    currentDay = day;
                    const heading = document.createElement('div');
                    heading.className = 'movement-day-heading d-flex justify-content-between align-items-center';
                    const match = day.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    if (match) {
                        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
                        const dateLabel = document.createElement('span');
                        dateLabel.className = 'fw-semibold';
                        dateLabel.textContent = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: 'long' }).format(date);
                        const weekdayLabel = document.createElement('span');
                        weekdayLabel.className = 'small text-secondary fw-normal';
                        weekdayLabel.textContent = new Intl.DateTimeFormat('pt-PT', { weekday: 'long' }).format(date);
                        heading.append(dateLabel, weekdayLabel);
                    } else {
                        heading.textContent = 'Data não disponível';
                    }
                    list.append(heading);
                }
                list.append(renderItem(item));
            });
        }

        const controls = document.createElement('div');
        controls.className = 'pt-3 pb-4';
        const count = document.createElement('p');
        count.className = 'small text-secondary text-center mb-0';
        count.setAttribute('aria-live', 'polite');
        count.textContent = 'A mostrar ' + visible.length + ' de ' + total + ' ' + (total === 1 ? 'movimento' : 'movimentos');
        controls.append(count);

        if (hasMore) {
            const buttonContainer = document.createElement('div');
            buttonContainer.className = 'd-flex justify-content-center pt-3';
            const button = document.createElement('button');
            button.type = 'button';
            button.id = 'loadMoreMovements';
            button.className = 'btn btn-outline-primary btn-sm px-4';
            button.textContent = 'Ver mais movimentos';
            buttonContainer.append(button);
            controls.append(buttonContainer);
        }

        list.append(controls);
        saveCache();
    }

    function syncExpenseBehaviorFromCategory() {
        const behavior = document.getElementById('movementExpenseBehavior');
        if (!behavior) return;
        if (movementType !== 'despesa' || !category?.value) {
            behavior.value = 'variable';
            return;
        }
        const configured = cfg.expenseCategoryBehaviors?.[category.value];
        behavior.value = configured === 'fixed' ? 'fixed' : 'variable';
    }

    function setMovementType(type) {
        movementType = type;
        const income = type === 'receita';
        const isEditing = editing;

        expenseButton.disabled = isEditing && income;
        incomeButton.disabled = isEditing && !income;
        expenseButton.classList.toggle('active', !income);
        expenseButton.classList.toggle('btn-danger', !income);
        expenseButton.classList.toggle('btn-outline-danger', income);
        incomeButton.classList.toggle('active', income);
        incomeButton.classList.toggle('btn-success', income);
        incomeButton.classList.toggle('btn-outline-success', !income);
        expenseButton.setAttribute('aria-pressed', String(!income));
        incomeButton.setAttribute('aria-pressed', String(income));

        title.textContent = isEditing ? ('Editar ' + (income ? 'receita' : 'despesa')) : 'Novo movimento';
        saveButton.textContent = isEditing ? ('Atualizar ' + (income ? 'receita' : 'despesa')) : 'Guardar movimento';
        saveButton.className = 'btn w-100 ' + (income ? 'btn-success' : 'btn-danger');
        date.name = income ? 'income_date' : 'expense_date';
        form.action = isEditing
            ? form.action
            : (income ? cfg.incomeStore : cfg.expenseStore);

        category.replaceChildren(new Option('Seleciona uma categoria', ''));
        const options = income ? cfg.incomeCategories : cfg.expenseCategories;
        Object.entries(options || {}).forEach(([key, label]) => category.add(new Option(label, key)));
        category.disabled = false;
        if (account) account.disabled = false;
        syncExpenseBehaviorFromCategory();
        cancelButton?.classList.remove('d-none');
        refreshPaymentFields();
    }

    function openEditor() { editorModal?.show(); }
    function closeEditor() { editorModal?.hide(); }

    function resetEditor(keepType = true) {
        const restoredType = keepType ? movementType : 'despesa';
        editing = false;
        method.value = 'POST';
        form.reset();
        date.value = localDate();
        amount.readOnly = false;
        date.readOnly = false;
        form.action = restoredType === 'receita' ? cfg.incomeStore : cfg.expenseStore;
        setMovementType(restoredType);
        refreshPaymentFields();
        cancelButton?.classList.add('d-none');
        closeEditor();
    }

    async function fetchMovements(options = {}) {
        const currentRequest = ++requestNumber;
        const selectedPage = options.page || 1;
        const params = new URLSearchParams({
            mes: monthInput.value,
            tipo: 'todos',
            categoria: cfg.category || 'all',
            page: String(selectedPage),
            limit: String(pageSize())
        });
        const response = await fetch(cfg.movementsUrl + '?' + params.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        });
        if (!response.ok) throw new Error('Não foi possível carregar os movimentos.');
        const result = await response.json();
        if (currentRequest !== requestNumber) return null;
        return result;
    }

    async function refreshDashboard() {
        const result = await fetchMovements({ page: 1 });
        if (!result) return;
        page = 1;
        visibleCount = pageSize();
        offlineMode = false;
        loadedMovements = result.data || [];
        totalExpenseCount = Number(result.total_despesas_filtradas) || 0;
        totalIncomeCount = Number(result.total_receitas_filtradas) || 0;
        updateSummary(result.total_despesas, result.total_receitas);
        updatePeriodCaption();
        renderMovements();
    }

    async function loadMore() {
        if (!hasMore || !navigator.onLine) return;
        visibleCount += pageSize();

        const filteredLoaded = loadedMovements.filter((item) =>
            cfg.type === 'todos' || item.tipo === cfg.type
        );
        const button = document.getElementById('loadMoreMovements');

        if (filteredLoaded.length < visibleCount) {
            if (button) {
                button.disabled = true;
                button.textContent = 'A carregar…';
            }

            try {
                const result = await fetchMovements({ page: page + 1 });
                if (!result) return;
                loadedMovements = loadedMovements.concat(result.data || []);
                page = result.page;
                totalExpenseCount = Number(result.total_despesas_filtradas) || totalExpenseCount;
                totalIncomeCount = Number(result.total_receitas_filtradas) || totalIncomeCount;
            } catch (error) {
                visibleCount -= pageSize();
                alert('Não foi possível carregar mais movimentos.');
            } finally {
                if (button) button.disabled = false;
            }
        }

        renderMovements();
    }

    async function applyFilters(event) {
        event?.preventDefault();
        const filterForm = document.querySelector('.transaction-filters');
        const submitter = event?.submitter;
        const previousCategory = cfg.category;
        if (submitter?.name === 'tipo') {
            cfg.type = submitter.value;
            visibleCount = pageSize();
        }

        const selectedCategory = document.getElementById('categoryFilter')?.value || 'all';
        cfg.category = selectedCategory;

        const hiddenType = filterForm?.querySelector('input[name="tipo"]');
        if (hiddenType) hiddenType.value = cfg.type;

        filterForm?.querySelectorAll('button[name="tipo"]').forEach((button) => {
            const active = button.value === cfg.type;
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-link', !active);
            button.classList.toggle('text-decoration-none', !active);
            button.setAttribute('aria-pressed', String(active));
        });

        if (previousCategory !== selectedCategory) {
            try {
                await refreshDashboard();
            } catch (error) {
                handleSyncFailure(navigator.onLine
                    ? 'Não foi possível sincronizar os dados com o servidor.'
                    : 'Sem ligação à internet.');
            }
        } else {
            renderMovements();
        }
    }

    function setMonth(value) {
        if (!value) return;
        monthInput.value = value;
        refreshDashboard().catch(() => handleSyncFailure(navigator.onLine
            ? 'Não foi possível sincronizar os dados com o servidor.'
            : 'Sem ligação à internet.'));
    }

    function changeMonth(offset) {
        const parts = monthInput.value.split('-').map(Number);
        const next = new Date(parts[0], parts[1] - 1 + offset, 1);
        setMonth(next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0'));
    }

    const editModalElement = document.getElementById('movementEditModal');
    // Mantém o modal fora de contentores que possam criar stacking context.
    if (editModalElement && editModalElement.parentElement !== document.body) {
        document.body.appendChild(editModalElement);
    }
    const editModal = editModalElement ? bootstrap.Modal.getOrCreateInstance(editModalElement) : null;
    const editForm = document.getElementById('movementEditForm');
    let editingMovement = null;

    function startEdit(row) {
        if (row.dataset.movementStatus !== 'pending' || !row.dataset.financialMovementId || !editModalElement || !editForm) return;
        returnToDetailsAfterCancel = false;
        if (editModalElement.parentElement !== document.body) document.body.appendChild(editModalElement);
        editingMovement = row;
        const isExpense = row.dataset.movementType === 'despesa';
        const editCategory = document.getElementById('movementEditCategory');
        const editTitle = document.getElementById('movementEditTitle');
        editTitle.textContent = isExpense ? 'Editar despesa' : 'Editar receita';
        document.getElementById('movementEditDescription').value = row.dataset.description || '';
        document.getElementById('movementEditNotes').value = row.dataset.notes || '';
        document.getElementById('movementEditAmount').value = String(row.dataset.amount || '').replace('.', ',');
        document.getElementById('movementEditDate').value = row.dataset.date || localDate();
        editCategory.replaceChildren(new Option('Seleciona uma categoria', ''));
        Object.entries(isExpense ? (cfg.expenseCategories || {}) : (cfg.incomeCategories || {}))
            .forEach(([id, label]) => editCategory.add(new Option(label, id)));
        editCategory.value = row.dataset.categoryId || '';
        document.getElementById('movementEditExpenseBehavior').value = row.dataset.expenseBehavior || 'variable';
        document.getElementById('movementEditError').classList.add('d-none');
        editModal?.show();
    }

    async function submitEditMovement(event) {
        event.preventDefault();
        if (!editingMovement || !editForm || editForm.dataset.submitting === '1') return;
        const submit = document.getElementById('movementEditSubmit');
        const error = document.getElementById('movementEditError');
        const amountValue = parseMoney(document.getElementById('movementEditAmount').value);
        if (!amountValue) {
            error.textContent = 'Introduz um valor válido, superior a zero e com até duas casas decimais.';
            error.classList.remove('d-none');
            return;
        }
        if (!editForm.reportValidity()) return;
        editForm.dataset.submitting = '1';
        submit.disabled = true;
        submit.textContent = 'A guardar…';
        error.classList.add('d-none');
        const isExpense = editingMovement.dataset.movementType === 'despesa';
        const payload = {
            description: document.getElementById('movementEditDescription').value.trim(),
            notes: document.getElementById('movementEditNotes').value.trim() || null,
            amount: amountValue,
            movement_date: document.getElementById('movementEditDate').value,
            category_id: document.getElementById('movementEditCategory').value || null,
            ...(isExpense ? { expense_behavior: document.getElementById('movementEditExpenseBehavior').value } : {})
        };
        try {
            const response = await fetch(cfg.financialMovementBase + '/' + encodeURIComponent(editingMovement.dataset.financialMovementId), {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível atualizar o movimento.');
            editModal?.hide();
            editingMovement = null;
            await refreshDashboard();
        } catch (err) {
            error.textContent = err.message || 'Não foi possível atualizar o movimento.';
            error.classList.remove('d-none');
        } finally {
            editForm.dataset.submitting = '0';
            submit.disabled = false;
            submit.textContent = 'Guardar alterações';
        }
    }

    function cancelEdit() {
        if (!editing) return;
        const restore = typeBeforeEdit;
        editing = false;
        resetEditor(false);
        setMovementType(restore);
    }

    async function submitMovement(event) {
        event.preventDefault();
        if (submittingMovement) return;
        showMovementError('');
        if (!navigator.onLine) {
            showMovementError('Não é possível guardar movimentos sem ligação à internet.');
            return;
        }
        if (document.getElementById('movementSettlementFields') && !document.getElementById('movementSettlementFields').hidden && !account.value) {
            showMovementError('Seleciona a conta do movimento.');
            return;
        }
        if (!form.reportValidity()) return;

        const buttonText = saveButton.textContent;
        const wasEditing = editing;
        let confirmation = null;
        submittingMovement = true;
        saveButton.disabled = true;
        saveButton.textContent = 'A guardar...';
        try {
            const values = Object.fromEntries(new FormData(form).entries());
            const condition = document.getElementById('movementPaymentCondition')?.value || 'cash';
            values.payment_condition = condition;
            values.due_date = document.getElementById('dashboardDueDateEnabled')?.checked ? (document.getElementById('dashboardDueDateInput')?.value || '') : '';
            const paidState = values.paid_now || '0';
            const isNewExpense = !editing && movementType === 'despesa';
            if (isNewExpense && paidState !== '0' && ['deferred', 'installments'].includes(condition)) {
                throw new Error('O parcelamento e o vencimento futuro só podem ser criados para movimentos pendentes. Regista o pagamento no próprio movimento quando o efetuares.');
            }
            const isInstallment = isNewExpense && condition === 'installments';
            const normalizedAmount = parseMoney(values.amount);
            if (!normalizedAmount) {
                throw new Error('Introduz um valor superior a zero, com até dois cêntimos. Para milhares usa, por exemplo, 1.234,56 ou 1234,56.');
            }
            values.amount = normalizedAmount;
            if (paidState !== '0' && values.payment_amount) {
                const normalizedPayment = parseMoney(values.payment_amount);
                if (!normalizedPayment || Number(normalizedPayment) > Number(normalizedAmount)) {
                    throw new Error('Introduz um valor pago válido que não exceda o valor do movimento.');
                }
                values.payment_amount = normalizedPayment;
            }



            let response;
            let payload;

            if (isInstallment) {
                // Só compras parceladas criam um Compromisso.
                let commitmentResponse;
                try {
                    commitmentResponse = await fetch(cfg.commitmentStore, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({
                            name: values.description,
                            total_amount: values.amount,
                            start_date: values.expense_date,
                            due_date: values.due_date || values.expense_date,
                            payment_condition: 'installments',
                            paid_now: paidState,
                            payment_method: values.payment_method || null,
                            account_id: values.account_id || null,
                            category_id: values.category_id || null,
                            installments_count: values.installments_count,
                            installment_frequency: values.installment_frequency || 'monthly'
                        })
                    });
                } catch (_) {
                    throw new Error('Não foi possível confirmar se o parcelamento foi criado. Verifica Parcelamentos antes de tentar novamente, para evitar duplicados.');
                }
                const commitmentPayload = await commitmentResponse.json().catch(() => ({}));
                if (commitmentResponse.status === 422) {
                    throw new Error(Object.values(commitmentPayload.errors || {}).flat()[0] || commitmentPayload.message || 'Não foi possível criar o parcelamento.');
                }
                if (commitmentResponse.status === 419 || commitmentResponse.status === 401) {
                    throw new Error('A sessão expirou. Atualiza a página e inicia sessão novamente.');
                }
                if (commitmentResponse.status >= 500 || (commitmentResponse.ok && !commitmentPayload.data?.id)) {
                    throw new Error('O servidor não confirmou a criação. Verifica Parcelamentos antes de tentar novamente, para evitar duplicados.');
                }
                if (!commitmentResponse.ok) {
                    throw new Error(commitmentPayload.message || 'Não foi possível criar o parcelamento.');
                }

                // A obrigação e as prestações são a fonte canónica. Não criar
                // uma despesa pendente adicional pelo valor total da compra.
                response = commitmentResponse;
                payload = { ok: true, data: commitmentPayload.data, message: 'Parcelamento criado.' };
            } else {
                const movementData = new FormData(form);
                movementData.set('amount', values.amount);
                if (values.payment_amount) movementData.set('payment_amount', values.payment_amount);
                if (isNewExpense) {
                    const isRecurring = values.recurrence_enabled === '1';
                    if (paidState === '0' && !isRecurring) {
                        // Despesa pendente: não associa conta nem movimenta saldo.
                        movementData.delete('account_id');
                        movementData.delete('payment_account_id');
                    } else {
                        // Despesa liquidada: usa a conta efetivamente selecionada no pagamento.
                        movementData.set('payment_account_id', values.account_id || '');
                        movementData.delete('account_id');
                    }
                }
                response = await fetch(form.action, {
                    method: 'POST',
                    body: movementData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                });
                payload = await response.json().catch(() => ({}));
            }

            if (response.status === 422) {
                const errors = payload.errors || {};
                throw new Error(Object.values(errors).flat()[0] || payload.message || 'Não foi possível guardar as alterações.');
            }
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Não foi possível guardar as alterações.');
            }
            confirmation = {
                isInstallment,
                message: isInstallment ? installmentConfirmation(values) : (wasEditing ? 'Movimento atualizado.' : 'Movimento guardado.')
            };
        } catch (error) {
            showMovementError(error.message || 'Ocorreu um erro ao guardar o lançamento.');
            return;
        } finally {
            submittingMovement = false;
            saveButton.disabled = false;
            saveButton.textContent = buttonText;
            if (!editing) {
                saveButton.textContent = 'Guardar ' + (movementType === 'receita' ? 'receita' : 'despesa');
            }
        }

        // O POST já foi confirmado. Uma falha ao atualizar a lista não deve
        // apresentar o registo como falhado nem incentivar um segundo POST.
        returnToDetailsAfterCancel = false;
        if (wasEditing) {
            const restore = typeBeforeEdit;
            editing = false;
            resetEditor(false);
            setMovementType(restore);
        } else {
            form.reset();
            date.value = localDate();
            setMovementType(movementType);
            closeEditor();
        }
        if (cfg.dashboardHome) {
            const dashboardUrl = new URL(window.location.href);
            dashboardUrl.searchParams.set('mes', monthInput.value);
            window.location.assign(dashboardUrl);
            return;
        }
        showMovementConfirmation(confirmation.message, confirmation.isInstallment);
        try {
            await refreshDashboard();
        } catch (_) {
            showMovementConfirmation(confirmation.message + ' Não foi possível atualizar a lista; atualiza a página. Não guardes novamente.', confirmation.isInstallment, true);
        }
    }

    function formatMovementDate(value) {
        const match = String(value || '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return 'Data não disponível';
        return new Intl.DateTimeFormat('pt-PT').format(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    }

    function openMovementDetails(row) {
        actionMovement = row;
        const detailsError = document.getElementById('movementDetailsError');
        if (detailsError) {
            detailsError.textContent = '';
            detailsError.classList.add('d-none');
        }
        const isExpense = row.dataset.movementType === 'despesa';
        const isInvoiceCredit = row.dataset.isInvoiceCredit === '1';
        const total = Number(row.dataset.amount) || 0;
        const remaining = Math.max(0, Number(row.dataset.remainingAmount ?? row.dataset.amount) || 0);
        const paid = Math.max(0, total - remaining);
        const status = row.dataset.movementStatus || 'pending';
        const partial = ['partial', 'partially_settled'].includes(status);
        const currency = row.dataset.currency || cfg.currency || 'EUR';
        const symbol = new Intl.NumberFormat('pt-PT', { style: 'currency', currency }).format(total);
        const titleEl = document.getElementById('movementDetailsName');
        const typeEl = document.getElementById('movementDetailsType');
        const statusEl = document.getElementById('movementDetailsStatus');
        const amountEl = document.getElementById('movementDetailsAmount');
        const paidEl = document.getElementById('movementDetailsPaid');
        const pendingEl = document.getElementById('movementDetailsPending');
        const progressWrap = document.getElementById('movementDetailsProgressWrap');
        const progress = document.getElementById('movementDetailsProgress');
        const progressLabel = document.getElementById('movementDetailsProgressLabel');
        const paidLine = document.getElementById('movementDetailsPaidLine');
        const pendingLine = document.getElementById('movementDetailsPendingLine');
        const categoryEl = document.getElementById('movementDetailsCategory');
        const dateEl = document.getElementById('movementDetailsDate');
        const kindEl = document.getElementById('movementDetailsKind');
        const originWrap = document.getElementById('movementDetailsOrigin');
        const originHeading = document.getElementById('movementDetailsOriginHeading');
        const originName = document.getElementById('movementDetailsOriginName');
        const originMeta = document.getElementById('movementDetailsOriginMeta');
        const originLink = document.getElementById('movementDetailsOriginLink');
        const actionsWrap = document.getElementById('movementDetailsActions');
        const isInvoiceMovement = Boolean(row.dataset.invoiceId);
        const isInvoicePayment = row.dataset.isInvoicePayment === '1';
        const isInstallment = Boolean(row.dataset.installmentId && row.dataset.debtId);

        titleEl.textContent = row.dataset.description || 'Movimento';
        typeEl.textContent = isInvoiceCredit
            ? 'Crédito de compra'
            : (isInvoicePayment
                ? 'Liquidação de fatura'
                : (isInvoiceMovement ? 'Compra no cartão de crédito' : (isInstallment ? 'Prestação de compra parcelada' : (isExpense ? 'Despesa' : 'Receita'))));
        statusEl.textContent = isInvoiceMovement && !isInvoicePayment
            ? 'Na fatura'
            : (({ settled: 'Pago', partial: 'Parcial', partially_settled: 'Parcial', pending: 'Pendente' })[status] || 'Registado');
        statusEl.className = 'badge ' + (isInvoiceMovement && !isInvoicePayment
            ? 'text-bg-warning'
            : (status === 'settled' ? 'text-bg-success' : partial ? 'text-bg-info' : 'text-bg-secondary'));
        amountEl.textContent = (isInvoiceCredit || !isExpense ? '+' : '−') + symbol;
        paidEl.textContent = formatCurrency(paid, currency);
        pendingEl.textContent = formatCurrency(remaining, currency);
        categoryEl.textContent = row.querySelector('.expense-item__category')?.textContent || row.querySelector('.expense-item p')?.textContent?.split(' · ')[0] || '—';
        dateEl.textContent = formatMovementDate(row.dataset.date);
        kindEl.textContent = isInvoiceCredit
            ? 'Ajuste de despesa · crédito de fatura'
            : (isInvoicePayment
                ? 'Liquidação de fatura'
                : (isInvoiceMovement ? 'Compra no cartão' : (isInstallment ? 'Prestação parcelada' : (row.dataset.expenseBehavior === 'fixed' ? 'Despesa fixa' : (isExpense ? 'Despesa variável' : 'Receita')))));

        const percentage = total > 0 ? Math.min(100, Math.max(0, paid / total * 100)) : 0;
        progress.style.width = percentage + '%';
        progressWrap.hidden = !partial;
        paidLine.hidden = !partial;
        pendingLine.hidden = !partial;
        progressLabel.textContent = percentage.toLocaleString('pt-PT', { maximumFractionDigits: 1 }) + '% concluído';

        const settleAction = document.getElementById('movementDetailsSettle');
        const editAction = document.getElementById('movementDetailsEdit');
        const deleteAction = document.getElementById('movementDetailsDelete');
        const hasOrigin = isInvoiceMovement || isInstallment;
        settleAction.hidden = hasOrigin || isInvoicePayment ||
            !(['pending', 'partial', 'partially_settled', 'settled'].includes(status) && Boolean(row.dataset.financialMovementId));
        const settleLabel = settleAction.querySelector('span');
        const settleIcon = settleAction.querySelector('[data-lucide]');
        const isSettled = status === 'settled';
        if (settleLabel) settleLabel.textContent = isSettled ? 'Reverter' : (isExpense ? 'Pagar' : 'Receber');
        if (settleIcon) {
            settleIcon.setAttribute('data-lucide', isSettled ? 'undo-2' : 'check-circle');
            settleIcon.classList.toggle('text-success', !isSettled);
            settleIcon.classList.toggle('text-warning', isSettled);
        }
        editAction.hidden = hasOrigin || isInvoicePayment || status !== 'pending' || !row.dataset.financialMovementId;
        deleteAction.hidden = hasOrigin || isInvoicePayment;
        if (actionsWrap) actionsWrap.hidden = hasOrigin || isInvoicePayment;
        if (originWrap) originWrap.hidden = !hasOrigin;

        if (isInvoiceMovement) {
            const reference = row.dataset.invoiceReferenceMonth
                ? (() => { const [year, month] = row.dataset.invoiceReferenceMonth.slice(0, 10).split('-').map(Number); return new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1)); })()
                : 'Fatura associada';
            originHeading.textContent = 'Fatura associada';
            originName.textContent = 'Fatura de ' + reference;
            const dueDate = row.dataset.invoiceDueDate ? 'Vencimento ' + formatMovementDate(row.dataset.invoiceDueDate) : '';
            const cardName = row.dataset.invoiceCardName || 'Cartão de crédito';
            const invoiceState = ({ open: 'Ciclo aberto', closed: 'Ciclo fechado', carried: 'Saldo transportado' })[row.dataset.invoiceStatus] || '';
            originMeta.textContent = [cardName, invoiceState, dueDate].filter(Boolean).join(' · ');
            originLink.href = row.dataset.invoiceUrl || '#';
            originLink.textContent = 'Consultar fatura';
            originLink.setAttribute('aria-label', 'Consultar fatura de ' + reference);
        } else if (isInstallment) {
            const number = row.dataset.installmentNumber || '—';
            const count = row.dataset.installmentsCount || '—';
            const commitmentTotal = Number(row.dataset.commitmentTotal || 0);
            originHeading.textContent = 'Parcelamento associado';
            originName.textContent = row.dataset.debtName || row.dataset.description || 'Parcelamento';
            originMeta.textContent = 'Prestação ' + number + ' de ' + count + ' · Total ' + formatCurrency(commitmentTotal, currency);
            originLink.href = row.dataset.commitmentUrl || '#';
            originLink.textContent = 'Consultar parcelamento';
            originLink.setAttribute('aria-label', 'Consultar parcelamento ' + (row.dataset.debtName || 'associado'));
        }

        const progressTitle = document.getElementById('movementDetailsPaymentHeading');
        progressTitle.hidden = !partial;
        movementDetailsModal?.show();
    }

    // Bootstrap ignora hide() enquanto o modal ainda está em transição de abertura.
    // Espera por shown.bs.modal nesse caso; só depois regista hidden e inicia o fecho.
    function hideModalThen(element, instance, callback) {
        if (!element || !instance) return;
        const hideWhenReady = () => {
            element.addEventListener('hidden.bs.modal', callback, { once: true });
            instance.hide();
        };
        if (instance._isTransitioning) {
            element.addEventListener('shown.bs.modal', hideWhenReady, { once: true });
            return;
        }
        if (element.classList.contains('show')) {
            hideWhenReady();
            return;
        }
        callback();
    }

    function runMovementDetailsAction(action) {
        if (!actionMovement) return;
        const row = actionMovement;

        if (action === 'edit') {
            hideModalThen(movementDetailsModalElement, movementDetailsModal, () => {
                startEdit(row);
                // Ao cancelar, regressa aos detalhes do movimento que originou a edição.
                if (editingMovement === row) returnToDetailsAfterCancel = true;
            });
            return;
        }

        const actions = {
            settle: () => openSettleOrReverse(row),
            delete: () => openDeleteModal(row),
        };
        const nextAction = actions[action];
        if (!nextAction) return;
        returnToDetailsAfterCancel = true;
        if (movementDetailsModalElement?.classList.contains('show')) {
            movementDetailsModalElement.addEventListener('hidden.bs.modal', nextAction, { once: true });
            movementDetailsModal?.hide();
            return;
        }
        movementDetailsModal?.hide();
        nextAction();
    }

    function openSettleOrReverse(row) {
        actionMovement = row;
        if (row.dataset.movementStatus === 'settled') {
            reverseMovementSettlement(row);
            return;
        }
        openSettleModal(row);
    }

    async function reverseMovementSettlement(row) {
        if (!row.dataset.financialMovementId || !window.confirm('Reverter a liquidação deste movimento? O movimento voltará ao estado pendente.')) return;
        const button = document.getElementById('movementDetailsSettle');
        const errorBox = document.getElementById('movementDetailsError');
        if (button) button.disabled = true;
        if (errorBox) errorBox.classList.add('d-none');
        try {
            const response = await fetch(cfg.financialMovementBase + '/' + encodeURIComponent(row.dataset.financialMovementId) + '/reverse-settlement', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf || '' }
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível reverter a liquidação.');
            returnToDetailsAfterCancel = false;
            movementDetailsModal?.hide();
            showMovementConfirmation('Liquidação revertida. O movimento voltou a Pendente.');
            try {
                await refreshDashboard();
            } catch (_) {
                showMovementConfirmation('Liquidação revertida. Não foi possível atualizar a lista; atualiza a página. Não repitas a reversão.', false, true);
            }
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message || 'Não foi possível reverter a liquidação.';
                errorBox.classList.remove('d-none');
            } else {
                window.alert(error.message || 'Não foi possível reverter a liquidação.');
            }
        } finally {
            if (button) button.disabled = false;
        }
    }

    function openSettleModal(row) {
        actionMovement = row;
        showSettleError('');
        const isExpense = row.dataset.movementType === 'despesa';
        document.getElementById('settleMovementTitle').textContent = isExpense ? 'Confirmar despesa' : 'Confirmar receita';
        document.getElementById('settleMovementDescription').textContent = row.dataset.description || '';
        document.getElementById('settleMovementAmount').value = row.dataset.remainingAmount || row.dataset.amount || '';
        document.getElementById('settleMovementDate').value = row.dataset.date || localDate();
        document.getElementById('settleMovementAccountLabel').textContent = isExpense ? 'Conta de saída' : 'Conta de destino';
        document.getElementById('settleMovementAccount').value = row.dataset.accountId || '';
        accountPickers.forEach((picker) => picker.refresh());
        const submit = document.getElementById('settleMovementSubmit');
        submit.textContent = isExpense ? 'Confirmar despesa' : 'Confirmar receita';
        submit.className = 'btn w-100 ' + (isExpense ? 'btn-danger' : 'btn-success');
        settleModal?.show();
    }

    function openDeleteModal(row) {
        actionMovement = row;
        const recurring = row.dataset.isSettlementEvent !== '1' && Boolean(row.dataset.recurrenceId && row.dataset.recurrenceFrequency);
        const settled = row.dataset.movementStatus === 'settled';
        const onlyOption = document.getElementById('deleteScopeOnly');
        const futureOption = document.getElementById('deleteScopeFuture');
        const note = document.getElementById('deleteMovementNote');

        document.getElementById('deleteMovementDescription').textContent = row.dataset.description || '';
        document.getElementById('deleteRecurringOptions').hidden = !recurring;
        document.getElementById('deleteSingleConfirmation').hidden = true;
        document.getElementById('deleteMovementTitle').textContent = recurring ? 'Excluir recorrência' : 'Excluir movimento';

        // Reinicia sempre a seleção: o estado anterior do modal não pode influenciar esta exclusão.
        [onlyOption, futureOption].forEach((input) => {
            if (input) {
                input.checked = false;
                input.disabled = false;
            }
        });

        if (recurring) {
            // Movimentos já pagos permanecem no histórico; não podem ser excluídos isoladamente.
            onlyOption.closest('label').hidden = settled;
            onlyOption.disabled = settled;
            futureOption.checked = settled;
            onlyOption.checked = !settled;
        } else {
            onlyOption.closest('label').hidden = false;
        }

        note.hidden = !(recurring && settled);
        note.textContent = 'Este movimento já foi pago e fica no histórico.';
        deleteModal?.show();
    }

    function showSettleError(message) {
        const errorBox = document.getElementById('settleMovementError');
        if (!errorBox) {
            if (message) alert(message);
            return;
        }
        errorBox.textContent = message || '';
        errorBox.classList.toggle('d-none', !message);
        if (message) errorBox.focus();
    }

    async function submitSettleMovement(event) {
        event.preventDefault();
        if (!actionMovement || settlingMovement) return;
        showSettleError('');
        if (!navigator.onLine) {
            showSettleError('Não é possível confirmar movimentos sem ligação à internet.');
            return;
        }
        if (!document.getElementById('settleMovementAccount')?.value) {
            showSettleError('Seleciona a conta do movimento.');
            return;
        }
        if (!settleForm.reportValidity()) return;
        const submit = document.getElementById('settleMovementSubmit');
        const settleAmount = document.getElementById('settleMovementAmount');
        const normalizedAmount = parseMoney(settleAmount.value);
        if (!normalizedAmount) {
            settleAmount.setCustomValidity('Introduz um valor superior a zero com até dois cêntimos. Exemplo: 90,50.');
            settleAmount.reportValidity();
            return;
        }
        const original = submit.textContent;
        settlingMovement = true;
        submit.disabled = true;
        submit.textContent = 'A confirmar...';
        const isExpense = actionMovement.dataset.movementType === 'despesa';
        const accountId = document.getElementById('settleMovementAccount').value;
        const payload = {
            amount: normalizedAmount,
            expected_amount: actionMovement.dataset.remainingAmount || actionMovement.dataset.amount,
            movement_date: document.getElementById('settleMovementDate').value,
            source_account_id: isExpense ? accountId : null,
            destination_account_id: isExpense ? null : accountId
        };
        let isInstallment = false;
        try {
            let response;
            try {
                response = await fetch(cfg.financialMovementBase + '/' + encodeURIComponent(actionMovement.dataset.financialMovementId) + '/settle', {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                    body: JSON.stringify(payload)
                });
            } catch (_) {
                throw new Error('Não foi possível confirmar o pagamento. Verifica o histórico e os parcelamentos antes de tentar novamente.');
            }
            const result = await response.json().catch(() => ({}));
            if (response.status >= 500 || (response.ok && !result.data?.id)) {
                throw new Error('O servidor não confirmou o pagamento. Verifica o histórico e os parcelamentos antes de tentar novamente.');
            }
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Não foi possível efetivar o movimento.');
            isInstallment = Boolean(result.installment);
        } catch (error) {
            showSettleError(error.message || 'Não foi possível efetivar o movimento.');
            return;
        } finally {
            settlingMovement = false;
            submit.disabled = false;
            submit.textContent = original;
        }
        returnToDetailsAfterCancel = false;
        settleModal?.hide();
        const message = isInstallment ? 'Pagamento da prestação registado.' : 'Movimento efetivado.';
        showMovementConfirmation(message, isInstallment);
        try {
            await refreshDashboard();
        } catch (_) {
            showMovementConfirmation(message + ' Não foi possível atualizar a lista; atualiza a página. Não confirmes novamente.', isInstallment, true);
        }
    }

    async function submitDeleteMovement(event) {
        event.preventDefault();
        if (!actionMovement) {
            alert('Seleciona um movimento para excluir.');
            return;
        }
        if (!navigator.onLine) {
            alert('Sem ligação à internet. Liga-te à internet e tenta novamente.');
            return;
        }
        const submit = document.getElementById('deleteMovementSubmit');
        const original = submit.textContent;
        submit.disabled = true;
        submit.textContent = 'A excluir...';
        try {
            let response;
            if (actionMovement.dataset.isSettlementEvent !== '1' && actionMovement.dataset.recurrenceId && actionMovement.dataset.recurrenceFrequency && actionMovement.dataset.financialMovementId) {
                const scope = document.querySelector('input[name="delete_scope"]:checked')?.value || 'only';
                response = await fetch(cfg.financialMovementBase + '/' + encodeURIComponent(actionMovement.dataset.financialMovementId) + '/recurrence-scope', {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                    body: JSON.stringify({ scope })
                });
            } else if (actionMovement.dataset.financialMovementId) {
                response = await fetch(cfg.financialMovementBase + '/' + encodeURIComponent(actionMovement.dataset.financialMovementId), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf || '' }
                });
            } else {
                response = await fetch(actionMovement.dataset.editUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf || '' },
                    body: new URLSearchParams({ _method: 'DELETE', _token: csrf || '' })
                });
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(response.redirected
                    ? 'A sessão pode ter expirado. Atualiza a página e tenta novamente.'
                    : 'O servidor devolveu uma resposta inesperada. A exclusão não foi confirmada.');
            }
            const result = await response.json();
            if (!response.ok || result.ok !== true) {
                throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'O servidor não confirmou a exclusão.');
            }

            await refreshDashboard();
            const stillVisible = loadedMovements.some((item) =>
                String(item.financial_movement_id || '') === String(actionMovement.dataset.financialMovementId || '')
            );
            if (stillVisible) {
                throw new Error('O servidor confirmou a operação, mas o movimento continua na lista. Atualiza a página; se persistir, verifica a recorrência na base de dados.');
            }
            returnToDetailsAfterCancel = false;
            deleteModal?.hide();
        } catch (error) {
            alert(error.message);
        } finally {
            submit.disabled = false;
            submit.textContent = original;
        }
    }

    async function convertCurrency() {
        const value = Number(document.getElementById('conversionAmount').value);
        const from = document.getElementById('conversionFrom').value;
        const to = document.getElementById('conversionTo').value;
        const output = document.getElementById('convertedValue');
        const rateLabel = document.getElementById('conversionRate');
        const button = document.getElementById('convertButton');

        if (!Number.isFinite(value) || value <= 0) {
            output.textContent = '—';
            rateLabel.textContent = 'Introduz um valor válido.';
            return;
        }

        button.disabled = true;
        output.textContent = 'A calcular...';
        rateLabel.textContent = '';
        try {
            if (from === to) {
                output.textContent = formatCurrency(value, to);
                rateLabel.textContent = 'As moedas selecionadas são iguais.';
                return;
            }

            const response = await fetch('https://api.frankfurter.dev/v2/rate/' + from.toLowerCase() + '/' + to.toLowerCase());
            if (!response.ok) throw new Error('Erro ao consultar a API: ' + response.status);
            const result = await response.json();
            if (!Number.isFinite(Number(result.rate))) throw new Error('Não foi possível obter a taxa de câmbio.');
            output.textContent = formatCurrency(value * Number(result.rate), to);
            rateLabel.textContent = '1 ' + from + ' = ' + Number(result.rate).toFixed(4) + ' ' + to + (result.date ? ' · Taxa de ' + result.date : '');
        } catch (error) {
            output.textContent = 'Erro na conversão';
            rateLabel.textContent = error.message || 'Não foi possível consultar a taxa de câmbio.';
        } finally {
            button.disabled = false;
        }
    }

    function restoreCachedData(syncMessage = 'Sem ligação à internet.') {
        if (!cfg.userId) return false;
        try {
            const cached = JSON.parse(localStorage.getItem(cacheKey()) || 'null');
            if (!cached) return false;

            const expenses = (cached.despesas || []).map((item) => ({
                ...item,
                tipo: 'despesa',
                data_movimento: item.expense_date
            }));
            const incomes = (cached.receitas || []).map((item) => ({
                ...item,
                tipo: 'receita',
                data_movimento: item.income_date
            }));
            loadedMovements = [...expenses, ...incomes];
            totalExpenseCount = Number(cached.quantidadeDespesasFiltradas) || expenses.length;
            totalIncomeCount = Number(cached.quantidadeReceitasFiltradas) || incomes.length;
            totalExpenses = Number(cached.totalDespesasPeriodo) || 0;
            visibleCount = pageSize();
            totalIncome = Number(cached.totalReceitasPeriodo) || 0;
            cfg.currency = cached.moeda || cfg.currency || 'EUR';
            page = 1;
            visibleCount = pageSize();
            offlineMode = true;
            hasMore = false;
            updateSummary(totalExpenses, totalIncome);
            renderMovements();

            const saved = cached.guardadoEm
                ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(cached.guardadoEm))
                : 'data desconhecida';
            showStatus(syncMessage + ' Dados guardados em ' + saved + '. Apenas são apresentados os movimentos previamente carregados.');
            return true;
        } catch (error) {
            console.warn('Não foi possível ler os dados locais:', error);
            return false;
        }
    }

    function handleSyncFailure(message) {
        if (restoreCachedData(message)) return;

        loadedMovements = [];
        totalExpenseCount = 0;
        totalIncomeCount = 0;
        updateSummary(0, 0);
        list.innerHTML = navigator.onLine
            ? '<div class="alert alert-danger mb-0" role="alert">Não foi possível sincronizar os movimentos com o servidor. Verifica a ligação e tenta novamente.</div>'
            : '<div class="alert alert-warning mb-0" role="alert">Sem ligação à internet e sem dados guardados neste dispositivo.</div>';
        showStatus(message);
    }

    function handleOffline() {
        offlineMode = true;
        if (!restoreCachedData()) {
            showStatus('Sem ligação à internet. Ainda não existem dados guardados para este período.');
        }
    }

    function handleOnline() {
        offlineMode = false;
        showStatus('');
        refreshDashboard().catch(() => handleSyncFailure('Não foi possível sincronizar os dados com o servidor.'));
    }

    document.getElementById('quickExpenseButton')?.addEventListener('click', () => {
        if (!editing) {
            setMovementType('despesa');
            openEditor();
        }
    });
    document.getElementById('quickIncomeButton')?.addEventListener('click', () => {
        if (!editing) {
            setMovementType('receita');
            openEditor();
        }
    });

    expenseButton?.addEventListener('click', () => {
        if (!editing) {
            setMovementType('despesa');
            openEditor();
        }
    });
    incomeButton?.addEventListener('click', () => {
        if (!editing) {
            setMovementType('receita');
            openEditor();
        }
    });

    cancelButton?.addEventListener('click', () => {
        if (editing) {
            cancelEdit();
            return;
        }
        resetEditor(true);
    });
    form.querySelectorAll('[name="paid_now"]').forEach((input) => input.addEventListener('change', refreshPaymentFields));
    account?.addEventListener('change', refreshPaymentFields);
    ['dashboardDueDateEnabled', 'dashboardRecurrenceEnabled', 'dashboardInstallmentsEnabled'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', refreshPaymentFields);
    });
    ['movementAmount', 'dashboardDueDateInput', 'dashboardInstallments', 'dashboardInstallmentFrequency', 'dashboardActualPaymentMethod'].forEach((id) => {
        document.getElementById(id)?.addEventListener('input', refreshPaymentFields);
        document.getElementById(id)?.addEventListener('change', refreshPaymentFields);
    });
    form.addEventListener('submit', submitMovement);
    editForm?.addEventListener('submit', submitEditMovement);
    settleForm?.addEventListener('submit', submitSettleMovement);
    document.getElementById('settleMovementAmount')?.addEventListener('input', (event) => event.target.setCustomValidity(''));
    deleteForm?.addEventListener('submit', submitDeleteMovement);
    list.addEventListener('click', (event) => {
        if (event.target.closest('#loadMoreMovements')) {
            loadMore();
            return;
        }
        const actionButton = event.target.closest('[data-movement-action]');
        if (actionButton) {
            const row = actionButton.closest('[data-movement-id]');
            if (row && actionButton.dataset.movementAction === 'details') openMovementDetails(row);
            if (row && actionButton.dataset.movementAction === 'settle') openSettleOrReverse(row);
            if (row && actionButton.dataset.movementAction === 'delete') openDeleteModal(row);
            return;
        }
        const editButton = event.target.closest('[data-acao="editar"], .edit-movement');
        if (editButton) {
            const row = editButton.closest('[data-movement-id]');
            if (row) startEdit(row);
            return;
        }

        // Qualquer área livre da linha abre os detalhes; controlos internos mantêm a sua ação.
        const row = event.target.closest('[data-movement-id]');
        const interactiveTarget = event.target.closest('button, a, input, select, textarea, label, [role="button"], [data-bs-toggle]');
        if (row && !interactiveTarget) openMovementDetails(row);
    });
    list.addEventListener('keydown', (event) => {
        if (!['Enter', ' '].includes(event.key) || event.target !== event.target.closest('[data-movement-id]')) return;
        event.preventDefault();
        openMovementDetails(event.target);
    });
    document.getElementById('movementDetailsSettle')?.addEventListener('click', () => runMovementDetailsAction('settle'));
    document.getElementById('movementDetailsEdit')?.addEventListener('click', () => runMovementDetailsAction('edit'));
    document.getElementById('movementDetailsDelete')?.addEventListener('click', () => runMovementDetailsAction('delete'));
    document.querySelector('.transaction-filters')?.addEventListener('submit', applyFilters);
    document.getElementById('categoryFilter')?.addEventListener('change', () => applyFilters());
    document.getElementById('clearMovementFilters')?.addEventListener('click', () => {
        const categorySelect = document.getElementById('categoryFilter');
        if (categorySelect) categorySelect.value = 'all';
        if (accountFilter) accountFilter.value = 'all';
        applyFilters();
        const filtersElement = document.getElementById('movementFiltersSheet');
        if (filtersElement) bootstrap.Offcanvas.getOrCreateInstance(filtersElement).hide();
    });
    movementSearch?.addEventListener('input', () => renderMovements());
    accountFilter?.addEventListener('change', () => renderMovements());
    document.getElementById('periodPrevious')?.addEventListener('click', () => changeMonth(-1));
    document.getElementById('periodNext')?.addEventListener('click', () => changeMonth(1));
    monthInput?.addEventListener('change', () => setMonth(monthInput.value));
    document.getElementById('periodForm')?.addEventListener('submit', (event) => {
        event.preventDefault();
        setMonth(monthInput.value);
    });
    document.getElementById('convertButton')?.addEventListener('click', convertCurrency);
    document.getElementById('conversionAmount')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') convertCurrency();
    });

    editorModalElement?.addEventListener('hide.bs.modal', (event) => {
        if (submittingMovement) event.preventDefault();
    });
    settleModalElement?.addEventListener('hide.bs.modal', (event) => {
        if (settlingMovement) event.preventDefault();
    });
    const restoreMovementDetails = () => {
        if (!returnToDetailsAfterCancel || !actionMovement) return;
        returnToDetailsAfterCancel = false;
        movementDetailsModal?.show();
    };
    [editModalElement, editorModalElement, settleModalElement, deleteModalElement].forEach((modalElement) => {
        modalElement?.addEventListener('hidden.bs.modal', restoreMovementDetails);
    });
    editorModalElement?.addEventListener('shown.bs.modal', () => {
        expenseButton?.setAttribute('aria-expanded', 'true');
        incomeButton?.setAttribute('aria-expanded', 'true');
    });
    editorModalElement?.addEventListener('hidden.bs.modal', () => {
        expenseButton?.setAttribute('aria-expanded', 'false');
        incomeButton?.setAttribute('aria-expanded', 'false');
    });


    const currencyFrom = document.getElementById('conversionFrom');
    const currencyTo = document.getElementById('conversionTo');
    if (currencyFrom && currencyTo) {
        currencyTo.value = cfg.currency || 'EUR';
        currencyFrom.value = cfg.currency === 'USD' ? 'EUR' : 'USD';
    }

    setMovementType('despesa');
    movementType = cfg.initialType === 'receita' ? 'receita' : 'despesa';
    setMovementType(movementType);
    if (cfg.initialCategory) category.value = cfg.initialCategory;
    syncExpenseBehaviorFromCategory();

    if (cfg.autoOpenEditor) {
        openEditor();
        if (typeof cfg.returnUrl === 'string' && cfg.returnUrl.startsWith('/') && !cfg.returnUrl.startsWith('//')) {
            let returnAfterQuickAction = true;
            editorModalElement?.addEventListener('hidden.bs.modal', () => {
                if (!returnAfterQuickAction) return;
                returnAfterQuickAction = false;
                window.location.assign(cfg.returnUrl);
            }, { once: true });
        }
    }

    updateSummary(totalExpenses, totalIncome);
    updatePeriodCaption();

    // Os movimentos e totais iniciais já vêm na resposta Blade. Não repetir a consulta
    // AJAX ao abrir o Dashboard; a API é chamada apenas quando o utilizador pede mais dados
    // ou altera um filtro que exige nova consulta.
    if (navigator.onLine) {
        offlineMode = false;
        renderMovements();
    } else if (!restoreCachedData()) {
        offlineMode = true;
        hasMore = false;
        renderMovements();
        showStatus('Sem ligação à internet. A mostrar apenas os movimentos recebidos nesta página.');
    }
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
})();

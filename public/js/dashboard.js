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
    let actionMovement = null;
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
        const behaviorField = document.getElementById('dashboardExpenseBehaviorField');
        if (behaviorField) behaviorField.hidden = !isExpense;

        let condition = form.querySelector('[name="payment_condition"]:checked')?.value || 'cash';
        const recurrenceToggle = form.querySelector('[name="recurrence_enabled"]');
        const isRecurring = !editing && Boolean(recurrenceToggle?.checked);
        if (isRecurring && condition !== 'cash') {
            const cashOption = form.querySelector('[name="payment_condition"][value="cash"]');
            if (cashOption) cashOption.checked = true;
            condition = 'cash';
        }
        const isCommitment = isExpense && condition !== 'cash';
        if (paymentFields) paymentFields.hidden = !isExpense;
        if (commitmentOptions) commitmentOptions.hidden = false;
        // A conta só é necessária quando existe uma liquidação efetiva.
        const accountField = document.getElementById('movementAccount');
        if (accountField) {
            accountField.required = !isExpense || editing || isRecurring;
            const accountContainer = accountField.closest('.mb-3');
            if (accountContainer) accountContainer.hidden = isExpense && !isRecurring;
        }

        const dueDateField = document.getElementById('dashboardDueDateField');
        const installmentsField = document.getElementById('dashboardInstallmentsField');
        const frequencyField = document.getElementById('dashboardInstallmentFrequencyField');
        const schedule = document.getElementById('dashboardInstallmentSchedule');
        const installments = condition === 'installments';
        if (dueDateField) dueDateField.hidden = !isCommitment;
        if (installmentsField) installmentsField.hidden = !installments;
        if (frequencyField) frequencyField.hidden = !installments;
        if (schedule) schedule.hidden = !installments;

        const countInput = document.getElementById('dashboardInstallments');
        if (countInput) countInput.required = installments;
        const dueInput = document.getElementById('dashboardDueDate');
        if (dueInput) dueInput.required = isCommitment;

        const cards = form.querySelectorAll('.payment-mode-card');
        cards.forEach((card) => {
            const radio = card.querySelector('[name="payment_condition"]');
            card.classList.toggle('is-selected', Boolean(radio?.checked));
        });

        const paymentState = form.querySelector('[name="paid_now"]:checked')?.value || '0';
        const hasPayment = paymentState === '1' || paymentState === 'partial';
        const isInstallmentPayment = condition === 'installments';
        const partialLabel = document.getElementById('dashboardPaidPartialLabel');
        const fullLabel = document.getElementById('dashboardPaidFullLabel');
        if (partialLabel) partialLabel.textContent = isInstallmentPayment ? 'Paguei uma parte da primeira prestação' : 'Paguei uma parte';
        if (fullLabel) fullLabel.textContent = isInstallmentPayment ? 'Já paguei a primeira prestação' : 'Já paguei tudo';
        if (paidFields) paidFields.hidden = !isExpense;
        if (paymentDetails) paymentDetails.hidden = !hasPayment || !isExpense;
        const paymentAccount = document.getElementById('dashboardPaymentAccount');
        const paymentAmount = document.getElementById('dashboardPaymentAmount');
        const paymentDate = document.getElementById('dashboardPaymentDate');
        const amountField = document.getElementById('dashboardPaymentAmountField');
        if (paymentAccount) paymentAccount.required = hasPayment && isExpense;
        if (paymentAmount) paymentAmount.required = paymentState === 'partial' && isExpense;
        if (paymentDate) paymentDate.required = hasPayment && isExpense;
        if (paymentAmount) {
            if (paymentState === '1') {
                const total = parseMoney(document.getElementById('movementAmount')?.value || '');
                const count = Math.max(2, Number(document.getElementById('dashboardInstallments')?.value || 2));
                if (condition === 'installments' && total) {
                    const cents = Math.round(Number(total) * 100);
                    paymentAmount.value = (Math.floor(cents / count) / 100).toFixed(2);
                } else {
                    paymentAmount.value = total || '';
                }
            }
            if (paymentState !== 'partial' && paymentState !== '1') paymentAmount.value = '';
        }
        if (amountField) amountField.hidden = paymentState === '1';

        renderInstallmentSchedule();
    }

    function renderInstallmentSchedule() {
        const list = document.getElementById('dashboardInstallmentScheduleList');
        if (!list) return;
        const count = Math.min(120, Math.max(2, Number(document.getElementById('dashboardInstallments')?.value || 2)));
        const normalizedAmount = parseMoney(document.getElementById('movementAmount')?.value || '');
        const amount = normalizedAmount ? Math.round(Number(normalizedAmount) * 100) : 0;
        const dueValue = document.getElementById('dashboardDueDate')?.value;
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
        const firstCents = base;
        const lastCents = base + remainder;
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
        const initialPayment = values.paid_now !== '0' && values.payment_amount
            ? ' Pagamento inicial de ' + values.payment_amount + ' registado.'
            : '';
        return prefix + initialPayment + ' Em Movimentos, procura pelo mês do vencimento e verifica os filtros.';
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
        // O Laravel pode devolver uma data ISO completa; o input date precisa apenas de YYYY-MM-DD.
        const dateMatch = String(movementDate || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        let normalizedDate = '';
        let displayDate = 'Data não disponível';

        if (dateMatch) {
            const year = Number(dateMatch[1]);
            const month = Number(dateMatch[2]);
            const day = Number(dateMatch[3]);
            const calendarDate = new Date(year, month - 1, day);

            if (
                calendarDate.getFullYear() === year &&
                calendarDate.getMonth() === month - 1 &&
                calendarDate.getDate() === day
            ) {
                normalizedDate = dateMatch[1] + '-' + dateMatch[2] + '-' + dateMatch[3];
                displayDate = new Intl.DateTimeFormat('pt-PT').format(calendarDate);
            }
        }

        const article = document.createElement('article');
        article.className = 'expense-item d-flex justify-content-between align-items-center gap-3 py-3 border-bottom';
        article.dataset.movementId = item.id;
        article.dataset.financialMovementId = item.financial_movement_id || '';
        article.dataset.movementType = item.tipo;
        article.dataset.editUrl = (isExpense ? cfg.expenseUpdateBase : cfg.incomeUpdateBase) + '/' + encodeURIComponent(item.id);
        article.dataset.date = normalizedDate;
        article.dataset.description = item.description;
        article.dataset.amount = item.amount;
        article.dataset.currency = item.currency || cfg.currency || 'EUR';
        article.dataset.categoryId = item.category;
        article.dataset.accountId = item.account_id || '';
        article.dataset.expenseBehavior = item.expense_behavior || 'variable';
        article.dataset.movementStatus = item.movement_status || '';
        article.dataset.isInvoicePayment = item.is_invoice_payment ? '1' : '0';
        article.dataset.dueDate = item.due_date || '';
        article.dataset.recurrenceId = item.recurrence_id || '';
        article.dataset.recurrenceFrequency = item.recurrence_frequency || '';
        article.dataset.recurrenceInterval = item.recurrence_interval || '';
        article.dataset.recurrenceEndsAt = item.recurrence_ends_at || '';

        const info = document.createElement('div');
        info.className = 'd-flex align-items-center gap-3 min-w-0';
        const icon = document.createElement('div');
        icon.className = isExpense ? 'expense-icon flex-shrink-0' : 'expense-icon income-icon flex-shrink-0';
        icon.setAttribute('aria-hidden', 'true');
        icon.style.setProperty('--category-color', item.category_color || '#635BFF');
        icon.innerHTML = '<i data-lucide="' + (item.category_icon || 'tag') + '"></i>';

        const details = document.createElement('div');
        details.className = 'min-w-0';
        const name = document.createElement('h3');
        name.className = 'h6 fw-semibold mb-1 text-break';
        name.textContent = item.description;
        const metadata = document.createElement('p');
        metadata.className = 'small text-secondary mb-0';
        metadata.textContent = categoryName(item) + ' · ' + displayDate;
        const badges = document.createElement('div');
        badges.className = 'movement-badges d-flex flex-wrap align-items-center gap-1 mt-1';
        const statusBadge = document.createElement('span');
        statusBadge.className = 'badge rounded-pill ' + (
            item.movement_status === 'settled' ? 'text-bg-success' :
            (item.movement_status === 'partial' || item.movement_status === 'partially_settled') ? 'text-bg-info' :
            'text-bg-warning'
        );
        statusBadge.textContent = item.movement_status === 'settled' ? 'Pago' :
            (item.movement_status === 'partial' || item.movement_status === 'partially_settled') ? 'Parcialmente pago' : 'Pendente';
        badges.append(statusBadge);
        if (item.recurrence_id) {
            const recurrenceBadge = document.createElement('span');
            recurrenceBadge.className = 'badge rounded-pill text-bg-light border text-secondary';
            recurrenceBadge.textContent = 'Recorrente';
            badges.append(recurrenceBadge);
        }
        details.append(name, metadata);
        if (isExpense && item.due_date) {
            const due = String(item.due_date).slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (due) {
                const dueDate = new Date(Number(due[1]), Number(due[2]) - 1, Number(due[3]));
                const dueLine = document.createElement('p');
                dueLine.className = 'small text-body-tertiary mb-0';
                const dueLabel = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: 'short' }).format(dueDate);
                const today = new Date(); today.setHours(0, 0, 0, 0);
                dueDate.setHours(0, 0, 0, 0);
                const daysLate = Math.floor((today - dueDate) / 86400000);
                dueLine.textContent = daysLate > 0 && item.movement_status !== 'settled' ? 'Vencido há ' + daysLate + (daysLate === 1 ? ' dia' : ' dias') : 'Vence em ' + dueLabel;
                details.append(dueLine);
            }
        }
        details.append(badges);
        info.append(icon, details);

        const actions = document.createElement('div');
        actions.className = 'expense-actions d-flex align-items-center justify-content-end gap-2';
        const value = document.createElement('strong');
        value.className = (isExpense ? 'text-danger' : 'text-success') + ' text-nowrap movement-value';
        value.dataset.value = item.amount;
        value.dataset.currency = item.currency || cfg.currency || 'EUR';
        value.textContent = (isExpense ? '−' : '+') + formatCurrency(item.amount, item.currency);

        actions.append(value);
        if (!item.is_invoice_payment) {
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn btn-outline-secondary';
            edit.dataset.acao = 'editar';
            edit.setAttribute('aria-label', 'Editar ' + item.description);
            edit.title = 'Editar';
            edit.innerHTML = '<i data-lucide="pencil" aria-hidden="true"></i>';
            actions.append(edit);
            if (item.movement_status === 'pending' && item.financial_movement_id) {
                const settle = document.createElement('button');
                settle.type = 'button';
                settle.className = 'btn btn-outline-success';
                settle.dataset.movementAction = 'settle';
                settle.title = isExpense ? 'Efetivar despesa' : 'Efetivar receita';
                settle.setAttribute('aria-label', settle.title + ': ' + item.description);
                settle.innerHTML = '<i data-lucide="check-circle" aria-hidden="true"></i>';
                actions.append(settle);
            }
    
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-outline-danger';
            remove.dataset.movementAction = 'delete';
            remove.title = 'Excluir';
            remove.setAttribute('aria-label', 'Excluir ' + item.description);
            remove.innerHTML = '<i data-lucide="trash-2" aria-hidden="true"></i>';
            actions.append(remove);
    
        }
        article.append(info, actions);
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

        title.textContent = (isEditing ? 'Editar ' : 'Adicionar ') + (income ? 'receita' : 'despesa');
        saveButton.textContent = (isEditing ? 'Atualizar ' : 'Guardar ') + (income ? 'receita' : 'despesa');
        saveButton.className = 'btn w-100 ' + (income ? 'btn-success' : 'btn-danger');
        date.name = income ? 'income_date' : 'expense_date';
        const accountLabel = document.getElementById('movementAccountLabel');
        if (accountLabel) accountLabel.textContent = income ? 'Onde entrou o dinheiro?' : 'De onde saiu o dinheiro?';
        form.action = isEditing
            ? form.action
            : (income ? cfg.incomeStore : cfg.expenseStore);

        category.replaceChildren(new Option('Seleciona uma categoria', ''));
        const options = income ? cfg.incomeCategories : cfg.expenseCategories;
        Object.entries(options || {}).forEach(([key, label]) => category.add(new Option(label, key)));
        category.disabled = false;
        if (account) account.disabled = false;
        cancelButton?.classList.toggle('d-none', !isEditing);
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

    function startEdit(row) {
        const type = row.dataset.movementType;
        typeBeforeEdit = movementType;
        editing = true;
        method.value = 'PUT';
        form.action = row.dataset.editUrl;
        setMovementType(type);
        title.textContent = type === 'receita' ? 'Editar receita' : 'Editar despesa';
        description.value = row.dataset.description;
        amount.value = row.dataset.amount;
        date.value = row.dataset.date;
        category.value = row.dataset.categoryId;
        if (account) account.value = row.dataset.accountId || '';
        if (type === 'despesa') {
            const settled = row.dataset.movementStatus === 'settled';
            form.querySelectorAll('[name="paid_now"]').forEach((input) => {
                input.checked = input.value === (settled ? '1' : '0');
            });
            const paymentAccount = document.getElementById('dashboardPaymentAccount');
            const paymentDate = document.getElementById('dashboardPaymentDate');
            const paymentAmount = document.getElementById('dashboardPaymentAmount');
            if (paymentAccount) paymentAccount.value = row.dataset.accountId || '';
            if (paymentDate) paymentDate.value = row.dataset.date || localDate();
            if (paymentAmount) paymentAmount.value = settled ? row.dataset.amount : '';
        }
        if (type === 'despesa') {
            form.querySelectorAll('[name="expense_behavior"]').forEach((input) => {
                input.checked = input.value === (row.dataset.expenseBehavior || 'variable');
            });
        }
        const repeat = document.getElementById('dashboardRecurrenceEnabled');
        const repeatFields = document.getElementById('dashboardRecurrenceFields');
        if (repeat) {
            repeat.checked = Boolean(row.dataset.recurrenceId);
            const frequency = document.getElementById('dashboardRecurrenceFrequency');
            const interval = document.getElementById('dashboardRecurrenceInterval');
            const ends = document.getElementById('dashboardRecurrenceEnds');
            if (frequency) frequency.value = row.dataset.recurrenceFrequency || 'monthly';
            if (interval) interval.value = row.dataset.recurrenceInterval || '1';
            if (ends) ends.value = row.dataset.recurrenceEndsAt || '';
            if (repeatFields) repeatFields.hidden = !repeat.checked;
        }
        // A edição substitui o movimento canónico no backend; todos os campos
        // permanecem editáveis, inclusive em movimentos liquidados.
        amount.readOnly = false;
        date.readOnly = false;
        refreshPaymentFields();
        cancelButton?.classList.remove('d-none');
        openEditor();
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        description.focus({ preventScroll: true });
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
        if (!form.reportValidity()) return;

        const buttonText = saveButton.textContent;
        const wasEditing = editing;
        let confirmation = null;
        submittingMovement = true;
        saveButton.disabled = true;
        saveButton.textContent = 'A guardar...';
        try {
            const values = Object.fromEntries(new FormData(form).entries());
            const condition = values.payment_condition || 'cash';
            const paidState = values.paid_now || '0';
            const isNewExpense = !editing && movementType === 'despesa';
            const isInstallment = isNewExpense && condition === 'installments';
            const normalizedAmount = parseMoney(values.amount);
            if (!normalizedAmount) {
                throw new Error('Introduz um valor superior a zero, com até dois cêntimos. Para milhares usa, por exemplo, 1.234,56 ou 1234,56.');
            }
            values.amount = normalizedAmount;
            if (paidState !== '0' && values.payment_amount) {
                const normalizedPayment = parseMoney(values.payment_amount);
                let paymentLimit = Number(normalizedAmount);
                if (isInstallment) {
                    const count = Math.max(2, Number(values.installments_count || 2));
                    const totalCents = Math.round(Number(normalizedAmount) * 100);
                    paymentLimit = Math.ceil(totalCents / count) / 100;
                }
                if (!normalizedPayment || Number(normalizedPayment) > paymentLimit) {
                    throw new Error(isInstallment
                        ? 'O pagamento inicial não pode exceder o valor da primeira prestação.'
                        : 'Introduz um valor pago válido que não exceda o valor do movimento.');
                }
                values.payment_amount = normalizedPayment;
            }

            // A estrutura atual do motor não possui registo de pagamentos parciais
            // para despesas comuns. Não aceitar silenciosamente um pagamento total.
            if (isNewExpense && paidState === 'partial' && !isInstallment) {
                throw new Error('O registo de pagamentos parciais para despesas sem parcelamento ainda não está disponível. Seleciona “Ainda não paguei” ou “Já paguei tudo”.');
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
                            payment_method: values.payment_method || null,
                            account_id: values.account_id || null,
                            category_id: values.category_id || null,
                            installments_count: values.installments_count,
                            installment_frequency: values.installment_frequency || 'monthly',
                            ...(paidState !== '0' ? {
                                initial_payment: {
                                    account_id: values.payment_account_id || null,
                                    amount: values.payment_amount || null,
                                    payment_date: values.payment_date || localDate()
                                }
                            } : {})
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
                        movementData.set('payment_account_id', values.payment_account_id || '');
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
        showMovementConfirmation(confirmation.message, confirmation.isInstallment);
        try {
            await refreshDashboard();
        } catch (_) {
            showMovementConfirmation(confirmation.message + ' Não foi possível atualizar a lista; atualiza a página. Não guardes novamente.', confirmation.isInstallment, true);
        }
    }

    function openSettleModal(row) {
        actionMovement = row;
        showSettleError('');
        const isExpense = row.dataset.movementType === 'despesa';
        document.getElementById('settleMovementTitle').textContent = isExpense ? 'Confirmar despesa' : 'Confirmar receita';
        document.getElementById('settleMovementDescription').textContent = row.dataset.description || '';
        document.getElementById('settleMovementAmount').value = row.dataset.amount || '';
        document.getElementById('settleMovementDate').value = row.dataset.date || localDate();
        document.getElementById('settleMovementAccountLabel').textContent = isExpense ? 'Conta de saída' : 'Conta de destino';
        document.getElementById('settleMovementAccount').value = row.dataset.accountId || '';
        const submit = document.getElementById('settleMovementSubmit');
        submit.textContent = isExpense ? 'Confirmar despesa' : 'Confirmar receita';
        submit.className = 'btn w-100 ' + (isExpense ? 'btn-danger' : 'btn-success');
        settleModal?.show();
    }

    function openDeleteModal(row) {
        actionMovement = row;
        const recurring = Boolean(row.dataset.recurrenceId);
        const settled = row.dataset.movementStatus === 'settled';
        const onlyOption = document.getElementById('deleteScopeOnly');
        const futureOption = document.getElementById('deleteScopeFuture');
        const allOption = document.getElementById('deleteScopeAll');
        const note = document.getElementById('deleteMovementNote');

        document.getElementById('deleteMovementDescription').textContent = row.dataset.description || '';
        document.getElementById('deleteRecurringOptions').hidden = !recurring;
        document.getElementById('deleteSingleOption').hidden = recurring;
        document.getElementById('deleteMovementTitle').textContent = recurring ? 'Excluir recorrência' : 'Excluir movimento';

        // Reinicia sempre a seleção: o estado anterior do modal não pode influenciar esta exclusão.
        [onlyOption, futureOption, allOption, document.getElementById('deleteScopeOnlySingle')].forEach((input) => {
            if (input) input.checked = false;
        });

        if (recurring) {
            // Ocorrências já pagas são preservadas; nesse caso só se pode interromper esta e/ou as seguintes.
            onlyOption.closest('label').hidden = settled;
            onlyOption.disabled = settled;
            futureOption.checked = settled;
            onlyOption.checked = !settled;
            allOption.checked = false;
        } else {
            document.getElementById('deleteScopeOnlySingle').checked = true;
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
            expected_amount: actionMovement.dataset.amount,
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
            if (actionMovement.dataset.recurrenceId && actionMovement.dataset.financialMovementId) {
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

    cancelButton?.addEventListener('click', cancelEdit);
    form.querySelectorAll('[name="payment_condition"], [name="paid_now"], [name="recurrence_enabled"]').forEach((input) => input.addEventListener('change', refreshPaymentFields));
    ['movementAmount', 'dashboardDueDate', 'dashboardInstallments', 'dashboardInstallmentFrequency'].forEach((id) => {
        document.getElementById(id)?.addEventListener('input', refreshPaymentFields);
        document.getElementById(id)?.addEventListener('change', refreshPaymentFields);
    });
    form.addEventListener('submit', submitMovement);
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
            if (row && actionButton.dataset.movementAction === 'settle') openSettleModal(row);
            if (row && actionButton.dataset.movementAction === 'delete') openDeleteModal(row);
            return;
        }
        const editButton = event.target.closest('[data-acao="editar"], .edit-movement');
        if (editButton) {
            const row = editButton.closest('[data-movement-id]');
            if (row) startEdit(row);
        }
    });
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
    editorModalElement?.addEventListener('shown.bs.modal', () => {
        expenseButton?.setAttribute('aria-expanded', 'true');
        incomeButton?.setAttribute('aria-expanded', 'true');
    });
    editorModalElement?.addEventListener('hidden.bs.modal', () => {
        expenseButton?.setAttribute('aria-expanded', 'false');
        incomeButton?.setAttribute('aria-expanded', 'false');
    });
    form.querySelectorAll('[name="payment_condition"], [name="paid_now"]').forEach((input) => input.addEventListener('change', refreshPaymentFields));
    ['dashboardInstallments', 'dashboardInstallmentFrequency', 'dashboardDueDate', 'movementAmount'].forEach((id) => {
        document.getElementById(id)?.addEventListener('input', refreshPaymentFields);
        document.getElementById(id)?.addEventListener('change', refreshPaymentFields);
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

    if (cfg.autoOpenEditor) openEditor();

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

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
    const category = document.getElementById('movementCategory');
    const saveButton = document.getElementById('saveMovementButton');
    const method = document.getElementById('movementMethod');
    const cancelButton = document.getElementById('cancelMovementEdit');
    const editorPanel = document.getElementById('recordEditorPanel');
    const editorCard = document.getElementById('movementEntryCard');
    const editorModalElement = document.getElementById('movementEntryModal');
    const editorModal = editorModalElement ? bootstrap.Modal.getOrCreateInstance(editorModalElement) : null;
    const monthInput = document.getElementById('periodMonth');
    const periodCaption = document.getElementById('periodCaption');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

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
        if (!parts[0] || !parts[1] || !periodCaption) return;
        periodCaption.textContent = new Intl.DateTimeFormat('pt-PT', {
            month: 'long',
            year: 'numeric'
        }).format(new Date(parts[0], parts[1] - 1, 1));
    }

    function showStatus(message) {
        const status = document.getElementById('offlineStatus');
        if (!status) return;
        status.textContent = message || '';
        status.classList.toggle('d-none', !message);
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
        article.dataset.movementType = item.tipo;
        article.dataset.editUrl = (isExpense ? cfg.expenseUpdateBase : cfg.incomeUpdateBase) + '/' + encodeURIComponent(item.id);
        article.dataset.date = normalizedDate;
        article.dataset.description = item.description;
        article.dataset.amount = item.amount;
        article.dataset.currency = item.currency || cfg.currency || 'EUR';
        article.dataset.categoryId = item.category;

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
        details.append(name, metadata);
        info.append(icon, details);

        const actions = document.createElement('div');
        actions.className = 'expense-actions d-flex align-items-center justify-content-end gap-2';
        const value = document.createElement('strong');
        value.className = (isExpense ? 'text-danger' : 'text-success') + ' text-nowrap movement-value';
        value.dataset.value = item.amount;
        value.dataset.currency = item.currency || cfg.currency || 'EUR';
        value.textContent = (isExpense ? '−' : '+') + formatCurrency(item.amount, item.currency);

        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-outline-secondary';
        edit.dataset.acao = 'editar';
        edit.setAttribute('aria-label', 'Editar ' + item.description);
        edit.title = 'Editar';
        edit.innerHTML = '<i class="bi bi-pencil" aria-hidden="true"></i>';

        const deleteForm = document.createElement('form');
        deleteForm.method = 'POST';
        deleteForm.action = article.dataset.editUrl;
        deleteForm.dataset.deleteType = item.tipo;
        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrf || '';
        const verb = document.createElement('input');
        verb.type = 'hidden';
        verb.name = '_method';
        verb.value = 'DELETE';
        const remove = document.createElement('button');
        remove.type = 'submit';
        remove.className = 'btn btn-outline-danger';
        remove.setAttribute('aria-label', 'Eliminar ' + item.description);
        remove.title = 'Eliminar';
        remove.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
        deleteForm.append(token, verb, remove);
        actions.append(value, edit, deleteForm);
        article.append(info, actions);
        return article;
    }

    function renderMovements() {
        const filtered = loadedMovements
            .filter((item) => cfg.type === 'todos' || item.tipo === cfg.type)
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
            visible.forEach((item) => list.append(renderItem(item)));
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
        form.action = isEditing
            ? form.action
            : (income ? cfg.incomeStore : cfg.expenseStore);

        category.replaceChildren(new Option('Seleciona uma categoria', ''));
        const options = income ? cfg.incomeCategories : cfg.expenseCategories;
        Object.entries(options || {}).forEach(([key, label]) => category.add(new Option(label, key)));
        category.disabled = false;
        cancelButton?.classList.toggle('d-none', !isEditing);
    }

    function openEditor() { editorModal?.show(); }
    function closeEditor() { editorModal?.hide(); }

    function resetEditor(keepType = true) {
        const restoredType = keepType ? movementType : 'despesa';
        editing = false;
        method.value = 'POST';
        form.reset();
        date.value = localDate();
        form.action = restoredType === 'receita' ? cfg.incomeStore : cfg.expenseStore;
        setMovementType(restoredType);
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
        if (!navigator.onLine) {
            alert('Não é possível guardar movimentos sem ligação à internet.');
            return;
        }
        if (!form.reportValidity()) return;

        const buttonText = saveButton.textContent;
        saveButton.disabled = true;
        saveButton.textContent = 'A guardar...';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const payload = await response.json().catch(() => ({}));
            if (response.status === 422) {
                const errors = payload.errors || {};
                const firstError = Object.values(errors).flat()[0] || payload.message || 'Não foi possível guardar as alterações.';
                alert(firstError);
                return;
            }
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Não foi possível guardar as alterações.');
            }

            if (editing) {
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
            await refreshDashboard();
        } catch (error) {
            alert(error.message || 'Ocorreu um erro ao guardar o lançamento.');
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = buttonText;
            if (!editing) {
                saveButton.textContent = 'Guardar ' + (movementType === 'receita' ? 'receita' : 'despesa');
            }
        }
    }

    async function deleteMovement(deleteForm) {
        if (!navigator.onLine) {
            alert('Não é possível eliminar movimentos sem ligação à internet.');
            return;
        }
        const type = deleteForm.dataset.deleteType;
        const name = type === 'receita' ? 'receita' : 'despesa';
        if (!window.confirm('Tens a certeza de que queres eliminar esta ' + name + '?')) return;

        try {
            const response = await fetch(deleteForm.action, {
                method: 'POST',
                body: new FormData(deleteForm),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Não foi possível eliminar a ' + name + '.');
            }
            await refreshDashboard();
        } catch (error) {
            alert(error.message || 'Ocorreu um erro ao eliminar a ' + name + '.');
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
    form.addEventListener('submit', submitMovement);
    list.addEventListener('click', (event) => {
        if (event.target.closest('#loadMoreMovements')) {
            loadMore();
            return;
        }
        const editButton = event.target.closest('[data-acao="editar"], .edit-movement');
        if (editButton) {
            const row = editButton.closest('[data-movement-id]');
            if (row) startEdit(row);
        }
    });
    list.addEventListener('submit', (event) => {
        const deleteForm = event.target.closest('form[data-delete-type]');
        if (!deleteForm) return;
        event.preventDefault();
        deleteMovement(deleteForm);
    });

    document.querySelector('.transaction-filters')?.addEventListener('submit', applyFilters);
    document.getElementById('categoryFilter')?.addEventListener('change', () => applyFilters());
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

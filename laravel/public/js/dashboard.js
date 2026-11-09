(() => {
  const form = document.getElementById('dashboardMovementForm');
  const list = document.getElementById('expensesList');
  if (!form || !list) return;

  const typeExpense = document.getElementById('expenseTypeButton');
  const typeIncome = document.getElementById('incomeTypeButton');
  const title = document.getElementById('recordTitle');
  const description = document.getElementById('movementDescription');
  const amount = document.getElementById('movementAmount');
  const date = document.getElementById('movementDate');
  const category = document.getElementById('movementCategory');
  const submit = document.getElementById('saveMovementButton');
  const method = document.getElementById('movementMethod');
  const cancel = document.getElementById('cancelMovementEdit');
  const month = document.getElementById('periodMonth');
  const loadButton = document.getElementById('loadMoreMovements');
  const loadContainer = document.getElementById('loadMoreContainer');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const cfg = window.meuBolsoDashboard || {};
  const pageSize = () => window.matchMedia('(max-width: 991.98px)').matches ? 6 : 10;
  let page = 1;
  let hasMore = cfg.hasMore === true;

  function setType(type) {
    const income = type === 'receita';
    typeExpense.disabled = income;
    typeIncome.disabled = !income;
    typeExpense.classList.toggle('active', !income);
    typeIncome.classList.toggle('active', income);
    title.textContent = (method.value === 'PUT' ? 'Editar ' : 'Adicionar ') + (income ? 'receita' : 'despesa');
    submit.textContent = (method.value === 'PUT' ? 'Atualizar ' : 'Guardar ') + (income ? 'receita' : 'despesa');
    submit.className = 'btn w-100 ' + (income ? 'btn-success' : 'btn-danger');
    date.name = income ? 'income_date' : 'expense_date';
    const options = income ? cfg.incomeCategories : cfg.expenseCategories;
    category.replaceChildren(new Option('Seleciona uma categoria', ''));
    Object.entries(options || {}).forEach(([key, label]) => category.add(new Option(label, key)));
  }

  function resetEditor() {
    method.value = 'POST';
    form.action = cfg.expenseStore;
    form.reset();
    date.value = new Date().toISOString().slice(0, 10);
    setType('despesa');
    typeExpense.disabled = false;
    typeIncome.disabled = false;
    cancel.classList.add('d-none');
  }

  list.addEventListener('click', (event) => {
    const button = event.target.closest('.edit-movement');
    if (!button) return;
    const row = button.closest('[data-movement-id]');
    const type = row.dataset.movementType;
    method.value = 'PUT';
    form.action = row.dataset.editUrl;
    setType(type);
    description.value = row.dataset.description;
    amount.value = row.dataset.amount;
    date.value = row.dataset.date;
    category.value = row.dataset.category;
    typeExpense.disabled = type === 'receita';
    typeIncome.disabled = type === 'despesa';
    cancel.classList.remove('d-none');
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    description.focus({ preventScroll: true });
  });

  cancel?.addEventListener('click', resetEditor);
  typeExpense?.addEventListener('click', () => { if (method.value === 'POST') setType('despesa'); });
  typeIncome?.addEventListener('click', () => { if (method.value === 'POST') setType('receita'); });

  function showStatus(message) {
    const status = document.getElementById('offlineStatus');
    if (status) {
      status.textContent = message;
      status.classList.remove('d-none');
    }
  }

  function cacheKey() {
    return ['meu-bolso', 'dashboard', cfg.userId, month?.value, cfg.type, cfg.category].join(':');
  }

  function saveCache() {
    if (!navigator.onLine) return;
    try {
      const snapshot = list.cloneNode(true);
      snapshot.querySelectorAll('form, .edit-movement').forEach((element) => element.remove());
      localStorage.setItem(cacheKey(), JSON.stringify({
        html: snapshot.innerHTML,
        savedAt: new Date().toISOString()
      }));
    } catch (_) {}
  }

  function restoreCache() {
    try {
      const cached = JSON.parse(localStorage.getItem(cacheKey()) || 'null');
      if (!cached?.html) return false;
      list.innerHTML = cached.html;
      showStatus('Sem ligação. A mostrar dados guardados em ' + new Date(cached.savedAt).toLocaleString('pt-PT') + '. Modo de leitura.');
      return true;
    } catch (_) {
      return false;
    }
  }

  function updateOfflineState() {
    const offline = !navigator.onLine;
    document.querySelectorAll('#dashboardMovementForm input, #dashboardMovementForm select, #dashboardMovementForm button, #expensesList form button, #loadMoreMovements').forEach((control) => {
      control.disabled = offline;
    });
    if (offline) {
      if (!restoreCache()) showStatus('Sem ligação e sem movimentos em cache para este período.');
      showStatus('Sem ligação. Os dados estão disponíveis apenas para consulta; alterações estão desativadas.');
    } else {
      document.getElementById('offlineStatus')?.classList.add('d-none');
    }
  }

  function renderItem(item) {
    const isExpense = item.tipo === 'despesa';
    const article = document.createElement('article');
    article.className = 'expense-item';
    article.dataset.movementId = item.id;
    article.dataset.movementType = item.tipo;
    article.dataset.editUrl = (isExpense ? cfg.expenseUpdateBase : cfg.incomeUpdateBase) + '/' + encodeURIComponent(item.id);
    article.dataset.date = item.data_movimento;
    article.dataset.description = item.description;
    article.dataset.amount = item.amount;
    article.dataset.category = item.category;

    const info = document.createElement('div');
    info.className = 'expense-info d-flex align-items-center gap-3';
    const icon = document.createElement('span');
    icon.className = 'expense-category-icon';
    icon.innerHTML = '<i class="bi ' + (isExpense ? 'bi-arrow-up-right' : 'bi-arrow-down-left') + '"></i>';
    const text = document.createElement('div');
    const name = document.createElement('strong');
    name.textContent = item.description;
    const meta = document.createElement('div');
    meta.className = 'small text-secondary';
    meta.textContent = new Date(item.data_movimento + 'T00:00:00').toLocaleDateString('pt-PT') + ' · ' + ((cfg.categories || {})[item.category] || item.category) + ' · ' + (isExpense ? 'Despesa' : 'Receita');
    text.append(name, meta);
    info.append(icon, text);

    const actions = document.createElement('div');
    actions.className = 'd-flex align-items-center gap-2';
    const value = document.createElement('strong');
    value.className = isExpense ? 'text-danger' : 'text-success';
    value.textContent = (isExpense ? '− ' : '+ ') + Number(item.amount).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + item.currency;
    const edit = document.createElement('button');
    edit.type = 'button';
    edit.className = 'btn btn-sm btn-outline-secondary edit-movement';
    edit.setAttribute('aria-label', 'Editar movimento');
    edit.innerHTML = '<i class="bi bi-pencil"></i>';
    const deleteForm = document.createElement('form');
    deleteForm.method = 'POST';
    deleteForm.action = article.dataset.editUrl;
    deleteForm.addEventListener('submit', (event) => {
      if (!confirm('Eliminar este movimento?')) event.preventDefault();
    });
    const token = document.createElement('input');
    token.type = 'hidden'; token.name = '_token'; token.value = csrf || '';
    const verb = document.createElement('input');
    verb.type = 'hidden'; verb.name = '_method'; verb.value = 'DELETE';
    const remove = document.createElement('button');
    remove.type = 'submit'; remove.className = 'btn btn-sm btn-outline-danger';
    remove.setAttribute('aria-label', 'Eliminar movimento');
    remove.innerHTML = '<i class="bi bi-trash"></i>';
    deleteForm.append(token, verb, remove);
    actions.append(value, edit, deleteForm);
    article.append(info, actions);
    return article;
  }

  async function loadMore() {
    if (!hasMore || !navigator.onLine) return;
    loadButton.disabled = true;
    loadButton.textContent = 'A carregar…';
    const params = new URLSearchParams({
      mes: month.value,
      tipo: cfg.type,
      categoria: cfg.category,
      page: String(page + 1),
      limit: String(pageSize())
    });
    try {
      const response = await fetch(cfg.movementsUrl + '?' + params, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const result = await response.json();
      result.data.forEach((item) => list.append(renderItem(item)));
      page = result.page;
      hasMore = result.has_more;
      loadContainer.hidden = !hasMore;
      saveCache();
    } catch (error) {
      showStatus('Não foi possível carregar mais movimentos. Tenta novamente.');
    } finally {
      loadButton.disabled = false;
      loadButton.textContent = 'Carregar mais';
    }
  }

  loadButton?.addEventListener('click', loadMore);

  async function adaptInitialPageToViewport() {
    if (!navigator.onLine) return;
    const limit = pageSize();
    const initialCount = list.querySelectorAll('[data-movement-id]').length;
    if (initialCount === limit) return;
    const params = new URLSearchParams({
      mes: month.value,
      tipo: cfg.type,
      categoria: cfg.category,
      page: '1',
      limit: String(limit)
    });
    try {
      const response = await fetch(cfg.movementsUrl + '?' + params, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      });
      if (!response.ok) return;
      const result = await response.json();
      list.replaceChildren(...result.data.map(renderItem));
      page = 1;
      hasMore = result.has_more;
      loadContainer.hidden = !hasMore;
      saveCache();
    } catch (_) {}
  }

  form.addEventListener('submit', (event) => {
    if (!navigator.onLine) {
      event.preventDefault();
      showStatus('Liga-te à internet para guardar alterações.');
    }
  });

  saveCache();
  updateOfflineState();
  adaptInitialPageToViewport();
  window.addEventListener('offline', updateOfflineState);
  window.addEventListener('online', () => window.location.reload());
})();

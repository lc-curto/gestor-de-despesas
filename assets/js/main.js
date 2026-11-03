
import {
  verificarSessao,
  criarUsuario,
  iniciarSessao,
  encerrarSessao,
  iniciarMonitoramentoInatividade,
  pararMonitoramentoInatividade
} from "./auth.js";

import { inicializarTema, alternarTema } from "./tema.js";
import {
  adicionarDespesa,
  listarDespesas,
  atualizarDespesa,
  eliminarDespesa
} from "./despesas.js";
import {
  adicionarReceita,
  listarReceitas,
  atualizarReceita,
  eliminarReceita
} from "./receitas.js";
import { obterTaxaCambio } from "./api.js";

// ======================================
// ELEMENTOS HTML
// ======================================

const loadingView = document.getElementById("loadingView");
const loginView = document.getElementById("loginView");
const appView = document.getElementById("appView");

const loginForm = document.getElementById("loginForm");
const signupNameGroup = document.getElementById("signupNameGroup");
const signupNameInput = document.getElementById("signupName");
const loginEmail = document.getElementById("loginEmail");
const loginPassword = document.getElementById("loginPassword");

const authTitle = document.getElementById("authTitle");
const authDescription = document.getElementById("authDescription");
const authSubmitButton = document.getElementById("authSubmitButton");
const authToggleText = document.getElementById("authToggleText");
const authToggleButton = document.getElementById("authToggleButton");
const authMessage = document.getElementById("authMessage");

const inactivityModalElement = document.getElementById("inactivityModal");
const inactivityCountdown = document.getElementById("inactivityCountdown");
const continueSessionButton = document.getElementById("continueSessionButton");

const logoutButton = document.getElementById("logoutButton");
const themeToggle = document.getElementById("themeToggle");
const currentDateTime = document.getElementById("currentDateTime");
const greetingTitle = document.getElementById("greetingTitle");

const periodMonth = document.getElementById("periodMonth");
const periodPrevious = document.getElementById("periodPrevious");
const periodNext = document.getElementById("periodNext");
const periodCaption = document.getElementById("periodCaption");

const expenseTypeButton = document.getElementById("expenseTypeButton");
const incomeTypeButton = document.getElementById("incomeTypeButton");
const recordTitle = document.getElementById("recordTitle");
const recordEditorPanel = document.getElementById("recordEditorPanel");
const recordPanelClose = document.getElementById("recordPanelClose");
const saveRecordButton = document.getElementById("saveExpenseButton");
const descriptionLabel = document.getElementById("descriptionLabel");
const amountLabel = document.getElementById("amountLabel");
const categoryLabel = document.getElementById("categoryLabel");
const transactionFilterAll = document.getElementById("transactionFilterAll");
const transactionFilterExpenses = document.getElementById("transactionFilterExpenses");
const transactionFilterIncome = document.getElementById("transactionFilterIncome");
const cancelEditButton = document.getElementById("cancelEditButton");

const expenseForm = document.getElementById("expenseForm");
const expenseDate = document.getElementById("expenseDate");
const expenseAmount = document.getElementById("expenseAmount");
const expenseDescription = document.getElementById("expenseDescription");
const expenseCategory = document.getElementById("expenseCategory");

const categoryFilter = document.getElementById("categoryFilter");
const expensesList = document.getElementById("expensesList");

const totalAmount = document.getElementById("totalAmount");
const totalIncome = document.getElementById("totalIncome");
const balanceAmount = document.getElementById("balanceAmount");

const conversionAmount = document.getElementById("conversionAmount");
const conversionFrom = document.getElementById("conversionFrom");
const conversionTo = document.getElementById("conversionTo");
const convertButton = document.getElementById("convertButton");
const convertedValue = document.getElementById("convertedValue");
const conversionRate = document.getElementById("conversionRate");

// ======================================
// ESTADO DA APLICAÇÃO
// ======================================

let modoRegisto = false;
let dataReferencia = new Date();
let despesasAtuais = [];
let receitasAtuais = [];
let tipoLancamento = "despesa";
let filtroMovimentos = "todos";
let edicaoAtual = null;
let tipoAntesEdicao = "despesa";
let offsetDespesas = 0;
let offsetReceitas = 0;
let haMaisDespesas = false;
let haMaisReceitas = false;
let aCarregarMais = false;
let totalDespesasPeriodo = 0;
let totalReceitasPeriodo = 0;
let quantidadeDespesasPeriodo = 0;
let quantidadeReceitasPeriodo = 0;
let requisicaoLista = 0;
let utilizadorAtualId = null;

const TAMANHO_PAGINA = 10;

let modalInatividade = null;

// ======================================
// CONFIGURAÇÕES
// ======================================

const categorias = {
  casa: "Casa",
  alimentacao: "Alimentação",
  transportes: "Transportes",
  lazer: "Lazer",
  saude: "Saúde",
  outros: "Outros"
};

const categoriasReceita = {
  salario: "Salário",
  vendas: "Vendas",
  trabalho_extra: "Trabalho extra",
  investimentos: "Investimentos",
  reembolsos: "Reembolsos",
  outros: "Outros"
};

const formatadorMoeda = new Intl.NumberFormat("pt-PT", {
  style: "currency",
  currency: "EUR"
});

const formatadorData = new Intl.DateTimeFormat("pt-PT", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric"
});

// ======================================
// FUNÇÕES AUXILIARES
// ======================================

function alterarVista(vista) {
  loadingView.classList.toggle("d-none", vista !== "loading");
  loginView.classList.toggle("d-none", vista !== "login");
  appView.classList.toggle("d-none", vista !== "app");
}

function formatarValor(valor) {
  return formatadorMoeda.format(Number(valor) || 0);
}

function formatarDataLocal(data = new Date()) {
  const ano = data.getFullYear();
  const mes = String(data.getMonth() + 1).padStart(2, "0");
  const dia = String(data.getDate()).padStart(2, "0");

  return `${ano}-${mes}-${dia}`;
}

function formatarDataApresentacao(data) {
  if (!data) return "Data não disponível";

  const [ano, mes, dia] = data.split("-").map(Number);

  return formatadorData.format(new Date(ano, mes - 1, dia));
}

function obterNomeApresentacao(utilizador) {
  // A conta existente nesta alteração é a única sem nome; novos cadastros guardam full_name.
  const dataReferenciaContaExistente = Date.parse("2026-11-03T10:10:48Z");
  const dataCriacao = Date.parse(utilizador.created_at || "");
  if (Number.isFinite(dataCriacao) && dataCriacao < dataReferenciaContaExistente) {
    return "Lucas Curto";
  }

  const nomeGuardado = utilizador.user_metadata?.full_name || utilizador.user_metadata?.name;
  return typeof nomeGuardado === "string" && nomeGuardado.trim()
    ? nomeGuardado.trim()
    : "Utilizador";
}

function escaparHTML(valor) {
  return String(valor).replace(/[&<>"']/g, caractere => {
    const entidades = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    };

    return entidades[caractere];
  });
}

function mostrarMensagem(elemento, mensagem, tipo = "danger") {
  elemento.textContent = mensagem;
  elemento.className = `alert alert-${tipo} mt-3 mb-0`;
}

function ocultarMensagem(elemento) {
  elemento.textContent = "";
  elemento.className = "alert d-none mt-3 mb-0";
}

function obterMensagemErro(erro, mensagemPadrao) {
  return erro?.message || mensagemPadrao;
}

// ======================================
// DATA E HORA
// ======================================

function atualizarDataHora() {
  const agora = new Date();

  currentDateTime.textContent = new Intl.DateTimeFormat("pt-PT", {
    dateStyle: "full",
    timeStyle: "short"
  }).format(agora);
}

// ======================================
// CONTROLO DO PERÍODO
// ======================================

function obterIntervaloPeriodo() {
  const ano = dataReferencia.getFullYear();
  const mes = dataReferencia.getMonth();
  const inicio = new Date(ano, mes, 1);
  const fim = new Date(ano, mes + 1, 1);

  return {
    inicio: formatarDataLocal(inicio),
    fim: formatarDataLocal(fim)
  };
}

function atualizarSeletorPeriodo() {
  const ano = dataReferencia.getFullYear();
  const mes = String(dataReferencia.getMonth() + 1).padStart(2, "0");

  periodMonth.value = `${ano}-${mes}`;
  periodCaption.textContent = new Intl.DateTimeFormat("pt-PT", {
    month: "long",
    year: "numeric"
  }).format(dataReferencia);
}

function alterarPeriodo(direcao) {
  dataReferencia.setMonth(dataReferencia.getMonth() + direcao);
  atualizarSeletorPeriodo();
  carregarDados();
}

function selecionarMes() {
  if (!periodMonth.value) return;

  const [ano, mes] = periodMonth.value.split("-").map(Number);
  dataReferencia = new Date(ano, mes - 1, 1);
  atualizarSeletorPeriodo();
  carregarDados();
}

// ======================================
// RESUMO FINANCEIRO
// ======================================

function atualizarResumo() {
  totalAmount.textContent = formatarValor(totalDespesasPeriodo);
  totalIncome.textContent = formatarValor(totalReceitasPeriodo);
  balanceAmount.textContent = formatarValor(totalReceitasPeriodo - totalDespesasPeriodo);
}

function renderizarMovimentos() {
  const movimentos = [
    ...despesasAtuais.map(item => ({ ...item, tipo: "despesa", data: item.expense_date })),
    ...receitasAtuais.map(item => ({ ...item, tipo: "receita", data: item.income_date }))
  ]
    .filter(item => filtroMovimentos === "todos" || item.tipo === filtroMovimentos)
    .sort((a, b) => b.data.localeCompare(a.data) || String(a.id).localeCompare(String(b.id)));

  const haMais = filtroMovimentos === "despesa"
    ? haMaisDespesas
    : filtroMovimentos === "receita"
      ? haMaisReceitas
      : haMaisDespesas || haMaisReceitas;
  const totalFiltrado = filtroMovimentos === "despesa"
    ? quantidadeDespesasPeriodo
    : filtroMovimentos === "receita"
      ? quantidadeReceitasPeriodo
      : quantidadeDespesasPeriodo + quantidadeReceitasPeriodo;
  const textoContagem = `A mostrar ${movimentos.length} de ${totalFiltrado} ${totalFiltrado === 1 ? "movimento" : "movimentos"}`;
  const controlosLista = `
    <div class="pt-3 pb-4 border-top">
      <p class="small text-secondary text-center mb-2" aria-live="polite">${textoContagem}</p>
      ${haMais ? `
        <button type="button" class="btn btn-outline-primary w-100" id="loadMoreButton" ${aCarregarMais ? "disabled" : ""}>
          ${aCarregarMais ? "A carregar..." : "Carregar mais"}
        </button>
      ` : ""}
    </div>
  `;

  if (movimentos.length === 0) {
    expensesList.innerHTML = `
      <div class="empty-state text-center py-4">
        <div class="empty-icon mb-3" aria-hidden="true">◎</div>
        <h3 class="h6">${haMais ? "Ainda não há movimentos carregados para este filtro" : "Não existem movimentos para este filtro"}</h3>
        <p class="small text-secondary mb-0">${haMais ? "Carrega mais para continuar a procurar." : "Adiciona um movimento ou altera os filtros."}</p>
      </div>${controlosLista}
    `;
    return;
  }

  expensesList.innerHTML = movimentos.map(item => {
    const descricao = escaparHTML(item.description);
    const categoria = item.tipo === "despesa"
      ? categorias[item.category] || "Outros"
      : categoriasReceita[item.category] || "Outros";
    const valor = formatarValor(item.amount);
    const classeTipo = item.tipo === "despesa" ? "expense-icon" : "expense-icon income-icon";
    const classeValor = item.tipo === "despesa" ? "text-danger" : "text-success";
    const icone = item.tipo === "despesa" ? "bi-arrow-up-right" : "bi-arrow-down-left";
    const id = escaparHTML(item.id);

    return `
      <article class="expense-item d-flex justify-content-between align-items-center gap-3 py-3 border-bottom">
        <div class="d-flex align-items-center gap-3 min-w-0">
          <div class="${classeTipo} flex-shrink-0" aria-hidden="true">
            <i class="bi ${icone}"></i>
          </div>
          <div class="min-w-0">
            <h3 class="h6 fw-semibold mb-1 text-break">${descricao}</h3>
            <p class="small text-secondary mb-0">${categoria} · ${formatarDataApresentacao(item.data)}</p>
          </div>
        </div>
        <div class="expense-actions d-flex align-items-center justify-content-end gap-2">
          <strong class="${classeValor} text-nowrap">${item.tipo === "despesa" ? "−" : "+"}${valor}</strong>
          <div class="btn-group btn-group-sm" role="group" aria-label="Ações de ${descricao}">
            <button type="button" class="btn btn-outline-secondary" data-acao="editar" data-id="${id}" data-tipo="${item.tipo}" aria-label="Editar ${descricao}" title="Editar">
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-outline-danger" data-acao="eliminar" data-id="${id}" data-tipo="${item.tipo}" aria-label="Eliminar ${descricao}" title="Eliminar">
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </article>
    `;
  }).join("") + controlosLista;
}

async function carregarDados() {
  const requisicaoAtual = ++requisicaoLista;
  const intervalo = obterIntervaloPeriodo();
  const categoria = categoryFilter.value;

  despesasAtuais = [];
  receitasAtuais = [];
  offsetDespesas = 0;
  offsetReceitas = 0;
  haMaisDespesas = false;
  haMaisReceitas = false;
  aCarregarMais = false;
  totalDespesasPeriodo = 0;
  totalReceitasPeriodo = 0;
  quantidadeDespesasPeriodo = 0;
  quantidadeReceitasPeriodo = 0;

  expensesList.innerHTML = `
    <div class="text-center py-4">
      <div class="spinner-border spinner-border-sm text-primary" role="status">
        <span class="visually-hidden">A carregar...</span>
      </div>
    </div>
  `;

  let resultados;
  try {
    resultados = await Promise.all([
      listarDespesas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria: categoria === "all" ? null : categoria, userId: utilizadorAtualId, offset: 0, limite: TAMANHO_PAGINA + 1 }),
      listarReceitas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria: categoria === "all" ? null : categoria, userId: utilizadorAtualId, offset: 0, limite: TAMANHO_PAGINA + 1 }),
      listarDespesas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria: categoria === "all" ? null : categoria, userId: utilizadorAtualId, somenteMontantes: true }),
      listarReceitas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria: categoria === "all" ? null : categoria, userId: utilizadorAtualId, somenteMontantes: true })
    ]);
  } catch {
    if (requisicaoAtual !== requisicaoLista) return;
    expensesList.innerHTML = '<div class="alert alert-danger mb-0" role="alert">Não foi possível carregar os movimentos. Tenta novamente.</div>';
    atualizarResumo();
    return;
  }

  const [resultadoDespesas, resultadoReceitas, resumoDespesas, resumoReceitas] = resultados;

  if (requisicaoAtual !== requisicaoLista) return;

  if (resultadoDespesas.error || resultadoReceitas.error || resumoDespesas.error || resumoReceitas.error) {
    expensesList.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível carregar os movimentos.</div>';
    despesasAtuais = [];
    receitasAtuais = [];
    atualizarResumo();
    return;
  }

  const dadosDespesas = resultadoDespesas.data || [];
  const dadosReceitas = resultadoReceitas.data || [];
  despesasAtuais = dadosDespesas.slice(0, TAMANHO_PAGINA);
  receitasAtuais = dadosReceitas.slice(0, TAMANHO_PAGINA);
  offsetDespesas = despesasAtuais.length;
  offsetReceitas = receitasAtuais.length;
  haMaisDespesas = dadosDespesas.length > TAMANHO_PAGINA;
  haMaisReceitas = dadosReceitas.length > TAMANHO_PAGINA;
  totalDespesasPeriodo = (resumoDespesas.data || []).reduce((soma, item) => soma + Number(item.amount), 0);
  totalReceitasPeriodo = (resumoReceitas.data || []).reduce((soma, item) => soma + Number(item.amount), 0);
  quantidadeDespesasPeriodo = (resumoDespesas.data || []).length;
  quantidadeReceitasPeriodo = (resumoReceitas.data || []).length;
  atualizarResumo();
  renderizarMovimentos();
}

async function carregarMaisMovimentos() {
  if (aCarregarMais) return;

  const requisicaoAtual = requisicaoLista;
  aCarregarMais = true;
  renderizarMovimentos();
  try {
    const intervalo = obterIntervaloPeriodo();
    const categoria = categoryFilter.value === "all" ? null : categoryFilter.value;
    const [resultadoDespesas, resultadoReceitas] = await Promise.all([
      haMaisDespesas
        ? listarDespesas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria, userId: utilizadorAtualId, offset: offsetDespesas, limite: TAMANHO_PAGINA + 1 })
        : Promise.resolve(null),
      haMaisReceitas
        ? listarReceitas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria, userId: utilizadorAtualId, offset: offsetReceitas, limite: TAMANHO_PAGINA + 1 })
        : Promise.resolve(null)
    ]);

    if (requisicaoAtual !== requisicaoLista) return;

    if (resultadoDespesas?.error || resultadoReceitas?.error) {
      throw resultadoDespesas?.error || resultadoReceitas?.error;
    }

    if (resultadoDespesas) {
      const dados = resultadoDespesas.data || [];
      const novos = dados.slice(0, TAMANHO_PAGINA);
      despesasAtuais = [...despesasAtuais, ...novos];
      offsetDespesas += novos.length;
      haMaisDespesas = dados.length > TAMANHO_PAGINA;
    }

    if (resultadoReceitas) {
      const dados = resultadoReceitas.data || [];
      const novos = dados.slice(0, TAMANHO_PAGINA);
      receitasAtuais = [...receitasAtuais, ...novos];
      offsetReceitas += novos.length;
      haMaisReceitas = dados.length > TAMANHO_PAGINA;
    }
  } catch (erro) {
    if (requisicaoAtual === requisicaoLista) {
      alert(obterMensagemErro(erro, "Não foi possível carregar mais movimentos."));
    }
  } finally {
    if (requisicaoAtual === requisicaoLista) {
      aCarregarMais = false;
      renderizarMovimentos();
    }
  }
}

function alterarFiltroMovimentos(filtro) {
  filtroMovimentos = filtro;

  [transactionFilterAll, transactionFilterExpenses, transactionFilterIncome].forEach(botao => {
    botao.classList.toggle("btn-primary", botao === {
      todos: transactionFilterAll,
      despesa: transactionFilterExpenses,
      receita: transactionFilterIncome
    }[filtro]);
    botao.classList.toggle("btn-link", botao !== {
      todos: transactionFilterAll,
      despesa: transactionFilterExpenses,
      receita: transactionFilterIncome
    }[filtro]);
  });

  transactionFilterAll.setAttribute("aria-pressed", String(filtro === "todos"));
  transactionFilterExpenses.setAttribute("aria-pressed", String(filtro === "despesa"));
  transactionFilterIncome.setAttribute("aria-pressed", String(filtro === "receita"));
  renderizarMovimentos();
}

// ======================================
// AUTENTICAÇÃO
// ======================================

function alternarModoAutenticacao() {
  modoRegisto = !modoRegisto;
  signupNameGroup.classList.toggle("d-none", !modoRegisto);
  signupNameInput.required = modoRegisto;

  if (!modoRegisto) signupNameInput.value = "";

  authTitle.textContent = modoRegisto
    ? "Criar conta"
    : "Bem-vindo";

  authDescription.textContent = modoRegisto
    ? "Regista-te para começares a gerir as tuas despesas."
    : "Inicia sessão para acompanhares as tuas despesas.";

  authSubmitButton.textContent = modoRegisto
    ? "Criar conta"
    : "Entrar";

  authToggleText.textContent = modoRegisto
    ? "Já tens uma conta?"
    : "Ainda não tens uma conta?";

  authToggleButton.textContent = modoRegisto
    ? "Iniciar sessão"
    : "Criar conta";

  loginPassword.autocomplete = modoRegisto
    ? "new-password"
    : "current-password";

  ocultarMensagem(authMessage);
}

async function processarAutenticacao(evento) {
  evento.preventDefault();

  const email = loginEmail.value.trim();
  const password = loginPassword.value;
  const nomeCompleto = signupNameInput.value.trim();

  if (!email || !password || (modoRegisto && !nomeCompleto)) {
    mostrarMensagem(
      authMessage,
      modoRegisto
        ? "Preenche o nome, o email e a palavra-passe."
        : "Preenche o email e a palavra-passe."
    );

    return;
  }

  authSubmitButton.disabled = true;
  authSubmitButton.textContent = "A processar...";

  ocultarMensagem(authMessage);

  try {
    const resultado = modoRegisto
      ? await criarUsuario(email, password, nomeCompleto)
      : await iniciarSessao(email, password);

    if (resultado.error) {
      mostrarMensagem(
        authMessage,
        obterMensagemErro(
          resultado.error,
          "Não foi possível concluir a autenticação."
        )
      );

      return;
    }

    if (modoRegisto && !resultado.session) {
      mostrarMensagem(
        authMessage,
        "Conta criada. Verifica o teu email para confirmares o registo.",
        "success"
      );

      loginForm.reset();
      return;
    }

    await iniciarAplicacao();
  } catch (erro) {
    mostrarMensagem(
      authMessage,
      obterMensagemErro(
        erro,
        "Ocorreu um erro durante a autenticação."
      )
    );
  } finally {
    authSubmitButton.disabled = false;
    authSubmitButton.textContent = modoRegisto
      ? "Criar conta"
      : "Entrar";
  }
}

// ======================================
// CONTROLO DE INATIVIDADE
// ======================================


function iniciarControloInatividade() {
  modalInatividade = bootstrap.Modal.getOrCreateInstance(
    inactivityModalElement
  );

  iniciarMonitoramentoInatividade({
    onCountdown(segundos) {
      if (segundos === null) {
        modalInatividade.hide();
        return;
      }

      const minutos = Math.floor(segundos / 60);
      const resto = segundos % 60;

      inactivityCountdown.textContent =
        `${String(minutos).padStart(2, "0")}:${String(resto).padStart(2, "0")}`;

      modalInatividade.show();
    },

    onTimeout() {
      modalInatividade.hide();
      alterarVista("login");
      loginForm.reset();
      modoRegisto = false;
      alternarModoAutenticacao();
    }
  });
}


function continuarSessao() {
  modalInatividade.hide();
}

async function terminarSessao() {
  pararMonitoramentoInatividade();

  try {
    const resultado = await encerrarSessao();

    if (resultado?.error) {
      await encerrarSessao({ scope: "local" });
    }
  } catch (erro) {
    console.error("Erro ao terminar sessão:", erro);
  }

  alterarVista("login");
  loginForm.reset();
  modoRegisto = false;
  alternarModoAutenticacao();
}

// ======================================
// NOVO LANÇAMENTO
// ======================================

function atualizarTipoLancamento(tipo) {
  tipoLancamento = tipo;
  const isReceita = tipo === "receita";

  recordTitle.textContent = isReceita ? "Adicionar receita" : "Adicionar despesa";
  descriptionLabel.textContent = "Descrição";
  amountLabel.textContent = "Valor";
  categoryLabel.textContent = "Categoria";
  saveRecordButton.textContent = edicaoAtual
    ? (isReceita ? "Atualizar receita" : "Atualizar despesa")
    : (isReceita ? "Guardar receita" : "Guardar despesa");
  saveRecordButton.classList.toggle("btn-success", isReceita);
  saveRecordButton.classList.toggle("btn-danger", !isReceita);
  expenseTypeButton.disabled = Boolean(edicaoAtual && edicaoAtual.tipo !== "despesa");
  incomeTypeButton.disabled = Boolean(edicaoAtual && edicaoAtual.tipo !== "receita");
  cancelEditButton.classList.toggle("d-none", !edicaoAtual);

  expenseTypeButton.classList.toggle("active", !isReceita);
  expenseTypeButton.classList.toggle("btn-danger", !isReceita);
  expenseTypeButton.classList.toggle("btn-outline-danger", isReceita);
  incomeTypeButton.classList.toggle("active", isReceita);
  incomeTypeButton.classList.toggle("btn-success", isReceita);
  incomeTypeButton.classList.toggle("btn-outline-success", !isReceita);
  expenseTypeButton.setAttribute("aria-pressed", String(!isReceita));
  incomeTypeButton.setAttribute("aria-pressed", String(isReceita));

  expenseCategory.innerHTML = isReceita
    ? `<option value="" selected disabled>Seleciona uma categoria</option>
       <option value="salario">Salário</option>
       <option value="vendas">Vendas</option>
       <option value="trabalho_extra">Trabalho extra</option>
       <option value="investimentos">Investimentos</option>
       <option value="reembolsos">Reembolsos</option>
       <option value="outros">Outros</option>`
    : `<option value="" selected disabled>Seleciona uma categoria</option>
       <option value="casa">Casa</option>
       <option value="alimentacao">Alimentação</option>
       <option value="transportes">Transportes</option>
       <option value="lazer">Lazer</option>
       <option value="saude">Saúde</option>
       <option value="outros">Outros</option>`;
}

function iniciarEdicaoMovimento(tipo, id) {
  const lista = tipo === "receita" ? receitasAtuais : despesasAtuais;
  const movimento = lista.find(item => String(item.id) === id);
  if (!movimento) return;

  tipoAntesEdicao = tipoLancamento;
  edicaoAtual = { tipo, id };
  atualizarTipoLancamento(tipo);
  recordTitle.textContent = tipo === "receita" ? "Editar receita" : "Editar despesa";
  expenseDescription.value = movimento.description;
  expenseAmount.value = movimento.amount;
  expenseCategory.value = movimento.category;
  expenseDate.value = tipo === "receita" ? movimento.income_date : movimento.expense_date;

  if (window.matchMedia("(max-width: 991.98px)").matches && !recordEditorPanel.classList.contains("show")) {
    recordEditorPanel.addEventListener("shown.bs.collapse", () => {
      expenseForm.scrollIntoView({ behavior: "smooth", block: "center" });
      expenseDescription.focus({ preventScroll: true });
    }, { once: true });
    bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, { toggle: false }).show();
    return;
  }

  expenseForm.scrollIntoView({ behavior: "smooth", block: "center" });
  expenseDescription.focus({ preventScroll: true });
}

function selecionarTipoLancamento(tipo) {
  if (edicaoAtual) {
    if (tipo === edicaoAtual.tipo && window.matchMedia("(max-width: 991.98px)").matches) {
      bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, { toggle: false }).show();
    }
    return;
  }

  atualizarTipoLancamento(tipo);

  if (window.matchMedia("(max-width: 991.98px)").matches) {
    bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, { toggle: false }).show();
  }
}

function sincronizarEstadoPainel() {
  const aberto = window.matchMedia("(min-width: 992px)").matches || recordEditorPanel.classList.contains("show");
  expenseTypeButton.setAttribute("aria-expanded", String(aberto));
  incomeTypeButton.setAttribute("aria-expanded", String(aberto));
}

function cancelarEdicao() {
  if (!edicaoAtual) return;

  const tipoRestaurado = tipoAntesEdicao;
  edicaoAtual = null;
  expenseForm.reset();
  expenseDate.value = formatarDataLocal();
  atualizarTipoLancamento(tipoRestaurado);

  if (window.matchMedia("(max-width: 991.98px)").matches) {
    bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, { toggle: false }).hide();
  }
}

async function guardarMovimento(evento) {
  evento.preventDefault();
  if (!expenseForm.reportValidity()) return;

  const dados = {
    description: expenseDescription.value.trim(),
    amount: Number(expenseAmount.value),
    category: expenseCategory.value,
    date: expenseDate.value
  };

  if (!dados.description || !Number.isFinite(dados.amount) || dados.amount <= 0 || !dados.category || !dados.date) return;

  saveRecordButton.disabled = true;
  saveRecordButton.textContent = "A guardar...";

  try {
    const resultado = edicaoAtual
      ? (edicaoAtual.tipo === "receita"
        ? await atualizarReceita(edicaoAtual.id, { ...dados, income_date: dados.date }, utilizadorAtualId)
        : await atualizarDespesa(edicaoAtual.id, { ...dados, expense_date: dados.date }, utilizadorAtualId))
      : (tipoLancamento === "receita"
        ? await adicionarReceita({ ...dados, income_date: dados.date })
        : await adicionarDespesa({ ...dados, expense_date: dados.date }));

    if (resultado.error) {
      alert(obterMensagemErro(resultado.error, "Não foi possível guardar as alterações."));
      return;
    }

    if (edicaoAtual) {
      cancelarEdicao();
    } else {
      expenseForm.reset();
      expenseDate.value = formatarDataLocal();
      atualizarTipoLancamento(tipoLancamento);
    }
    await carregarDados();
  } catch (erro) {
    alert(obterMensagemErro(erro, "Ocorreu um erro ao guardar o lançamento."));
  } finally {
    saveRecordButton.disabled = false;
    saveRecordButton.textContent = edicaoAtual
      ? (edicaoAtual.tipo === "receita" ? "Atualizar receita" : "Atualizar despesa")
      : (tipoLancamento === "receita" ? "Guardar receita" : "Guardar despesa");
  }
}

async function eliminarMovimento(tipo, id) {
  const nomeTipo = tipo === "receita" ? "receita" : "despesa";
  if (!window.confirm(`Tens a certeza de que queres eliminar esta ${nomeTipo}?`)) return;

  try {
    const resultado = tipo === "receita"
      ? await eliminarReceita(id, utilizadorAtualId)
      : await eliminarDespesa(id, utilizadorAtualId);

    if (resultado.error) {
      alert(obterMensagemErro(resultado.error, `Não foi possível eliminar a ${nomeTipo}.`));
      return;
    }

    if (!resultado.data?.length) {
      alert(`A ${nomeTipo} não foi eliminada. Pode já não existir ou não teres permissão.`);
      return;
    }

    if (edicaoAtual?.tipo === tipo && edicaoAtual.id === id) cancelarEdicao();
    await carregarDados();
  } catch (erro) {
    alert(obterMensagemErro(erro, `Ocorreu um erro ao eliminar a ${nomeTipo}.`));
  }
}

// ======================================
// CONVERSOR DE MOEDAS
// ======================================

// Regra de negócio: o conversor apenas consulta a API; não cria nem altera movimentos.
async function converterMoeda() {
  const valor = Number(conversionAmount.value);
  const moedaOrigem = conversionFrom.value;
  const moedaDestino = conversionTo.value;

  if (!Number.isFinite(valor) || valor <= 0) {
    convertedValue.textContent = "—";
    conversionRate.textContent = "Introduz um valor válido.";
    return;
  }

  convertButton.disabled = true;
  convertedValue.textContent = "A calcular...";
  conversionRate.textContent = "";

  try {
    if (moedaOrigem === moedaDestino) {
      convertedValue.textContent = new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: moedaDestino
      }).format(valor);

      conversionRate.textContent = "As moedas selecionadas são iguais.";
      return;
    }

    const resultado = await obterTaxaCambio(
      moedaOrigem,
      moedaDestino
    );

    if (!resultado || !Number.isFinite(Number(resultado.rate))) {
      throw new Error("Não foi possível obter a taxa de câmbio.");
    }

    const taxa = Number(resultado.rate);
    const convertido = valor * taxa;

    convertedValue.textContent = new Intl.NumberFormat("pt-PT", {
      style: "currency",
      currency: moedaDestino
    }).format(convertido);

    conversionRate.textContent =
      `1 ${moedaOrigem} = ${taxa.toFixed(4)} ${moedaDestino}` +
      (resultado.date ? ` · Taxa de ${resultado.date}` : "");
  } catch (erro) {
    convertedValue.textContent = "Erro na conversão";

    conversionRate.textContent = obterMensagemErro(
      erro,
      "Não foi possível consultar a taxa de câmbio."
    );
  } finally {
    convertButton.disabled = false;
  }
}

// ======================================
// INICIALIZAÇÃO DA APLICAÇÃO
// ======================================

async function iniciarAplicacao() {
  alterarVista("loading");

  try {
    const resultado = await verificarSessao();

    if (resultado.error || !resultado.user) {
      utilizadorAtualId = null;
      pararMonitoramentoInatividade();
      alterarVista("login");
      return;
    }

    utilizadorAtualId = resultado.user.id;
    greetingTitle.textContent = `Olá, ${obterNomeApresentacao(resultado.user)}!`;

    inicializarTema();

    dataReferencia = new Date();

    expenseDate.value = formatarDataLocal();
    atualizarTipoLancamento("despesa");

    atualizarSeletorPeriodo();
    atualizarDataHora();

    await carregarDados();

    alterarVista("app");

    iniciarControloInatividade();
  } catch (erro) {
    console.error("Erro ao iniciar aplicação:", erro);
    alterarVista("login");
  }
}

// ======================================
// EVENTOS
// ======================================

loginForm.addEventListener("submit", processarAutenticacao);

authToggleButton.addEventListener(
  "click",
  alternarModoAutenticacao
);

themeToggle.addEventListener("click", alternarTema);

logoutButton.addEventListener("click", terminarSessao);

continueSessionButton.addEventListener("click", continuarSessao);

expenseForm.addEventListener("submit", guardarMovimento);
expenseTypeButton.addEventListener("click", () => selecionarTipoLancamento("despesa"));
incomeTypeButton.addEventListener("click", () => selecionarTipoLancamento("receita"));

categoryFilter.addEventListener("change", carregarDados);
transactionFilterAll.addEventListener("click", () => alterarFiltroMovimentos("todos"));
transactionFilterExpenses.addEventListener("click", () => alterarFiltroMovimentos("despesa"));
transactionFilterIncome.addEventListener("click", () => alterarFiltroMovimentos("receita"));
cancelEditButton.addEventListener("click", cancelarEdicao);

recordPanelClose.addEventListener("click", () => {
  bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, { toggle: false }).hide();
});

recordEditorPanel.addEventListener("shown.bs.collapse", sincronizarEstadoPainel);
recordEditorPanel.addEventListener("hidden.bs.collapse", sincronizarEstadoPainel);
window.matchMedia("(min-width: 992px)").addEventListener("change", sincronizarEstadoPainel);
sincronizarEstadoPainel();

expensesList.addEventListener("click", async evento => {
  if (evento.target.closest("#loadMoreButton")) {
    await carregarMaisMovimentos();
    return;
  }

  const botao = evento.target.closest("button[data-acao]");
  if (!botao) return;

  if (botao.dataset.acao === "editar") {
    iniciarEdicaoMovimento(botao.dataset.tipo, botao.dataset.id);
  } else if (botao.dataset.acao === "eliminar") {
    await eliminarMovimento(botao.dataset.tipo, botao.dataset.id);
  }
});

periodPrevious.addEventListener("click", () => {
  alterarPeriodo(-1);
});

periodNext.addEventListener("click", () => {
  alterarPeriodo(1);
});

periodMonth.addEventListener("change", selecionarMes);

convertButton.addEventListener("click", converterMoeda);

conversionAmount.addEventListener("keydown", evento => {
  if (evento.key === "Enter") {
    converterMoeda();
  }
});

// ======================================
// EXECUÇÃO INICIAL
// ======================================

iniciarAplicacao();

window.setInterval(atualizarDataHora, 60000);

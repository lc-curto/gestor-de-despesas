
import {
  verificarSessao,
  criarUsuario,
  iniciarSessao,
  encerrarSessao,
  iniciarMonitoramentoInatividade,
  pararMonitoramentoInatividade
} from "./auth.js";

import { inicializarTema, alternarTema } from "./tema.js";
import { adicionarDespesa, listarDespesas } from "./despesas.js";
import { adicionarReceita, listarReceitas } from "./receitas.js";
import { obterTaxaCambio } from "./api.js";

// ======================================
// ELEMENTOS HTML
// ======================================

const loadingView = document.getElementById("loadingView");
const loginView = document.getElementById("loginView");
const appView = document.getElementById("appView");

const loginForm = document.getElementById("loginForm");
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

const periodMonth = document.getElementById("periodMonth");
const periodPrevious = document.getElementById("periodPrevious");
const periodNext = document.getElementById("periodNext");
const periodCaption = document.getElementById("periodCaption");

const expenseTypeButton = document.getElementById("expenseTypeButton");
const incomeTypeButton = document.getElementById("incomeTypeButton");
const recordTitle = document.getElementById("recordTitle");
const saveRecordButton = document.getElementById("saveExpenseButton");
const descriptionLabel = document.getElementById("descriptionLabel");
const amountLabel = document.getElementById("amountLabel");
const categoryLabel = document.getElementById("categoryLabel");
const transactionFilterAll = document.getElementById("transactionFilterAll");
const transactionFilterExpenses = document.getElementById("transactionFilterExpenses");
const transactionFilterIncome = document.getElementById("transactionFilterIncome");

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
  freelance: "Freelance",
  investimento: "Investimento",
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

function atualizarResumo(despesas, receitas) {
  const totalDespesas = despesas.reduce(
    (soma, despesa) => soma + Number(despesa.amount),
    0
  );

  const totalReceitas = receitas.reduce(
    (soma, receita) => soma + Number(receita.amount),
    0
  );

  totalAmount.textContent = formatarValor(totalDespesas);
  totalIncome.textContent = formatarValor(totalReceitas);
  balanceAmount.textContent = formatarValor(totalReceitas - totalDespesas);
}

// ======================================
// LISTA DE MOVIMENTOS
// ======================================

function atualizarResumo(despesas, receitas) {
  const totalDespesas = despesas.reduce((soma, item) => soma + Number(item.amount), 0);
  const totalReceitas = receitas.reduce((soma, item) => soma + Number(item.amount), 0);

  totalAmount.textContent = formatarValor(totalDespesas);
  totalIncome.textContent = formatarValor(totalReceitas);
  balanceAmount.textContent = formatarValor(totalReceitas - totalDespesas);
}

function renderizarMovimentos() {
  const movimentos = [
    ...despesasAtuais.map(item => ({ ...item, tipo: "despesa", data: item.expense_date })),
    ...receitasAtuais.map(item => ({ ...item, tipo: "receita", data: item.income_date }))
  ]
    .filter(item => filtroMovimentos === "todos" || item.tipo === filtroMovimentos)
    .sort((a, b) => b.data.localeCompare(a.data));

  if (movimentos.length === 0) {
    expensesList.innerHTML = `
      <div class="empty-state text-center py-4">
        <div class="empty-icon mb-3" aria-hidden="true">◎</div>
        <h3 class="h6">Ainda não existem movimentos</h3>
        <p class="small text-secondary mb-0">Adiciona uma despesa ou receita para começar.</p>
      </div>
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
        <strong class="${classeValor} text-nowrap">${item.tipo === "despesa" ? "−" : "+"}${valor}</strong>
      </article>
    `;
  }).join("");
}

async function carregarDados() {
  const intervalo = obterIntervaloPeriodo();
  const categoria = categoryFilter.value;

  expensesList.innerHTML = `
    <div class="text-center py-4">
      <div class="spinner-border spinner-border-sm text-primary" role="status">
        <span class="visually-hidden">A carregar...</span>
      </div>
    </div>
  `;

  const [resultadoDespesas, resultadoReceitas] = await Promise.all([
    listarDespesas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria: categoria === "all" ? null : categoria }),
    listarReceitas({ inicio: intervalo.inicio, fim: intervalo.fim, categoria: categoria === "all" ? null : categoria })
  ]);

  if (resultadoDespesas.error || resultadoReceitas.error) {
    expensesList.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível carregar os movimentos.</div>';
    despesasAtuais = [];
    receitasAtuais = [];
    atualizarResumo(despesasAtuais, receitasAtuais);
    return;
  }

  despesasAtuais = resultadoDespesas.data || [];
  receitasAtuais = resultadoReceitas.data || [];
  atualizarResumo(despesasAtuais, receitasAtuais);
  renderizarMovimentos();
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

  if (!email || !password) {
    mostrarMensagem(
      authMessage,
      "Preenche o email e a palavra-passe."
    );

    return;
  }

  authSubmitButton.disabled = true;
  authSubmitButton.textContent = "A processar...";

  ocultarMensagem(authMessage);

  try {
    const resultado = modoRegisto
      ? await criarUsuario(email, password)
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
  saveRecordButton.textContent = isReceita ? "Guardar receita" : "Guardar despesa";
  saveRecordButton.classList.toggle("btn-success", isReceita);
  saveRecordButton.classList.toggle("btn-danger", !isReceita);

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
       <option value="freelance">Freelance</option>
       <option value="investimento">Investimento</option>
       <option value="outros">Outros</option>`
    : `<option value="" selected disabled>Seleciona uma categoria</option>
       <option value="casa">Casa</option>
       <option value="alimentacao">Alimentação</option>
       <option value="transportes">Transportes</option>
       <option value="lazer">Lazer</option>
       <option value="saude">Saúde</option>
       <option value="outros">Outros</option>`;
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
    const resultado = tipoLancamento === "receita"
      ? await adicionarReceita({ ...dados, income_date: dados.date })
      : await adicionarDespesa({ ...dados, expense_date: dados.date });

    if (resultado.error) {
      alert(obterMensagemErro(resultado.error, "Não foi possível guardar o lançamento."));
      return;
    }

    expenseForm.reset();
    expenseDate.value = formatarDataLocal();
    atualizarTipoLancamento(tipoLancamento);
    await carregarDados();
  } catch (erro) {
    alert(obterMensagemErro(erro, "Ocorreu um erro ao guardar o lançamento."));
  } finally {
    saveRecordButton.disabled = false;
    saveRecordButton.textContent = tipoLancamento === "receita" ? "Guardar receita" : "Guardar despesa";
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
      pararMonitoramentoInatividade();
      alterarVista("login");
      return;
    }

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
expenseTypeButton.addEventListener("click", () => atualizarTipoLancamento("despesa"));
incomeTypeButton.addEventListener("click", () => atualizarTipoLancamento("receita"));

categoryFilter.addEventListener("change", carregarDados);
transactionFilterAll.addEventListener("click", () => alterarFiltroMovimentos("todos"));
transactionFilterExpenses.addEventListener("click", () => alterarFiltroMovimentos("despesa"));
transactionFilterIncome.addEventListener("click", () => alterarFiltroMovimentos("receita"));

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

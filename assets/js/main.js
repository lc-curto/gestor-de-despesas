
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

const periodModeMonth = document.getElementById("periodModeMonth");
const periodModeYear = document.getElementById("periodModeYear");
const periodMonth = document.getElementById("periodMonth");
const periodYear = document.getElementById("periodYear");
const periodPrevious = document.getElementById("periodPrevious");
const periodNext = document.getElementById("periodNext");
const periodCaption = document.getElementById("periodCaption");

const expenseForm = document.getElementById("expenseForm");
const expenseDate = document.getElementById("expenseDate");
const expenseAmount = document.getElementById("expenseAmount");
const expenseDescription = document.getElementById("expenseDescription");
const expenseCategory = document.getElementById("expenseCategory");

const categoryFilter = document.getElementById("categoryFilter");
const expensesList = document.getElementById("expensesList");

const totalAmount = document.getElementById("totalAmount");
const expenseCount = document.getElementById("expenseCount");
const topCategory = document.getElementById("topCategory");

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
let modoPeriodo = "month";
let dataReferencia = new Date();
let despesasAtuais = [];

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

  if (modoPeriodo === "month") {
    const inicio = new Date(ano, mes, 1);
    const fim = new Date(ano, mes + 1, 1);

    return {
      inicio: formatarDataLocal(inicio),
      fim: formatarDataLocal(fim)
    };
  }

  const inicio = new Date(ano, 0, 1);
  const fim = new Date(ano + 1, 0, 1);

  return {
    inicio: formatarDataLocal(inicio),
    fim: formatarDataLocal(fim)
  };
}

function atualizarSeletorPeriodo() {
  const ano = dataReferencia.getFullYear();
  const mes = String(dataReferencia.getMonth() + 1).padStart(2, "0");

  periodModeMonth.classList.toggle(
    "btn-primary",
    modoPeriodo === "month"
  );

  periodModeMonth.classList.toggle(
    "btn-outline-primary",
    modoPeriodo !== "month"
  );

  periodModeYear.classList.toggle(
    "btn-primary",
    modoPeriodo === "year"
  );

  periodModeYear.classList.toggle(
    "btn-outline-primary",
    modoPeriodo !== "year"
  );

  periodModeMonth.setAttribute(
    "aria-pressed",
    String(modoPeriodo === "month")
  );

  periodModeYear.setAttribute(
    "aria-pressed",
    String(modoPeriodo === "year")
  );

  periodMonth.classList.toggle("d-none", modoPeriodo !== "month");
  periodYear.classList.toggle("d-none", modoPeriodo !== "year");

  periodMonth.value = `${ano}-${mes}`;
  periodYear.value = ano;

  if (modoPeriodo === "month") {
    periodCaption.textContent = new Intl.DateTimeFormat("pt-PT", {
      month: "long",
      year: "numeric"
    }).format(dataReferencia);
  } else {
    periodCaption.textContent = `Ano de ${ano}`;
  }
}

function alterarModoPeriodo(modo) {
  modoPeriodo = modo;
  atualizarSeletorPeriodo();
  carregarDespesas();
}

function alterarPeriodo(direcao) {
  if (modoPeriodo === "month") {
    dataReferencia.setMonth(
      dataReferencia.getMonth() + direcao
    );
  } else {
    dataReferencia.setFullYear(
      dataReferencia.getFullYear() + direcao
    );
  }

  atualizarSeletorPeriodo();
  carregarDespesas();
}

function selecionarMes() {
  if (!periodMonth.value) return;

  const [ano, mes] = periodMonth.value.split("-").map(Number);

  dataReferencia = new Date(ano, mes - 1, 1);

  atualizarSeletorPeriodo();
  carregarDespesas();
}

function selecionarAno() {
  const ano = Number(periodYear.value);

  if (!Number.isInteger(ano) || ano < 1) return;

  dataReferencia = new Date(ano, 0, 1);

  atualizarSeletorPeriodo();
  carregarDespesas();
}

// ======================================
// RESUMO FINANCEIRO
// ======================================

function atualizarResumo(despesas) {
  const total = despesas.reduce(
    (soma, despesa) => soma + Number(despesa.amount),
    0
  );

  totalAmount.textContent = formatarValor(total);
  expenseCount.textContent = despesas.length;

  if (despesas.length === 0) {
    topCategory.textContent = "—";
    return;
  }

  const totaisPorCategoria = {};

  despesas.forEach(despesa => {
    const categoria = despesa.category;

    totaisPorCategoria[categoria] =
      (totaisPorCategoria[categoria] || 0) +
      Number(despesa.amount);
  });

  const categoriaPrincipal = Object.entries(totaisPorCategoria)
    .sort((a, b) => b[1] - a[1])[0][0];

  topCategory.textContent =
    categorias[categoriaPrincipal] || categoriaPrincipal;
}

// ======================================
// HISTÓRICO DE DESPESAS
// ======================================

function renderizarDespesas(despesas) {
  if (despesas.length === 0) {
    expensesList.innerHTML = `
      <div class="empty-state text-center py-4">
        <div class="empty-icon mb-3" aria-hidden="true">◎</div>

        <h3 class="h6">
          Ainda não existem despesas registadas
        </h3>

        <p class="small text-secondary mb-0">
          Adiciona uma despesa para começares a acompanhar os teus gastos.
        </p>
      </div>
    `;

    return;
  }

  expensesList.innerHTML = despesas.map(despesa => {
    const descricao = escaparHTML(despesa.description);
    const categoria = categorias[despesa.category] || "Outros";
    const data = formatarDataApresentacao(despesa.expense_date);
    const valor = formatarValor(despesa.amount);

    return `
      <article class="expense-item d-flex justify-content-between align-items-center gap-3 py-3 border-bottom">
        <div class="d-flex align-items-center gap-3 min-w-0">
          <div class="expense-icon flex-shrink-0" aria-hidden="true">
            <i class="bi bi-receipt"></i>
          </div>

          <div class="min-w-0">
            <h3 class="h6 fw-semibold mb-1 text-break">
              ${descricao}
            </h3>

            <p class="small text-secondary mb-0">
              ${categoria} · ${data}
            </p>
          </div>
        </div>

        <strong class="text-nowrap">
          ${valor}
        </strong>
      </article>
    `;
  }).join("");
}

async function carregarDespesas() {
  const intervalo = obterIntervaloPeriodo();
  const categoria = categoryFilter.value;

  expensesList.innerHTML = `
    <div class="text-center py-4">
      <div class="spinner-border spinner-border-sm text-primary" role="status">
        <span class="visually-hidden">A carregar...</span>
      </div>
    </div>
  `;

  const resultado = await listarDespesas({
    inicio: intervalo.inicio,
    fim: intervalo.fim,
    categoria: categoria === "all" ? null : categoria
  });

  if (resultado.error) {
    expensesList.innerHTML = `
      <div class="alert alert-danger mb-0">
        Não foi possível carregar as despesas.
      </div>
    `;

    atualizarResumo([]);
    return;
  }

  despesasAtuais = resultado.data || [];

  atualizarResumo(despesasAtuais);
  renderizarDespesas(despesasAtuais);
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
// REGISTAR DESPESA
// ======================================

async function guardarDespesa(evento) {
  evento.preventDefault();

  if (!expenseForm.reportValidity()) return;

  const dados = {
    description: expenseDescription.value.trim(),
    amount: Number(expenseAmount.value),
    category: expenseCategory.value,
    expense_date: expenseDate.value
  };

  if (
    !dados.description ||
    !Number.isFinite(dados.amount) ||
    dados.amount <= 0 ||
    !dados.category ||
    !dados.expense_date
  ) {
    return;
  }

  const botao = document.getElementById("saveExpenseButton");

  botao.disabled = true;
  botao.textContent = "A guardar...";

  try {
    const resultado = await adicionarDespesa(dados);

    if (resultado.error) {
      alert(
        obterMensagemErro(
          resultado.error,
          "Não foi possível guardar a despesa."
        )
      );

      return;
    }

    expenseForm.reset();
    expenseDate.value = formatarDataLocal();

    await carregarDespesas();
  } catch (erro) {
    alert(
      obterMensagemErro(
        erro,
        "Ocorreu um erro ao guardar a despesa."
      )
    );
  } finally {
    botao.disabled = false;
    botao.textContent = "Guardar despesa";
  }
}

// ======================================
// CONVERSOR DE MOEDAS
// ======================================

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

    atualizarSeletorPeriodo();
    atualizarDataHora();

    await carregarDespesas();

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

expenseForm.addEventListener("submit", guardarDespesa);

categoryFilter.addEventListener("change", carregarDespesas);

periodModeMonth.addEventListener("click", () => {
  alterarModoPeriodo("month");
});

periodModeYear.addEventListener("click", () => {
  alterarModoPeriodo("year");
});

periodPrevious.addEventListener("click", () => {
  alterarPeriodo(-1);
});

periodNext.addEventListener("click", () => {
  alterarPeriodo(1);
});

periodMonth.addEventListener("change", selecionarMes);

periodYear.addEventListener("change", selecionarAno);

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

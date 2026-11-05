// PONTO DE ENTRADA DA INTERFACE
// Este ficheiro coordena os módulos da aplicação. A lógica específica de autenticação,
// dados, tema e câmbio continua nos respetivos módulos importados abaixo.

import {
  verificarSessao,
  criarUsuario,
  iniciarSessao,
  encerrarSessao,
  iniciarMonitoramentoInatividade,
  pararMonitoramentoInatividade,
} from "./auth.js";

import { inicializarTema, alternarTema } from "./tema.js";

import {
  adicionarDespesa,
  listarDespesas,
  atualizarDespesa,
  eliminarDespesa,
} from "./despesas.js";

import {
  adicionarReceita,
  listarReceitas,
  atualizarReceita,
  eliminarReceita,
} from "./receitas.js";

import { obterTaxaCambio } from "./api.js";

// ======================================
// REFERÊNCIAS AO HTML
// Guardamos os elementos do DOM uma única vez para os reutilizar nas funções.
// Os IDs têm de corresponder aos definidos em index.html.
// ======================================

const loadingView = document.getElementById("loadingView");
const loginView = document.getElementById("loginView");
const appView = document.getElementById("appView");
const offlineStatus = document.getElementById("offlineStatus");

const loginForm = document.getElementById("loginForm");
const signupNameGroup = document.getElementById("signupNameGroup");
const signupCurrencyGroup = document.getElementById("signupCurrencyGroup");
const signupCurrency = document.getElementById("signupCurrency");
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
const installAppButton = document.getElementById("installAppButton");
const accountDisplayName = document.getElementById("accountDisplayName");
const accountEmail = document.getElementById("accountEmail");
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
const transactionFilterExpenses = document.getElementById(
  "transactionFilterExpenses",
);
const transactionFilterIncome = document.getElementById(
  "transactionFilterIncome",
);
const cancelEditButton = document.getElementById("cancelEditButton");

const expenseForm = document.getElementById("expenseForm");
const expenseDate = document.getElementById("expenseDate");
const expenseAmount = document.getElementById("expenseAmount");
const amountCurrencySymbol = document.getElementById("amountCurrencySymbol");
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
// Variáveis que representam a situação atual da interface e dos dados em memória.
// Não são dados permanentes: os movimentos são obtidos e guardados através dos módulos de dados.
// ======================================

const TAMANHO_PAGINA_DESKTOP = 10;
const TAMANHO_PAGINA_MOVEL = 6;
const MEDIA_QUERY_MOVEL = "(max-width: 991.98px)";

// Define quantos movimentos são apresentados inicialmente e quantos são acrescentados
// em cada pedido. No telemóvel são usados grupos menores para facilitar a navegação.
function obterLimiteMovimentos() {
  return window.matchMedia(MEDIA_QUERY_MOVEL).matches
    ? TAMANHO_PAGINA_MOVEL
    : TAMANHO_PAGINA_DESKTOP;
}

// Mantém o tamanho de cada carregamento adicional alinhado com o limite inicial.
function obterIncrementoMovimentos() {
  return obterLimiteMovimentos();
}

// Define quantos movimentos são apresentados na primeira renderização da lista.
function obterQuantidadeInicialMovimentos() {
  return obterLimiteMovimentos();
}

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
let quantidadeDespesasFiltradas = 0;
let quantidadeReceitasFiltradas = 0;
let quantidadeMovimentosVisiveis = obterQuantidadeInicialMovimentos();
let requisicaoLista = 0;
let utilizadorAtualId = null;
let moedaAtual = "EUR";
let eventoInstalacaoPendente = null;

let modalInatividade = null;

// ======================================
// CONFIGURAÇÕES DA INTERFACE
// Categorias, regras de apresentação e formatadores partilhados por várias funções.
// ======================================

const categoriasDespesa = {
  casa: "Casa",
  alimentacao: "Alimentação",
  transportes: "Transporte",
  saude: "Saúde",
  lazer: "Lazer",
  compras: "Compras",
  educacao: "Educação",
  outros: "Outros",
};

const categoriasReceita = {
  salario: "Salário",
  vendas: "Vendas",
  trabalho_extra: "Trabalho extra",
  investimentos: "Investimentos",
  reembolsos: "Reembolsos",
  outros: "Outros",
};

const palavrasLigacao = new Set([
  "a",
  "as",
  "o",
  "os",
  "um",
  "uma",
  "uns",
  "umas",
  "de",
  "da",
  "das",
  "do",
  "dos",
  "em",
  "na",
  "nas",
  "no",
  "nos",
  "por",
  "para",
  "com",
  "sem",
  "e",
  "ou",
]);

const entidadesHTML = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#039;",
};

let formatadorMoeda = new Intl.NumberFormat("pt-PT", {
  style: "currency",
  currency: moedaAtual,
});

const formatadorData = new Intl.DateTimeFormat("pt-PT", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
});

// ======================================
// FUNÇÕES AUXILIARES
// Funções pequenas e reutilizáveis. Centralizam tarefas comuns para evitar repetição.
// ======================================

// Mostra apenas uma das três vistas principais: carregamento, autenticação ou aplicação.
function alterarVista(vista) {
  loadingView.classList.toggle("d-none", vista !== "loading");
  loginView.classList.toggle("d-none", vista !== "login");
  appView.classList.toggle("d-none", vista !== "app");
}

// Formata valores numéricos com a moeda atualmente definida para a conta.
function formatarValor(valor) {
  return formatadorMoeda.format(Number(valor) || 0);
}

// Converte uma data para o formato ISO usado nos campos HTML e nas consultas à base de dados.
// É construída com componentes locais para evitar alterações de dia causadas pelo fuso horário.
function formatarDataLocal(data = new Date()) {
  const ano = data.getFullYear();
  const mes = String(data.getMonth() + 1).padStart(2, "0");
  const dia = String(data.getDate()).padStart(2, "0");

  return `${ano}-${mes}-${dia}`;
}

// Converte a data recebida da base de dados (AAAA-MM-DD) para o formato português.
function formatarDataApresentacao(data) {
  if (!data) return "Data não disponível";

  const [ano, mes, dia] = data.split("-").map(Number);

  return formatadorData.format(new Date(ano, mes - 1, dia));
}

// Obtém o nome guardado nos metadados da conta para apresentar na interface.
function obterNomeApresentacao(utilizador) {
  const nomeGuardado =
    utilizador.user_metadata?.full_name || utilizador.user_metadata?.name;

  return typeof nomeGuardado === "string" && nomeGuardado.trim()
    ? nomeGuardado.trim()
    : "Utilizador";
}

// Melhora apenas a apresentação da descrição. O valor original guardado na base de dados
// não é alterado por esta função.
function formatarDescricaoMovimento(valor) {
  return String(valor ?? "")
    .toLocaleLowerCase("pt-PT")
    .replace(/\S+/gu, (palavra, indice, texto) => {
      const inicioFrase =
        indice === 0 || /[.!?;:]\s+$/u.test(texto.slice(0, indice));
      if (!inicioFrase && palavrasLigacao.has(palavra)) return palavra;

      return palavra.charAt(0).toLocaleUpperCase("pt-PT") + palavra.slice(1);
    });
}

// Escapa caracteres especiais antes de inserir texto dinâmico no HTML, reduzindo o risco
// de interpretar uma descrição do utilizador como código HTML.
function escaparHTML(valor) {
  return String(valor).replace(
    /[&<>"']/g,
    (caractere) => entidadesHTML[caractere],
  );
}

// Recebe um objeto de categoriasDespesa e cria as opções do seletor usado no formulário.
// Assim, despesas e receitas partilham a mesma lógica de construção do campo.
function criarOpcoesCategoria(listaCategorias) {
  const opcoes = Object.entries(listaCategorias)
    .map(([valor, nome]) => `<option value="${valor}">${nome}</option>`)
    .join("");

  return `<option value="" selected disabled>Seleciona uma categoria</option>${opcoes}`;
}

// Apresenta uma mensagem no elemento indicado, usando o estilo de alerta do Bootstrap.
function mostrarMensagem(elemento, mensagem, tipo = "danger") {
  elemento.textContent = mensagem;
  elemento.className = `alert alert-${tipo} mt-3 mb-0`;
}

// Limpa e esconde um alerta sem precisar de remover o elemento do HTML.
function ocultarMensagem(elemento) {
  elemento.textContent = "";
  elemento.className = "alert d-none mt-3 mb-0";
}

// Usa a mensagem original do erro quando existe; caso contrário, devolve uma alternativa compreensível.
function obterMensagemErro(erro, mensagemPadrao) {
  const mensagemOriginal = String(erro?.message || "").trim();
  const mensagemNormalizada = mensagemOriginal.toLowerCase();

  // Traduz o erro de moeda inválida na tabela de receitas sem expor detalhes da base de dados.
  if (
    mensagemNormalizada.includes("receitas_currency_check") ||
    (mensagemNormalizada.includes("check constraint") &&
      mensagemNormalizada.includes("receitas"))
  ) {
    return "Não foi possível guardar a receita porque a moeda da tua conta não é aceite para este tipo de movimento. A receita não foi guardada. Verifica a configuração da moeda da conta ou contacta o suporte.";
  }

  // O Supabase limita o envio de emails de autenticação num determinado período.
  // Traduzimos este erro técnico e indicamos ao utilizador como pode prosseguir.
  if (
    mensagemNormalizada.includes("email rate limit exceeded") ||
    mensagemNormalizada.includes("rate limit")
  ) {
    return "Atingiste o limite de emails de confirmação. Aguarda alguns minutos antes de tentares novamente. Se já recebeste um email, verifica a tua caixa de entrada e a pasta de spam.";
  }

  return mensagemOriginal || mensagemPadrao;
}

// A chave identifica o utilizador, o período e a categoria. Assim, cada combinação
// tem a sua própria entrada de cache no armazenamento local do navegador.
function obterChaveCache() {
  const intervalo = obterIntervaloPeriodo();
  return (
    "meu-bolso:" +
    utilizadorAtualId +
    ":" +
    intervalo.inicio +
    ":" +
    intervalo.fim +
    ":" +
    categoryFilter.value
  );
}

// Guarda localmente os últimos dados carregados. O cache permite consultar informação
// anteriormente obtida quando não existe ligação à internet.
function guardarDadosEmCache(dados) {
  try {
    localStorage.setItem(
      obterChaveCache(),
      JSON.stringify({
        guardadoEm: new Date().toISOString(),
        ...dados,
      }),
    );
  } catch (erro) {
    console.warn("Não foi possível guardar os dados localmente:", erro);
  }
}

// Recupera e converte de JSON para objeto os dados guardados no localStorage.
function obterDadosEmCache() {
  try {
    const dados = localStorage.getItem(obterChaveCache());
    return dados ? JSON.parse(dados) : null;
  } catch (erro) {
    console.warn("Não foi possível ler os dados locais:", erro);
    return null;
  }
}

// Atualiza o aviso de ligação à internet; uma mensagem vazia esconde o elemento.
function atualizarAvisoLigacao(mensagem) {
  if (!offlineStatus) return;
  offlineStatus.textContent = mensagem || "";
  offlineStatus.classList.toggle("d-none", !mensagem);
}

// Reconstitui o estado da interface a partir do cache. Estes dados são apenas os movimentos
// que já tinham sido carregados anteriormente; não representam necessariamente o período completo.
// Reconstitui o estado da interface a partir do cache e apresenta o motivo correto da utilização dos dados locais.
function aplicarDadosEmCache(cache, mensagemAviso = "Sem ligação à internet.") {
  despesasAtuais = Array.isArray(cache.despesas) ? cache.despesas : [];
  receitasAtuais = Array.isArray(cache.receitas) ? cache.receitas : [];
  totalDespesasPeriodo = Number(cache.totalDespesasPeriodo) || 0;
  totalReceitasPeriodo = Number(cache.totalReceitasPeriodo) || 0;
  quantidadeDespesasFiltradas =
    Number(cache.quantidadeDespesasFiltradas) || despesasAtuais.length;
  quantidadeReceitasFiltradas =
    Number(cache.quantidadeReceitasFiltradas) || receitasAtuais.length;
  offsetDespesas = despesasAtuais.length;
  offsetReceitas = receitasAtuais.length;
  haMaisDespesas = false;
  haMaisReceitas = false;
  quantidadeMovimentosVisiveis = obterQuantidadeInicialMovimentos();

  const dataCache = cache.guardadoEm
    ? new Intl.DateTimeFormat("pt-PT", {
        dateStyle: "short",
        timeStyle: "short",
      }).format(new Date(cache.guardadoEm))
    : "data desconhecida";

  atualizarAvisoLigacao(
    mensagemAviso +
      " Dados guardados em " +
      dataCache +
      ". Apenas são apresentados os movimentos previamente carregados.",
  );
  atualizarResumo();
  renderizarMovimentos();
}

// ======================================
// DATA E HORA
// Atualização da data apresentada no cabeçalho da aplicação.
// ======================================

// Atualiza a data e hora visíveis no cabeçalho com a formatação local portuguesa.
function atualizarDataHora() {
  const agora = new Date();

  currentDateTime.textContent = new Intl.DateTimeFormat("pt-PT", {
    dateStyle: "full",
    timeStyle: "short",
  }).format(agora);
}

// ======================================
// NAVEGAÇÃO ENTRE PERÍODOS
// Define o mês selecionado e calcula o intervalo usado para filtrar movimentos.
// ======================================

function obterIntervaloPeriodo() {
  const ano = dataReferencia.getFullYear();
  const mes = dataReferencia.getMonth();
  const inicio = new Date(ano, mes, 1);
  const fim = new Date(ano, mes + 1, 1);

  return {
    inicio: formatarDataLocal(inicio),
    fim: formatarDataLocal(fim),
  };
}

// Sincroniza o seletor de mês e a legenda textual com dataReferencia.
function atualizarSeletorPeriodo() {
  const ano = dataReferencia.getFullYear();
  const mes = String(dataReferencia.getMonth() + 1).padStart(2, "0");

  periodMonth.value = `${ano}-${mes}`;
  periodCaption.textContent = new Intl.DateTimeFormat("pt-PT", {
    month: "long",
    year: "numeric",
  }).format(dataReferencia);
}

// Avança ou recua um mês e volta a carregar os movimentos correspondentes.
function alterarPeriodo(direcao) {
  dataReferencia = new Date(
    dataReferencia.getFullYear(),
    dataReferencia.getMonth() + direcao,
    1,
  );

  atualizarSeletorPeriodo();
  carregarDados(); // Função async que Carrega os dados do DB e monta a interface
}

// Lê o mês escolhido no seletor, atualiza a referência e consulta os respetivos dados.
function selecionarMes() {
  if (!periodMonth.value) return;

  const [ano, mes] = periodMonth.value.split("-").map(Number);
  dataReferencia = new Date(ano, mes - 1, 1);
  atualizarSeletorPeriodo();
  carregarDados(); //Função async que Carrega os dados do DB e monta a interface
}

// ======================================
// RESUMO E HISTÓRICO DE MOVIMENTOS
// O resumo financeiro é calculado sobre todos os registos do período; a lista pode estar paginada.
// ======================================

// Atualiza os três indicadores financeiros a partir dos totais já calculados.
function atualizarResumo() {
  totalAmount.textContent = formatarValor(totalDespesasPeriodo);
  totalIncome.textContent = formatarValor(totalReceitasPeriodo);
  balanceAmount.textContent = formatarValor(
    totalReceitasPeriodo - totalDespesasPeriodo,
  );
}

// Prepara os parâmetros comuns enviados aos módulos despesas.js e receitas.js.
// A categoria "all" da interface significa que não deve ser aplicado filtro de categoria.
function obterParametrosConsulta(inicio, fim, categoria) {
  return {
    inicio,
    fim,
    categoria: categoria === "all" ? null : categoria,
    userId: utilizadorAtualId,
  };
}

// Devolve a quantidade total correspondente ao filtro de tipo atualmente selecionado.
function obterTotalMovimentos() {
  if (filtroMovimentos === "despesa") return quantidadeDespesasFiltradas;
  if (filtroMovimentos === "receita") return quantidadeReceitasFiltradas;

  return quantidadeDespesasFiltradas + quantidadeReceitasFiltradas;
}

// Conta apenas os registos já recebidos da base de dados, não o total do período.
function obterQuantidadeMovimentosCarregados() {
  if (filtroMovimentos === "despesa") return despesasAtuais.length;
  if (filtroMovimentos === "receita") return receitasAtuais.length;

  return despesasAtuais.length + receitasAtuais.length;
}

// Define o comportamento quando a consulta falha: tenta recuperar o cache ou apresenta um erro.
function mostrarErroCarregamento() {
  const estaOffline = !navigator.onLine;
  const cache = obterDadosEmCache();
  const mensagemAviso = estaOffline
    ? "Sem ligação à internet."
    : "Não foi possível sincronizar os dados com o servidor.";

  if (cache) {
    aplicarDadosEmCache(cache, mensagemAviso);
    return;
  }

  despesasAtuais = [];
  receitasAtuais = [];
  expensesList.innerHTML = estaOffline
    ? '<div class="alert alert-warning mb-0" role="alert">Sem ligação à internet e sem dados guardados neste dispositivo.</div>'
    : '<div class="alert alert-danger mb-0" role="alert">Não foi possível sincronizar os movimentos com o servidor. Verifica a ligação e tenta novamente.</div>';
  atualizarAvisoLigacao(mensagemAviso);
  atualizarResumo();
}

// Junta despesas e receitas numa única lista visual, aplica o filtro selecionado,
// ordena por data e apresenta apenas a quantidade atualmente visível.
function renderizarMovimentos() {
  const movimentos = [
    ...despesasAtuais.map((item) => ({
      ...item,
      tipo: "despesa",
      data: item.expense_date,
    })),
    ...receitasAtuais.map((item) => ({
      ...item,
      tipo: "receita",
      data: item.income_date,
    })),
  ]
    .filter(
      (item) => filtroMovimentos === "todos" || item.tipo === filtroMovimentos,
    )
    .sort(
      (a, b) =>
        b.data.localeCompare(a.data) ||
        String(a.id).localeCompare(String(b.id)),
    );

  const movimentosVisiveis = movimentos.slice(0, quantidadeMovimentosVisiveis);
  const totalFiltrado = obterTotalMovimentos();
  const haMais = movimentosVisiveis.length < totalFiltrado;
  const textoContagem = `A mostrar ${movimentosVisiveis.length} de ${totalFiltrado} ${totalFiltrado === 1 ? "movimento" : "movimentos"}`;
  const controlosLista = `
    <div class="pt-3 pb-4">
      <p class="small text-secondary text-center mb-0" aria-live="polite">${textoContagem}</p>
      ${haMais ? `<div class="d-flex justify-content-center pt-3"><button type="button" class="btn btn-outline-primary btn-sm px-4" id="loadMoreMovements" ${aCarregarMais ? "disabled" : ""}>${aCarregarMais ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>A carregar…' : "Ver mais movimentos"}</button></div>` : ""}
    </div>
  `;

  if (movimentosVisiveis.length === 0) {
    expensesList.innerHTML = `
      <div class="empty-state text-center py-4">
        <div class="empty-icon mb-3" aria-hidden="true">◎</div>
        <h3 class="h6">Não existem movimentos para este filtro</h3>
        <p class="small text-secondary mb-0">Adiciona um movimento ou altera os filtros.</p>
      </div>${controlosLista}
    `;
    return;
  }

  expensesList.innerHTML =
    movimentosVisiveis
      .map((item) => {
        const descricao = escaparHTML(
          formatarDescricaoMovimento(item.description),
        );
        const categoria =
          item.tipo === "despesa"
            ? categoriasDespesa[item.category] || "Outros"
            : categoriasReceita[item.category] || "Outros";
        const valor = formatarValor(item.amount);
        const classeTipo =
          item.tipo === "despesa" ? "expense-icon" : "expense-icon income-icon";
        const classeValor =
          item.tipo === "despesa" ? "text-danger" : "text-success";
        const icone =
          item.tipo === "despesa" ? "bi-arrow-up-right" : "bi-arrow-down-left";
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
      })
      .join("") + controlosLista;
}

// Carregamento inicial ou atualização do histórico após mudar o mês, categoria ou registo.
// Promise.all permite consultar despesas, receitas e respetivos totais em paralelo.
// O identificador da requisição impede uma resposta antiga de substituir dados mais recentes.
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
  quantidadeMovimentosVisiveis = obterQuantidadeInicialMovimentos();
  totalDespesasPeriodo = 0;
  totalReceitasPeriodo = 0;
  quantidadeDespesasFiltradas = 0;
  quantidadeReceitasFiltradas = 0;

  expensesList.innerHTML = `
    <div class="text-center py-4">
      <div class="spinner-border spinner-border-sm text-primary" role="status">
        <span class="visually-hidden">A carregar...</span>
      </div>
    </div>
  `;

  // O histórico respeita a categoria selecionada; os totais dos cards abrangem todo o período.
  const parametros = obterParametrosConsulta(
    intervalo.inicio,
    intervalo.fim,
    categoria,
  ); // utilizado na lsitagem
  const parametrosResumo = obterParametrosConsulta(
    intervalo.inicio,
    intervalo.fim,
    "all",
  ); // Utilizado nos KPIS
  let resultados;

  try {
    resultados = await Promise.all([
      listarDespesas({
        ...parametros,
        offset: 0,
        limite: obterLimiteMovimentos(),
      }),
      listarReceitas({
        ...parametros,
        offset: 0,
        limite: obterLimiteMovimentos(),
      }),
      listarDespesas({ ...parametrosResumo, somenteMontantes: true }),
      listarReceitas({ ...parametrosResumo, somenteMontantes: true }),
    ]);
  } catch (erro) {
    console.error("Erro ao carregar movimentos:", erro);
    if (requisicaoAtual === requisicaoLista) mostrarErroCarregamento();
    return;
  }

  const [resultadoDespesas, resultadoReceitas, resumoDespesas, resumoReceitas] =
    resultados;

  if (requisicaoAtual !== requisicaoLista) return;

  const erroConsulta =
    resultadoDespesas.error ||
    resultadoReceitas.error ||
    resumoDespesas.error ||
    resumoReceitas.error;

  if (erroConsulta) {
    console.error("Erro nas consultas dos movimentos:", erroConsulta);
    mostrarErroCarregamento();
    return;
  }

  const dadosDespesas = resultadoDespesas.data || [];
  const dadosReceitas = resultadoReceitas.data || [];
  despesasAtuais = dadosDespesas.slice(0, obterLimiteMovimentos()); // vai trazer a quantidade pre definida de movimentos para PC (10) ou mobile (6)
  receitasAtuais = dadosReceitas.slice(0, obterLimiteMovimentos());
  offsetDespesas = despesasAtuais.length;
  offsetReceitas = receitasAtuais.length; // define a partir de onde vamos buscar na base de dados, esta relacionado com a paginacao dos movimentos
  totalDespesasPeriodo = (resumoDespesas.data || []).reduce(
    (soma, item) => soma + Number(item.amount),
    0,
  );
  totalReceitasPeriodo = (resumoReceitas.data || []).reduce(
    //O reduce() percorre um array e acumula os seus elementos num único resultado.
    (soma, item) => soma + Number(item.amount),
    0,
  );
  quantidadeDespesasFiltradas = resultadoDespesas.count ?? dadosDespesas.length;
  quantidadeReceitasFiltradas = resultadoReceitas.count ?? dadosReceitas.length;
  haMaisDespesas = offsetDespesas < quantidadeDespesasFiltradas;
  haMaisReceitas = offsetReceitas < quantidadeReceitasFiltradas;
  guardarDadosEmCache({
    despesas: despesasAtuais,
    receitas: receitasAtuais,
    totalDespesasPeriodo,
    totalReceitasPeriodo,
    quantidadeDespesasFiltradas,
    quantidadeReceitasFiltradas,
  });
  atualizarAvisoLigacao("");
  atualizarResumo(); // Atualiza os KPIS
  renderizarMovimentos(); // Monsta a lista de movimentos
}

// Paginação manual: só consulta mais registos quando o utilizador pede para ver mais.
// Despesas e receitas têm offsets separados porque são tabelas distintas na base de dados.
async function carregarMaisMovimentos() {
  if (aCarregarMais) return;

  const requisicaoAtual = requisicaoLista;
  const totalMovimentos = obterTotalMovimentos();
  const proximoLimite = Math.min(
    quantidadeMovimentosVisiveis + obterIncrementoMovimentos(),
    totalMovimentos,
  );
  const movimentosCarregados = obterQuantidadeMovimentosCarregados();

  if (quantidadeMovimentosVisiveis >= totalMovimentos) return;

  aCarregarMais = true;
  renderizarMovimentos();

  try {
    if (movimentosCarregados < proximoLimite) {
      const intervalo = obterIntervaloPeriodo();
      const parametros = obterParametrosConsulta(
        intervalo.inicio,
        intervalo.fim,
        categoryFilter.value,
      );
      const carregarDespesas = filtroMovimentos !== "receita" && haMaisDespesas;
      const carregarReceitas = filtroMovimentos !== "despesa" && haMaisReceitas;

      const [resultadoDespesas, resultadoReceitas] = await Promise.all([
        carregarDespesas
          ? listarDespesas({
              ...parametros,
              offset: offsetDespesas, // a partir de onde
              limite: obterLimiteMovimentos(), // até onde
            })
          : Promise.resolve(null),
        carregarReceitas
          ? listarReceitas({
              ...parametros,
              offset: offsetReceitas,
              limite: obterLimiteMovimentos(),
            })
          : Promise.resolve(null),
      ]);

      if (requisicaoAtual !== requisicaoLista) return;
      if (resultadoDespesas?.error || resultadoReceitas?.error) {
        throw resultadoDespesas?.error || resultadoReceitas?.error;
      }

      if (resultadoDespesas) {
        const novos = resultadoDespesas.data || [];
        despesasAtuais = [...despesasAtuais, ...novos]; // vai juntar tudo que tem nas despesas atuais com tudo que tem nas novas despesas
        offsetDespesas += novos.length;
        haMaisDespesas = offsetDespesas < quantidadeDespesasFiltradas;
      }

      if (resultadoReceitas) {
        const novos = resultadoReceitas.data || [];
        receitasAtuais = [...receitasAtuais, ...novos]; // vai juntar tudo que tem nas receitas atuais com tudo que tem nas novas receitas
        offsetReceitas += novos.length;
        haMaisReceitas = offsetReceitas < quantidadeReceitasFiltradas;
      }
    }

    quantidadeMovimentosVisiveis = proximoLimite;
    guardarDadosEmCache({
      despesas: despesasAtuais,
      receitas: receitasAtuais,
      totalDespesasPeriodo,
      totalReceitasPeriodo,
      quantidadeDespesasFiltradas,
      quantidadeReceitasFiltradas,
    });
  } catch (erro) {
    if (requisicaoAtual === requisicaoLista) {
      alert(
        obterMensagemErro(erro, "Não foi possível carregar mais movimentos."),
      );
    }
  } finally {
    if (requisicaoAtual === requisicaoLista) {
      aCarregarMais = false;
      renderizarMovimentos();
    }
  }
}

// Altera o filtro visual (todos, despesas ou receitas) sem voltar a consultar a base de dados.
function alterarFiltroMovimentos(filtro) {
  filtroMovimentos = filtro;
  quantidadeMovimentosVisiveis = obterQuantidadeInicialMovimentos();

  const botaoAtivo = {
    todos: transactionFilterAll,
    despesa: transactionFilterExpenses,
    receita: transactionFilterIncome,
  }[filtro];

  [
    transactionFilterAll,
    transactionFilterExpenses,
    transactionFilterIncome,
  ].forEach((botao) => {
    const ativo = botao === botaoAtivo;
    botao.classList.toggle("btn-primary", ativo);
    botao.classList.toggle("btn-link", !ativo);
  });

  transactionFilterAll.setAttribute("aria-pressed", String(filtro === "todos"));
  transactionFilterExpenses.setAttribute(
    "aria-pressed",
    String(filtro === "despesa"),
  );
  transactionFilterIncome.setAttribute(
    "aria-pressed",
    String(filtro === "receita"),
  );
  renderizarMovimentos();
}

// ======================================
// AUTENTICAÇÃO E SESSÃO
// O main.js gere a interação com o formulário e delega as operações de autenticação em auth.js.
// ======================================

// Alterna entre os formulários de início de sessão e criação de conta, atualizando os campos
// obrigatórios, textos e atributos de preenchimento automático.
function alternarModoAutenticacao(modo = !modoRegisto) {
  modoRegisto = modo;
  signupNameGroup.classList.toggle("d-none", !modoRegisto);
  signupCurrencyGroup.classList.toggle("d-none", !modoRegisto);
  signupNameInput.required = modoRegisto;

  if (!modoRegisto) signupNameInput.value = "";

  authTitle.textContent = modoRegisto ? "Criar conta" : "Bem-vindo";

  authDescription.textContent = modoRegisto
    ? "Regista-te para começares a gerir as tuas despesas."
    : "Inicia sessão para acompanhares as tuas despesas.";

  authSubmitButton.textContent = modoRegisto ? "Criar conta" : "Entrar";

  authToggleText.textContent = modoRegisto
    ? "Já tens uma conta?"
    : "Ainda não tens uma conta?";

  authToggleButton.textContent = modoRegisto ? "Iniciar sessão" : "Criar conta";

  loginPassword.autocomplete = modoRegisto
    ? "new-password"
    : "current-password";

  ocultarMensagem(authMessage);
}

// Trata o envio do formulário. A operação concreta é delegada a criarUsuario() ou iniciarSessao().
// Se o registo exigir confirmação por email, apresenta a mensagem sem iniciar a aplicação.
async function processarAutenticacao(evento) {
  evento.preventDefault();

  const email = loginEmail.value.trim();
  const password = loginPassword.value;
  const nomeCompleto = signupNameInput.value.trim();
  const moeda = signupCurrency.value;

  if (!email || !password || (modoRegisto && !nomeCompleto)) {
    mostrarMensagem(
      authMessage,
      modoRegisto
        ? "Preenche o nome, o email e a palavra-passe."
        : "Preenche o email e a palavra-passe.",
    );

    return;
  }

  authSubmitButton.disabled = true;
  authSubmitButton.textContent = "A processar...";

  ocultarMensagem(authMessage);

  try {
    const resultado = modoRegisto
      ? await criarUsuario(email, password, nomeCompleto, moeda)
      : await iniciarSessao(email, password);

    if (resultado.error) {
      mostrarMensagem(
        authMessage,
        obterMensagemErro(
          resultado.error,
          "Não foi possível concluir a autenticação.",
        ),
      );

      return;
    }

    if (modoRegisto && !resultado.session) {
      mostrarMensagem(
        authMessage,
        "Conta criada. Verifica o teu email para confirmares o registo.",
        "success",
      );

      loginForm.reset();
      return;
    }

    await iniciarAplicacao();
  } catch (erro) {
    mostrarMensagem(
      authMessage,
      obterMensagemErro(erro, "Ocorreu um erro durante a autenticação."),
    );
  } finally {
    authSubmitButton.disabled = false;
    authSubmitButton.textContent = modoRegisto ? "Criar conta" : "Entrar";
  }
}

// ======================================
// CONTROLO DE INATIVIDADE
// auth.js deteta a inatividade e chama as funções de retorno (callbacks) definidas aqui.
// O Bootstrap apresenta o modal e o main.js atualiza a interface conforme o estado da sessão.
// ======================================

// Liga o monitor de inatividade ao modal do Bootstrap e apresenta a contagem decrescente.
function iniciarControloInatividade() {
  modalInatividade = bootstrap.Modal.getOrCreateInstance(
    //procura uma instância existente do modal. Se não existir, cria uma.
    inactivityModalElement,
  );

  iniciarMonitoramentoInatividade({
    onCountdown(segundos) {
      // recebe por parametro em segundos quando deve aparecer o modal de contagem regressiva (5 min)
      if (segundos === null) {
        modalInatividade.hide();
        return;
      }

      const minutos = Math.floor(segundos / 60);
      const resto = segundos % 60;

      inactivityCountdown.textContent = `${String(minutos).padStart(2, "0")}:${String(resto).padStart(2, "0")}`;

      modalInatividade.show();
    },

    onTimeout() {
      // quando o tempo de inatividade zera
      modalInatividade.hide();
      alterarVista("login");
      loginForm.reset();
      modoRegisto = false;
      alternarModoAutenticacao(false);
    },
  });
}

// Fecha o aviso de inatividade. O monitor em auth.js trata da continuação da sessão.
function continuarSessao() {
  modalInatividade.hide();
}

// Termina a sessão remota. Se essa operação falhar, tenta limpar a sessão apenas localmente
// para não deixar a interface presa numa conta que o utilizador já pretende abandonar.
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
  alternarModoAutenticacao(false);
}

// ======================================
// FORMULÁRIO DE DESPESAS E RECEITAS
// O mesmo formulário serve para criar e editar os dois tipos de movimento.
// ======================================

// Atualiza o formulário para despesa ou receita: textos, cores, botões e categoriasDespesa disponíveis.
function atualizarTipoLancamento(tipo) {
  tipoLancamento = tipo;
  const isReceita = tipo === "receita";

  recordTitle.textContent = isReceita
    ? "Adicionar receita"
    : "Adicionar despesa";
  descriptionLabel.textContent = "Descrição";
  amountLabel.textContent = "Valor";
  amountCurrencySymbol.textContent = new Intl.NumberFormat("pt-PT", {
    // api formata o valor monetario de acordo com a moeda escolhida
    style: "currency",
    currency: moedaAtual, // variavel controladora na app (EUR, USD, BRL, GBP)
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  })
    .formatToParts(0)
    .find((parte) => parte.type === "currency").value;
  categoryLabel.textContent = "Categoria";
  saveRecordButton.textContent = edicaoAtual // altera o icone da moeda no formulario
    ? isReceita
      ? "Atualizar receita"
      : "Atualizar despesa"
    : isReceita
      ? "Guardar receita"
      : "Guardar despesa";
  saveRecordButton.classList.toggle("btn-success", isReceita);
  saveRecordButton.classList.toggle("btn-danger", !isReceita);
  expenseTypeButton.disabled = Boolean(
    edicaoAtual && edicaoAtual.tipo !== "despesa",
  );
  incomeTypeButton.disabled = Boolean(
    edicaoAtual && edicaoAtual.tipo !== "receita",
  );
  cancelEditButton.classList.toggle("d-none", !edicaoAtual);

  expenseTypeButton.classList.toggle("active", !isReceita);
  expenseTypeButton.classList.toggle("btn-danger", !isReceita);
  expenseTypeButton.classList.toggle("btn-outline-danger", isReceita);
  incomeTypeButton.classList.toggle("active", isReceita);
  incomeTypeButton.classList.toggle("btn-success", isReceita);
  incomeTypeButton.classList.toggle("btn-outline-success", !isReceita);
  expenseTypeButton.setAttribute("aria-pressed", String(!isReceita));
  incomeTypeButton.setAttribute("aria-pressed", String(isReceita));

  expenseCategory.innerHTML = criarOpcoesCategoria(
    isReceita ? categoriasReceita : categoriasDespesa,
  );
}

// Procura o movimento carregado em memória e preenche o formulário com os seus dados.
// O tipo determina em que lista procurar e qual campo de data utilizar.
function iniciarEdicaoMovimento(tipo, id) { // formulario que abre quando clica em editar na listagem de movimentos
  const lista = tipo === "receita" ? receitasAtuais : despesasAtuais;
  const movimento = lista.find((item) => String(item.id) === id);
  if (!movimento) return;

  tipoAntesEdicao = tipoLancamento;
  edicaoAtual = { tipo, id };
  atualizarTipoLancamento(tipo);
  recordTitle.textContent =
    tipo === "receita" ? "Editar receita" : "Editar despesa";
  expenseDescription.value = movimento.description;
  expenseAmount.value = movimento.amount;
  expenseCategory.value = movimento.category;
  expenseDate.value =
    tipo === "receita" ? movimento.income_date : movimento.expense_date;

  if (
    window.matchMedia(MEDIA_QUERY_MOVEL).matches && // se for telemovel
    !recordEditorPanel.classList.contains("show")
  ) {
    recordEditorPanel.addEventListener(
      "shown.bs.collapse", // garante que a animacao do painel termine antes de posicionar o formulario
      () => {
        expenseForm.scrollIntoView({ behavior: "smooth", block: "center" });
        expenseDescription.focus({ preventScroll: true });
      },
      { once: true }, // garante que so vai ser executado uma vez
    );
    bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, {
      toggle: false,
    }).show();
    return;
  }

  expenseForm.scrollIntoView({ behavior: "smooth", block: "center" }); // se nao for telemovel
  expenseDescription.focus({ preventScroll: true });
}

// Trata a seleção do tipo de movimento. Em ecrãs pequenos, abre também o painel recolhível.
function selecionarTipoLancamento(tipo) {
  if (edicaoAtual) {
    if (
      tipo === edicaoAtual.tipo &&
      window.matchMedia(MEDIA_QUERY_MOVEL).matches
    ) {
      bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, {
        toggle: false,
      }).show();
    }
    return;
  }

  atualizarTipoLancamento(tipo);

  if (window.matchMedia(MEDIA_QUERY_MOVEL).matches) {
    bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, {
      toggle: false,
    }).show();
  }
}

// Mantém os atributos de acessibilidade coerentes com o estado aberto/fechado do painel.
function sincronizarEstadoPainel() {
  const aberto =
    window.matchMedia("(min-width: 992px)").matches ||
    recordEditorPanel.classList.contains("show");
  expenseTypeButton.setAttribute("aria-expanded", String(aberto));
  incomeTypeButton.setAttribute("aria-expanded", String(aberto));
}

// Descarta as alterações ainda não guardadas e repõe o formulário no modo de criação.
function cancelarEdicao() {
  if (!edicaoAtual) return;

  const tipoRestaurado = tipoAntesEdicao;
  edicaoAtual = null;
  expenseForm.reset();
  expenseDate.value = formatarDataLocal();
  atualizarTipoLancamento(tipoRestaurado);

  if (window.matchMedia(MEDIA_QUERY_MOVEL).matches) {
    bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, {
      toggle: false,
    }).hide();
  }
}

// Valida os campos e decide entre inserir ou atualizar, e entre despesa ou receita.
// As operações de persistência são delegadas aos módulos despesas.js e receitas.js.
async function guardarMovimento(evento) {
  evento.preventDefault();
  if (!navigator.onLine) {
    alert("Não é possível guardar movimentos sem ligação à internet.");
    return;
  }
  if (!expenseForm.reportValidity()) return;

  const dados = {
    description: expenseDescription.value.trim(),
    amount: Number(expenseAmount.value),
    category: expenseCategory.value,
    date: expenseDate.value,
  };

  if (
    !dados.description ||
    !Number.isFinite(dados.amount) ||
    dados.amount <= 0 ||
    !dados.category ||
    !dados.date
  )
    return;

  saveRecordButton.disabled = true;
  saveRecordButton.textContent = "A guardar...";

  try {
    let resultado;

    if (edicaoAtual?.tipo === "receita") {
      resultado = await atualizarReceita(
        edicaoAtual.id,
        { ...dados, income_date: dados.date },
        utilizadorAtualId,
      );
    } else if (edicaoAtual?.tipo === "despesa") {
      resultado = await atualizarDespesa(
        edicaoAtual.id,
        { ...dados, expense_date: dados.date },
        utilizadorAtualId,
      );
    } else if (tipoLancamento === "receita") {
      resultado = await adicionarReceita({
        ...dados,
        income_date: dados.date,
      });
    } else {
      resultado = await adicionarDespesa({
        ...dados,
        expense_date: dados.date,
      });
    }

    if (resultado.error) {
      alert(
        obterMensagemErro(
          resultado.error,
          "Não foi possível guardar as alterações.",
        ),
      );
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
      ? edicaoAtual.tipo === "receita"
        ? "Atualizar receita"
        : "Atualizar despesa"
      : tipoLancamento === "receita"
        ? "Guardar receita"
        : "Guardar despesa";
  }
}

// Confirma a intenção do utilizador, delega a eliminação no módulo correto e atualiza o histórico.
async function eliminarMovimento(tipo, id) {
  if (!navigator.onLine) {
    alert("Não é possível eliminar movimentos sem ligação à internet.");
    return;
  }

  const nomeTipo = tipo === "receita" ? "receita" : "despesa";
  if (
    !window.confirm(`Tens a certeza de que queres eliminar esta ${nomeTipo}?`)
  )
    return;

  try {
    const resultado =
      tipo === "receita"
        ? await eliminarReceita(id, utilizadorAtualId)
        : await eliminarDespesa(id, utilizadorAtualId);

    if (resultado.error) {
      alert(
        obterMensagemErro(
          resultado.error,
          `Não foi possível eliminar a ${nomeTipo}.`,
        ),
      );
      return;
    }

    if (!resultado.data?.length) {
      alert(
        `A ${nomeTipo} não foi eliminada. Pode já não existir ou não teres permissão.`,
      );
      return;
    }

    if (edicaoAtual?.tipo === tipo && edicaoAtual.id === id) cancelarEdicao();
    await carregarDados();
  } catch (erro) {
    alert(
      obterMensagemErro(erro, `Ocorreu um erro ao eliminar a ${nomeTipo}.`),
    );
  }
}

// ======================================
// CONVERSOR DE MOEDAS
// O conversor é independente dos movimentos financeiros e serve apenas para consultar valores.
// ======================================

// Regra de negócio: o conversor apenas consulta a API; não cria nem altera movimentos.
// Valida o valor, consulta a taxa através de api.js e apresenta o resultado formatado.
// Se as moedas forem iguais, não é necessário fazer qualquer pedido à API.
async function converterMoeda() { // API fornece informações sobre moedas e taxas de câmbio
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
        currency: moedaDestino,
      }).format(valor);

      conversionRate.textContent = "As moedas selecionadas são iguais.";
      return;
    }

    const resultado = await obterTaxaCambio(moedaOrigem, moedaDestino);

    if (!resultado || !Number.isFinite(Number(resultado.rate))) {
      throw new Error("Não foi possível obter a taxa de câmbio.");
    }

    const taxa = Number(resultado.rate);
    const convertido = valor * taxa;

    convertedValue.textContent = new Intl.NumberFormat("pt-PT", {
      style: "currency",
      currency: moedaDestino,
    }).format(convertido);

    conversionRate.textContent =
      `1 ${moedaOrigem} = ${taxa.toFixed(4)} ${moedaDestino}` +
      (resultado.date ? ` · Taxa de ${resultado.date}` : "");
  } catch (erro) {
    convertedValue.textContent = "Erro na conversão";

    conversionRate.textContent = obterMensagemErro(
      erro,
      "Não foi possível consultar a taxa de câmbio.",
    );
  } finally {
    convertButton.disabled = false;
  }
}

// ======================================
// INICIALIZAÇÃO DA APLICAÇÃO
// Verifica a sessão e prepara os dados e componentes necessários antes de mostrar o dashboard.
// ======================================

// Fluxo principal após abrir a página ou autenticar: valida sessão, configura conta,
// tema, período, formulário, histórico e monitorização de inatividade.
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
    moedaAtual = resultado.user.user_metadata?.currency || "EUR";
    try {
      formatadorMoeda = new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: moedaAtual,
      });
    } catch {
      moedaAtual = "EUR";
      formatadorMoeda = new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: moedaAtual,
      });
    }
    conversionTo.value = moedaAtual;
    conversionFrom.value = moedaAtual === "USD" ? "EUR" : "USD";
    const nomeUtilizador = obterNomeApresentacao(resultado.user);
    greetingTitle.textContent = `Olá, ${nomeUtilizador}!`;
    accountDisplayName.textContent = nomeUtilizador;
    accountEmail.textContent = resultado.user.email || "";

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
// REGISTO DOS EVENTOS DA INTERFACE
// Aqui ligamos ações do utilizador (cliques, submissões e alterações) às funções da aplicação.
// A delegação de eventos na lista permite tratar também os botões criados dinamicamente.
// ======================================

loginForm.addEventListener("submit", processarAutenticacao);

authToggleButton.addEventListener("click", () => alternarModoAutenticacao());

themeToggle.addEventListener("click", alternarTema);

installAppButton.addEventListener("click", async () => {
  if (!eventoInstalacaoPendente) return;

  eventoInstalacaoPendente.prompt();
  const escolha = await eventoInstalacaoPendente.userChoice;

  eventoInstalacaoPendente = null;
  installAppButton.classList.add("d-none");

  if (escolha.outcome === "accepted") {
    console.info("O utilizador aceitou instalar o Meu Bolso.");
  }
});

logoutButton.addEventListener("click", terminarSessao);

continueSessionButton.addEventListener("click", continuarSessao);

expenseForm.addEventListener("submit", guardarMovimento);
expenseTypeButton.addEventListener("click", () =>
  selecionarTipoLancamento("despesa"),
);
incomeTypeButton.addEventListener("click", () =>
  selecionarTipoLancamento("receita"),
);

categoryFilter.addEventListener("change", carregarDados);
transactionFilterAll.addEventListener("click", () =>
  alterarFiltroMovimentos("todos"),
);
transactionFilterExpenses.addEventListener("click", () =>
  alterarFiltroMovimentos("despesa"),
);
transactionFilterIncome.addEventListener("click", () =>
  alterarFiltroMovimentos("receita"),
);
cancelEditButton.addEventListener("click", cancelarEdicao);

recordPanelClose.addEventListener("click", () => {
  bootstrap.Collapse.getOrCreateInstance(recordEditorPanel, {
    toggle: false,
  }).hide();
});

recordEditorPanel.addEventListener(
  "shown.bs.collapse",
  sincronizarEstadoPainel,
);
recordEditorPanel.addEventListener(
  "hidden.bs.collapse",
  sincronizarEstadoPainel,
);
window
  .matchMedia("(min-width: 992px)")
  .addEventListener("change", sincronizarEstadoPainel);
sincronizarEstadoPainel();

expensesList.addEventListener("click", async (evento) => {
  if (evento.target.closest("#loadMoreMovements")) {
    carregarMaisMovimentos();
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

conversionAmount.addEventListener("keydown", (evento) => {
  if (evento.key === "Enter") {
    converterMoeda();
  }
});

// ======================================
// ARRANQUE E FUNCIONALIDADES DO NAVEGADOR
// Inicia a aplicação e regista eventos globais relacionados com rede, instalação e PWA.
// ======================================

iniciarAplicacao();

window.addEventListener("online", () => {
  atualizarAvisoLigacao("Ligação restabelecida. A atualizar os dados...");
  if (utilizadorAtualId) carregarDados();
});

window.addEventListener("offline", () => {
  const cache = obterDadosEmCache();
  if (cache) aplicarDadosEmCache(cache);
  else
    atualizarAvisoLigacao(
      "Sem ligação à internet. Ainda não existem dados guardados para este período.",
    );
});

window.addEventListener("beforeinstallprompt", (evento) => {
  evento.preventDefault();
  eventoInstalacaoPendente = evento;
  installAppButton.classList.remove("d-none");
});

window.addEventListener("appinstalled", () => {
  eventoInstalacaoPendente = null;
  installAppButton.classList.add("d-none");
});

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("./service-worker.js").catch((erro) => {
      console.warn("Não foi possível registar o Service Worker:", erro);
    });
  });
}

window.setInterval(atualizarDataHora, 60000);


 // ======================================
 // IMPORTAÇÕES
 // ======================================

import {
  verificarSessao,
  criarUsuario,
  iniciarSessao,
  encerrarSessao,
  iniciarMonitoramentoInatividade,
  pararMonitoramentoInatividade
} from "./auth.js";

import {
  inicializarTema,
  alternarTema
} from "./tema.js";

// ======================================
// ELEMENTOS HTML
// ======================================

// Vistas da aplicação.
const loadingView = document.getElementById("loadingView");
const loginView = document.getElementById("loginView");
const appView = document.getElementById("appView");

// Elementos do modal de inatividade.
const inactivityModalElement = document.getElementById("inactivityModal");
const inactivityCountdown = document.getElementById("inactivityCountdown");

// Elementos de autenticação.
const authTitle = document.getElementById("authTitle");
const authDescription = document.getElementById("authDescription");
const authSubmitButton = document.getElementById("authSubmitButton");
const authToggleText = document.getElementById("authToggleText");
const authToggleButton = document.getElementById("authToggleButton");
const authMessage = document.getElementById("authMessage");
const currentDateTime = document.getElementById("currentDateTime");

// Formulário e campos.
const loginForm = document.getElementById("loginForm");
const loginPassword = document.getElementById("loginPassword");

// Botões principais.
const logoutButton = document.getElementById("logoutButton");
const themeToggle = document.getElementById("themeToggle");

// ======================================
// ESTADO DA APLICAÇÃO
// ======================================

// Controla o modo atual de autenticação.
let modoRegisto = false;

// ======================================
// CONTROLO DAS VISTAS
// ======================================

// Oculta todas as vistas e apresenta apenas a indicada.
function alterarVista(vista) {
  loadingView.classList.add("d-none");
  loginView.classList.add("d-none");
  appView.classList.add("d-none");

  vista.classList.remove("d-none");
}

// Atualiza o relógio do dashboard, em vez de deixar uma data fixa no HTML.
function atualizarDataHora() {
  if (!currentDateTime) return;

  const agora = new Date();
  currentDateTime.dateTime = agora.toISOString();
  currentDateTime.textContent = new Intl.DateTimeFormat("pt-PT", {
    dateStyle: "short",
    timeStyle: "short"
  }).format(agora);
}

// ======================================
// AUTENTICAÇÃO
// ======================================

// Verifica se existe uma sessão válida ao iniciar a aplicação.
async function iniciarAplicacao() {
  try {
    const resultado = await verificarSessao();

    if (resultado.error) {
      console.error("Erro ao verificar sessão:", resultado.error);
      alterarVista(loginView);
      return;
    }

    if (!resultado.user) {
      alterarVista(loginView);
      return;
    }

    alterarVista(appView);
    iniciarControloInatividade();

  } catch (error) {
    console.error("Erro ao inicializar aplicação:", error);
    alterarVista(loginView);
  }
}

// Alterna entre os modos de login e registo.
function alternarModoAutenticacao() {
  modoRegisto = !modoRegisto;

  authTitle.textContent = modoRegisto ? "Criar conta" : "Bem-vindo";

  authDescription.textContent = modoRegisto
    ? "Regista-te para começares a gerir as tuas despesas."
    : "Inicia sessão para acompanhares as tuas despesas.";

  authSubmitButton.textContent = modoRegisto ? "Criar conta" : "Entrar";

  authToggleText.textContent = modoRegisto
    ? "Já tens uma conta?"
    : "Ainda não tens uma conta?";

  authToggleButton.textContent = modoRegisto
    ? "Iniciar sessão"
    : "Criar conta";

  loginPassword.value = "";
  authMessage.classList.add("d-none");
}

// Apresenta mensagens de sucesso ou erro na autenticação.
function mostrarMensagem(mensagem, tipo) {
  authMessage.textContent = mensagem;

  authMessage.classList.remove(
    "d-none",
    "alert-danger",
    "alert-success",
    "alert-warning"
  );

  authMessage.classList.add(`alert-${tipo}`);
}

// ======================================
// CONTROLO DE INATIVIDADE
// ======================================

function iniciarControloInatividade() {
  const modal = bootstrap.Modal.getOrCreateInstance(
    inactivityModalElement
  );

  iniciarMonitoramentoInatividade({
    onCountdown: (segundos) => {
      if (segundos === null) {
        // Remove o foco antes de fechar o modal.
        if (inactivityModalElement.contains(document.activeElement)) {
          document.activeElement.blur();
        }

        if (inactivityModalElement.classList.contains("show")) {
          modal.hide();
        }

        return;
      }

      const minutos = String(Math.floor(segundos / 60)).padStart(2, "0");
      const segundosRestantes = String(segundos % 60).padStart(2, "0");

      inactivityCountdown.textContent =
        `${minutos}:${segundosRestantes}`;

      if (!inactivityModalElement.classList.contains("show")) {
        modal.show();
      }
    },

    onTimeout: () => {
      alterarVista(loginView);

      mostrarMensagem(
        "A aplicação foi bloqueada por inatividade. Inicia sessão novamente.",
        "danger"
      );
    }
  });
}

// ======================================
// EVENTOS
// ======================================

// Alterna entre login e registo.
authToggleButton.addEventListener("click", alternarModoAutenticacao);

// Alterna entre tema claro e escuro.
themeToggle.addEventListener("click", alternarTema);

// Processa o envio do formulário de autenticação.
loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();

  if (!loginForm.reportValidity()) {
    return;
  }

  const dataFromForm = new FormData(loginForm);

  const email = dataFromForm.get("email").trim();
  const password = dataFromForm.get("password");

  authSubmitButton.disabled = true;
  authSubmitButton.textContent = "A processar...";

  try {
    if (modoRegisto) {
      const resultado = await criarUsuario(email, password);

      if (resultado.error) {
        mostrarMensagem(resultado.error.message, "danger");
        return;
      }

      if (resultado.session) {
        // Alguns projetos Supabase não exigem confirmação por email.
        authMessage.classList.add("d-none");
        alterarVista(appView);
        iniciarControloInatividade();
      } else {
        mostrarMensagem(
          "Conta criada com sucesso! Verifica o teu email para confirmares a conta.",
          "success"
        );
        loginPassword.value = "";
      }

    } else {
      const resultado = await iniciarSessao(email, password);

      if (resultado.error) {
        mostrarMensagem(resultado.error.message, "danger");
        return;
      }

      authMessage.classList.add("d-none");
      alterarVista(appView);
      iniciarControloInatividade();
    }

  } catch (error) {
    console.error("Erro na autenticação:", error);

    mostrarMensagem(
      "Ocorreu um erro inesperado. Tenta novamente.",
      "danger"
    );

  } finally {
    authSubmitButton.disabled = false;

    authSubmitButton.textContent = modoRegisto
      ? "Criar conta"
      : "Entrar";
  }
});

// Processa o encerramento manual da sessão.
logoutButton.addEventListener("click", async () => {
  logoutButton.disabled = true;
  alterarVista(loginView);

  try {
    try {
      pararMonitoramentoInatividade();

      const resultado = await encerrarSessao();

      if (!resultado.error) {
        authMessage.classList.add("d-none");
        alterarVista(loginView);
        return;
      }

      console.error("Erro ao encerrar sessão:", resultado.error);
    } catch (error) {
      console.error("Erro inesperado ao encerrar sessão:", error);
    }

    try {
      // A ação é um logout explícito: tenta remover a sessão local se o remoto falhar.
      const resultadoLocal = await encerrarSessao({ scope: "local" });
      if (!resultadoLocal.error) {
        alterarVista(loginView);
        mostrarMensagem(
          "A sessão foi encerrada neste dispositivo, mas o servidor não confirmou o logout.",
          "warning"
        );
        return;
      }
      console.error("Erro ao terminar a sessão local:", resultadoLocal.error);
    } catch (erroLocal) {
      console.error("Erro ao terminar a sessão local:", erroLocal);
    }

    // Não manter dados protegidos visíveis se nem o logout local funcionar.
    alterarVista(loginView);
    mostrarMensagem(
      "Não foi possível confirmar o encerramento da sessão. A vista protegida foi bloqueada neste separador.",
      "danger"
    );

  } finally {
    logoutButton.disabled = false;
  }
});

// ======================================
// INICIALIZAÇÃO
// ======================================

// Inicializa o tema da aplicação.
inicializarTema();

atualizarDataHora();
window.setInterval(atualizarDataHora, 60_000);

// Inicia a aplicação após configurar os eventos.
iniciarAplicacao();

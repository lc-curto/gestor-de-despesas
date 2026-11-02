
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
    "alert-success"
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
        "A tua sessão foi encerrada por inatividade. Inicia sessão novamente.",
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

      mostrarMensagem(
        "Conta criada com sucesso! Verifica o teu email para confirmares a conta.",
        "success"
      );

      loginPassword.value = "";

    } else {
      const resultado = await iniciarSessao(email, password);

      if (resultado.error) {
        mostrarMensagem(resultado.error.message, "danger");
        return;
      }

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

  try {
    pararMonitoramentoInatividade();

    const resultado = await encerrarSessao();

    if (resultado.error) {
      console.error("Erro ao encerrar sessão:", resultado.error);

      iniciarControloInatividade();
      return;
    }

    alterarVista(loginView);

  } catch (error) {
    console.error("Erro inesperado ao encerrar sessão:", error);

    iniciarControloInatividade();

  } finally {
    logoutButton.disabled = false;
  }
});

// ======================================
// INICIALIZAÇÃO
// ======================================

// Inicializa o tema da aplicação.
inicializarTema();

// Inicia a aplicação após configurar os eventos.
iniciarAplicacao();

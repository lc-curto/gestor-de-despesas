// ======================================
// CONFIGURAÇÕES
// ======================================

const CHAVE_TEMA = "temaAplicacao";

// ======================================
// APLICAÇÃO DO TEMA
// ======================================

export function aplicarTema(tema) {
  document.documentElement.setAttribute("data-bs-theme", tema);

  try {
    localStorage.setItem(CHAVE_TEMA, tema);
  } catch (error) {
    // A aplicação continua utilizável mesmo se o browser bloquear storage.
    console.warn("Não foi possível guardar a preferência de tema:", error);
  }

  atualizarBotaoTema(tema);
}

// ======================================
// ATUALIZAÇÃO DO BOTÃO
// ======================================

function atualizarBotaoTema(tema) {
  const themeToggle = document.getElementById("themeToggle");
  if (!themeToggle) return;

  const icone = themeToggle.querySelector("i");

  if (tema === "dark") {
    if (icone) icone.className = "bi bi-sun-fill";
    themeToggle.setAttribute("aria-label", "Mudar para tema claro");
    themeToggle.setAttribute("aria-pressed", "true");
  } else {
    if (icone) icone.className = "bi bi-moon-stars-fill";
    themeToggle.setAttribute("aria-label", "Mudar para tema escuro");
    themeToggle.setAttribute("aria-pressed", "false");
  }
}

// ======================================
// ALTERNÂNCIA DE TEMA
// ======================================

export function alternarTema() {
  const temaAtual = document.documentElement.getAttribute("data-bs-theme");

  const novoTema = temaAtual === "dark" ? "light" : "dark";

  aplicarTema(novoTema);
}

// ======================================
// INICIALIZAÇÃO DO TEMA
// ======================================

export function inicializarTema() {
  let temaGuardado = null;
  try {
    temaGuardado = localStorage.getItem(CHAVE_TEMA);
  } catch (error) {
    console.warn("Não foi possível ler a preferência de tema:", error);
  }

  const temaInicial = ["light", "dark"].includes(temaGuardado)
    ? temaGuardado
    : "light";

  aplicarTema(temaInicial);
}

// ======================================
// CONFIGURAÇÕES
// ======================================

const CHAVE_TEMA = "temaAplicacao";

// ======================================
// APLICAÇÃO DO TEMA
// ======================================

export function aplicarTema(tema) {
  document.documentElement.setAttribute("data-bs-theme", tema);

  localStorage.setItem(CHAVE_TEMA, tema);

  atualizarBotaoTema(tema);
}

// ======================================
// ATUALIZAÇÃO DO BOTÃO
// ======================================

function atualizarBotaoTema(tema) {
  const themeToggle = document.getElementById("themeToggle");
  const icone = themeToggle.querySelector("i");

  if (tema === "dark") {
    icone.className = "bi bi-sun-fill";
    themeToggle.setAttribute("aria-label", "Dark");
  } else {
    icone.className = "bi bi-moon-stars-fill";
    themeToggle.setAttribute("aria-label", "Light");
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
  const temaGuardado = localStorage.getItem(CHAVE_TEMA);

  const temaInicial = ["light", "dark"].includes(temaGuardado)
    ? temaGuardado
    : "light";

  aplicarTema(temaInicial);
}

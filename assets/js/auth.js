
import { supabase } from "./supabase.js";

// ======================================
// CONFIGURAÇÕES
// ======================================

const TEMPO_LIMITE = 15 * 60 * 1000;
const TEMPO_AVISO = 60 * 1000;

// ======================================
// ESTADO DO MONITORAMENTO
// ======================================

let temporizador = null;
let contador = null;
let ultimaAtividade = null;
let ultimoMovimento = 0;
let monitoramentoAtivo = false;

let atualizarInterface = null;
let encerrarInterface = null;

// ======================================
// VERIFICAÇÃO DE SESSÃO
// ======================================

// Verifica se existe uma sessão e valida o utilizador autenticado.
export async function verificarSessao() {
  const { data, error } = await supabase.auth.getSession();

  if (error) {
    return { user: null, error };
  }

  if (!data.session) {
    return { user: null, error: null };
  }

  const { data: userData, error: userError } =
    await supabase.auth.getUser();

  if (userError) {
    return { user: null, error: userError };
  }

  return {
    user: userData.user,
    error: null
  };
}

// ======================================
// REGISTO DE UTILIZADOR
// ======================================

// Cria uma nova conta utilizando email e palavra-passe.
export async function criarUsuario(email, password) {
  const { data, error } = await supabase.auth.signUp({
    email,
    password
  });

  if (error) {
    return { user: null, session: null, error };
  }

  return {
    user: data.user,
    session: data.session,
    error: null
  };
}

// ======================================
// INÍCIO DE SESSÃO
// ======================================

// Autentica um utilizador com uma conta existente.
export async function iniciarSessao(email, password) {
  const { data, error } = await supabase.auth.signInWithPassword({
    email,
    password
  });

  if (error) {
    return { user: null, error };
  }

  return {
    user: data.user,
    error: null
  };
}

// ======================================
// ENCERRAMENTO DE SESSÃO
// ======================================

// Encerra a sessão atual do utilizador.
export async function encerrarSessao(options = {}) {
  const { error } = await supabase.auth.signOut(options);

  return { error };
}

// ======================================
// CONTROLO DE INATIVIDADE
// ======================================

// Agenda o aviso e o encerramento da sessão.
function agendarInatividade() {
  clearTimeout(temporizador);
  clearInterval(contador);

  const restante = TEMPO_LIMITE - (Date.now() - ultimaAtividade);

  if (restante <= 0) {
    expirarSessao();
    return;
  }

  // Agenda o início do aviso para o último minuto.
  if (restante > TEMPO_AVISO) {
    temporizador = setTimeout(
      iniciarContagem,
      restante - TEMPO_AVISO
    );
  } else {
    iniciarContagem();
  }
}

// Inicia a contagem regressiva.
function iniciarContagem() {
  clearInterval(contador);

  atualizarContagem();

  contador = setInterval(atualizarContagem, 1000);
}

// Atualiza o contador na interface.
function atualizarContagem() {
  const restante = TEMPO_LIMITE - (Date.now() - ultimaAtividade);
  const segundos = Math.ceil(restante / 1000);

  if (segundos <= 0) {
    expirarSessao();
    return;
  }

  atualizarInterface?.(segundos);
}

// Regista atividade e reinicia o prazo.
function registrarAtividade() {
  if (!monitoramentoAtivo) return;

  ultimaAtividade = Date.now();

  atualizarInterface?.(null);

  agendarInatividade();
}

// Limita a frequência de registo dos movimentos do rato.
function registrarMovimento() {
  const agora = Date.now();

  if (agora - ultimoMovimento < 1000) return;

  ultimoMovimento = agora;

  registrarAtividade();
}

// Verifica o tempo de inatividade ao regressar à aplicação.
function verificarAoRetornar() {
  if (document.visibilityState === "visible") {
    agendarInatividade();
  }
}

// Encerra a sessão após atingir o limite de inatividade.
async function expirarSessao() {
  if (!monitoramentoAtivo) return;

  const callback = encerrarInterface;

  pararMonitoramentoInatividade();
  // Oculte a aplicação ao expirar; não espere pela ligação ao servidor.
  callback?.();

  try {
    const resultado = await encerrarSessao();

    if (resultado.error) {
      console.error("Erro ao encerrar sessão por inatividade:", resultado.error);

      // Mesmo sem ligação, remove a sessão deste dispositivo e bloqueia a vista.
      const resultadoLocal = await encerrarSessao({ scope: "local" });
      if (resultadoLocal.error) {
        console.error("Erro ao remover a sessão local:", resultadoLocal.error);
      }
    }
  } catch (error) {
    console.error("Erro inesperado ao expirar a sessão:", error);

    try {
      const resultadoLocal = await encerrarSessao({ scope: "local" });
      if (resultadoLocal.error) {
        console.error("Erro ao remover a sessão local:", resultadoLocal.error);
      }
    } catch (erroLocal) {
      console.error("Erro inesperado ao remover a sessão local:", erroLocal);
    }
  }
}

// Inicia o monitoramento de inatividade.
export function iniciarMonitoramentoInatividade({
  onCountdown,
  onTimeout
}) {
  pararMonitoramentoInatividade();

  atualizarInterface = onCountdown;
  encerrarInterface = onTimeout;

  monitoramentoAtivo = true;
  ultimaAtividade = Date.now();
  ultimoMovimento = 0;

  document.addEventListener("click", registrarAtividade);
  document.addEventListener("keydown", registrarAtividade);
  document.addEventListener("mousemove", registrarMovimento);
  document.addEventListener("scroll", registrarAtividade);
  document.addEventListener("touchstart", registrarAtividade);

  document.addEventListener("visibilitychange", verificarAoRetornar);
  window.addEventListener("focus", verificarAoRetornar);

  agendarInatividade();
}

// Para o monitoramento e remove os eventos.
export function pararMonitoramentoInatividade() {
  clearTimeout(temporizador);
  clearInterval(contador);

  document.removeEventListener("click", registrarAtividade);
  document.removeEventListener("keydown", registrarAtividade);
  document.removeEventListener("mousemove", registrarMovimento);
  document.removeEventListener("scroll", registrarAtividade);
  document.removeEventListener("touchstart", registrarAtividade);

  document.removeEventListener("visibilitychange", verificarAoRetornar);
  window.removeEventListener("focus", verificarAoRetornar);

  temporizador = null;
  contador = null;
  monitoramentoAtivo = false;

  atualizarInterface?.(null);

  atualizarInterface = null;
  encerrarInterface = null;
}

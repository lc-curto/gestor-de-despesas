
import { supabase } from "./supabase.js";

// ======================================
// CONFIGURAÇÕES
// ======================================

const TABELA = "receitas";

// ======================================
// ADICIONAR RECEITA
// ======================================

export async function adicionarReceita(dados) {
  const {
    data: { user },
    error: erroUsuario
  } = await supabase.auth.getUser();

  if (erroUsuario || !user) {
    return {
      data: null,
      error: erroUsuario || new Error("Utilizador não autenticado.")
    };
  }

  const novaReceita = {
    user_id: user.id,
    description: dados.description.trim(),
    amount: Number(dados.amount),
    currency: "EUR",
    category: dados.category,
    income_date: dados.income_date
  };

  const { data, error } = await supabase
    .from(TABELA)
    .insert(novaReceita)
    .select()
    .single();

  return { data, error };
}

// ======================================
// LISTAR RECEITAS
// ======================================

export async function listarReceitas({
  inicio,
  fim,
  categoria = null,
  userId,
  offset = 0,
  limite = null,
  somenteMontantes = false
} = {}) {
  let consulta = supabase
    .from(TABELA)
    .select(somenteMontantes ? "amount" : "*")
    .order("income_date", { ascending: false })
    .order("id", { ascending: true });

  if (!userId) {
    return { data: [], error: new Error("Utilizador não autenticado.") };
  }

  consulta = consulta.eq("user_id", userId);

  if (inicio) {
    consulta = consulta.gte("income_date", inicio);
  }

  if (fim) {
    consulta = consulta.lt("income_date", fim);
  }

  if (categoria) {
    consulta = consulta.eq("category", categoria);
  }

  if (limite !== null) {
    consulta = consulta.range(offset, offset + limite - 1);
  }

  const { data, error } = await consulta;

  return {
    data: data || [],
    error
  };
}

// ======================================
// ATUALIZAR RECEITA
// ======================================

export async function atualizarReceita(id, dados, userId) {
  if (!userId) {
    return { data: null, error: new Error("Utilizador não autenticado.") };
  }

  const receitaAtualizada = {
    description: dados.description.trim(),
    amount: Number(dados.amount),
    currency: "EUR",
    category: dados.category,
    income_date: dados.income_date
  };

  const { data, error } = await supabase
    .from(TABELA)
    .update(receitaAtualizada)
    .eq("id", id)
    .eq("user_id", userId)
    .select()
    .single();

  return { data, error };
}

// ======================================
// ELIMINAR RECEITA
// ======================================

export async function eliminarReceita(id, userId) {
  if (!userId) {
    return { data: null, error: new Error("Utilizador não autenticado.") };
  }

  const { data, error } = await supabase
    .from(TABELA)
    .delete()
    .eq("id", id)
    .eq("user_id", userId)
    .select("id");

  return { data, error };
}

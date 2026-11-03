
import { supabase } from "./supabase.js";

// ======================================
// CONFIGURAÇÕES
// ======================================

const TABELA = "despesas";

// ======================================
// ADICIONAR DESPESA
// ======================================

export async function adicionarDespesa(dados) {
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

  const novaDespesa = {
    user_id: user.id,
    description: dados.description.trim(),
    amount: Number(dados.amount),
    currency: user.user_metadata?.currency || "EUR",
    category: dados.category,
    expense_date: dados.expense_date
  };

  const { data, error } = await supabase
    .from(TABELA)
    .insert(novaDespesa)
    .select()
    .single();

  return { data, error };
}

// ======================================
// LISTAR DESPESAS
// ======================================

export async function listarDespesas({
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
    .order("expense_date", { ascending: false })
    .order("id", { ascending: true });

  if (!userId) {
    return { data: [], error: new Error("Utilizador não autenticado.") };
  }

  consulta = consulta.eq("user_id", userId);

  if (inicio) {
    consulta = consulta.gte("expense_date", inicio);
  }

  if (fim) {
    consulta = consulta.lt("expense_date", fim);
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
// ATUALIZAR DESPESA
// ======================================

export async function atualizarDespesa(id, dados, userId) {
  if (!userId) {
    return { data: null, error: new Error("Utilizador não autenticado.") };
  }

  const { data: { user } } = await supabase.auth.getUser();
  const despesaAtualizada = {
    description: dados.description.trim(),
    amount: Number(dados.amount),
    currency: user?.user_metadata?.currency || "EUR",
    category: dados.category,
    expense_date: dados.expense_date
  };

  const { data, error } = await supabase
    .from(TABELA)
    .update(despesaAtualizada)
    .eq("id", id)
    .eq("user_id", userId)
    .select()
    .single();

  return { data, error };
}

// ======================================
// ELIMINAR DESPESA
// ======================================

export async function eliminarDespesa(id, userId) {
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

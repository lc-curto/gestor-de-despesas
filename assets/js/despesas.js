
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
    currency: "EUR",
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
  categoria = null
} = {}) {
  let consulta = supabase
    .from(TABELA)
    .select("*")
    .order("expense_date", { ascending: false });

  if (inicio) {
    consulta = consulta.gte("expense_date", inicio);
  }

  if (fim) {
    consulta = consulta.lt("expense_date", fim);
  }

  if (categoria) {
    consulta = consulta.eq("category", categoria);
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

export async function atualizarDespesa(id, dados) {
  const despesaAtualizada = {
    description: dados.description.trim(),
    amount: Number(dados.amount),
    currency: "EUR",
    category: dados.category,
    expense_date: dados.expense_date
  };

  const { data, error } = await supabase
    .from(TABELA)
    .update(despesaAtualizada)
    .eq("id", id)
    .select()
    .single();

  return { data, error };
}

// ======================================
// ELIMINAR DESPESA
// ======================================

export async function eliminarDespesa(id) {
  const { error } = await supabase
    .from(TABELA)
    .delete()
    .eq("id", id);

  return { error };
}

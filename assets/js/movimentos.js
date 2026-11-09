import { supabase } from "./supabase.js";

// Cria as operações comuns de um tipo de movimento.
// A tabela e o campo de data são definidos pelo módulo que utiliza esta fábrica.
export function criarOperacoesMovimentos({ tabela, campoData }) {
  async function adicionar(dados) {
    const {
      data: { user },
      error: erroUsuario,
    } = await supabase.auth.getUser();

    if (erroUsuario || !user) {
      return {
        data: null,
        error: erroUsuario || new Error("Utilizador não autenticado."),
      };
    }

    const novoMovimento = {
      user_id: user.id,
      description: dados.description.trim(),
      amount: Number(dados.amount),
      currency: user.user_metadata?.currency || "EUR",
      category: dados.category,
      [campoData]: dados[campoData],
    };

    const { data, error } = await supabase
      .from(tabela)
      .insert(novoMovimento)
      .select()
      .single();

    return { data, error };
  }

  async function listar({
    inicio,
    fim,
    categoria = null,
    userId,
    offset = 0,
    limite = null,
    somenteMontantes = false,
  } = {}) {
    let consulta = supabase
      .from(tabela)
      .select(somenteMontantes ? "amount" : "*", { count: "exact" })
      .order(campoData, { ascending: false })
      .order("id", { ascending: true });

    if (!userId) {
      return { data: [], error: new Error("Utilizador não autenticado.") };
    }

    consulta = consulta.eq("user_id", userId);

    if (inicio) {
      consulta = consulta.gte(campoData, inicio);
    }

    if (fim) {
      consulta = consulta.lt(campoData, fim);
    }

    if (categoria) {
      consulta = consulta.eq("category", categoria);
    }

    if (limite !== null) {
      consulta = consulta.range(offset, offset + limite - 1);
    }

    const { data, error, count } = await consulta;

    return {
      data: data || [],
      count: count ?? data?.length ?? 0,
      error,
    };
  }

  async function atualizar(id, dados, userId) {
    if (!userId) {
      return { data: null, error: new Error("Utilizador não autenticado.") };
    }

    const {
      data: { user },
    } = await supabase.auth.getUser();

    const movimentoAtualizado = {
      description: dados.description.trim(),
      amount: Number(dados.amount),
      currency: user?.user_metadata?.currency || "EUR",
      category: dados.category,
      [campoData]: dados[campoData],
    };

    const { data, error } = await supabase
      .from(tabela)
      .update(movimentoAtualizado)
      .eq("id", id)
      .eq("user_id", userId)
      .select()
      .single();

    return { data, error };
  }

  async function eliminar(id, userId) {
    if (!userId) {
      return { data: null, error: new Error("Utilizador não autenticado.") };
    }

    const { data, error } = await supabase
      .from(tabela)
      .delete()
      .eq("id", id)
      .eq("user_id", userId)
      .select("id");

    return { data, error };
  }

  return { adicionar, listar, atualizar, eliminar };
}

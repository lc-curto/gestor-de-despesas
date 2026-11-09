import { criarOperacoesMovimentos } from "./movimentos.js";

// As despesas utilizam a tabela "despesas" e a coluna "expense_date".
const operacoes = criarOperacoesMovimentos({
  tabela: "despesas",
  campoData: "expense_date",
});

export const adicionarDespesa = operacoes.adicionar;
export const listarDespesas = operacoes.listar;
export const atualizarDespesa = operacoes.atualizar;
export const eliminarDespesa = operacoes.eliminar;

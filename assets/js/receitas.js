import { criarOperacoesMovimentos } from "./movimentos.js";

// As receitas utilizam a tabela "receitas" e a coluna "income_date".
const operacoes = criarOperacoesMovimentos({
  tabela: "receitas",
  campoData: "income_date",
});

export const adicionarReceita = operacoes.adicionar;
export const listarReceitas = operacoes.listar;
export const atualizarReceita = operacoes.atualizar;
export const eliminarReceita = operacoes.eliminar;

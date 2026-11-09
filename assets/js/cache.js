// Persistência local dos dados já carregados pela aplicação.
// Este módulo trata apenas do armazenamento; não conhece o DOM nem o estado da interface.

export function guardarDadosEmCache(chave, dados) {
  try {
    localStorage.setItem(
      chave,
      JSON.stringify({
        guardadoEm: new Date().toISOString(),
        ...dados,
      }),
    );
  } catch (erro) {
    console.warn("Não foi possível guardar os dados localmente:", erro);
  }
}

// Recupera os dados guardados para uma chave específica.
// Devolve null quando não existe cache ou quando o conteúdo não pode ser lido.
export function obterDadosEmCache(chave) {
  try {
    const dados = localStorage.getItem(chave);
    return dados ? JSON.parse(dados) : null;
  } catch (erro) {
    console.warn("Não foi possível ler os dados locais:", erro);
    return null;
  }
}

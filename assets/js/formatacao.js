// Funções puras de apresentação de texto.
// Este módulo não depende do DOM, do estado da aplicação nem da base de dados.

const palavrasLigacao = new Set([
  "a",
  "as",
  "o",
  "os",
  "um",
  "uma",
  "uns",
  "umas",
  "de",
  "da",
  "das",
  "do",
  "dos",
  "em",
  "na",
  "nas",
  "no",
  "nos",
  "por",
  "para",
  "com",
  "sem",
  "e",
  "ou",
]);

const entidadesHTML = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#039;",
};

// Melhora apenas a apresentação da descrição, sem alterar o valor guardado na base de dados.
export function formatarDescricaoMovimento(valor) {
  return String(valor ?? "")
    .toLocaleLowerCase("pt-PT")
    .replace(/\S+/gu, (palavra, indice, texto) => {
      const inicioFrase =
        indice === 0 || /[.!?;:]\s+$/u.test(texto.slice(0, indice));
      if (!inicioFrase && palavrasLigacao.has(palavra)) return palavra;

      return palavra.charAt(0).toLocaleUpperCase("pt-PT") + palavra.slice(1);
    });
}

// Escapa caracteres especiais antes de inserir texto dinâmico no HTML.
export function escaparHTML(valor) {
  return String(valor).replace(
    /[&<>"']/g,
    (caractere) => entidadesHTML[caractere],
  );
}

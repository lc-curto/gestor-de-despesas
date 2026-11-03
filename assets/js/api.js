
const API_URL = "https://api.frankfurter.dev/v2";

export async function obterTaxaCambio(moedaOrigem, moedaDestino) {
  const origem = moedaOrigem.toLowerCase();
  const destino = moedaDestino.toLowerCase();

  const resposta = await fetch(
    `${API_URL}/rate/${origem}/${destino}`
  );

  if (!resposta.ok) {
    throw new Error(`Erro ao consultar a API: ${resposta.status}`);
  }

  const dados = await resposta.json();

  return {
    rate: dados.rate,
    date: dados.date
  };
}

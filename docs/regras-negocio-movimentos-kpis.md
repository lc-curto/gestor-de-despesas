# Regras de negócio — Movimentos e KPIs

## Objetivo

A página **Movimentos** é o histórico financeiro unificado. Os KPIs mantêm a estrutura existente: **Receita**, **Despesa** e **Resultado**. A classificação económica da operação determina o KPI; o meio de pagamento e a liquidação não devem provocar duplicações.

## Regras de contabilização

| Operação | Histórico de Movimentos | Receita | Despesa | Resultado |
|---|---:|---:|---:|---:|
| Receita reconhecida | Sim | Soma | — | Aumenta |
| Despesa reconhecida por dinheiro/débito | Sim | — | Soma | Diminui |
| Compra realizada no cartão de crédito | Sim | — | Soma na data da compra | Diminui |
| Prestação de compra parcelada | Sim, uma linha por prestação | — | Valor da prestação no mês correspondente | Diminui |
| Prestação futura fora do mês selecionado | Sim, como pendente no respetivo mês | — | Não entra no mês anterior | Não afeta o mês anterior |
| Transferência entre contas próprias | Sim | — | — | Não altera |
| Pagamento de fatura de cartão | Sim, identificado como liquidação | — | — | Não altera |
| Estorno/devolução de compra | Sim | — | Abate a despesa original | Corrige o resultado |
| Movimento anulado | Preservado no histórico, se aplicável | — | — | Não altera |

## Cartões de crédito

1. A compra é uma despesa na data em que ocorre, independentemente de a fatura já ter sido paga.
2. A compra deve aparecer na página Movimentos, mesmo quando está associada a uma fatura.
3. O pagamento da fatura é uma transferência/liquidação entre a conta bancária e a conta do cartão.
4. A liquidação pode aparecer no histórico para rastreabilidade, mas nunca deve ser somada novamente como despesa.
5. Pagamentos parciais da fatura seguem a mesma regra: liquidam a obrigação, não criam uma nova despesa.
6. Estornos e créditos de compras devem reduzir a despesa, sem serem apresentados como receita operacional.

## Parcelamentos

1. O parcelamento gera prestações individualizadas, com valor, número e data próprios.
2. Cada prestação aparece em Movimentos no mês correspondente à sua data de vencimento/reconhecimento.
3. O KPI Despesa considera apenas o valor da prestação daquele mês, não o valor total original da compra.
4. Prestações de meses futuros não contaminam os KPIs de meses anteriores.
5. A liquidação de uma prestação atualiza a obrigação e o saldo da conta, mas não cria uma segunda despesa.
6. Parcelamento é distinto de recorrência: uma compra parcelada tem quantidade e valor total definidos; uma recorrência é uma regra de repetição.

## Fórmula dos KPIs

- **Receita:** soma das receitas reconhecidas no período.
- **Despesa:** soma das despesas reconhecidas no período, incluindo compras no cartão e as prestações pertencentes ao período, deduzindo estornos.
- **Resultado:** Receita − Despesa.

Transferências entre contas próprias e liquidações de faturas não entram em Receita, Despesa ou Resultado.

## Navegação a partir de Movimentos

- O modal de uma compra associada a cartão não disponibiliza ações operacionais; apresenta os dados e um acesso direto à fatura correspondente.
- O modal de uma prestação não disponibiliza ações operacionais; apresenta a prestação e um acesso direto ao parcelamento de origem.
- Pagamentos de fatura também encaminham para a respetiva fatura.
- A página Movimentos mantém a função de histórico e consulta; edição, pagamento, renegociação, cancelamento e eliminação são feitos no módulo de origem.

## Separação entre módulos

- **Movimentos:** apresenta compras no cartão, prestações, receitas, despesas, transferências e liquidações.
- **Cartões/Faturas:** apresenta cartões, encargos, saldos e liquidações da fatura.
- **Parcelamentos:** gere o compromisso original e as respetivas prestações.
- **Rota Financeira:** mantém a sua regra própria de projeção; não se deve aplicar globalmente o filtro de exclusão de compras individuais de cartão a Movimentos ou aos KPIs.

## Critérios de aceitação

- Uma compra de €60 no cartão aparece uma única vez em Movimentos e aumenta Despesa em €60 no mês da compra.
- O pagamento de €60 da fatura aparece como liquidação, mas não aumenta Despesa.
- Uma compra parcelada de €90 em três prestações de €30 gera €30 de Despesa em cada um dos três meses, e não €90 no primeiro mês.
- Uma prestação de dezembro não aumenta os KPIs de novembro.
- Os filtros e a paginação da listagem incluem compras no cartão sem alterar o valor económico já apurado nos KPIs.

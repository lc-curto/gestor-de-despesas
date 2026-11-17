# Matriz de regras de negócio — Liquidações, parcelamentos e cartões

- **Produto:** Meu Bolso
- **Estado:** Regra de negócio aprovada para orientar o desenvolvimento
- **Âmbito:** Movimentos, liquidações, parcelamentos, cartões de crédito, faturas e Rota Financeira
- **Última atualização:** 17/11/2026

> Este documento define o comportamento funcional esperado. Não afirma que todas as regras já estejam implementadas no código. A implementação deve ser validada por testes de regressão e integração.

## 1. Objetivo e princípios

O sistema deve representar corretamente obrigações, valores a receber, liquidações efetivas e previsões de fluxo de caixa, sem duplicar despesas, receitas ou saídas/entradas de dinheiro.

Princípios obrigatórios:

1. Separar o **movimento original** dos **eventos de liquidação** associados.
2. Permitir liquidação total, parcial e múltiplas liquidações para qualquer obrigação ou valor a receber.
3. Registar cada liquidação pela sua data efetiva e conta financeira utilizada.
4. Preservar o histórico: correções devem ser feitas por estorno ou ajuste rastreável, não por eliminação silenciosa.
5. Projetar apenas o saldo ainda pendente, sem somar novamente valores já efetivados.
6. Tratar cartões de crédito como uma extensão específica do mecanismo geral, porque compras são consolidadas em faturas e saldos não pagos podem transitar entre ciclos.
7. Não presumir regras contratuais de bancos. Juros, multas, comissões e imputações são registados com base nos valores efetivamente informados pelo banco.

## 2. Conceitos

| Conceito | Definição |
|---|---|
| Movimento original | Despesa ou receita que representa a obrigação ou o direito a receber. Mantém o valor e a data originalmente registados, salvo ajuste explícito. |
| Liquidação | Evento financeiro que paga parte ou a totalidade de uma despesa, ou recebe parte ou a totalidade de uma receita. |
| Saldo pendente | Parcela do valor liquidável que ainda não foi paga ou recebida. |
| Prestação | Parcela individual de um compromisso parcelado, com valor, vencimento e estado próprios. |
| Fatura de cartão | Obrigação consolidada do cartão, formada por compras, saldo anterior, encargos, créditos e pagamentos imputados. |
| Saldo transportado | Valor de uma fatura anterior que permanece em dívida e passa a integrar a fatura seguinte. |
| Encargo financeiro | Juro, multa, comissão, imposto ou outro valor adicional efetivamente lançado pela instituição financeira. |
| Previsão | Valor ainda esperado de entrada ou saída de caixa, com base no saldo pendente e na data aplicável. |

## 3. Motor central de liquidações

O mecanismo central deve ser reutilizado por despesas, receitas, prestações, fornecedores e faturas, respeitando as extensões específicas de cada domínio.

### 3.1 Regras

| ID | Regra | Comportamento esperado |
|---|---|---|
| LIQ-01 | Liquidação total | Uma liquidação que cubra todo o saldo pendente coloca o movimento em estado liquidado. |
| LIQ-02 | Liquidação parcial | Uma liquidação inferior ao saldo reduz o saldo pendente e mantém o movimento parcialmente liquidado. |
| LIQ-03 | Múltiplas liquidações | Um movimento pode ter vários pagamentos ou recebimentos associados. |
| LIQ-04 | Data efetiva | Cada liquidação conserva a data em que o dinheiro efetivamente saiu ou entrou. |
| LIQ-05 | Conta financeira | Cada liquidação identifica a conta de origem (despesa) ou destino (receita). |
| LIQ-06 | Valor excedente | Não permitir liquidação acima do saldo pendente, salvo operação explícita de crédito, adiantamento ou ajuste com semântica própria. |
| LIQ-07 | Edição do original | Alterar o valor original recalcula o saldo, sem apagar o histórico das liquidações. Se o novo valor ficar abaixo do total já liquidado, exigir tratamento explícito do excedente. |
| LIQ-08 | Cancelamento | Cancelar o movimento não elimina nem apaga liquidações já realizadas. O cancelamento deve preservar o histórico e impedir novas liquidações indevidas. |
| LIQ-09 | Estorno | Reverter uma liquidação através de um evento de estorno rastreável, mantendo o evento original. |
| LIQ-10 | Auditoria | Guardar associação ao movimento, valor, data efetiva, conta, origem e estado de cada evento. |
| LIQ-11 | Consistência | O saldo não pode ficar negativo por efeito de liquidações comuns. Excedentes exigem uma operação explícita. |
| LIQ-12 | Histórico | Não apagar silenciosamente pagamentos, recebimentos, estornos ou ajustes. |

### 3.2 Estados

O estado de liquidação e a condição de vencimento devem ser conceitos separados.

| Estado de liquidação | Condição |
|---|---|
| Pendente | Não existe valor liquidado. |
| Parcialmente liquidado | Existe valor liquidado e ainda existe saldo pendente. |
| Liquidado | O saldo pendente é zero. |

A condição temporal é calculada separadamente:

- **No prazo:** existe saldo pendente e a data de vencimento ainda não passou.
- **Vencido:** existe saldo pendente e a data de vencimento já passou.
- Um movimento pode, portanto, estar **parcialmente liquidado e vencido** ao mesmo tempo.

### 3.3 Cálculo

Para uma despesa ou receita simples:

\[
\text{Saldo pendente} = \text{Valor liquidável ajustado} - \text{Total líquido liquidado}
\]

O total líquido liquidado considera liquidações válidas menos estornos correspondentes. Ajustes do valor original devem ser identificados e auditáveis.

## 4. Despesas e receitas

| Situação | Movimentos | Rota Financeira |
|---|---|---|
| Despesa pendente | Apresenta o movimento original | Projeta a saída pelo valor pendente na data prevista |
| Despesa parcialmente paga | Mantém o original e apresenta as liquidações efetivas associadas | Regista cada saída efetiva e mantém apenas o saldo pendente na previsão |
| Despesa totalmente paga | Mantém o original como histórico e as liquidações efetivas | Não mantém previsão para o saldo liquidado |
| Receita pendente | Apresenta o movimento original | Projeta a entrada pelo valor pendente na data prevista |
| Receita parcialmente recebida | Mantém o original e apresenta os recebimentos efetivos associados | Regista cada entrada efetiva e mantém apenas o saldo a receber na previsão |
| Receita totalmente recebida | Mantém o original como histórico e os recebimentos efetivos | Não mantém previsão para o saldo recebido |
| Movimento vencido | Mantém visível o saldo pendente e a condição de vencido | Não deve desaparecer; permanece identificado como obrigação ou recebimento em atraso |

Cada pagamento/recebimento deve ser visível como evento efetivo, associado ao movimento original. O valor original não deve ser transformado em vários movimentos independentes apenas porque houve pagamentos em datas diferentes.

## 5. Parcelamentos

Parcelamento e liquidação parcial são conceitos diferentes e devem coexistir.

| Característica | Liquidação parcial | Parcelamento |
|---|---|---|
| Estrutura | Uma obrigação ou direito original | Um compromisso dividido em prestações |
| Calendário | Não exige calendário próprio de parcelas | Cada prestação tem vencimento próprio |
| Liquidação | Pode ocorrer em um ou vários eventos | Cada prestação pode ser liquidada total ou parcialmente |
| Saldo | Saldo da obrigação original | Saldo pendente de cada prestação e do compromisso |

Regras:

1. Cada prestação possui valor, data prevista e estado próprios.
2. Cada prestação aceita uma ou várias liquidações parciais.
3. Uma liquidação afeta apenas a prestação à qual está associada.
4. A previsão considera apenas o saldo pendente de cada prestação.
5. Prestações liquidadas e respetivos pagamentos devem ser preservados numa renegociação.
6. Cancelar um compromisso não apaga prestações nem liquidações históricas.
7. A renegociação deve manter rastreabilidade dos termos anteriores, pagamentos realizados e novo calendário.
8. Uma prestação parcialmente paga não pode ser considerada integralmente liquidada.

## 6. Cartões de crédito e compras

### 6.1 Reconhecimento das compras

| Operação | Cartões/faturas | Movimentos | Rota Financeira |
|---|---|---|---|
| Compra à vista no cartão de crédito | Integra a fatura correspondente | Não aparece como despesa individual adicional | Integra a previsão consolidada da fatura |
| Compra parcelada no cartão | As parcelas integram as faturas dos respetivos ciclos | Não aparecem como despesas individuais adicionais | Considera as obrigações das faturas correspondentes |
| Compra no cartão de débito | Não integra fatura de crédito | É uma despesa na data efetiva | Considera a saída na data efetiva |
| Estorno de compra | Atualiza a fatura de origem | Mantém o ajuste identificável, sem duplicar a compra | Atualiza a obrigação projetada |
| Compra cancelada antes de ser contabilizada | Não integra a fatura | Não gera despesa efetiva | Não gera saída projetada |

**Regra CAR-01:** uma compra no cartão de crédito é reconhecida como despesa uma única vez. Não pode aparecer simultaneamente como despesa individual e como despesa adicional na fatura.

### 6.2 Ciclo de vida da fatura

| Estado | Comportamento |
|---|---|
| Aberta | Valor atualizado conforme compras, créditos, estornos e encargos lançados |
| Fechada | Valor definitivo conforme extrato bancário |
| Parcialmente paga | Regista pagamentos e mantém o saldo em dívida |
| Vencida | Mantém o saldo não liquidado como obrigação vencida |
| Paga | Saldo em dívida igual a zero |

Uma fatura aberta deve aparecer como uma única obrigação consolidada na data de vencimento. O valor é atualizado enquanto estiver aberta e passa a refletir o valor definitivo do extrato quando fechada.

### 6.3 Pagamentos da fatura

1. Permitir um ou vários pagamentos por fatura.
2. Cada pagamento deve possuir valor, data efetiva e conta de origem.
3. O pagamento deve aparecer como saída efetiva de dinheiro na data em que ocorre.
4. O pagamento não é uma nova despesa económica: é liquidação de dívida.
5. O pagamento parcial reduz o saldo em dívida, mas não liquida a fatura.
6. O pagamento total liquida a fatura quando o saldo devido chega a zero.
7. Não permitir pagamento superior ao saldo devido sem operação explícita de crédito ou ajuste.
8. Se o banco fornecer a imputação entre capital, juros, comissões e impostos, guardar essa decomposição.
9. Se a imputação não estiver disponível, registar o pagamento e reduzir o saldo total devido, sem presumir a ordem de imputação.

### 6.4 Saldo transportado e encargos

O saldo não pago de uma fatura transita para a fatura seguinte, mantendo referência à fatura de origem. O transporte não cria uma nova despesa nem uma nova saída de caixa.

Composição de referência:

\[
\begin{aligned}
\text{Fatura seguinte} ={}&
\text{Saldo anterior transportado} \\
&+ \text{Novas compras} \\
&+ \text{Juros efetivamente lançados} \\
&+ \text{Multas e comissões efetivamente lançadas} \\
&+ \text{Outros ajustes} \\
&- \text{Créditos e pagamentos imputados}
\end{aligned}
\]

| Componente | Tratamento |
|---|---|
| Saldo anterior não pago | Transita com referência à fatura de origem; não cria nova despesa |
| Novas compras | Novas despesas reconhecidas uma única vez |
| Juros | Nova despesa financeira quando efetivamente lançados pelo banco |
| Multas | Nova despesa quando efetivamente lançadas |
| Comissões e impostos | Novas despesas quando efetivamente lançados |
| Estornos/créditos | Ajustam ou revertem os lançamentos correspondentes |
| Pagamentos | Reduzem o valor devido segundo a imputação conhecida |

O sistema não calcula nem presume juros, multas, pagamento mínimo, ordem de imputação ou regras contratuais de um banco. Regista os valores efetivamente comunicados pelo emissor.

## 7. Movimentos e Rota Financeira — regra de não duplicação

A Rota Financeira deve representar entradas e saídas efetivas e previstas, sem duplicar o mesmo valor.

### 7.1 Regra geral

- Antes da liquidação, projetar o saldo pendente na data prevista.
- Quando ocorre uma liquidação parcial, registar o fluxo efetivo na data real e reduzir a previsão pelo valor liquidado.
- Manter projetado apenas o saldo ainda pendente.
- Quando ocorre a liquidação total, eliminar a previsão restante.
- Se o vencimento passar sem liquidação, manter o saldo identificado como vencido; não o tratar como uma nova obrigação.
- Receitas seguem a mesma lógica, com entradas efetivas e saldo a receber.

### 7.2 Regra específica das faturas

- A fatura é a obrigação consolidada na data de vencimento.
- Cada pagamento é uma saída efetiva na sua data.
- O saldo ainda devido permanece associado à fatura.
- A projeção não pode somar o valor integral original da fatura aos pagamentos já realizados.
- O saldo transportado para a fatura seguinte não pode criar nova saída projetada por si só, pois já representa dívida reconhecida.
- Juros, multas e encargos lançados aumentam a obrigação e são reconhecidos como novas despesas financeiras.

### 7.3 Separação entre resultado e caixa

- Compras e encargos são reconhecidos como despesas económicas.
- Pagamentos de despesas e faturas representam movimentos de caixa/liquidação, não novas despesas.
- Recebimentos liquidam valores a receber e representam entradas de caixa, não novas receitas económicas.
- A mesma operação pode ter impacto no resultado económico numa data e no caixa noutra; as duas dimensões não devem ser confundidas.

## 8. Edição, cancelamento, estorno e auditoria

| Operação | Regra |
|---|---|
| Editar movimento | Recalcular o saldo pendente; preservar liquidações |
| Reduzir valor abaixo do já liquidado | Exigir ajuste explícito para tratar o excedente |
| Cancelar movimento | Preservar eventos efetivos e bloquear novas liquidações incompatíveis |
| Estornar pagamento/recebimento | Criar reversão rastreável, mantendo o evento original |
| Corrigir fatura | Preservar o extrato/lançamento anterior e registar o ajuste |
| Renegociar parcelamento | Preservar prestações e liquidações históricas; criar novo calendário rastreável |
| Transportar saldo | Preservar vínculo entre fatura de origem e fatura de destino |

## 9. Critérios de aceitação para testes

| ID | Cenário | Resultado esperado |
|---|---|---|
| LIQ-T01 | Pagar uma despesa integralmente | Estado liquidado; saída efetiva na data do pagamento |
| LIQ-T02 | Pagar uma despesa parcialmente | Estado parcial; saldo correto |
| LIQ-T03 | Realizar vários pagamentos | Todos os eventos associados e saldo acumulado correto |
| LIQ-T04 | Receber receita parcialmente | Saldo a receber atualizado |
| LIQ-T05 | Liquidar por contas diferentes | Cada evento associado à respetiva conta |
| LIQ-T06 | Pagar após vencimento | Histórico preservado e saldo identificado como vencido até liquidar |
| LIQ-T07 | Estornar liquidação | Reversão rastreável e saldo recalculado |
| LIQ-T08 | Editar valor original | Saldo recalculado sem apagar liquidações |
| PAR-T01 | Liquidar parcialmente uma prestação | Apenas a prestação correspondente é atualizada |
| PAR-T02 | Renegociar compromisso | Prestações e pagamentos históricos preservados |
| CAR-T01 | Efetuar compra no cartão | Compra não aparece como despesa individual duplicada |
| CAR-T02 | Atualizar fatura aberta | Valor consolidado atualizado na previsão |
| CAR-T03 | Fechar fatura | Valor atualizado para o montante definitivo do extrato |
| CAR-T04 | Pagar parcialmente fatura | Pagamento efetivo registado e saldo mantido |
| CAR-T05 | Pagar fatura em várias datas | Cada saída aparece na respetiva data |
| CAR-T06 | Transportar saldo | Saldo mantém origem e não cria nova despesa |
| CAR-T07 | Lançar juros/multa | Nova despesa financeira apenas quando lançada |
| CAR-T08 | Fechar fatura seguinte | Consolida saldo anterior, novas compras e encargos sem duplicação |
| ROT-T01 | Liquidar parcialmente despesa | Saída efetiva + apenas saldo pendente projetado |
| ROT-T02 | Liquidar totalmente despesa | Previsão restante removida |
| ROT-T03 | Receber parcialmente receita | Entrada efetiva + apenas saldo a receber projetado |
| ROT-T04 | Transportar saldo de fatura | Não cria nova saída de caixa |
| ROT-T05 | Pagar parcialmente fatura antes do vencimento | Projeção da fatura reduzida pelo pagamento |
| ROT-T06 | Pagar fatura depois do vencimento | Pagamento registado na data efetiva; saldo vencido preservado até liquidação |

## 10. Fora do âmbito da regra geral

Não presumir nem calcular automaticamente:

- Percentagem ou montante mínimo obrigatório de pagamento de cartão.
- Ordem contratual de imputação dos pagamentos.
- Taxas de juro, penalizações ou comissões.
- Data inicial de contagem de juros.
- Regras específicas de um banco ou emissor.
- Distribuição de um pagamento entre capital, juros, impostos e comissões quando o extrato não a informa.

Quando o banco disponibilizar esses dados, o sistema deve permitir registá-los e manter a respetiva origem.

## 11. Diretriz de implementação

A implementação deve partir de um motor central de liquidações, com associações explícitas aos movimentos originais e extensões para prestações e faturas. Antes de alterar o modelo de dados ou os serviços:

1. Inspecionar a estrutura atual de movimentos, pagamentos, transferências, prestações e faturas.
2. Identificar funcionalidades existentes que já representem liquidações para evitar duplicação de conceitos ou tabelas.
3. Definir a associação e a contabilização de cada evento.
4. Garantir compatibilidade com dados históricos.
5. Implementar testes de regressão para dashboard, Movimentos, Cartões e Rota Financeira.
6. Não alterar regras existentes de produção sem migração e testes de compatibilidade.

Esta matriz é a referência funcional para decisões futuras de arquitetura, implementação e testes do Meu Bolso.

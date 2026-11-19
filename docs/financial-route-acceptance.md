# Critérios de aceitação — Rota Financeira

**Estado:** especificação aprovada para preparação dos testes  
**Escopo:** testes de aceitação e regressão; sem alterações na lógica de produção  
**Decisões de produto:** obrigações vencidas em secção separada; meses anteriores apresentados como projeção, não como extrato histórico reconciliado.

## 1. Regras funcionais aprovadas

### 1.1 Saldo inicial e saldo de caixa

- O saldo de abertura da Rota soma os saldos iniciais das contas operacionais do tipo banco e dinheiro.
- Saldos de cartões de crédito não integram o saldo de caixa.
- Movimentos operacionais liquidados, não históricos e anteriores ao início do período ajustam o saldo de abertura uma única vez.
- Movimentos históricos, pendentes, anulados e movimentos de contas inativas não alteram o saldo de abertura.
- Saldos negativos válidos devem ser preservados; não podem ser truncados a zero.

### 1.2 Obrigações e eventos projetados

- Receitas e despesas pendentes associadas a contas bancárias/dinheiro são projetadas na respetiva data prevista.
- Uma compra pendente associada a cartão de crédito não reduz o saldo bancário/dinheiro na data da compra.
- A compra no cartão é refletida no caixa através da fatura e da respetiva data de vencimento, quando aplicável.
- Obrigações parcialmente liquidadas projetam apenas o montante em falta.
- Prestações projetam apenas o valor ainda não pago.
- Pagamentos de fatura são movimentos de caixa/transferência e não uma segunda despesa económica.
- Juros, encargos, créditos e saldos transportados devem ser considerados uma única vez.
- Transferências entre contas próprias não são classificadas como receita ou despesa.
- Ajustes aumentam ou diminuem o saldo conforme a direção registada.

### 1.3 Obrigações vencidas — decisão aprovada

- Obrigações com vencimento anterior ao início do período selecionado e ainda em aberto devem permanecer visíveis numa secção própria, identificada como **Vencidas**.
- O saldo em atraso deve corresponder ao montante ainda não liquidado.
- Uma obrigação vencida não deve ser artificialmente lançada no primeiro dia do período.
- A secção de vencidas informa a exposição em atraso, mas não altera o saldo diário projetado sem uma data efetiva ou prevista de pagamento.
- Uma obrigação transportada para outra fatura deve aparecer apenas no destino; a origem marcada como transportada não pode ser duplicada.
- Obrigações liquidadas, anuladas, arquivadas ou encerradas não devem permanecer na lista de vencidas, sem prejuízo da apresentação de liquidações efetivas.

### 1.4 Navegação temporal — decisão aprovada

- A Rota Financeira é uma ferramenta de projeção atual e futura.
- A navegação para meses anteriores é permitida, mas esses meses não são apresentados nem descritos como extratos históricos reconciliados.
- A projeção deve manter coerência interna no período consultado.
- Em períodos de vários meses, o saldo de abertura de cada mês é o saldo de fecho do mês anterior.
- Consultar um mês isoladamente ou dentro de uma janela de três meses deve produzir o mesmo saldo diário para as mesmas datas.
- Não deve haver reinício, duplicação ou omissão de eventos na transição entre meses.

## 2. Matriz de aceitação

| ID | Critério verificável | Cobertura |
|---|---|---|
| RF-01 | Saldo de abertura soma contas banco/dinheiro operacionais ativas | Testes de projeção |
| RF-02 | Cartões não integram o saldo de caixa | Testes de projeção |
| RF-03 | Saldo inicial negativo é preservado | Novo teste de matriz |
| RF-04 | Movimentos liquidados anteriores ao período são incorporados uma única vez | Teste de janela multi-mês |
| RF-05 | Movimentos históricos não alteram a projeção | Testes de regressão existentes |
| RF-06 | Receita/despesa pendente de conta de caixa usa a data prevista | Testes de projeção existentes |
| RF-07 | Despesa pendente de cartão não reduz caixa na data da compra | Novo teste de matriz |
| RF-08 | Liquidação parcial projeta apenas o remanescente | Testes de regressão existentes; ampliar para datas vencidas |
| RF-09 | Fatura projeta saldo em dívida após pagamentos e créditos | Testes de regressão existentes |
| RF-10 | Juros, encargos e saldos transportados entram uma única vez | Teste integrado dedicado a preparar |
| RF-11 | Fatura transportada não é duplicada na origem e no destino | Teste dedicado a preparar |
| RF-12 | Prestação projeta apenas o valor em falta | Testes de regressão existentes |
| RF-13 | Recorrências não duplicam eventos | Teste integrado dedicado a preparar |
| RF-14 | Obrigações vencidas permanecem visíveis em secção própria | Novo teste de matriz |
| RF-15 | Obrigações vencidas não são lançadas no primeiro dia do período | Novo teste de matriz |
| RF-16 | Transferências próprias não alteram totais de receita/despesa | Testes existentes; ampliar |
| RF-17 | Ajustes respeitam sinal e conta afetada | FinancialRouteAdjustmentViewTest |
| RF-18 | Exclusões/anulações atualizam a projeção sem apagar histórico de auditoria | Testes existentes |
| RF-19 | Saldo de fecho de um mês passa a saldo de abertura do seguinte | Novo teste multi-mês |
| RF-20 | Janela de três meses e consultas mensais isoladas são equivalentes | Novo teste multi-mês |

## 3. Testes preparados nesta branch

Ficheiro: tests/Feature/FinancialRouteAcceptanceMatrixTest.php

- test_three_month_projection_carries_closing_balance_and_matches_single_month_views
  - Confirma saldo de abertura, movimentos anteriores ao período, continuidade entre dezembro/janeiro/fevereiro e equivalência com consultas isoladas.
- test_overdue_commitment_is_visible_in_a_separate_section_without_being_assigned_to_period_first_day
  - Define a secção **Vencidas**, preserva o valor em atraso e impede a atribuição artificial ao primeiro dia.
- test_pending_credit_card_expense_does_not_reduce_cash_balance_on_purchase_date
  - Impede que uma despesa pendente de cartão reduza prematuramente o saldo de caixa.


## 5. Resultado da primeira execução de CI — 19/11/2026

**Execução após corrigir a tipagem UUID do helper de teste:**

- php artisan test: **226 aprovados, 2 falhados**, 1.769 assertions, 16,26 s.
- npm run check: aprovado.
- npm run test:js: aprovado.
- npm run build: aprovado.
- Workflow Verificação do projeto: aprovado.

### Falhas funcionais confirmadas

1. **RF-14 / RF-15 — Obrigações vencidas**
   - A página não contém a secção Vencidas nem apresenta o compromisso vencido.
   - O compromisso com vencimento anterior ao início do período desaparece da consulta da Rota.
   - A implementação precisa de consultar obrigações abertas vencidas separadamente, calcular o remanescente e apresentá-lo sem o incorporar no saldo diário.

2. **RF-07 — Despesa pendente de cartão**
   - Uma despesa pendente de 80,00 € associada a cartão foi devolvida pela projeção com projection_delta = -80.00.
   - Confirma-se que a condição atual de cashDelta() reduz o caixa na data da compra, mesmo quando a conta de origem é um cartão.
   - A correção deverá excluir esse débito de caixa na data da compra e preservar o tratamento da fatura no vencimento.

### Critérios aprovados nesta execução

- RF-03: saldo inicial negativo preservado.
- RF-04 / RF-19 / RF-20: continuidade de saldo entre dezembro, janeiro e fevereiro, com equivalência entre consulta de três meses e consultas isoladas.
- Os testes existentes da suíte também passaram: 226 testes no total, incluindo os dois novos cenários acima.

### Nota sobre a primeira tentativa

A primeira execução registou 224 aprovados e 3 falhados. Uma das falhas era apenas uma tipagem incorreta no helper do teste (?int em vez de ?string para IDs UUID). O helper foi corrigido; essa falha não representa defeito de produção.

### Limites

A execução de CI foi feita num PR em rascunho. As etapas de SSH, publicação e ativação de release foram ignoradas por serem exclusivas de eventos que não sejam pull_request. Nenhuma publicação foi realizada.

## 4. Estado e limites desta preparação

- Esta alteração adiciona apenas especificação e testes.
- Não altera serviços, controladores, modelos, migrations, views ou JavaScript de produção.
- Os testes ainda precisam de ser executados no ambiente do projeto, com a base de dados de teste configurada.
- Os testes que definem comportamentos ainda não implementados podem falhar inicialmente; essa falha é evidência para a fase posterior de correção, não autorização para alterar a lógica agora.
- Meses anteriores não serão objeto de reconstrução histórica nesta fase.

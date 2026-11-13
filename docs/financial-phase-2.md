# Fase 2 — Cartões, faturas e compromissos

## Estado

A página autenticada `/compromissos` é para acompanhar cartões, faturas e parcelas. Novas despesas, compras com cartão e compromissos criam-se no formulário habitual `/despesas/criar`, evitando lançamentos duplicados.

## Cartões

- Cartões são contas do tipo `credit_card`, com dia de fecho, dia de vencimento e limite opcional.
- Os dias de ciclo aceitam valores de 1 a 28 para evitar datas inexistentes em meses curtos.
- O limite é opcional; quando definido, a criação de uma compra não pode ultrapassar a utilização disponível.
- Os dados do cartão são definidos ao criar a conta e podem ser alterados na página Cartões e compromissos.

## Compras e faturas

- Ao guardar uma despesa em `/despesas/criar` com uma conta `credit_card`, cria-se um movimento do tipo `expense` debitado no cartão, ligado à despesa legada, categoria (se escolhida) e fatura do ciclo.
- Compra até ao dia de fecho, inclusive, entra na fatura desse mês; compra após o fecho passa para o ciclo do mês seguinte.
- A data de vencimento é o dia configurado do mês seguinte ao mês de referência.
- O total da fatura é calculado pelos movimentos liquidados associados; não é mantido um total paralelo.
- Liquidar uma fatura cria uma transferência da conta bancária/dinheiro para o cartão, sem criar uma segunda despesa. A fatura passa a paga e não aceita novo pagamento.
- A liquidação é transacional e protegida contra pagamentos concorrentes/duplicados.

## Compras parceladas e compromissos

- É possível criar compromissos diferidos ou parcelados (2–120 parcelas).
- Os cêntimos restantes são distribuídos pelas primeiras parcelas, de modo que a soma seja exatamente igual ao total.
- As parcelas têm vencimentos mensais, podem ser liquidadas pela página e aceitam pagamento parcial via API.
- Os pagamentos das parcelas são rastreados na dívida e parcela: a partir de banco/dinheiro criam uma despesa; a partir de cartão criam uma compra na fatura do ciclo, sem lançamento de despesa em duplicado.
- As compras parceladas fora do ciclo de cartão são geridas como compromissos. Compras feitas diretamente com o cartão seguem o fluxo de fatura.

## Rotas acrescentadas

- `GET /compromissos`: acompanhamento e pagamento de faturas/parcelas, com atalhos para lançar despesas em `/despesas/criar`.
- `POST /api/financial/cards/purchases`: regista compra no cartão.
- `GET /api/financial/invoices`: lista faturas com total e quantidade de compras.
- `POST /api/financial/invoices/{id}/pay`: liquida fatura por transferência.
- `PUT /api/financial/accounts/{id}/card-settings`: altera fecho, vencimento e limite.
- A API existente de compromissos cria planos e liquida parcelas.

## Migração

A migration `2026_11_13_150000_add_credit_card_billing_settings.php` acrescenta os campos de ciclo e limite às contas. Deve ser executada em cada ambiente pelo processo normal de deploy; não foi executada nem alterou uma base de dados de produção nesta alteração.

# Núcleo financeiro — primeira implementação

## Regras de migração do legado

- A data de corte fica em `users.financial_cutoff_date`; para utilizadores existentes, a migração inicializa-a em 12/11/2026.
- Despesas e receitas com data menor ou igual à data de corte são copiadas para `movimentos`, ligadas à conta `legacy` do utilizador e marcadas como históricas.
- A conta `legacy` tem `is_operational = false`; nunca participa no saldo operacional.
- Registos antigos com data posterior à data de corte são importados sem conta, com `status = pending` e `requires_account_assignment = true`. Não afetam saldos até à associação a uma conta real.
- O saldo inicial é definido em cada conta real, através de `opening_balance` e `opening_balance_date`. A conta histórica não recebe o saldo bancário real.
- As tabelas `despesas` e `receitas` não são apagadas nem alteradas nesta fase.

## Convenção dos movimentos

Todos os valores são positivos. O tipo e as referências às contas determinam o efeito:

| type | source_account_id | destination_account_id |
|---|---|---|
| income | NULL | Conta que recebe |
| expense | Conta que suporta a despesa | NULL |
| transfer | Conta de origem | Conta de destino |
| adjustment / increase | NULL | Conta ajustada |
| adjustment / decrease | Conta ajustada | NULL |

Transferências e pagamentos de faturas não são receitas nem despesas. Uma compra com cartão é uma despesa suportada pela conta do tipo `credit_card`, associada à respetiva fatura.

## Isolamento por utilizador

As relações financeiras usam FKs compostas por `user_id` e pelo identificador da entidade relacionada. Isto impede que movimentos, contas, categorias, faturas, recorrências, dívidas e parcelas de utilizadores diferentes sejam associados entre si.

## Limites desta etapa

Esta branch implementa a estrutura e a importação inicial do legado. Não altera controllers, interface, cálculos do dashboard nem produção. A lógica transacional de criação/liquidação de movimentos e a reconciliação de saldos serão implementadas em etapas próprias, com testes antes de ativar a utilização.

# Núcleo financeiro — serviços e contratos da API

Esta etapa acrescenta a camada de domínio sobre as tabelas introduzidas em `feature/financial-core-schema`. Não altera os ecrãs existentes nem substitui ainda o dashboard legado.

## Modelos

- `ContaFinanceira`: conta bancária, dinheiro, cartão ou histórico.
- `Movimento`: entrada, saída, transferência ou ajuste.
- `HistoricoMovimento`: auditoria imutável das operações.
- `Fatura`, `Recorrencia`, `Divida` e `ParcelaDivida`: modelos e relações Eloquent para as entidades já criadas no schema.
- `User`: relações para contas, movimentos, dívidas e recorrências.

## Serviços

### Contas

- Criação na moeda principal do utilizador.
- Conta sem saldo inicial fica em `setup_pending` e não pode ser usada.
- Definição do saldo inicial apenas antes de existirem movimentos associados.
- Saldo calculado a partir do saldo inicial e dos movimentos liquidados desde a data de abertura.
- Saldo negativo bloqueado por omissão; permitido quando explicitamente ativado. Cartões de crédito podem representar saldo devedor negativo.
- Encerramento preserva o histórico; saldo positivo exige transferência para outra conta.

### Movimentos

- Criação de receitas, despesas, transferências e ajustes com transação DB.
- Valores sempre positivos; tipo e contas definem o sentido financeiro.
- Transferência movimenta duas contas, sem gerar receita ou despesa.
- Movimentos pendentes não afetam saldos e podem ser editados, liquidados ou anulados.
- A liquidação exige associação a conta operacional do mesmo utilizador e valida saldo disponível.
- Movimentos liquidados não permitem alteração de valor, data ou contas. Correções descritivas são auditadas.
- Cada criação, liquidação, anulação ou correção gera registo em `historico_movimentos`.
- Operações críticas usam transações e bloqueio de linhas (`lockForUpdate`).

## API autenticada

As rotas são registadas em `routes/web.php`, sob `auth`, e devolvem JSON.

| Método | Rota | Função |
|---|---|---|
| GET | `/api/financial/accounts` | Listar contas e saldos calculados |
| POST | `/api/financial/accounts` | Criar conta |
| PUT | `/api/financial/accounts/{id}/opening-balance` | Definir saldo inicial |
| POST | `/api/financial/accounts/{id}/close` | Encerrar conta |
| GET | `/api/financial/movements` | Listar movimentos com filtros e paginação |
| POST | `/api/financial/movements` | Criar movimento ou compromisso pendente |
| PUT | `/api/financial/movements/{id}` | Editar pendente ou corrigir descrição de liquidado |
| PUT | `/api/financial/movements/{id}/settle` | Liquidar e associar a conta |
| PUT | `/api/financial/movements/{id}/void` | Anular pendente |

### Payloads essenciais

Criar conta:
```json
{
  "name": "Conta principal",
  "type": "bank",
  "opening_balance": "1250.00",
  "opening_balance_date": "2026-11-12",
  "allow_negative_balance": false
}
```

Criar despesa liquidada:
```json
{
  "type": "expense",
  "description": "Supermercado",
  "amount": "42.50",
  "movement_date": "2026-11-12",
  "source_account_id": "UUID_DA_CONTA"
}
```

Criar compromisso pendente:
```json
{
  "type": "expense",
  "status": "pending",
  "description": "Eletricidade",
  "amount": "65.00",
  "movement_date": "2026-11-20"
}
```

Liquidar pendente:
```json
{
  "source_account_id": "UUID_DA_CONTA"
}
```

## Fora do âmbito desta etapa

- Interface de gestão de contas e movimentos.
- Migração do dashboard antigo para a nova tabela `movimentos`.
- Gestão funcional de cartões/faturas, planos de dívida e geração automática de recorrências.
- Projeção da Rota Financeira e relatórios.
- Ajustes/estornos financeiros completos de movimentos liquidados (a regra de bloqueio está aplicada; o fluxo de estorno será uma etapa própria).

## Segurança operacional

- Nenhuma migration nova nesta etapa.
- Nenhuma migration executada em produção.
- O dashboard e CRUD legado de despesas/receitas continuam intactos.

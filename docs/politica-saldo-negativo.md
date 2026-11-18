# Política de saldo negativo

## Âmbito

A permissão de saldo negativo aplica-se exclusivamente a contas bancárias e contas de dinheiro/carteira. Cartões de crédito seguem as regras próprias de limite, compras, dívida inicial e faturação.

## Regra padrão

- Novas contas bancárias e de dinheiro são criadas com `allow_negative_balance = false`.
- A interface de criação apresenta a opção **Permitir saldo negativo nesta conta**, desativada por padrão.
- O utilizador pode ativar ou desativar a permissão nas definições da conta.
- Um saldo inicial negativo ativa automaticamente a permissão, porque a conta já começa com saldo operacional negativo.

## Operações sujeitas à regra

Quando a permissão está desativada, o sistema rejeita qualquer operação cujo débito exceda o saldo operacional disponível:

- criação de despesas;
- transferências, considerando a conta de origem;
- ajustes de diminuição;
- liquidação total ou parcial de despesas e prestações;
- pagamento de faturas e de dívida inicial de cartões, considerando a conta bancária ou de dinheiro usada como origem;
- associação de despesas importadas a uma conta operacional.

Receitas, transferências recebidas e ajustes de aumento não consomem saldo disponível.

Movimentos históricos não alteram o saldo operacional e não são submetidos a esta validação.

## Saldo disponível

O saldo operacional é calculado em cêntimos inteiros a partir do saldo inicial e dos movimentos operacionais liquidados. O débito é permitido quando:

`saldo_operacional - valor_do_débito >= 0`

Com `allow_negative_balance = true`, o sistema permite que o resultado seja negativo.

A validação ocorre no serviço financeiro, dentro da transação que regista o movimento. A conta debitada é bloqueada antes da leitura do saldo, para serializar débitos concorrentes. Operações que envolvem duas contas bloqueiam-nas por ordem determinística de identificador, evitando ciclos de lock em transferências opostas.

## Desativação da permissão

Não é possível desativar a permissão enquanto o saldo operacional atual for negativo. A regra considera o saldo total atual, não apenas o valor do saldo inicial. Depois de o saldo regressar a zero ou a um valor positivo, o utilizador pode desativar a permissão.

## Cartões de crédito

Compras em cartão devem passar por `CardInvoiceService` e estar associadas à fatura do cartão correto. O serviço de movimentos genérico não aceita despesas diretamente numa conta de cartão. Despesas importadas também não podem ser associadas diretamente a cartões através da associação genérica de contas.

O pagamento de faturas e da dívida inicial continua a ser uma transferência da conta bancária/de dinheiro para o cartão; por isso, a conta de origem respeita a política de saldo negativo.

## Auditoria e testes

Os testes cobrem:
- débito acima do saldo com permissão desligada e ligada;
- transferências e ajustes de diminuição;
- pagamentos parciais de prestações;
- pagamento de fatura com saldo insuficiente na conta de origem;
- proteção contra criação genérica e associação de despesas importadas a cartões;
- impossibilidade de desativar a permissão enquanto o saldo operacional for negativo.

A validação de concorrência em MySQL/InnoDB deve ser executada separadamente; testes em SQLite não comprovam o comportamento de `SELECT ... FOR UPDATE` em produção.

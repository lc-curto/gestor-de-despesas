# Fase 3 — Recorrências e previsões

## Entregue

- Regras de receitas e despesas recorrentes com cadência diária, semanal, mensal, anual ou intervalo personalizado em dias; permitem valor, categoria, conta predefinida, data inicial e data final opcional.
- Geração idempotente de movimentos previstos numa janela móvel de 90 dias. O comando `php artisan financial:generate-recurring-movements` é agendado diariamente às 00:10 e a página também preenche as previsões em falta ao ser aberta.
- Cada previsão é um movimento pendente, criado no núcleo financeiro e espelhado na tabela legada de receita/despesa para continuar visível no histórico habitual. Não altera o saldo da conta.
- A confirmação associa a conta escolhida, mantém o movimento pendente e não afeta o saldo até à liquidação. A liquidação posterior reutiliza o mesmo movimento; se for uma despesa num cartão, associa-o à fatura sem duplicar a despesa.
- Uma previsão pode ser reagendada ou anulada. A data original fica guardada à parte da data reagendada; as ações ficam em `historico_movimentos`.
- As regras podem ser pausadas e retomadas. Previsões já geradas continuam disponíveis; as ocorrências anuladas não são recriadas. Regras com data final deixam de gerar depois dessa data.
- As ocorrências pertencem ao mesmo registo de movimentos usado pelo restante núcleo; não existe uma tabela paralela de projeções.

## Operação no alojamento

O scheduler Laravel requer que o alojamento invoque `schedule:run` a cada minuto. No Alwaysdata, configurar uma tarefa cron com:

```cron
* * * * * cd /caminho/da/aplicacao && php artisan schedule:run >> /dev/null 2>&1
```

Sem essa tarefa externa, a geração continua a ocorrer quando o utilizador abre a página de Recorrências, mas não é garantida enquanto a aplicação estiver sem acessos.

## Teste local

Depois de atualizar a branch e instalar as dependências, executar `php artisan migrate` e abrir `/recorrencias`. A geração também pode ser acionada manualmente com `php artisan financial:generate-recurring-movements`; a suite específica é `php artisan test --filter=FinancialRecurrenceTest`.

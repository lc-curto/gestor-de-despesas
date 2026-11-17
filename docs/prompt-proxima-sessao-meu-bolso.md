# Prompt para a próxima sessão — Meu Bolso

Continua o desenvolvimento do Meu Bolso no repositório `https://github.com/lc-curto/gestor-de-despesas`.

## Regras obrigatórias

- Trabalha exclusivamente na branch `feature/payment-modal-and-financial-workflow`.
- Lê primeiro `docs/implementation-plan-meu-bolso.md`, `docs/prompt-continuacao-meu-bolso.md` e `docs/audit-http-forms-actions-routes-2026-11-17.md`.
- A Fase 1 e a Fase 2 estão concluídas. A Fase 3 continua aberta.
- Antes de qualquer alteração, executa exatamente `php artisan test --filter='FinancialAcceptanceTest'` e regista o resultado.
- Não faças migrações destrutivas. Não marques nenhuma fase como concluída sem testes de aceitação PASS.
- Não apagues alterações locais existentes. Inspeciona `git status` e o último commit primeiro.

## Contexto da última sessão

Foram implementadas proteções de alta prioridade: movimentos liquidados já não podem ter montante/data/descrição/categoria substituídos através da API ou do formulário legacy; pagamentos não podem atingir obrigações/prestações canceladas; criação de compromisso e pagamento inicial passaram para uma transação única; o estorno de pagamentos de dívida inicial atualiza o contador do cartão; e a conclusão da revisão financeira recalcula snapshots sob lock por utilizador. Foi adicionada uma migração aditiva para `movimentos.opening_debt_card_id`. Ao validar saldos, também foi corrigido o estorno geral: o movimento original permanece liquidado e o contra-lançamento liquidado compensa-o, em vez de anular o original e deixar só o contra-lançamento a afetar o saldo.

A suíte direcionada `php artisan test --filter='FinancialAcceptanceTest|FinancialCoreServiceTest'` passou: **35 testes, 172 assertions**. A execução completa `php artisan test` não passou: **46 falhas, 68 testes aprovados, 373 assertions**. Não assumes que as falhas são preexistentes sem as investigar. Entre os sinais observados estão falhas nos testes de recorrência (`FinancialRecurrenceTest`: forecast/consulta de ocorrência, edição recorrente e estado após remoção por âmbito), respostas CSRF 419 em testes `FinanceFlowTest` que ainda não desativam `PreventRequestForgery`, e assertions de constraints/decimal no SQLite.

O relatório foi atualizado com as correções, limites, resultados e problemas por triagem. Não foi declarada a Fase 3 concluída.

## Próximas tarefas

1. Verifica o estado remoto/local, o commit mais recente e se as alterações desta sessão já foram publicadas; preserva a branch obrigatória.
2. Reexecuta primeiro o teste de aceitação obrigatório. Depois investiga e corrige as falhas restantes da suíte completa, começando pelas recorrências e pelos testes 419 (corrige o harness de testes, não desativa CSRF na aplicação).
3. Confirma que estornos mantêm saldos corretos para despesas, receitas, transferências, ajustes, faturas e pagamentos de dívida inicial; garante que nenhum endpoint pode apagar/editar só um lado de um par de estorno.
4. Valida o novo FK/coluna em SQLite e no motor de produção esperado; acrescenta teste de migração apropriado. Não faças backfill quando o vínculo histórico não for determinístico; relata casos ambíguos.
5. Revê a serialização por lock do middleware e adiciona teste concorrente multi-conexão, se o ambiente permitir. Testa conclusão final da revisão contra alterações concorrentes.
6. Continua a auditoria de persistência ponta a ponta: valores/data/método efetivos enviados pelos formulários têm de chegar aos registos legacy e canónicos, ser lidos de volta e ser coerentes após edição/estorno. Mantém cada lacuna separada dos bugs confirmados.
7. Atualiza o relatório e o plano com evidência atual, publica na branch se os testes e a revisão do diff justificarem, e termina com um resumo honesto dos PASS/FAIL. Não declares a Fase 3 concluída enquanto a aceitação não estiver PASS.

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

 A suíte de aceitação final passou: `php artisan test --filter='FinancialAcceptanceTest'` — **19 testes, 92 assertions**. A suíte completa passou com **109 passed, 5 skipped, 495 assertions**. Os cinco skipped são constraints `CHECK` dependentes de `ALTER TABLE`, não suportadas pelo SQLite local; não equivalem a validação do motor de produção.

 O relatório e o plano foram atualizados com as correções, limites e resultados. O commit publicado é `e269b8a` (`Corrigir recorrências e harness financeiro`). Não foi declarada a Fase 3 concluída.

 Foi iniciado um teste visual da interface pelo browser sandbox com o servidor Laravel local. O login carregou, mas o registo falhou com **419 CSRF** e a página apareceu sem estilo. O diagnóstico confirmou que a aplicação gera URLs `http://127.0.0.1:8000` para formulários e CSS, enquanto o proxy apresenta a página em HTTPS; o browser bloqueia os assets por mixed content e envia o POST para HTTP. É um problema de configuração/proxy do ambiente de teste, ainda não corrigido no código publicado. Não houve criação bem-sucedida da conta temporária.

## Próximas tarefas

1. Verifica o estado remoto/local, o commit mais recente e se as alterações desta sessão já foram publicadas; preserva a branch obrigatória.
2. Reexecuta primeiro o teste de aceitação obrigatório. Depois investiga e corrige as falhas restantes da suíte completa, começando pelas recorrências e pelos testes 419 (corrige o harness de testes, não desativa CSRF na aplicação).
3. Confirma que estornos mantêm saldos corretos para despesas, receitas, transferências, ajustes, faturas e pagamentos de dívida inicial; garante que nenhum endpoint pode apagar/editar só um lado de um par de estorno.
4. Valida o novo FK/coluna em SQLite e no motor de produção esperado; acrescenta teste de migração apropriado. Não faças backfill quando o vínculo histórico não for determinístico; relata casos ambíguos.
5. Corrige/reproduz o teste visual local através do proxy: configurar `TrustProxies`/`X-Forwarded-Proto` ou uma origem consistente para que `url()`, `asset()` e ações de formulário usem HTTPS. Não resolver desativando CSRF; validar login/registo numa sessão real e criar um utilizador temporário apenas na base descartável.
6. Revê a serialização por lock do middleware e adiciona teste concorrente multi-conexão, se o ambiente permitir. Testa conclusão final da revisão contra alterações concorrentes.
7. Continua a auditoria de persistência ponta a ponta: valores/data/método efetivos enviados pelos formulários têm de chegar aos registos legacy e canónicos, ser lidos de volta e ser coerentes após edição/estorno. Mantém cada lacuna separada dos bugs confirmados.
8. Atualiza o relatório e o plano com evidência atual, publica na branch se os testes e a revisão do diff justificarem, e termina com um resumo honesto dos PASS/FAIL. Não declares a Fase 3 concluída enquanto a aceitação não estiver PASS.

# Prompt de continuação — Meu Bolso

Continua apenas na branch `feature/payment-modal-and-financial-workflow` do repositório `https://github.com/lc-curto/gestor-de-despesas`.

Lê antes de trabalhar:

1. `docs/implementation-plan-meu-bolso.md`
2. `docs/audit-http-forms-actions-routes-2026-11-17.md`
3. `docs/ui-test-setup-meu-bolso.md`
4. `docs/prompt-proxima-sessao-meu-bolso.md`

Antes de qualquer alteração de código, executa exatamente:

```bash
php artisan test --filter='FinancialAcceptanceTest'
```

A Fase 3 permanece aberta. Não desativar CSRF, não usar dados reais na base descartável e não considerar SQLite evidência de concorrência MySQL/PostgreSQL. A receita de reprodução do browser HTTPS, os resultados concretos e as pendências estão no prompt da próxima sessão e no procedimento de UI. Segue a ordem indicada nesse prompt, atualiza documentos com resultados observados e não declares a fase concluída sem reconciliação, testes concorrentes multi-conexão e aceitação PASS.

Publica apenas na branch de feature definida, depois de rever `git diff`, `git diff --check` e resultados de teste. No final informa hash do commit e resultados, sem mascarar limitações.

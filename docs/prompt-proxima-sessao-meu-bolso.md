# Prompt para a próxima sessão — Meu Bolso

Continua o desenvolvimento no repositório `https://github.com/lc-curto/gestor-de-despesas`.

## Restrições e arranque

- Trabalha exclusivamente na branch `feature/payment-modal-and-financial-workflow`; confirma `git status`, `git branch --show-current`, `git log -1` e o remoto antes de editar.
- Lê primeiro `docs/implementation-plan-meu-bolso.md`, `docs/prompt-continuacao-meu-bolso.md`, `docs/audit-http-forms-actions-routes-2026-11-17.md` e `docs/ui-test-setup-meu-bolso.md`.
- Antes de qualquer alteração de código, executa exatamente `php artisan test --filter='FinancialAcceptanceTest'` e regista o resultado.
- Preserva CSRF. Não executes `migrate:fresh` fora da base SQLite descartável indicada no procedimento. Não reconcilies/apagues dados reais sem diagnóstico, autorização e plano auditável.
- Não marques a Fase 3 concluída: continua pendente reconciliação de dados, validação concorrente e aceitação completa.

## Estado publicado desta sessão

- A branch contém a correção HTTPS do proxy via `config/trustedproxy.php` e `TRUSTED_PROXIES` em `.env.example`; o middleware `TrustProxies` global do Laravel consome essa configuração. Nesta sandbox, `.env` local usa `TRUSTED_PROXIES=REMOTE_ADDR`, `APP_URL=http://127.0.0.1:8000` e SQLite descartável.
- Para UI, o servidor tem de escutar em `0.0.0.0:8000`; abrir o URL HTTPS do encaminhador para a porta 8000. Nunca desligar CSRF.
- Corrigido o resumo de Movimentos: o JS substituía os totais Blade por zero porque não existiam `data-total`; a view agora fornece os valores e o acceptance valida a marcação.
- Browser comprovou registo e dashboard, criação de conta, despesa paga, receita e despesa pendente; também criou obrigação com vencimento de 90 €, pagou 25 € e depois 65 € pela página Compromissos e observou o saldo em falta passar de 90 € para 65 € e depois zero.
- Uma despesa única marcada como “Paguei uma parte” é rejeitada explicitamente pelo JS: o fluxo parcial é suportado em obrigações/parcelas, não em despesas comuns. Registar esta lacuna, não a classificar como teste bem-sucedido.
- Acceptance depois da correção do resumo: **19 testes / 100 assertions, sem falhas, com 1 teste marcado risky**. Investigar o aviso risky. A suíte completa de referência anterior foi **109 passed, 5 skipped, 495 assertions**; ainda não foi repetida depois das alterações desta sessão. Os cinco skipped correspondem a constraints CHECK via `ALTER TABLE` não suportadas no SQLite local.

## Próximas tarefas, por ordem

1. Confirmar o resultado atual de `git log -1` e reexecutar `php artisan test --filter='FinancialAcceptanceTest'` antes de novas alterações.
2. Reexecutar `php artisan test` completo e explicar o teste risky e os cinco skipped.
3. Retomar browser na base descartável: testar login separado, logout, criação de recorrência, parcelamento UI com frequências mensal/semanal/quinzenal, edição de frequência, conta/cartão, compra no cartão e pagamento de fatura. Registar PASS/FAIL por fluxo; não chamar “todos os cenários” ao que não foi percorrido.
4. Implementar e executar testes concorrentes multi-conexão em MySQL ou PostgreSQL para a conclusão da revisão financeira e pagamentos/edições protegidos por lock. SQLite não prova serialização real.
5. Fazer diagnóstico e, se autorizado, reconciliação auditável apenas dos dados reais determinísticos; manter ambíguos para revisão. Remover leituras legacy apenas depois da reconciliação e dos testes.
6. Atualizar plano/relatório com evidência, sem concluir Fase 3 enquanto reconciliação, concorrência e aceitação não estiverem PASS.

## Receita do teste visual

Segue `docs/ui-test-setup-meu-bolso.md`. Essencial: PHP 8.4 e `composer install`; `.env` ignorado com `APP_KEY`, `DB_CONNECTION=sqlite`, caminho absoluto para `database/meu-bolso-browser-disposable.sqlite`, `APP_URL=http://127.0.0.1:8000` e `TRUSTED_PROXIES=REMOTE_ADDR`; confirmar a base descartável, migrar essa base, arrancar `php artisan serve --host=0.0.0.0 --port=8000` e abrir a origem HTTPS do proxy. Criar utilizador fictício pelo registo. Não guardar password no repositório.

No fecho, informar branch/commit publicado, ficheiros alterados, comandos e resultados exatos, cenários UI efetivamente testados e lacunas. Não publicar na branch principal; usar somente a branch feature especificada.

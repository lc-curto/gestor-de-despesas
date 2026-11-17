# Prompt para a próxima sessão — Meu Bolso

Continua o desenvolvimento no repositório `https://github.com/lc-curto/gestor-de-despesas`.

## Restrições e arranque

- Trabalha exclusivamente na branch `feature/payment-modal-and-financial-workflow`.
- Antes de editar, confirma `git status`, `git branch --show-current`, `git log -1 --oneline` e `git remote -v`. Não assumes que o commit indicado numa sessão anterior continua a ser o HEAD.
- Lê primeiro:
  1. `docs/implementation-plan-meu-bolso.md`
  2. `docs/prompt-continuacao-meu-bolso.md`
  3. `docs/audit-http-forms-actions-routes-2026-11-17.md`
  4. `docs/ui-test-setup-meu-bolso.md`
  5. este documento.
- Antes de qualquer alteração de código, executa exatamente `php artisan test --filter='FinancialAcceptanceTest'` e regista o output completo, exit code, testes, assertions, falhas, warnings e risky tests.
- Preserva CSRF. Não uses bypass de CSRF na aplicação, não uses dados reais na base descartável e não executes `migrate:fresh` sem confirmar inequivocamente que `DB_DATABASE` aponta para o SQLite descartável.
- Não marques a Fase 3 concluída: continuam pendentes reconciliação de dados reais, testes concorrentes multi-conexão em MySQL/PostgreSQL e aceitação integral.
- Se o utilizador indicar que vai testar autenticação separadamente, podes saltar os testes de registo, login, logout, redireccionamento de rotas protegidas e persistência da sessão. Nesse caso, não os repitas nem os classifiques como pendências da ronda financeira; documenta-os como `SKIPPED — fora do escopo solicitado` e inicia a UI já com uma sessão autenticada disponível.

As falhas de infraestrutura e dependências encontradas durante a preparação estão documentadas em `docs/test-infrastructure-notes-2026-11-17.md`. Lê essa nota antes de reinstalar o ambiente: PHP 8.3 não satisfaz o `composer.lock`, o lock exige PHP >= 8.4.1, o `.env` de UI deve usar SQLite descartável e o PHPUnit usa uma base diferente definida em `phpunit.xml`.

### Inventário completo de infra/dependências e recuperação

Não interpretar falhas desta lista como regressões de negócio:

- **PHP ausente:** a primeira execução de `php artisan test --filter='FinancialAcceptanceTest'` terminou com `bash: php: command not found` e exit code `127`.
- **Versão incompatível:** PHP `8.3.6` não instala o lock actual. O `composer.lock` contém Symfony 8.1/Laravel 13 que exigem efectivamente `php >=8.4.1`; não executar `composer update` nem alterar o lock apenas para contornar esta incompatibilidade. Usar PHP `8.4.27` ou superior compatível.
- **Pacotes PHP obrigatórios:** confirmar `cli`, `common`, `sqlite3`/PDO SQLite, `mbstring`, `xml`, `curl`, `zip` e `intl` antes de correr Composer/Laravel.
- **Composer:** executar `composer install --no-interaction --prefer-dist --no-progress`; não actualizar dependências. O Composer distribuído pelo sistema emitiu vários `Deprecation Notice` (`E_STRICT` e parâmetros implicitamente nullable); esses avisos não são falhas do projecto se a instalação terminar com sucesso.
- **`.env` ausente:** criar a partir de `.env.example`; gerar uma chave local com `php artisan key:generate --force`. Nunca versionar `.env`, `APP_KEY`, credenciais, tokens ou passwords.
- **Ligação inicial errada:** `.env.example` pode apontar para MySQL. Para UI local, substituir por SQLite descartável e confirmar as variáveis por chave, sem substituições textuais parciais.
- **Base PHPUnit versus base UI:** `phpunit.xml` força `DB_DATABASE=database/database.sqlite`; a UI servida por `artisan serve` deve usar exclusivamente `database/meu-bolso-browser-disposable.sqlite`. Não misturar as duas bases.
- **`migrate:fresh`:** antes de executar, verificar que `DB_DATABASE` é exactamente `/home/ubuntu/gestor-de-despesas/database/meu-bolso-browser-disposable.sqlite`; `migrate:fresh` apaga a ligação seleccionada e nunca pode ser usado numa base real.
- **Proxy/HTTPS:** usar `APP_URL=http://127.0.0.1:8000`, `TRUSTED_PROXIES=REMOTE_ADDR` na sandbox equivalente e `php artisan serve --host=0.0.0.0 --port=8000`. O hostname HTTPS do port-forward é efémero por sessão; não reutilizar hosts antigos. Não trocar por `TRUSTED_PROXIES=*` nem desactivar CSRF.
- **Browser tooling:** se `browser_fill_form` devolver `browser:Unsupported browser action in payload`, preencher com `browser_input` campo a campo usando os índices visíveis. Isto é uma limitação da ferramenta, não da aplicação.
- **SQLite e skipped:** os cinco testes de constraints CHECK dependentes de `ALTER TABLE` podem ficar skipped em SQLite; isso não comprova o comportamento do motor de produção. Validar em MySQL/PostgreSQL quando for necessário testar constraints/concorrência.
- **Teste risky conhecido:** `test_acceptance_dashboard_and_movements_read_canonical_financial_movements` passa as assertions mas pode ser marcado risky porque código/teste deixa um output buffer aberto. Investigar a origem; não ocultar o resultado com opções PHPUnit.

Receita mínima de recuperação numa sandbox nova:

```bash
php -v                         # exigir >= 8.4.1
composer install --no-interaction --prefer-dist --no-progress
test -f .env || cp .env.example .env
php artisan key:generate --force
mkdir -p database
touch database/meu-bolso-browser-disposable.sqlite
grep -nE '^(DB_CONNECTION|DB_DATABASE|APP_URL|TRUSTED_PROXIES)=' .env
php artisan migrate:fresh --force  # apenas depois de confirmar o caminho descartável
php artisan serve --host=0.0.0.0 --port=8000
```

## Ponto exato da interrupção — 17/11/2026

A sessão anterior parou durante a preparação do runtime para repetir o teste de aceitação. **Não parou numa falha nova do proxy HTTPS.**

Sequência observada:
1. A primeira tentativa do comando PHPUnit não arrancou devido a erro de sintaxe da shell.
2. A tentativa seguinte também não arrancou: o ambiente não tinha PHP instalado (exit code 127).
3. Foi instalado PHP 8.3 e Composer, mas o `composer.lock` exige PHP >= 8.4.1; não atualizar o lock nem alterar dependências para contornar isto.
4. Foi disponibilizado PHP 8.4.27 e as dependências foram instaladas com sucesso, sem atualizar `composer.lock`.
5. O teste obrigatório foi então executado, mas o ambiente isolado não tinha `APP_KEY`: resultado reportado de 9 falhas, 10 avisos e 45 assertions. Esse resultado é uma falha de preparação/configuração do ambiente, não evidência de regressão nas regras financeiras.
6. A sessão foi interrompida antes de gerar uma chave local efémera e repetir o comando. Portanto, ainda não existe um resultado de baseline válido nesta execução.

### Próxima ação imediata

- Confirmar que PHP 8.4.27, extensões necessárias e dependências Composer continuam disponíveis.
- Verificar se existe `.env`. Se não existir, criar a partir de `.env.example`; gerar `APP_KEY` local com `php artisan key:generate`. Não versionar `.env`, chave, passwords ou dados de teste.
- Confirmar que a configuração de teste usa SQLite e que o caminho é descartável; não apontar para base de produção ou dados reais.
- Reexecutar exatamente `php artisan test --filter='FinancialAcceptanceTest'`. Se falhar, classificar cada falha pela causa e corrigir primeiro apenas a preparação do ambiente. Não alterar código de negócio para mascarar falhas de configuração.
- Só depois do baseline executado com sucesso iniciar alterações de código.

## Estado HTTPS e testes visuais — não reabrir sem evidência

A correção de HTTPS já está implementada e o procedimento foi exercitado com sucesso numa sessão anterior:
- `config/trustedproxy.php` lê `TRUSTED_PROXIES`; o middleware global `TrustProxies` respeita os cabeçalhos encaminhados.
- Na sandbox anterior, `TRUSTED_PROXIES=REMOTE_ADDR` resolveu o esquema/host. `APP_URL=http://127.0.0.1:8000` permaneceu como origem local.
- O servidor precisa de escutar em `0.0.0.0:8000`; abrir o URL HTTPS de port-forward da sessão atual (o hostname é efémero e não deve ser reutilizado).
- CSS, assets, form actions e submissão de registo foram observados em HTTPS, sem mixed content e sem desativar CSRF.
- Não substituir a configuração por `TRUSTED_PROXIES=*` num serviço exposto. Em produção, configurar apenas os proxies/CIDRs confiáveis reais.

**Não tratar o problema antigo de mixed content/419 como ainda aberto por defeito.** Só reabrir a investigação se a reprodução atual demonstrar novamente URLs HTTP, CSS bloqueado ou erro 419; nesse caso recolher URL, headers encaminhados, configuração efetiva e logs antes de alterar código.

## Evidência visual já obtida

Na base SQLite descartável e com utilizador fictício, o browser já comprovou:
- Registo e criação de sessão autenticada, com redirecionamento ao dashboard.
- Dashboard, CSS e assets servidos em HTTPS.
- Criação de conta bancária.
- Despesa paga de 24,50 €, receita paga de 250,00 € e despesa pendente de 18,75 €.
- Criação de obrigação de 90,00 €; pagamento de 25,00 € deixou 65,00 € em falta; pagamento de 65,00 € liquidou o saldo.
- Após reload de Movimentos, os totais apresentados foram 250,00 € de receitas, 43,25 € de despesas e 206,75 € de resultado.

Foi corrigido o bug em que o JavaScript substituía os totais renderizados pelo servidor por zero por falta de atributos `data-total`. A view fornece agora os valores e o teste de aceitação verifica a marcação.

### Lacunas de browser ainda abertas — não classificar como PASS

- Autenticação/login/logout: opcional e pode ser saltada quando o utilizador pedir explicitamente; não bloquear a ronda financeira por estes testes.
- Criação e edição de regras de recorrência.
- Parcelamentos na interface: mensal, semanal e quinzenal; incluindo edição da frequência e preservação de prestações/pagamentos já realizados.
- Fluxo de conta/cartão: compra no cartão e pagamento de fatura.
- Confirmar a consistência dos formulários e mensagens de validação nos fluxos acima.

Limitação funcional já observada: a opção “Paguei uma parte” numa despesa comum de pagamento único é rejeitada explicitamente pelo JavaScript. Pagamentos parciais estão suportados no fluxo de obrigações/prestações em Compromissos, não em despesas comuns. Não alterar esta regra sem decisão explícita de produto e cobertura de testes.

## Estado de testes conhecido — reconciliar antes de reportar

Os documentos contêm resultados de momentos diferentes; não os misturar:
- Estado inicial informado: acceptance 19 testes / 92 assertions PASS e suite completa 109 passed / 5 skipped / 495 assertions.
- Evidência mais recente escrita no relatório de auditoria e no procedimento UI: acceptance 19 testes / 100 assertions, sem falhas, com 1 teste risky.
- A suite completa de 109 passed / 5 skipped / 495 assertions é uma referência anterior e **não foi repetida depois das últimas alterações**.
- Os cinco skipped são testes de constraints CHECK dependentes de `ALTER TABLE`, não suportadas pelo SQLite local.

A próxima sessão deve executar novamente o acceptance e a suite completa. Investigar e explicar o teste risky; identificar nominalmente os cinco skipped e confirmar se continuam a ser apenas limitações SQLite. Não afirmar que o resultado histórico é o resultado atual.

## Próximas tarefas, por ordem

1. Restaurar o ambiente e obter baseline válido do acceptance, conforme “Ponto exato da interrupção”.
2. Executar `php artisan test` completo; explicar falhas, risky tests e skipped, sem ocultar resultados.
3. Repetir no browser os fluxos ainda pendentes listados acima, usando a base descartável e um utilizador fictício. Registar PASS/FAIL/BLOCKED por fluxo, com evidência observável.
4. Implementar e executar testes concorrentes multi-conexão em MySQL ou PostgreSQL para conclusão da revisão financeira e operações de pagamento/edição protegidas por lock. SQLite não comprova concorrência nem semântica de locks do motor de produção.
5. Executar `php artisan financial:diagnose-data` (e, quando necessário, `--user`/ `--json`) para produzir diagnóstico dos dados reais. Não aplicar migração sem autorização explícita, plano auditável, backup e revisão dos casos ambíguos. A migração determinística deve ser primeiro simulada sem `--apply`.
6. Só remover leituras legacy depois da reconciliação e dos testes correspondentes.
7. Atualizar plano, auditoria e procedimento UI com evidência observada. A Fase 3 só pode ser concluída após reconciliação, concorrência e aceitação PASS.

## Receita do teste visual

Seguir `docs/ui-test-setup-meu-bolso.md`. Em resumo:
- PHP 8.4 (o lock requer >= 8.4.1), extensões necessárias e `composer install`.
- `.env` local ignorado pelo Git, com `APP_KEY`, `DB_CONNECTION=sqlite`, caminho absoluto para `database/meu-bolso-browser-disposable.sqlite`, `APP_URL=http://127.0.0.1:8000` e `TRUSTED_PROXIES=REMOTE_ADDR` na sandbox equivalente.
- Confirmar o caminho da base antes de migrar; nunca usar dados reais.
- `php artisan migrate:fresh --force` apenas na base SQLite descartável confirmada.
- Arrancar `php artisan serve --host=0.0.0.0 --port=8000` e abrir o URL HTTPS encaminhado gerado na sessão atual.
- Criar utilizador fictício pelo registo; não guardar password no repositório.

## Critérios de publicação e encerramento

- Publicar apenas na branch `feature/payment-modal-and-financial-workflow`; nunca na branch principal.
- Antes do commit: rever `git diff`, `git diff --check`, testes direcionados e suite completa.
- Não incluir `.env`, SQLite descartável, credenciais, tokens ou passwords.
- Atualizar os documentos com resultados reais e respetivas datas.
- No fecho, informar branch e hash do commit, ficheiros alterados, comandos executados, resultados exatos, cenários UI efetivamente testados e limitações ainda abertas.

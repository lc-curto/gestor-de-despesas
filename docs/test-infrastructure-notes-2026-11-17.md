# Notas de infraestrutura e dependências de testes — 17/11/2026

## Objectivo

Este documento regista os problemas de ambiente encontrados ao retomar o trabalho na branch `feature/payment-modal-and-financial-workflow`, bem como a preparação que funcionou. Não contém chaves, passwords, tokens, dados reais ou conteúdo do `.env` além de nomes de variáveis e caminhos descartáveis.

## Estado inicial confirmado

- Repositório: `lc-curto/gestor-de-despesas`.
- Branch de trabalho: `feature/payment-modal-and-financial-workflow`.
- HEAD no início da sessão: `2883523 docs: clarify Meu Bolso session handoff and UI test status`.
- Working tree limpa antes da preparação.
- Remote: `origin https://github.com/lc-curto/gestor-de-despesas.git`.
- A branch `main` não deve ser usada para esta continuação nem receber commits desta fase.

## Bloqueios e incompatibilidades encontrados

### 1. PHP ausente na sandbox

A primeira execução obrigatória foi exactamente:

```bash
php artisan test --filter='FinancialAcceptanceTest'
```

Resultado:

```text
bash: php: command not found
exit code 127
```

Consequência: não existia baseline de testes nessa execução. Foi necessário instalar o runtime antes de interpretar qualquer falha de negócio.

### 2. PHP 8.3 instalado inicialmente, mas incompatível com o lock

O Ubuntu 24.04 disponibilizou inicialmente PHP `8.3.6`. A instalação feita foi composta por `php8.3-cli`, `php8.3-sqlite3`, `php8.3-mbstring`, `php8.3-xml`, `php8.3-curl`, `php8.3-zip` e dependências relacionadas.

O `composer install --no-interaction --prefer-dist --no-progress` não pôde instalar o lock e terminou com incompatibilidade de plataforma. O erro repetiu que vários pacotes Symfony bloqueados, por exemplo `symfony/clock v8.1.9` e `symfony/http-foundation v8.1.11`, exigem `php >=8.4.1`, enquanto o runtime era PHP `8.3.6`.

O `composer.json` deste branch declara `"php": "^8.3"`, mas o `composer.lock` efectivo contém versões Symfony/Laravel que exigem PHP `>=8.4.1`. Esta divergência deve ser tratada como requisito operacional real do lock; não executar `composer update` apenas para contornar o problema e não alterar o lock sem uma decisão própria de dependências.

### 3. PHP 8.4 necessário

Foi instalada a versão PHP `8.4.27` com as extensões necessárias (`sqlite3`, `mbstring`, `xml`, `curl`, `zip`, `intl`). Como o Ubuntu base não tinha pacotes `php8.4-*` disponíveis, foi adicionada a PPA `ondrej/php` para Ubuntu Noble.

Depois disso:

```text
PHP 8.4.27
Composer 2.7.1
```

O Composer instalou as dependências sem alterar o lock. A instalação em PHP 8.4 emitiu muitos `Deprecation Notice` do Composer distribuído pelo sistema, incluindo `E_STRICT` e parâmetros implicitamente nullable em `ClassMapGenerator`. Esses avisos não impediram a instalação nem os testes, mas devem ser distinguidos de falhas do projecto.

### 4. `.env` local inexistente e `APP_KEY` ausente

No início não existia `.env`. Depois de copiar `.env.example`, a primeira preparação ainda não era adequada para UI: o exemplo estava configurado para MySQL (`DB_CONNECTION=mysql`, `DB_DATABASE=meu_bolso`) e não para a base SQLite descartável. Sem chave local, o Laravel também não conseguiria arrancar correctamente; a sessão anterior já tinha registado falhas por `APP_KEY` ausente.

A preparação correcta foi:

```bash
cp .env.example .env
php artisan key:generate --force
mkdir -p database
touch database/meu-bolso-browser-disposable.sqlite
```

No `.env` ignorado pelo Git, usar pelo menos:

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
DB_DATABASE=/home/ubuntu/gestor-de-despesas/database/meu-bolso-browser-disposable.sqlite
TRUSTED_PROXIES=REMOTE_ADDR
```

Nunca versionar `.env`, `APP_KEY`, passwords, tokens ou a base SQLite.

### 5. Cuidado com a substituição automática do `.env`

Uma substituição textual demasiado ampla transformou temporariamente:

```dotenv
TRUSTED_PROXIES=REMOTE_ADDR127.0.0.1,::1
```

e deixou a ligação MySQL activa. Uma verificação explícita do caminho da base bloqueou o `migrate:fresh` antes de qualquer destruição. A correcção foi feita por chave de variável, não por substituição parcial.

Antes de executar `migrate:fresh`, confirmar inequivocamente:

```bash
grep -nE '^(DB_CONNECTION|DB_DATABASE|APP_URL|TRUSTED_PROXIES)=' .env
```

e verificar que `DB_DATABASE` é exactamente o ficheiro descartável. Não usar esta operação numa base real.

### 6. `phpunit.xml` usa outra base SQLite

A configuração PHPUnit define, através de `<env>`, `DB_DATABASE=database/database.sqlite`, independentemente do `.env`. Portanto:

- testes PHPUnit usam `database/database.sqlite` conforme `phpunit.xml`;
- testes de UI com `artisan serve` usam `DB_DATABASE` do `.env`, que deve apontar para `database/meu-bolso-browser-disposable.sqlite`;
- não misturar a base de testes PHPUnit com a base descartável da UI;
- antes de `migrate:fresh`, confirmar qual destas duas bases a execução vai utilizar.

### 7. Preparação correcta da base de UI

Após confirmar o caminho descartável, a migração foi executada com sucesso:

```bash
php artisan migrate:fresh --force
php artisan serve --host=0.0.0.0 --port=8000
```

O bind `0.0.0.0` é obrigatório para o port-forward. O hostname HTTPS é efémero por sessão e deve ser obtido novamente; não reutilizar o hostname gravado em documentação histórica.

O servidor actual foi iniciado como serviço nesta sessão no porto `8000`. Deve ser parado no encerramento da sessão se deixar de ser necessário.

### 8. Browser: ferramenta de preenchimento múltiplo

No teste UI, `browser_fill_form` devolveu `Browser action error: browser:Unsupported browser action in payload` mesmo com campos visíveis. A alternativa funcional foi `browser_input`, campo a campo, usando os índices apresentados pelo browser. O registo fictício foi iniciado, mas a submissão ainda não foi concluída quando a sessão foi interrompida.

## Resultados de testes obtidos nesta sessão

### Acceptance obrigatório

Primeira tentativa, antes de preparar o runtime:

- comando: `php artisan test --filter='FinancialAcceptanceTest'`;
- resultado: não iniciou;
- exit code: `127`;
- causa: `php: command not found`.

Após PHP 8.4.27, dependências Composer, `.env` local e `APP_KEY`:

- comando: `php artisan test --filter='FinancialAcceptanceTest'`;
- resultado: **PASS**;
- testes: **19 passed**;
- assertions: **100**;
- falhas: **0**;
- warnings: o relatório aparece com `WARN` porque existe um teste risky;
- risky: **1**;
- duração observada: **1,30 s**.

O teste risky é:

```text
Tests\\Feature\\FinancialAcceptanceTest::test_acceptance_dashboard_and_movements_read_canonical_financial_movements
```

A execução isolada com `--debug` identificou a causa exacta:

```text
Test code or tested code did not close its own output buffers
```

O teste passa todas as assertions; a investigação futura deve localizar o buffer aberto e fechá-lo correctamente, em vez de desactivar a detecção de risky.

### Suite completa

- comando: `php artisan test`;
- resultado: **109 passed, 5 skipped**;
- assertions: **503**;
- falhas: **0**;
- risky: **1**;
- duração observada: **5,07 s**.

Os cinco skipped são:

1. `FinancialCoreSchemaTest::transfer must use two different accounts` — SQLite não aplica o CHECK aditivo de forma compatível com a migration de produção.
2. `MovimentoTest::banco rejeita despesa com valor negativo` — SQLite não aplica os CHECK constraints legacy adicionados via `ALTER TABLE`.
3. `MovimentoTest::banco rejeita receita com valor zero` — mesma limitação SQLite.
4. `MovimentoTest::banco rejeita moeda invalida` — mesma limitação SQLite.
5. `MovimentoTest::banco rejeita categoria invalida` — mesma limitação SQLite.

Estes skips são limitações da configuração SQLite local, não prova de que as constraints funcionam no motor de produção. Devem ser repetidos em MySQL/PostgreSQL quando a validação do motor for necessária.

## Receita curta para a próxima sessão

1. Confirmar branch, status, HEAD e remote.
2. Confirmar `php -v` e exigir PHP `>=8.4.1` por causa do lock; PHP 8.3 não serve.
3. Confirmar `vendor/` e executar `composer install --no-interaction --prefer-dist --no-progress` sem `composer update`.
4. Confirmar `.env` ignorado, `APP_KEY` configurada e `DB_DATABASE` descartável antes de qualquer migração UI.
5. Executar exactamente `php artisan test --filter='FinancialAcceptanceTest'` e registar o resultado actual.
6. Executar `php artisan test` completo e investigar o risky do buffer de saída.
7. Para UI, usar `php artisan migrate:fresh --force` apenas depois da verificação do SQLite descartável, iniciar em `0.0.0.0:8000` e obter o hostname HTTPS novo.
8. Se `browser_fill_form` voltar a falhar com `Unsupported browser action`, usar `browser_input` campo a campo.
9. Não confundir os resultados desta nota com resultados futuros; repetir os comandos antes de reportar.

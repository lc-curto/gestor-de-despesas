# Procedimento de teste da UI — Meu Bolso

**Data da execução:** 17/11/2026
**Ambiente:** sandbox Manus, app Laravel local + browser encaminhado por HTTPS.
**Dados:** SQLite descartável; utilizador e movimentos de teste, sem dados reais.

## Preparação do ambiente

O `composer.lock` exige PHP 8.4. No sandbox foi instalado PHP 8.4 com as extensões do projeto e executado `composer install --no-interaction --prefer-dist`. Em ambiente novo, confirmar `php -v`, extensões PDO/SQLite/mbstring/XML/cURL e dependências Composer antes de arrancar a app.

Criar `.env` local (ignorado pelo Git) a partir de `.env.example`, gerar uma chave e configurar uma base SQLite exclusivamente descartável:

```bash
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/meu-bolso-browser-disposable.sqlite
```

No `.env`, configurar pelo menos:

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
DB_DATABASE=/home/ubuntu/gestor-de-despesas/database/meu-bolso-browser-disposable.sqlite
TRUSTED_PROXIES=REMOTE_ADDR
```

`TRUSTED_PROXIES=REMOTE_ADDR` foi o valor funcional neste sandbox: o middleware Laravel `TrustProxies` global lê `config/trustedproxy.php`, interpreta `REMOTE_ADDR` como o endereço do peer que encaminhou o pedido e respeita `X-Forwarded-Host`, `X-Forwarded-Port` e `X-Forwarded-Proto`. Se o endereço/CIDR do proxy for conhecido, preferir a allowlist explícita. Não usar `*` num serviço exposto à Internet. O `APP_URL` permanece a origem local; nos pedidos encaminhados, `url()`/`route()`/`asset()` devem herdar o host e esquema HTTPS encaminhados. Não forçar HTTP e não desativar CSRF.

> `migrate:fresh` apaga todas as tabelas da ligação selecionada. Só o executar depois de confirmar que `DB_DATABASE` aponta para o ficheiro descartável acima; nunca usar esta receita numa base com dados reais.

```bash
php artisan migrate:fresh --force
php artisan serve --host=0.0.0.0 --port=8000
```

O bind em `0.0.0.0` é necessário para o port-forward; `127.0.0.1` isolado não ficou acessível ao proxy. Abrir no browser o URL HTTPS encaminhado para a porta 8000. Nesta execução foi `https://8000-ibj5jff4mrioe5vnqo9f4-668e1271.us5.manus.computer`; o host/ID é efémero e deve ser obtido novamente em cada sessão.

## Fluxos realmente verificados

- Registo de utilizador fictício pelo formulário; sessão autenticada e redirecionamento ao dashboard.
- Dashboard e CSS/ativos carregados em HTTPS; formulários, actions e links passaram a apontar à origem HTTPS do proxy. CSRF permaneceu ativo e o registo válido chegou ao destino.
- Criação de uma conta bancária fictícia com saldo inicial.
- Despesa paga de 24,50 €, receita paga de 250 € e despesa pendente de 18,75 €; os estados e totais foram vistos no histórico.
- Despesa fixa com vencimento de 90 € (30/11/2026), sem saída de caixa antes do pagamento; na página Compromissos, pagamento de 25 € deixou 65 € em falta e o pagamento de 65 € liquidou o restante. O histórico mostrou o pagamento e a redução correspondente.
- Recarregamento de Movimentos encontrou um bug: os dados do endpoint e do HTML do servidor estavam corretos, mas o JS inicializava os totais a zero porque faltavam atributos `data-total`. A view agora fornece esses valores e o acceptance tem assertion HTTP; após reload o cabeçalho mostrou 250,00 € de receitas, 43,25 € de despesas e 206,75 € de resultado.

## Lacunas identificadas — não tratar como PASS

- A opção “Paguei uma parte” no formulário de despesa única é apresentada, mas a submissão é explicitamente rejeitada pelo JavaScript: pagamentos parciais de obrigações são suportados na página Compromissos; pagamentos parciais de despesas comuns não estão implementados.
- Neste percurso ainda não foram concluídos o login separado depois do logout, o logout final, UI de criação/edição de recorrências, UI de parcelamentos nas frequências mensal/semanal/quinzenal, compra de cartão e pagamento de fatura.
- Não foi feito teste concorrente multi-conexão em MySQL/PostgreSQL, nem reconciliação de dados reais.

## Encerramento

Nunca versionar `.env`, a base SQLite descartável, credenciais ou passwords de teste. O registo temporário foi feito só nesta base local. No fim dos testes, parar o servidor e conservar/apagar os artefactos descartáveis conforme a política da sessão. Esta evidência não conclui a Fase 3.

# Laravel — Meu Bolso

Aplicação em migração para Laravel 13, Blade, Bootstrap 5 e MySQL 8.

## Requisitos
- PHP 8.3 ou superior e extensões PDO MySQL, mbstring, OpenSSL, XML e Ctype.
- Composer 2.
- MySQL 8.
- Node.js apenas se forem adicionados assets compilados por Vite.

## Instalação local
Na pasta `laravel`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Configurar no `.env` uma base de dados dedicada, por exemplo `meu_bolso`. Nunca usar credenciais root na aplicação. Executar:

```powershell
php artisan migrate
php artisan serve
```

Abrir http://127.0.0.1:8000.

## Testes
A suíte usa MySQL e a base de dados isolada `meu_bolso_test`. Criar essa base antes de executar:

```powershell
php artisan test
```

## Funcionalidades implementadas
- Registo, login, logout e recuperação de palavra-passe.
- Proteção de rotas com middleware auth e limitação de tentativas de autenticação.
- CRUD de despesas e receitas, filtros por categoria e período.
- Isolamento dos movimentos por utilizador.
- Dashboard mensal, com totais agrupados por moeda para não somar moedas incompatíveis.
- Conversor indicativo através da API Frankfurter.
- Tema claro/escuro e shell PWA com página offline.

## Email de recuperação
Configurar um mailer real no `.env` (SMTP ou serviço transacional). Sem configuração de email, a recuperação de palavra-passe não consegue entregar a ligação ao utilizador.

## Migração Supabase
Consultar [docs/supabase-import.md](../docs/supabase-import.md). Os exports contêm hashes de palavra-passe e dados pessoais: mantê-los exclusivamente em `storage/app/private/supabase-export`, nunca os versionar.

## Limitação offline
O Service Worker guarda apenas recursos estáticos. Não guarda páginas autenticadas nem dados financeiros em cache, evitando expor dados pessoais num dispositivo partilhado. A consulta e alteração de movimentos exigem ligação ao servidor.

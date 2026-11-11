# Meu Bolso — Gestor de Despesas Pessoais

Aplicação web de gestão de finanças pessoais, desenvolvida como projeto final da formação **Software Developer do CESAE Digital — 2026**.

A aplicação está em migração para Laravel 13, utilizando Blade, Bootstrap 5 e MySQL 8.

## Funcionalidades

- Registo, autenticação, logout e recuperação de palavra-passe.
- Gestão de despesas e receitas.
- Filtros por categoria e período.
- Isolamento dos movimentos por utilizador.
- Dashboard mensal com totais agrupados por moeda.
- Conversor indicativo de moedas através da API Frankfurter.
- Tema claro e escuro.
- PWA com recursos estáticos em cache e página offline.

## Tecnologias

- PHP 8.3+
- Laravel 13
- Blade
- MySQL 8
- Bootstrap 5
- JavaScript
- Node.js e Vite
- Composer
- Git e GitHub

## Requisitos

- PHP 8.3 ou superior.
- Extensões PDO MySQL, mbstring, OpenSSL, XML e Ctype.
- Composer 2.
- MySQL 8.
- Node.js e npm.

## Instalação local

Na raiz do repositório, instalar as dependências PHP com `composer install`.

Criar o ficheiro `.env` a partir de `.env.example` e gerar a chave da aplicação com `php artisan key:generate`.

Configurar no `.env` uma base de dados dedicada. Não utilizar credenciais root na aplicação.

Executar `php artisan migrate` para criar as tabelas.

Para executar a aplicação localmente, iniciar apenas o servidor Laravel:

`php artisan serve`

Abrir http://127.0.0.1:8000.

O layout atual carrega os ficheiros de CSS e JavaScript diretamente de `public/`; por isso, o Vite, o worker de filas e o monitor de logs não são necessários para navegar na aplicação. O comando `composer run dev` também inicia apenas o servidor Laravel.

Instalar as dependências JavaScript com `npm install` e executar `npm run build` apenas quando forem alterados recursos que entram pelo Vite. Para validar a estrutura e a sintaxe dos ficheiros públicos, executar `npm run check`.

Depois de atualizar a aplicação, se o navegador continuar a apresentar recursos antigos, atualizar a página e aguardar a atualização do Service Worker.

## Testes

A suíte de testes utiliza MySQL e a base de dados isolada `meu_bolso_test`.

Depois de criar e configurar essa base de dados no ambiente de teste, executar `php artisan test`.

## Verificações automáticas

O projeto inclui um verificador estrutural executado com Node.js:

`npm run check`

Verifica a estrutura essencial do Laravel, ficheiros JSON de configuração, manifesto PWA, existência dos ícones e sintaxe dos ficheiros JavaScript públicos e do Service Worker.

Estas verificações não substituem testes funcionais, de integração ou de segurança.

## Email de recuperação

É necessário configurar um serviço de email real no `.env` (SMTP ou serviço transacional). Sem essa configuração, a recuperação de palavra-passe não consegue entregar a ligação ao utilizador.

## Migração dos dados Supabase

A documentação da migração encontra-se em `docs/supabase-import.md`.

Os ficheiros exportados podem conter hashes de palavra-passe e dados pessoais. Devem permanecer exclusivamente em `storage/app/private/supabase-export` e nunca ser adicionados ao Git.

## PWA e funcionamento offline

A aplicação inclui um Web App Manifest e um Service Worker.

O Service Worker mantém recursos estáticos em cache e disponibiliza uma página offline. Não armazena páginas autenticadas nem dados financeiros em cache. A consulta e alteração dos movimentos exigem ligação ao servidor.

## Repositório

https://github.com/lc-curto/gestor-de-despesas

## Projeto académico

**Projeto Final — Software Developer**  
CESAE Digital · 2026

# Preparação para deploy no Alwaysdata

A aplicação Laravel está agora na raiz do repositório. Este guia descreve a implantação do conteúdo dessa raiz no diretório de aplicação do Alwaysdata.

## 1. Configuração do alojamento

No painel Alwaysdata:

1. Criar um site do tipo PHP.
2. Selecionar uma versão PHP compatível com o projeto (PHP 8.3 ou superior).
3. Definir a raiz web para a pasta `public` da aplicação Laravel — não para a raiz do repositório.
4. Criar uma base de dados MariaDB/MySQL e um utilizador próprio para a aplicação.
5. Copiar o host, nome da base de dados, utilizador, palavra-passe e porta apresentados pelo painel. Não assumir que o host é `localhost`.

A raiz web deve apontar para a pasta `public/` dentro do diretório de aplicação no servidor. Os diretórios `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `storage` e o ficheiro `.env` devem permanecer fora da raiz pública.

## 2. Código e dependências

Publicar a branch `feature/migracao-laravel`. Como o plano gratuito dispõe de apenas 256 MB de RAM, evitar executar o Composer no servidor na primeira instalação: a resolução de dependências pode ultrapassar esse limite.

No computador local, com PHP 8.3 ou superior e extensões compatíveis com o servidor:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Depois, enviar o conteúdo da raiz do repositório para o diretório de aplicação no servidor, incluindo `vendor/`, por SFTP/SSH. Não substituir o `.env` nem os diretórios de dados existentes. Não enviar `.env`, dados locais, logs ou caches de desenvolvimento. O ficheiro `vendor/` não está versionado no Git, por isso tem de ser incluído no pacote de deploy.

Se futuramente for necessário executar Composer no servidor, fazê-lo apenas depois de confirmar que a memória disponível é suficiente. Não executar o script `composer setup` em produção: inclui passos de desenvolvimento e de build que não são necessários para este deploy.

## 3. Variáveis de ambiente

Criar o ficheiro `.env` diretamente no servidor. Nunca o adicionar ao Git nem enviar credenciais pelo chat.

Configuração-base (substituir os valores entre sinais angulares):

```dotenv
APP_NAME="Meu Bolso"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://<subdominio>.alwaysdata.net

APP_LOCALE=pt_PT
APP_FALLBACK_LOCALE=pt

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7

DB_CONNECTION=mysql
DB_HOST=<host-fornecido-pelo-alwaysdata>
DB_PORT=<porta-fornecida-pelo-alwaysdata>
DB_DATABASE=<nome-da-base>
DB_USERNAME=<utilizador-da-base>
DB_PASSWORD=<palavra-passe-da-base>

SESSION_DRIVER=file
SESSION_LIFETIME=15
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=log
```

Gerar uma chave de aplicação no servidor depois de criar o ficheiro `.env`:

```bash
php artisan key:generate
```

A chave deve ser mantida estável depois de existirem sessões ou dados cifrados. Não gerar uma nova chave em cada deploy.

O mailer `log` não envia mensagens. A recuperação de palavra-passe por email só ficará operacional depois de configurar um serviço SMTP e validar o envio.

## 4. Preparação e migração da base de dados

Antes de executar migrações, confirmar que a base de dados está vazia ou que existe um backup válido. Não executar `migrate:fresh` em produção.

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Se a aplicação não utilizar ficheiros no disco público, o link de storage pode não ser necessário. Confirmar permissões de escrita em `storage/` e `bootstrap/cache/`.

## 5. Verificações antes de introduzir dados reais

- [ ] HTTPS ativo e redirecionamento HTTP → HTTPS configurado.
- [ ] `APP_ENV=production` e `APP_DEBUG=false`.
- [ ] Raiz web aponta exclusivamente para `public/` dentro do diretório de aplicação no servidor.
- [ ] Ficheiro `.env` fora da raiz pública e ignorado pelo Git.
- [ ] Login, logout e expiração de sessão testados.
- [ ] Utilizador A não consegue consultar, editar ou eliminar movimentos do utilizador B.
- [ ] Criar, editar, listar e eliminar despesas e receitas.
- [ ] Paginação do histórico testada em páginas consecutivas.
- [ ] Recuperação de palavra-passe testada depois de configurar SMTP.
- [ ] Backup externo da base de dados criado e respetivo restauro testado.

## 6. Limitações e cuidados

- O plano gratuito tem recursos limitados. A paginação e as consultas foram preparadas para reduzir o volume processado por pedido, mas o consumo real de RAM tem de ser medido no alojamento.
- O plano gratuito mantém backups do fornecedor por um período curto. Manter cópias independentes da base de dados.
- A aplicação atual guarda no `localStorage` do navegador uma cópia dos movimentos já carregados para permitir consulta offline. Esses dados não estão cifrados no navegador. O acesso ao dispositivo e vulnerabilidades XSS podem expô-los. Antes de usar dados financeiros reais, decidir se esta funcionalidade offline deve ser desativada ou redesenhada com uma estratégia de proteção adequada.
- Não importar dados reais do Supabase até concluir os testes de produção, validar backups e decidir a política de armazenamento offline.

## 7. Deploy de atualizações

1. Fazer backup da base de dados.
2. Atualizar o código para a versão aprovada da branch de deploy.
3. Executar `composer install --no-dev --prefer-dist --optimize-autoloader` se o `composer.lock` tiver mudado.
4. Executar `php artisan migrate --force` apenas quando existirem novas migrações.
5. Executar `php artisan optimize`.
6. Confirmar login, dashboard e operações financeiras.

Nunca copiar o `.env` local para o servidor.

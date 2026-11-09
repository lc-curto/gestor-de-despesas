# Exportação de dados do Supabase para o Meu Bolso (Laravel)

Executar no SQL Editor do projeto Supabase. Cada consulta devolve uma única coluna JSON; copiar o conteúdo integral da célula para o respetivo ficheiro.

## 1. Utilizadores

A tabela auth.users contém hashes de palavra-passe. O ficheiro users.json é sensível: não o enviar por email, não o colocar no Git e não o partilhar em chats.

```sql
select coalesce(jsonb_agg(jsonb_build_object(
  'id', id,
  'email', email,
  'encrypted_password', encrypted_password,
  'email_confirmed_at', email_confirmed_at,
  'confirmed_at', confirmed_at,
  'created_at', created_at,
  'updated_at', updated_at,
  'raw_user_meta_data', raw_user_meta_data
)), '[]'::jsonb) as data
from auth.users;
```

## 2. Despesas

```sql
select coalesce(jsonb_agg(to_jsonb(d)), '[]'::jsonb) as data
from public.despesas d;
```

## 3. Receitas

```sql
select coalesce(jsonb_agg(to_jsonb(r)), '[]'::jsonb) as data
from public.receitas r;
```

Guardar os três arrays JSON como:

- users.json
- despesas.json
- receitas.json

Colocar os ficheiros em laravel/storage/app/private/supabase-export/. A pasta está dentro de storage e não deve ser versionada.

## Validação e importação

Na pasta laravel:

```powershell
php artisan supabase:import --dry-run
php artisan supabase:import
```

A importação é transacional e idempotente por UUID: pode ser repetida sem duplicar os registos. Utilizadores são importados antes dos movimentos. Os hashes bcrypt são preservados sem novo hashing. Contas sem hash recebem uma palavra-passe aleatória desconhecida e terão de usar a recuperação de palavra-passe.

Antes da importação real, fazer backup da base de dados Laravel e comparar as contagens do dry-run com as contagens do Supabase.

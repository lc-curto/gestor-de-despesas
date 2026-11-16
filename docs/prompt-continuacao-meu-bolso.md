# Prompt de continuação — Meu Bolso

Continua o desenvolvimento do projeto **Meu Bolso** a partir do repositório GitHub:

- Repositório: `https://github.com/lc-curto/gestor-de-despesas`
- Branch obrigatória: `feature/payment-modal-and-financial-workflow`
- Último commit publicado: `dd028b8 Implementar núcleo financeiro e diagnóstico de compatibilidade`

## Contexto de negócio

O objetivo é um app simples de finanças pessoais diárias, sem achismos e sem complexidade desnecessária. O modelo canónico é:

```text
Compra → Obrigação → Prestação → Pagamento → Movimento financeiro
```

Uma compra parcelada não pode criar também uma despesa pendente pelo valor total. O valor total pertence à obrigação; os pagamentos efetivos pertencem aos movimentos.

## Estado concluído

- Fase 1 concluída e marcada `[x]`: compromissos a prazo/parcelados, pagamentos parciais, periodicidade semanal/quinzenal/mensal e prevenção de movimento integral duplicado.
- Fase 2 concluída e marcada `[x]`: `FinancialProjectionService`, Rota Financeira, saldo de caixa separado de dívida de cartão, Dashboard e Movimentos consumindo movimentos canónicos com fallback legado.
- Fase 3 iniciada: o formulário legado encaminha `deferred`/`installments` para `FinancialCommitmentService`; foi criado o diagnóstico não destrutivo `php artisan financial:diagnose-data [--user=UUID] [--json]`.

## Testes atuais

Executar na raiz do projeto:

```bash
php artisan test --filter='FinancialAcceptanceTest'
```

Resultado de referência no último estado: **12 testes, 49 asserções, 0 falhas**.

A suíte financeira legada completa ainda tem falhas antigas, principalmente respostas HTTP `419` nos testes JSON e incompatibilidades SQLite/MySQL. Não apagar nem esconder essas falhas; separar claramente regressões novas de limitações antigas.

## Próxima tarefa obrigatória: terminar a Fase 3

1. Ler `docs/implementation-plan-meu-bolso.md`.
2. Auditar `FinancialDataDiagnosticService` e o comando de diagnóstico.
3. Criar testes de migração para casos determinísticos.
4. Implementar uma migração/rotina explícita, não destrutiva e auditável apenas para casos determinísticos, por exemplo:
   - movimento canónico ausente para um registo legado inequívoco;
   - obrigação sem prestação apenas quando todos os dados permitirem reconstrução segura.
5. Não corrigir ou apagar candidatos ambíguos automaticamente; produzir relatório para revisão do utilizador.
6. Só remover leituras legadas depois dos testes e da reconciliação.
7. Atualizar o checklist com `[x]` apenas quando os critérios de aceitação passarem.

## Regras de trabalho

- Trabalhar apenas na branch `feature/payment-modal-and-financial-workflow`.
- Antes de alterar código, ler os ficheiros envolvidos e o plano.
- Para cada fase: implementar, criar testes de aceitação, executar localmente e só então marcar `[x]`.
- Não fazer migração destrutiva nem apagar dados reais.
- Não fazer commit/push sem confirmar os testes e o `git diff`.
- No final, informar exatamente: ficheiros alterados, testes PASS/FAIL, commit e pendências.

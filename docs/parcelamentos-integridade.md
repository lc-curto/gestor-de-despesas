# Integridade e recuperação de parcelamentos

## Estados e visibilidade

A área **Parcelamentos** separa os compromissos operacionais dos registos que exigem revisão:

- **Ativos**: compromissos não arquivados, não eliminados e não cancelados com prestações por liquidar.
- **Concluídos**: compromissos operacionais sem prestações em aberto.
- **Arquivados**: compromissos com `archived_at`, cancelados ou eliminados logicamente (`deleted_at`).
- **Todos**: inclui os três grupos anteriores.

A consulta inclui explicitamente registos soft-deleted para que uma relação financeira histórica não fique inacessível apenas porque o agregado deixou de aparecer na consulta Eloquent normal.

## Recuperação

- Um compromisso apenas arquivado pode ser restaurado para a lista operacional se não estiver cancelado.
- Um compromisso soft-deleted é recuperado apenas para **Arquivados**, com `archived_at` preenchido. A recuperação não o reativa nem altera movimentos, prestações ou pagamentos.
- Um compromisso cancelado permanece cancelado; não existe reativação implícita.
- A eliminação continua centralizada em `FinancialDeletionService::deleteCommitment`, que valida reconciliação e preserva pagamentos liquidados como movimentos independentes.

## Auditoria de integridade

Executar em ambiente com a base de dados configurada:

```bash
php artisan financial:check-commitments
```

Para limitar a um utilizador:

```bash
php artisan financial:check-commitments --user=<UUID>
```

Por omissão, a auditoria é estritamente de leitura. Identifica:
- movimentos ligados a compromissos inexistentes;
- movimentos ligados a prestações inexistentes;
- divergência entre o compromisso do movimento e o compromisso da prestação;
- movimentos ainda associados a compromissos arquivados ou eliminados;
- movimentos associados a compromissos ou prestações cancelados.

Para corrigir apenas relações que possam ser provadas pela prestação, executar:

```bash
php artisan financial:check-commitments --user=<UUID> --repair-safe-links
```

Este modo só atualiza `movimentos.debt_id` quando:
- a prestação indicada por `installment_id` existe para o mesmo utilizador;
- o compromisso proprietário dessa prestação existe para o mesmo utilizador;
- o movimento ainda não está anulado;
- o valor atual de `debt_id` está vazio ou diverge do compromisso proprietário da prestação.

Cada correção é executada numa transação com locks e registada em `financial_integrity_corrections`, com os valores anteriores e posteriores. Montantes, estados, pagamentos e `installment_id` não são alterados.

Relações sem prestação identificável, prestações inexistentes ou compromissos proprietários inexistentes continuam apenas reportados; não são corrigidos por inferência. Não se deve limpar `debt_id` ou `installment_id` por SQL direto, nem eliminar movimentos liquidados para contornar a validação.

## Limite operacional

A recuperação de um registo torna o agregado novamente visível, mas não prova por si só que os seus movimentos e prestações estão reconciliados. Depois de recuperar um registo histórico, executar a auditoria e só então usar as operações normais de gestão/exclusão.

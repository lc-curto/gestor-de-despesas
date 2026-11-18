# Auditoria de exclusões — Meu Bolso

**Branch:** `audit/exclusoes-movimentos`  
**Base:** `main`  
**Data:** 17/11/2026  
**Âmbito:** todas as rotas HTTP de remoção/anulação/estorno e respetivos formulários visíveis identificados na branch `main`.

## Objetivo

Auditar e uniformizar os fluxos de exclusão do sistema sem quebrar a matriz financeira já aprovada. Esta branch parte diretamente de `main`. Não alterar a `main` nem misturar trabalho de outras branches.

## Regras de domínio que condicionam qualquer exclusão

1. **Movimento pendente:** pode ser eliminado logicamente, mantendo o registo canónico como `void`, o histórico e a auditoria.
2. **Movimento liquidado:** pode ser eliminado apenas quando o serviço propaga a remoção aos efeitos e dependências, recalcula os saldos e preserva a auditoria. Se a propagação não for segura, bloquear. Não criar movimentos inversos.
3. **Pagamento parcial:** eliminar o evento de pagamento recalcula a obrigação, a prestação, a previsão e os saldos afetados na mesma transação.
4. **Parcelamento:** previsões não podem ser eliminadas isoladamente. Eliminar/cancelar o agregado anula previsões pendentes/parciais e preserva pagamentos liquidados como movimentos autónomos, desassociados da obrigação.
5. **Recorrências:** ao pausar/alterar a regra, anular apenas previsões futuras não confirmadas, mantendo ocorrências realizadas e histórico. Respeitar o escopo da operação.
6. **Cartões/faturas:** compra/encargo com pagamentos ativos ou saldo transportado não pode ser eliminado isoladamente. Recalcular faturas quando a matriz permite; bloquear dependências não propagáveis. Faturas vazias usam soft delete e podem ser restauradas no mesmo ciclo.
7. **Dívida inicial do cartão:** eliminar o pagamento identificado atualiza atomicamente `opening_debt_paid` e os saldos das contas. Pagamentos legados ambíguos exigem reconciliação.
8. **Contas/cartões:** com histórico ou dependências, encerrar/desativar. Eliminação física só é permitida sem movimentos, dívidas, recorrências, faturas, saldos ou referências.
9. **Categorias:** com movimentos históricos, desativar; eliminar apenas sem referências legacy ou canónicas.
10. **Auditoria:** manter `historico_movimentos` e snapshots em `deletion_operations` / `deletion_operation_items`. Não apagar histórico para contornar FKs.
11. **Atomicidade e isolamento:** confirmar propriedade do utilizador, bloquear entidades relacionadas, validar dependências e gravar alteração + auditoria na mesma transação.
12. **Idempotência e integridade:** repetir uma exclusão não pode duplicar efeitos. Validar referências órfãs e consistência dos saldos/projeções.
13. **UI/API:** a ação apresentada é “Eliminar”, “Cancelar”, “Arquivar” ou “Desativar” conforme o domínio. Não expor operação de estorno como conceito de produto.

## Inventário de rotas identificado

| Rota | Handler | Operação |
|---|---|---|
| `DELETE /api/financial/movements/{id}` | `FinancialCoreController::destroyMovement` | Anulação/correção por `deleteAsCorrection` |
| `DELETE /api/financial/movements/{id}/recurrence-scope` | `destroyRecurringOccurrence` | Anulação de ocorrência(s) pendentes e possível desativação da recorrência |
| `DELETE /api/financial/commitments/{id}` | `deleteCommitment` → `FinancialCommitmentService::delete` → `FinancialDeletionService::deleteCommitment` | Exclusão propagada, preservando e desassociando pagamentos liquidados |
| `DELETE /api/financial/cards/{id}` | `deleteUnusedCard` → `FinancialAccountService::deleteUnusedCard` | Exclusão restrita de cartão sem dependências |
| `DELETE /contas/movimentos/{id}` | `FinancialAccountsPageController::destroyMovement` | Encaminha para `FinancialMovementService::deleteAsCorrection` e orquestrador central |
| `DELETE /despesas/{id}` | `FinanceController::destroy` | Apaga representação legacy e corrige movimento canónico |
| `DELETE /receitas/{id}` | `FinanceController::destroy` | Apaga representação legacy e corrige movimento canónico |
| `DELETE /categorias/{id}` | `CategoriaController::destroy` | Exclusão de categoria sem movimentos |
| Endpoint de reversão removido | — | A exclusão é tratada pela política propagada; dados legados permanecem compatíveis |
| Endpoint HTML de reversão removido | — | A UI utiliza a exclusão central |
| `POST /api/financial/commitments/{id}/cancel` | `cancelCommitment` → serviço | Cancelamento lógico de obrigação e previsões |
| `POST /api/financial/commitments/{id}/archive` | `archiveCommitment` → serviço | Arquivamento, sem apagar pagamentos/histórico |
| `PATCH /api/financial/recurrences/{id}/status` | `setRecurrenceStatus` | Pausar/retomar regra; não apagar ocorrências |
| `POST /api/financial/cards/{id}/status`, `/reopen` | Estado/reativação | Encerrar/reabrir conforme regras, não eliminar histórico |

A pesquisa também identificou exclusão direta de linhas de histórico durante substituição de um movimento legacy na edição. Deve ser auditada como mutação destrutiva não exposta por rota DELETE.

## Findings estáticos iniciais — fotografia da `main` antes das correções

Os itens E-01 a E-10 registam os defeitos encontrados na versão de base. Não devem ser interpretados como descrição do comportamento atual da branch. O estado atual das correções está resumido em “Implementação iniciada na branch” e nos testes regressivos.

### E-01 — [ALTA] Dois caminhos para eliminar movimentos não usam o mesmo serviço de domínio

`FinancialAccountsPageController::destroyMovement` elimina diretamente `historico()` e o registo do movimento, enquanto as rotas financeiras/legacy usam `FinancialMovementService::deleteAsCorrection`. Isto cria semânticas diferentes, incluindo perda de histórico e ausência de validações centrais (fatura, pagamento de dívida inicial, vínculo de estorno, etc.).

**Ficheiro:** `app/Http/Controllers/FinancialAccountsPageController.php`  
**Ação:** encaminhar exclusão de pendentes pelo serviço de domínio; bloquear movimentos ligados a fatura/compromisso/estorno segundo matriz; não apagar histórico em cascata.

### E-02 — [ALTA] Endpoint genérico de exclusão aceita estados sem whitelist explícita

`FinancialCoreController::destroyMovement` chama `deleteAsCorrection`. O serviço rejeita `void`, vínculos de liquidação, prestações, par de estorno, dívida inicial e compras de fatura paga; mas não exige explicitamente `status === pending`. Um movimento parcial ou liquidado comum sem os marcadores bloqueados pode ser alterado para `void`, em vez de exigir estorno.

**Ficheiro:** `app/Services/Financial/FinancialMovementService.php`  
**Ação:** definir estados elegíveis de forma explícita (pendente apenas, salvo exceção de domínio comprovada); liquidados usam reversão e parciais seguem correção da obrigação.

### E-03 — [ALTA] Eliminação legacy pode ocultar movimento que o serviço deixou intacto ou retornar resultado ambíguo

`FinanceController::destroy` chama `deleteAsCorrection` e depois apaga o registo legacy. A UI recebe confirmação genérica. Dependendo das ligações/estados, a representação legacy pode ser eliminada sem oferecer um fluxo claro de estorno/correção; garantir consistência entre tabelas legacy e canónica e impedir perda de visibilidade de histórico.

**Ficheiro:** `app/Http/Controllers/FinanceController.php`  
**Ação:** validar estado/vínculo antes de apagar legacy; efetuar mudança de ambos os registos atomicamente; garantir que legacy não é apagado se o movimento canónico não puder ser corrigido.

### E-04 — [ALTA] Botão “Eliminar” do detalhe de conta usa rota legacy para despesas/receitas e rota própria para outros movimentos

`account-show.blade.php` constrói `deleteRoute` conforme `legacy_type`. Movimentos canónicos não legacy passam para `FinancialAccountsPageController::destroyMovement`, que faz hard delete; liquidados usam estorno. É um segundo percurso com comportamento diferente para a mesma ação visual.

**Ficheiro:** `resources/views/financial/account-show.blade.php`  
**Ação:** todos os botões encaminham para a operação de domínio certa; ação e mensagem variam conforme estado e tipo.

### E-05 — [MÉDIA] Exclusão de categoria verifica apenas tabelas legacy

`CategoriaController::destroy` conta `despesas` ou `receitas`, mas o núcleo financeiro referencia `categoria_id` em `movimentos`. Categorias que só tenham movimentos canónicos podem ser apagadas apesar de possuírem histórico. A integridade depende de FK/constraints e configuração da base de dados.

**Ficheiro:** `app/Http/Controllers/CategoriaController.php`  
**Ação:** verificar movimentos canónicos e legacy; preferir desativação se houver qualquer referência.

### E-06 — [MÉDIA] Modal de exclusão no dashboard promete exclusão irreversível

A vista `movements.blade.php` afirma “não poderá ser desfeita” e usa “Excluir”, enquanto o serviço canónico implementa correção auditável via estado `void`. A mensagem não explica que o histórico se conserva nem distingue estorno de anulação.

**Ficheiro:** `resources/views/movements.blade.php`  
**Ação:** texto da confirmação contextual à operação efetiva e ao estado.

### E-07 — [MÉDIA] Exclusão de compromisso usa construção de URL por substituição de string

O JavaScript deriva DELETE de URL de arquivo usando `archiveActionUrl.replace('/archive', '')`. A lógica depende da forma textual da rota. O botão só é mostrado com `hasPayments === 0`, mas o serviço é a autoridade final; validar ambos os lados e apresentar erro de negócio.

**Ficheiro:** `resources/views/financial/commitments.blade.php`  
**Ação:** fornecer URL DELETE explícita em `data-delete-url` e testar que o backend rejeita qualquer pagamento total/parcial associado.

### E-08 — [MÉDIA] Exclusão de cartão apaga faturas em hard delete antes de apagar a conta

`FinancialAccountService::deleteUnusedCard` apaga linhas de `faturas` via query builder e depois apaga o cartão. Embora valide ausência de movimentos/dívidas/recorrências/saldos, a eliminação de faturas pode destruir histórico de ciclo vazio. Confirmar matriz e FKs/soft deletes antes de manter a operação destrutiva; preferir impedir exclusão se existirem faturas históricas.

### E-09 — [MÉDIA] Exclusão de compromisso é soft delete mas interface diz “definitivamente” e “não pode ser desfeita”

O serviço define estado cancelado, cancela prestações pendentes, anula previsões e usa soft delete. Interface descreve exclusão definitiva/irreversível. O significado funcional difere e o recurso some de consultas normais sem explicar restauração nem trilho de auditoria.

### E-10 — [MÉDIA] Alteração/substituição de movimento legacy apaga histórico manualmente

Em `FinanceController::update`, num ramo alternativo, `$movement->historico()->delete()` antecede `$movement->delete()` e criação do novo movimento. Mesmo sendo uma transação, remove o registo de auditoria em vez de preservar ligação de substituição/correção. A condição de elegibilidade do ramo deve ser verificada face a movimentos pendentes, recorrências, faturas e relações.

## Ficheiros e áreas adicionais a auditar

- `routes/web.php`: todas as rotas DELETE e operações POST/PATCH com semântica de cancelamento, arquivo ou estorno.
- `FinancialCoreController`, `FinanceController`, `FinancialAccountsPageController`, `CategoriaController`.
- `FinancialMovementService`, `FinancialCommitmentService`, `FinancialAccountService`, `CardInvoiceService`, `RecurrenceService`.
- Vistas e JavaScript de Dashboard/Movimentos, Rota Financeira, Contas/detalhe, Parcelamentos, Cartões/Faturas, Despesas/Receitas e Categorias.
- Models, migrations, FKs, SoftDeletes, eventos, histórico e auditorias.
- Testes de Feature/Unit e UI para cada origem de exclusão.

## Plano de execução

1. Fechar inventário de todas as rotas e todos os chamadores de UI/JS, incluindo métodos POST que alteram estado e exclusões executadas durante edição.
2. Construir matriz route → controller → service → model → relations → balance effects → UI caller.
3. Classificar cada operação: anular, estornar, cancelar, arquivar, desativar ou eliminar fisicamente.
4. Confirmar cada finding com código integral, constraints/migrations e testes; separar defeito comprovado de risco/questão.
5. Corrigir primeiro serviços de domínio e atomicidade, depois controllers e consumidores UI.
6. Criar testes regressivos para cada rota e contexto de página, incluindo pendente, parcial, liquidado, recorrente, prestação, fatura aberta/parcial/paga, dívida inicial, transferências e utilizador diferente.
7. Executar suites direcionadas e suite completa; não declarar concluído sem resultados reportados.

## Critérios de aceitação

- Nenhuma operação financeira apaga o histórico ou contorna o serviço central.
- Exclusões liquidadas só são permitidas com propagação integral, recálculo e auditoria; casos não determinísticos são bloqueados.
- Pagamentos parciais removidos atualizam o saldo da prestação/obrigação e da conta sem movimentos inversos.
- Cancelar/eliminar parcelamento anula previsões pendentes e parciais, preserva pagamentos reais e desassocia-os do agregado.
- Faturas com pagamentos ou saldos transportados não permitem exclusão isolada de compras/encargos.
- Dívida inicial de cartão atualiza o contador e a conta de origem na mesma transação.
- Recorrências mantêm ocorrências realizadas e anulam apenas previsões futuras elegíveis.
- Conta/cartão/categoria com histórico não perde dependências nem auditoria.
- Cada UI chama a rota correta, oferece confirmação honesta e apresenta erros de domínio.
- Testes de regressão, integridade e suíte completa passam.

## Estado

- [x] Branch criada a partir de `main`.
- [x] Inventário inicial de rotas e principais caminhos destrutivos.
- [x] Orquestrador central e tabelas de auditoria.
- [x] Unificação dos caminhos de exclusão de movimentos.
- [x] Políticas centrais para compromissos, cartões, categorias e recorrências.
- [x] Remoção das rotas de produto para estorno/anulação isolada.
- [x] Testes de regressão para pagamentos, faturas, dívida inicial, parcelamentos e recorrências.
- [x] CI aprovado no commit `fe4ecbb84f09e295fe8569f3169b658450f64222` (204 testes aprovados, 1.655 assertions, zero `risky`).
- [x] Cancelamento de parcelamento parcialmente pago: previsão parcial anulada, pagamentos preservados/desassociados e auditoria concluída.
- [x] Eliminação de parcelamento totalmente pago: evento preservado, prestação mantém estado `paid` e ligações ao agregado removidas.
- [x] Eliminação de parcelamento previamente cancelado: auditoria anterior validada e pagamentos preservados como movimentos independentes.
- [x] Renegociação de prestação parcialmente paga: previsão antiga anulada, pagamento real desassociado e prestação histórica cancelada.
- [x] Rollback transacional testado quando o recálculo de um agregado falha após o início da exclusão.
- [x] Eliminação de cartão sem uso auditada; cartão com movimento histórico `void` continua protegido.
- [x] Categoria referenciada apenas por movimento canónico não pode ser eliminada.
- [x] Idempotência: repetição da exclusão não duplica operações nem itens de auditoria.
- [x] Atomicidade: falha simulada no recálculo do agregado reverte a alteração do movimento e os registos de auditoria.
- [x] Bloqueio de exclusão/cancelamento quando `paid_amount` diverge dos eventos de pagamento ou quando o estado da previsão não corresponde aos eventos.
- [x] Isolamento multiutilizador: utilizador diferente recebe 404 ao tentar eliminar compromisso ou movimento alheio.
- [x] UI de Parcelamentos mantém a ação de eliminação disponível com pagamentos e utiliza URL DELETE explícita; backend valida a segurança da propagação.
- [x] Teste de rollback transacional após falha no recálculo de um agregado.
- [x] Testes de isolamento entre utilizadores nas rotas de eliminação de compromisso e movimento.
- [x] Revisão dos fluxos de edição/substituição e projeções de Dashboard, Movimentos, Rota Financeira, Contas, Parcelamentos, Cartões e Faturas.
- [x] Teste concorrente MySQL adicionado em `FinancialDeletionConcurrencyTest`: dois processos PHP com ligações independentes disputam a eliminação do mesmo movimento; o teste aguarda o estado `LOCK WAIT` no InnoDB, confirma a conclusão pela transação vencedora e a idempotência do processo concorrente; aguarda execução no CI.
- [x] Avisos `risky` eliminados: corrigida diretiva `@push('scripts')` duplicada em `resources/views/movements.blade.php`; adicionadas verificações de integridade dos buffers de saída nos testes de renderização.
- [ ] Merge/deploy apenas após aprovação explícita.

## Decisão de produto/arquitetura — exclusão propagada, sem operação de estorno

**Requisito do utilizador (17/11/2026):** não disponibilizar nem estruturar o fluxo de negócio em torno do conceito de estorno. O utilizador quer trabalhar com exclusões, com propagação integral e garantia de que não ficam eventos órfãos.

### Princípio

A operação apresentada ao utilizador é **Eliminar**. Internamente, o sistema executa uma exclusão de domínio controlada, transacional, auditável e com propagação explícita pelas dependências. Não se trata de executar `DELETE CASCADE` indiscriminadamente nem de apagar fisicamente todos os registos.

### Política por tipo de relação

Cada dependência deve ter uma política explícita:

- **CASCADE (exclusão propagada):** dependente sem significado autónomo fora do agregado eliminado, como previsões geradas por uma ocorrência/compromisso, ou eventos técnicos exclusivos dessa entidade. Excluir logicamente todos os dependentes na mesma transação.
- **RESTRICT (bloquear):** dependente partilhado, obrigação com pagamentos não reconciliados, fatura com pagamentos/alocações, conta com movimentos ou qualquer caso em que não exista propagação determinística e sem perda de informação. Explicar ao utilizador o que tem de ser resolvido primeiro.
- **DETACH (desassociar):** apenas quando o dependente tem significado financeiro autónomo e a matriz permite preservar a entidade sem a origem. Exige validação explícita, atualização de referências e auditoria; nunca deixar FK inválida.
- **RECALCULATE (recalcular):** projeções e agregados derivados (saldo da conta, saldo de obrigação, total de fatura, montantes pagos, estado da parcela/compromisso) são reconstruídos a partir dos eventos sobreviventes, na mesma transação. Não criar um movimento inverso visível ao utilizador.

### Movimentos liquidados

A exclusão de um movimento liquidado só pode ocorrer quando o serviço conseguir propagar a remoção a todas as consequências contabilísticas internas e dependências, recalcular os saldos e preservar a prova de auditoria. Se o movimento tiver dependências não propagáveis, a operação é bloqueada — não se substitui por uma operação chamada estorno.

O sistema não deve manter um evento de pagamento ativo apontando para uma obrigação removida, nem conservar um efeito no saldo de uma conta quando o evento que o originou foi eliminado. Quando necessário, os eventos dependentes exclusivos são também excluídos logicamente e as projeções são recalculadas.

### Auditoria e integridade

- Preferir **soft delete / tombstone** para entidades financeiras e dependentes: guardar quem eliminou, quando, motivo, operação/agregado de origem e snapshot mínimo necessário para auditoria e recuperação.
- O registo de auditoria é imutável e não deve ser apagado em cascata com a entidade de negócio.
- Toda a propagação ocorre numa única transação DB, com bloqueios das entidades relacionadas em ordem consistente.
- A operação deve ser idempotente: repetir o pedido não pode duplicar exclusões nem alterar saldos novamente.
- Depois da operação, validar invariantes: zero referências órfãs, zero eventos ativos dependentes de entidade eliminada e saldos/estados iguais à soma dos eventos sobreviventes.
- Hard delete só para dados sem relevância financeira, sem referências e sem obrigação de auditoria, após confirmação das constraints.

### Implicação para o desenho atual

Os métodos e rotas denominados `reverse`, `estorno` ou `reversal_of_id` devem ser revistos. O utilizador não quer um fluxo de estorno. Não remover colunas/dados de reversões antigas sem migração e análise dos registos existentes; definir compatibilidade histórica e plano de migração. Novas exclusões devem usar uma operação de domínio central (por exemplo, `DeletionOrchestrator`) que resolva o grafo de dependências com políticas explícitas, execute soft deletes, preserve auditoria e recalcule agregados.

### Casos de aceitação adicionais

1. Eliminar movimento pendente comum: excluir logicamente o movimento e respetivas projeções exclusivas; saldo não muda se nunca teve impacto.
2. Eliminar movimento liquidado simples: excluir logicamente o evento e recalcular saldo da conta; não criar contra-movimento.
3. Eliminar pagamento parcial: propagar a exclusão do evento, recalcular montante pago, saldo em aberto, estado da prestação/obrigação e previsão.
4. Eliminar compromisso: bloquear se houver pagamentos cuja propagação não esteja explicitamente autorizada; caso autorizado, excluir compromisso, parcelas, previsões e eventos exclusivos, e recalcular as contas afetadas.
5. Eliminar compra de cartão: se não houver pagamentos/alocações/transportes dependentes, excluir compra e recalcular a fatura; se existirem, propagar por uma regra validada ou bloquear. Nunca deixar pagamento/alocação órfão.
6. Eliminar uma ocorrência recorrente: respeitar o escopo; remover apenas a ocorrência ou as ocorrências futuras pendentes conforme pedido. Ocorrências liquidadas só podem ser removidas se a propagação financeira completa for válida.
7. Eliminar transferência: remover o evento único de transferência e recalcular ambas as contas na mesma transação.
8. Eliminar ajuste de saldo: remover o evento e recalcular a conta; manter trilho de auditoria.
9. Falha em qualquer dependência/recalculo: rollback integral; nenhum registo parcialmente eliminado.
10. Repetir uma exclusão: resposta idempotente e nenhum segundo efeito financeiro.

**Nota de rigor:** “exclusão propagada” não significa que qualquer operação possa sempre ser concluída. Quando houver uma dependência partilhada ou uma consequência externa/ambígua que não possa ser recalculada com segurança, o sistema deve bloquear a exclusão e explicar a dependência, em vez de criar órfãos ou corromper saldos.


## E-11 — [ALTA] Exclusão/cancelamento de parcelamento pode deixar movimentos liquidados visíveis

**Reprodução relatada pelo utilizador (17/11/2026):** um parcelamento foi removido, mas as parcelas pagas continuaram na página de Movimentos. A tentativa de remover essas linhas diretamente foi bloqueada; por outro percurso financeiro, o parcelamento deixou de aparecer, mas os movimentos permaneceram.

### O que o código confirma

1. `FinancialCommitmentService::create` cria três entidades relacionadas: a obrigação em `dividas`, as parcelas em `parcelas_divida` e uma previsão canónica por parcela em `movimentos`, com `debt_id` e `installment_id`.
2. Ao liquidar uma parcela, `FinancialMovementService::settle` cria um evento de pagamento ligado à previsão por `settles_movement_id`, altera a previsão para `partial` ou `settled` e sincroniza o montante pago na parcela. A previsão continua a ser um movimento canónico ligado à obrigação.
3. `FinancialMovementService::deleteAsCorrection` bloqueia qualquer movimento com `installment_id`, bem como eventos de liquidação e previsões de dívida. Por isso, a recusa na página de Movimentos é coerente com a proteção atualmente implementada.
4. `FinancialCommitmentService::cancel` cancela apenas parcelas `pending/partial` e previsões `pending`. Não elimina nem altera previsões `settled`, nem os eventos de pagamento associados. É uma operação de cancelamento do saldo futuro, não uma exclusão propagada do agregado.
5. `FinancialCommitmentService::deleteUnused` verifica pagamentos através de `parcelas.paid_amount > 0` ou movimentos `settled` com o mesmo `debt_id`. Se detetar pagamento, deve bloquear. Se não detetar, cancela parcelas pendentes/parciais, anula previsões pendentes e aplica soft delete apenas à obrigação (`dividas`). Não propaga soft delete às parcelas nem aos movimentos liquidados.
6. A listagem de Movimentos em `DashboardController::movimentosQuery` consulta diretamente `movimentos` canónicos com estado `pending/partial/settled`. Não exige que a obrigação associada continue ativa/não eliminada. Assim, uma previsão liquidada e/ou evento de pagamento sobrevivente continua elegível para aparecer, mesmo quando a obrigação sofre soft delete.
7. A vista de Parcelamentos calcula a visibilidade do botão de exclusão com base no total de `paid_amount`, enquanto o servidor também verifica movimentos liquidados associados. O backend deve continuar a ser a autoridade, mas os dois indicadores podem divergir em dados históricos inconsistentes.

### Conclusão técnica

As páginas não estão a consultar uma única entidade: Parcelamentos apresenta o agregado `Divida + Parcelas`; Movimentos apresenta eventos de `movimentos`. A ligação entre eles é feita por chaves (`debt_id`, `installment_id`, `settles_movement_id`), não por uma consulta que obrigue a existência/estado ativo da obrigação. Soft delete da obrigação não apaga nem oculta automaticamente os eventos canónicos.

**Limite da conclusão:** com o código atual da branch, uma chamada bem-sucedida a `DELETE /api/financial/commitments/{id}` para uma parcela corretamente registada como paga deveria ser bloqueada por `deleteUnused`. Portanto, o relato de sucesso nessa rota indica uma destas situações ainda por confirmar nos dados concretos: (a) o pagamento foi gravado num formato legado/desassociado que não satisfaz os dois predicados de proteção; (b) foi executado o endpoint de cancelamento, não o DELETE; ou (c) o código em produção divergia da branch auditada. Não é correto afirmar qual delas ocorreu sem identificar o URL/método efetivamente chamado e inspecionar os registos envolvidos.

### Lacunas de teste

- Não existe teste que tente eliminar um compromisso depois de uma liquidação total/parcial e confirme bloqueio sem alterações.
- Não existe teste que cancele um compromisso com parcelas pagas e confirme a política explícita de preservação/visibilidade dos eventos.
- Não existe teste de integridade que, após eliminar/cancelar uma obrigação, procure movimentos e eventos de liquidação com `debt_id`, `installment_id` ou `settles_movement_id` ligados ao agregado.
- Não existe teste que compare a listagem de Parcelamentos com a listagem canónica de Movimentos após exclusão/cancelamento.

### Correção exigida pela decisão de arquitetura

A exclusão deve resolver o grafo completo: obrigação → parcelas → previsões → eventos de pagamento → contas afetadas. Se a propagação financeira for permitida pela matriz, todos os eventos exclusivos devem ser logicamente eliminados na mesma transação e os saldos recalculados. Se não for possível fazê-lo com segurança, a exclusão deve ser bloqueada. Não basta soft-delete da obrigação nem cancelar apenas previsões pendentes. A consulta de Movimentos também deve respeitar a política de visibilidade de agregados eliminados, sem ocultar silenciosamente pagamentos válidos.


## E-12 — [CRÍTICA, baseline da main; mitigado na branch] Rota Financeira apresentava eventos de liquidação do parcelamento como movimentos independentes e permitia ação individual

> Este finding descreve o estado anterior à implementação. Os endpoints de reversão/anulação isolada foram removidos e a interface passou a utilizar a exclusão central.

**Página:** `/rota-financeira`  
**Controller de leitura:** `FinancialRouteController`  
**Projeção:** `FinancialProjectionService::build`  
**Interface:** `resources/views/financial-route.blade.php`

### Fluxo confirmado

- A Rota Financeira não usa a mesma consulta da página Movimentos. Ela consome `FinancialProjectionService::build`.
- A projeção exclui previsões ligadas a obrigações arquivadas/encerradas através de `debt_id`, mas preserva eventos efetivos de liquidação.
- `FinancialMovementService::settle` cria o evento de pagamento com `settles_movement_id`, mas não copia `debt_id` nem `installment_id` para esse evento.
- Assim, o evento de pagamento fica ligado à previsão, mas não carrega diretamente a referência da obrigação/parcela.
- A interface da Rota Financeira mostra ação diferente pelo estado: para movimentos `settled` chama `POST /api/financial/movements/{id}/reverse`; para previsões recorrentes chama `PUT /api/financial/movements/{id}/void`; nos restantes casos chama `DELETE /api/financial/movements/{id}`.
- O método `FinancialMovementService::reverse` bloqueia movimentos que tenham `installment_id` ou sejam `is_settlement_parent`, mas não bloqueia o evento que tenha apenas `settles_movement_id`. Esse evento é, precisamente, o registo criado para representar o pagamento da parcela.
- O método de reversão recalcula o movimento pai e chama `syncInstallmentFromSettlementParent`, pelo que a operação pode alterar o valor pago/estado da parcela. Ou seja, a Rota Financeira tem um caminho de alteração de pagamento de parcela que não está expresso como operação sobre o parcelamento.

### Impacto

A Rota Financeira pode apresentar um pagamento de parcela como movimento autónomo e oferecer uma ação individual sobre ele. Isso explica por que páginas diferentes podem dar experiências diferentes para o mesmo fluxo: uma página bloqueia a previsão ligada à parcela; a Rota Financeira opera sobre o evento filho, que não tem `installment_id` nem `debt_id` direto.

Esta é uma quebra de consistência de interface e de domínio. A operação deve resolver o agregado de origem, não depender apenas dos campos diretos do evento selecionado.

**Implementado na branch:** a Rota Financeira usa a mesma ação de exclusão central que Movimentos e Contas. Eventos de pagamento são tratados em conjunto com o movimento pai e o agregado correspondente; pagamentos preservados após cancelamento/renegociação ficam desassociados e continuam como movimentos efetivos.

## E-13 — [ALTA, baseline da main; corrigido na branch] Exclusões em páginas de conta tinham semântica diferente e um caminho de hard delete fora do serviço financeiro

> Este finding descreve o estado anterior à implementação. `FinancialAccountsPageController::destroyMovement` agora encaminha para `FinancialMovementService::deleteAsCorrection`.

**Página:** detalhe de conta/cartão, `resources/views/financial/account-show.blade.php`.

- Movimentos legados de despesa/receita encaminham para `FinanceController::destroy`.
- Movimentos canónicos encaminham para `FinancialAccountsPageController::destroyMovement`.
- Para estado `settled`, a interface oferece a rota POST de reversão; para outros estados, oferece DELETE.
- `FinancialAccountsPageController::destroyMovement` apaga diretamente o histórico e o movimento (`historico()->delete()`, `delete()`) dentro de transação, sem passar por `FinancialMovementService`.
- A validação bloqueia movimentos com `installment_id` e previsões pendentes com `debt_id`, e bloqueia estado `settled`; não valida explicitamente `settles_movement_id`, nem trata o estado `partial` como dependência protegida.
- O comportamento é diferente do endpoint JSON de movimentos, que usa `deleteAsCorrection` e marca o movimento como `void`.

**Impacto:** o mesmo movimento pode ser eliminado logicamente por uma página, fisicamente por outra ou bloqueado por outra. O hard delete também remove o histórico antes de apagar o movimento, eliminando evidência de auditoria.

## E-14 — [ALTA, baseline da main; corrigido na branch] Recorrências executavam hard delete de previsões e histórico em vários fluxos

> Este finding descreve o estado anterior à implementação. As previsões elegíveis agora são anuladas pelo orquestrador e o histórico é preservado.

**Serviço:** `RecurrenceService`.

Os métodos de pausa, edição/desativação de recorrência e sincronização de movimento removem previsões pendentes, representação legacy e histórico por hard delete. Os fluxos repetem a lógica em vários pontos, em vez de chamar uma política de exclusão central.

A regra atual filtra previsões pendentes e tenta preservar ocorrências confirmadas; ainda assim, apagar o histórico associado contradiz a exigência de trilho de auditoria imutável. Deve ser revisto também o comportamento para ocorrências com relações adicionais, e a atomicidade entre a regra, previsões, representação legacy e histórico.

## E-15 — [ALTA, baseline da main; corrigido na branch] Atualização de despesa/receita podia apagar movimento e histórico para o substituir

> Este finding descreve o estado anterior à implementação. A substituição agora preserva o movimento canónico e o histórico, libertando apenas a associação legacy.

**Controller:** `FinanceController::update`.

Foi identificado um ramo que executa `$movement->historico()->delete()` e `$movement->delete()` antes de criar a nova representação. Não é uma rota DELETE, mas é uma operação destrutiva disparada por edição e, portanto, faz parte do âmbito desta auditoria.

Deve ser analisado contra os estados `pending/partial/settled`, pagamentos associados, referências por `settles_movement_id`, relações com faturas/parcelas e auditoria. A substituição física não pode eliminar dependências nem apagar a prova do registo anterior.

## E-16 — [MÉDIA/ALTA, baseline da main; corrigido na branch] Exclusão de categoria validava apenas tabelas legacy e ignorava movimentos canónicos

> Este finding descreve o estado anterior à implementação. A política central verifica movimentos legacy e canónicos.

**Controller:** `CategoriaController::destroy`.

A contagem verifica apenas `despesas` ou `receitas` legacy. Não verifica `movimentos.category_id`. A migração financeira define FK composta de `movimentos` para `categorias` com `restrictOnDelete`. Se houver apenas movimentos canónicos, a aplicação não apresenta a validação de domínio esperada e pode falhar ao executar a exclusão por restrição da base de dados.

A validação deve considerar todas as representações e relações antes de permitir excluir; na presença de histórico, desativar deve ser a operação normal.

## E-17 — [MÉDIA, baseline da main; mitigado na branch] Exclusão de cartão apagava faturas fisicamente após validações próprias

> Este finding descreve o estado anterior à implementação. A exclusão de cartão está centralizada e bloqueia histórico/dependências; a eliminação de faturas vazias ocorre apenas dentro da política validada.

**Service:** `FinancialAccountService::deleteUnusedCard`.

O método verifica movimentos associados, dívidas, recorrências, movimentos de fatura, dívida inicial e saldo; se não encontrar dependências, executa hard delete de todas as faturas do cartão e do próprio cartão. É um fluxo próprio, não centralizado, e precisa de ser cruzado com saldos transportados, histórico de faturas, créditos, encargos, alocações e referências indiretas. A validação atual de movimentos de fatura não prova, por si só, que todos os dependentes de fatura estão ausentes.

## Matriz atual página → rota → operação → serviço

| Página/área | Ação | Endpoint/rota | Comportamento atual na branch |
|---|---|---|---|
| Movimentos | Eliminar movimento | `DELETE /api/financial/movements/{id}` | `FinancialMovementService::deleteAsCorrection` → `FinancialDeletionService::deleteMovement`; marca o movimento como `void`, preserva histórico, propaga para eventos filhos e recalcula pai, parcela, fatura ou dívida inicial quando aplicável |
| Movimentos recorrentes | Eliminar ocorrência/âmbito | `DELETE /api/financial/movements/{id}/recurrence-scope` | Aplica exclusão central ao âmbito permitido; preserva ocorrências realizadas e histórico |
| Rota Financeira | Eliminar movimento | `DELETE /api/financial/movements/{id}` | Usa a mesma política central; a UI já não chama endpoints de reverse/void |
| Parcelamentos | Eliminar obrigação | `DELETE /api/financial/commitments/{id}` | `FinancialCommitmentService::delete` → `deleteCommitment`; soft delete da obrigação, anula previsões, cancela prestações futuras e preserva/desassocia pagamentos liquidados |
| Parcelamentos | Cancelar obrigação | `POST /api/financial/commitments/{id}/cancel` | `FinancialCommitmentService::cancel` → `cancelCommitment`; mantém a obrigação cancelada, anula previsões pendentes/parciais e preserva pagamentos reais autónomos |
| Parcelamentos | Renegociar obrigação | `POST /api/financial/commitments/{id}/renegotiate` | `FinancialCommitmentService::renegotiate` → `FinancialDeletionService::renegotiateCommitmentInstallment`; anula previsões antigas, desassocia pagamentos reais de prestações parcialmente pagas e cria o novo plano sem deixar previsões órfãs |
| Parcelamentos | Arquivar obrigação | `POST /api/financial/commitments/{id}/archive` | Define `archived_at`; as projeções excluem previsões do agregado arquivado sem apagar eventos de pagamento |
| Contas — detalhe | Eliminar movimento canónico | `DELETE /contas/movimentos/{id}` | `FinancialAccountsPageController::destroyMovement` encaminha para `deleteAsCorrection`; sem hard delete direto |
| Despesas/receitas legacy | Eliminar registo | `DELETE /despesas/{id}`, `DELETE /receitas/{id}` | `FinanceController::destroy` elimina movimento canónico pela política central e grava snapshot do registo legacy; se não existir movimento canónico, audita a eliminação legacy isolada |
| Despesas/receitas legacy | Editar/substituir | `PUT/PATCH /despesas/{id}`, `PUT/PATCH /receitas/{id}` | Pendentes usam atualização controlada; liquidados não aceitam alteração financeira; substituição preserva movimento e histórico, libertando a associação legacy dentro da transação |
| Cartões | Eliminar cartão sem uso | `DELETE /api/financial/cards/{id}` | `FinancialAccountService::deleteUnusedCard` → orquestrador; bloqueia qualquer movimento/histórico, dívida, recorrência, fatura com movimentos, saldo transportado, dívida inicial ou saldo não nulo; só elimina cartão sem uso |
| Categorias | Eliminar categoria | `DELETE /categorias/{id}` | `CategoriaController::destroy` → orquestrador; verifica referências legacy e canónicas, incluindo movimentos `void`; se existir histórico, bloqueia e exige desativação |
| Recorrências | Pausar/retomar | `PATCH /api/financial/recurrences/{id}/status` | `RecurrenceService::setActive`; anula apenas previsões futuras elegíveis por exclusão central, mantém ocorrências realizadas e histórico; retomar regenera previsões |
| Recorrências | Alterar regra a partir de movimento | edição de despesa/receita | `RecurrenceService::syncEditedMovement`; anula previsões futuras não confirmadas e mantém a ocorrência realizada |
| Faturas | Eliminar compra/encargo | `DELETE /api/financial/movements/{id}` | Fatura com pagamentos ativos ou saldo transportado bloqueia exclusão isolada; sem essas dependências, recalcula total e soft-deletes a fatura vazia |
| Faturas | Eliminar pagamento | `DELETE /api/financial/movements/{id}` | Retira o efeito do pagamento, recalcula obrigação da fatura e saldo da conta; não cria movimento inverso |

## Observações sobre integridade referencial encontradas nas migrations

- `parcelas_divida.debt_id` usa `cascadeOnDelete` para `dividas`, mas `Divida` usa SoftDeletes; o soft delete não aciona a FK. As parcelas permanecem fisicamente.
- `movimentos.debt_id` e `movimentos.installment_id` usam `restrictOnDelete`, impedindo hard delete da obrigação/parcela enquanto movimentos diretamente ligados existirem.
- `historico_movimentos.movement_id` usa `restrictOnDelete`; apagar histórico manualmente antes do movimento é uma forma de contornar essa proteção, mas destrói trilho de auditoria.
- `movimentos.settles_movement_id` usa `cascadeOnDelete` para o movimento pai. Hard delete do pai pode propagar aos eventos filhos; esta cascata não substitui uma política de exclusão auditável e deve ser testada em conjunto com as FKs do histórico dos filhos.
- Faturas ligadas a contas e movimentos usam restrições; a exclusão de cartão contorna a dependência apagando faturas explicitamente após as verificações.

## Estado da auditoria ampliada

O inventário das rotas de exclusão, cancelamento, edição/substituição e recorrência foi cruzado com os serviços centrais, projeções, FKs e testes de regressão. Os findings E-01 a E-17 descrevem a baseline anterior e estão identificados como tal; a matriz acima descreve o comportamento da branch atual.

A validação automatizada cobre movimentos, eventos de liquidação, pagamentos parciais/totais, parcelamentos, faturas, dívida inicial de cartão, recorrências, categorias, cartões, isolamento entre utilizadores e idempotência. A revisão estática confirmou que os endpoints de produto para reverse/void isolados já não estão registados em `routes/web.php`.

Limites remanescentes: o CI não simula concorrência real entre transações/processos; a ordem de locks foi revista no código, mas um teste de stress concorrente em ambiente MySQL dedicado ainda seria validação adicional. O CI também reporta dois testes como `risky`, sem falhas; esses avisos devem permanecer visíveis e não ser apresentados como testes aprovados sem ressalva.


## 8. Modelo aprovado para exclusões financeiras

Decisão funcional aprovada:

- Não existe operação de estorno como conceito de produto. O utilizador elimina movimentos; o sistema altera o estado financeiro e recalcula dependências.
- Não criar movimentos inversos para compensar exclusões novas.
- O campo legado `reversal_of_id` e os registos históricos existentes permanecem por compatibilidade e auditoria; não remover sem migração específica.
- A fatura do cartão é uma agregação automática de movimentos por período. Não existe exclusão manual de fatura.
- Compras, encargos e pagamentos são movimentos de origem. A exclusão de movimentos de origem recalcula a obrigação da fatura.
- Faturas com pagamentos ativos ou saldos transportados bloqueiam a exclusão isolada de compras. Uma fatura fechada sem pagamentos nem saldo transportado pode ser recalculada; se ficar vazia, recebe soft delete.
- Eliminar um pagamento remove o seu efeito financeiro ativo, recalcula a obrigação e o saldo da conta; não cria um movimento inverso.
- Eliminar um parcelamento anula previsões e parcelas, preserva pagamentos reais como movimentos independentes e desassocia-os do agregado eliminado.
- Recorrências mantêm ocorrências já realizadas; ao pausar/alterar a regra, previsões futuras não confirmadas são anuladas com auditoria, sem apagar o histórico.
- Contas e cartões com histórico devem ser encerrados/desativados, não eliminados. Cartões sem utilização podem ser eliminados.
- Categorias com movimentos históricos são desativadas; só categorias sem referências podem ser eliminadas.

## 9. Implementação na branch

Foi criado `App\Services\Financial\FinancialDeletionService` como orquestrador de exclusões e a migration `2026_11_17_230000_create_financial_deletion_audit_tables.php`, com:

- `deletion_operations`: raiz, utilizador, motivo, estado e metadados da operação.
- `deletion_operation_items`: entidades abrangidas, ação aplicada e snapshots antes/depois.
- Movimentos mantidos como registos canónicos com estado `void`; não são removidos fisicamente.
- Histórico `historico_movimentos` preservado e complementado com a auditoria da operação.

Integrações já alteradas:

- `FinancialMovementService::deleteAsCorrection` encaminha para o orquestrador.
- Exclusão de eventos de pagamento atualiza a obrigação pai, a parcela e a projeção da fatura.
- Pagamentos identificados da dívida inicial atualizam o contador do cartão.
- `FinancialCommitmentService::delete` encaminha para exclusão propagada; pagamentos liquidados são preservados e desassociados.
- `FinancialCommitmentService::cancel` encaminha para `FinancialDeletionService::cancelCommitment`; previsões pendentes/parciais são anuladas, pagamentos liquidados são preservados e desassociados, e a operação recebe auditoria própria.
- Cancelamento e eliminação reconciliam `parcelas_divida.paid_amount` com a soma líquida dos eventos liquidados (eventos associados e pagamentos legacy); divergências bloqueiam a operação antes de qualquer mutação.
- O estado `paid` das prestações liquidadas é preservado; apenas prestações `pending/partial` passam a `cancelled`.
- `FinancialAccountService::deleteUnusedCard` encaminha para a política central; cartões com histórico/dependências são bloqueados.
- `CategoriaController::destroy` encaminha para a política central e considera movimentos canónicos e legacy.
- `RecurrenceService` deixou de apagar fisicamente previsões e respetivo histórico ao pausar/alterar recorrências.
- Detalhe da conta e Rota Financeira utilizam a mesma semântica de eliminação.
- Substituição de movimentos legacy na edição deixa de apagar o movimento canónico e o histórico.
- Endpoints de estorno foram removidos das rotas de produto; os dados antigos permanecem compatíveis.

Testes de regressão atualizados/adicionados para pagamentos parciais, pagamentos de faturas, dívida inicial, transferência, exclusão de parcelamentos parcial e totalmente pagos, cancelamento parcial, divergência entre contador e eventos, idempotência, proteção de cartão com histórico e categoria com movimento canónico. O CI confirmado no commit `fe4ecbb84f09e295fe8569f3169b658450f64222` executou 204 testes aprovados, 1.655 assertions e zero testes risky. A nova regressão de concorrência ainda aguarda execução no CI.

## 10. Validação final e limites conhecidos

1. [x] Inventário das rotas e caminhos destrutivos de movimentos, compromissos, cartões, categorias, recorrências, faturas e edição/substituição.
2. [x] Orquestrador central e trilho de auditoria com snapshots antes/depois.
3. [x] Remoção de hard delete de movimentos e histórico nos fluxos financeiros identificados.
4. [x] Testes de regressão para pagamentos parciais/totais, cancelamento e eliminação de parcelamentos, pagamentos de fatura, dívida inicial, recorrências, categorias, cartões e isolamento entre utilizadores.
5. [x] Teste de idempotência para eliminação repetida de movimento sem duplicar auditoria.
6. [x] Teste de fatura vazia: soft delete e restauração do mesmo ID quando há nova compra no mesmo ciclo.
7. [x] CI aprovado no commit `fe4ecbb84f09e295fe8569f3169b658450f64222`: 204 testes aprovados, 1.655 assertions e zero testes `risky`.
8. [ ] CI do HEAD atualizado, incluindo o teste de concorrência MySQL.
9. [x] Avisos `risky` eliminados: corrigida a diretiva Blade duplicada e adicionadas verificações de integridade dos buffers nos testes de renderização.
10. [x] Adicionado `FinancialDeletionConcurrencyTest`: dois processos PHP e duas ligações MySQL independentes disputam a eliminação do mesmo movimento; o teste confirma que o segundo processo fica em `LOCK WAIT` no InnoDB, acorda após o commit vencedor e não duplica a auditoria. A execução no CI ainda precisa de confirmação.
11. [ ] Merge e deploy permanecem fora do âmbito até aprovação explícita do utilizador.

A implementação e os testes funcionais/atómicos estão concluídos na branch. O teste de concorrência entre processos reais foi adicionado ao workflow, mas a aprovação depende da sua execução em MySQL no CI. Não fazer merge nem deploy sem aprovação explícita.

### Atualização adicional da implementação

- Faturas vazias sem movimentos ativos nem saldos transportados recebem soft delete; a listagem deixa de as apresentar.
- Ao surgir uma nova compra no mesmo ciclo, a fatura soft-deleted é restaurada e reutilizada, respeitando a unicidade cartão/período.
- Exclusões de cartões sem histórico, categorias sem referências e registos legacy isolados também passam pelo orquestrador e deixam snapshots na auditoria.
- A página de detalhe da conta filtra movimentos `void`, evitando que registos eliminados continuem visíveis na lista operacional.
- Removidos os endpoints de produto para estorno e para anulação isolada de movimentos; as páginas utilizam a exclusão central.
- Os fluxos de recorrência deixam de apagar fisicamente movimentos e histórico. Ao pausar/editar uma regra, previsões futuras são anuladas com registo da operação e libertação da chave de ocorrência para eventual regeneração.

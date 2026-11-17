# Auditoria de exclusões — Meu Bolso

**Branch:** `audit/exclusoes-movimentos`  
**Base:** `main`  
**Data:** 17/11/2026  
**Âmbito:** todas as rotas HTTP de remoção/anulação/estorno e respetivos formulários visíveis identificados na branch `main`.

## Objetivo

Auditar e uniformizar os fluxos de exclusão do sistema sem quebrar a matriz financeira já aprovada. Esta branch parte diretamente de `main`. Não alterar a `main` nem misturar trabalho de outras branches.

## Regras de domínio que condicionam qualquer exclusão

1. **Movimento pendente comum:** pode ser anulado como correção; deve permanecer auditável como `void`, deixando de afetar os saldos.
2. **Movimento liquidado comum:** não pode ser apagado nem convertido diretamente em `void`. Deve ser estornado por contra-lançamento, preservando o original e o vínculo da reversão.
3. **Movimento parcialmente liquidado / obrigação com eventos de liquidação:** não pode ser tratado como movimento simples. A alteração ou correção tem de respeitar pagamentos já registados e o agregado/obrigação correspondente.
4. **Previsão de prestação/compromisso:** não pode ser eliminada isoladamente; gerir pelo fluxo de Parcelamentos. Obrigações canceladas/pagas e prestações canceladas/pagas não podem receber pagamentos.
5. **Recorrências:** a remoção de ocorrência aplica-se apenas a previsões pendentes. “Apenas esta” conserva outras ocorrências; “esta e futuras” interrompe a regra e anula só ocorrências pendentes a partir da data indicada. Ocorrências liquidadas permanecem no histórico.
6. **Cartão/fatura:** compra/encargo associado a fatura com pagamentos registados não pode ser removido isoladamente. Deve usar crédito/correção da fatura ou fluxo próprio que preserve pagamentos, alocações, saldo transportado e reconciliação.
7. **Dívida inicial de cartão:** pagamentos não são elimináveis como correção simples. Estorno deve repor atomicamente o contador `opening_debt_paid` e o saldo entre contas. Movimentos legados ambíguos devem ser bloqueados e encaminhados para reconciliação.
8. **Compromisso sem pagamentos:** pode ser eliminado segundo o serviço específico, mas preservar parcelas/histórico por soft delete e anular previsões pendentes relacionadas na mesma transação. Com pagamentos: não eliminar.
9. **Cartão/conta:** eliminação só é permitida sem movimentos, dívidas, recorrências, faturas/itens associados, saldo ou dívida inicial. Caso contrário, encerrar/arquivar; nunca cascatar histórico financeiro.
10. **Categoria:** se possuir movimentos associados, não eliminar; desativar preservando referências. A validação deve considerar também movimentos canónicos, não apenas tabelas legadas.
11. **Atomicidade e isolamento:** confirmar propriedade do utilizador, bloquear entidades relacionadas em ordem consistente, validar dependências e persistir mudança + auditoria na mesma transação.
12. **UI/API:** cada botão deve representar a operação real (anular, estornar, cancelar, arquivar, eliminar). Confirmar escopo e consequência; mostrar erros HTTP ao utilizador e impedir reenvios duplicados.

## Inventário de rotas identificado

| Rota | Handler | Operação |
|---|---|---|
| `DELETE /api/financial/movements/{id}` | `FinancialCoreController::destroyMovement` | Anulação/correção por `deleteAsCorrection` |
| `DELETE /api/financial/movements/{id}/recurrence-scope` | `destroyRecurringOccurrence` | Anulação de ocorrência(s) pendentes e possível desativação da recorrência |
| `DELETE /api/financial/commitments/{id}` | `deleteCommitment` → `FinancialCommitmentService::deleteUnused` | Exclusão restrita de compromisso sem pagamentos |
| `DELETE /api/financial/cards/{id}` | `deleteUnusedCard` → `FinancialAccountService::deleteUnusedCard` | Exclusão restrita de cartão sem dependências |
| `DELETE /contas/movimentos/{id}` | `FinancialAccountsPageController::destroyMovement` | Exclusão de movimento não liquidado |
| `DELETE /despesas/{id}` | `FinanceController::destroy` | Apaga representação legacy e corrige movimento canónico |
| `DELETE /receitas/{id}` | `FinanceController::destroy` | Apaga representação legacy e corrige movimento canónico |
| `DELETE /categorias/{id}` | `CategoriaController::destroy` | Exclusão de categoria sem movimentos |
| `POST /api/financial/movements/{id}/reverse` | `FinancialCoreController::reverseMovement` | Estorno contabilístico de movimento liquidado |
| `POST /contas/movimentos/{id}/estornar` | `FinancialAccountsPageController::reverseMovement` | Estorno contabilístico (rota HTML) |
| `POST /api/financial/commitments/{id}/cancel` | `cancelCommitment` → serviço | Cancelamento lógico de obrigação e previsões |
| `POST /api/financial/commitments/{id}/archive` | `archiveCommitment` → serviço | Arquivamento, sem apagar pagamentos/histórico |
| `PATCH /api/financial/recurrences/{id}/status` | `setRecurrenceStatus` | Pausar/retomar regra; não apagar ocorrências |
| `POST /api/financial/cards/{id}/status`, `/reopen` | Estado/reativação | Encerrar/reabrir conforme regras, não eliminar histórico |

A pesquisa também identificou exclusão direta de linhas de histórico durante substituição de um movimento legacy na edição. Deve ser auditada como mutação destrutiva não exposta por rota DELETE.

## Findings estáticos iniciais

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

- Nenhuma operação de exclusão de movimento contorna o serviço de domínio ou apaga histórico financeiro/auditável.
- Movimento liquidado nunca é eliminado/void por DELETE; usa estorno ou fluxo explícito de correção.
- Pagamentos parciais e movimentos de liquidação nunca são removidos sem recalcular e preservar o agregado.
- Previsões de compromisso só podem ser geridas em Parcelamentos.
- Fatura com pagamentos não permite exclusão de compra/encargo que desequilibre o total ou as alocações.
- Dívida inicial de cartão é revertida em conjunto com o movimento bancário.
- Exclusão de recorrência obedece ao escopo e só altera ocorrências pendentes.
- Conta/cartão/categoria/compromisso não pode apagar dependências ou histórico.
- Cada UI chama a rota correta, oferece confirmação honesta e apresenta erro do servidor.
- Testes para todas as rotas e variantes passam.

## Estado

- [x] Branch criada a partir de `main`.
- [x] Regras de domínio consolidadas a partir da documentação atual.
- [x] Primeira passagem de rotas e controllers concluída.
- [ ] Auditoria integral de serviços, modelos, migrations, UI e testes.
- [ ] Findings confirmados com referências linha a linha e classificação final.
- [ ] Correções e testes regressivos.

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

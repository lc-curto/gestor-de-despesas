# Auditoria HTTP: formulários, ações e rotas

**Data:** 17 de novembro de 2026
**Âmbito:** oito áreas de auditoria do gestor de despesas
**Tipo de análise:** estática, baseada nos ficheiros e referências descritos nos resultados estruturados fornecidos
**Falhas da auditoria reportadas:** nenhuma (`[]`)

## Resumo executivo

Foram consolidadas oito auditorias estáticas que cobrem autenticação e sessão; revisão inicial de dados financeiros; dashboard e movimentos; registo de movimento na rota financeira; contas, cartões e núcleo financeiro; compromissos, prestações e recorrências; categorias; e registos legacy de despesas e receitas. As auditorias de origem produziram 17 registos de findings, incluindo uma ocorrência duplicada entre Compromissos e Despesas/Receitas. Após deduplicação, são **16 problemas confirmados únicos**: 5 de severidade alta, 9 média e 2 baixa. A classificação e evidência de cada finding são as apresentadas nas auditorias de origem; as lacunas não foram convertidas em defeitos.

Os temas de maior prioridade são: conclusão da revisão financeira sujeita a alteração concorrente; estorno de pagamento de dívida inicial sem reversão do contador; edição de movimentos liquidados; pagamento de prestações canceladas; e fluxo de criação de compromisso que pode aparentar sucesso quando o pagamento inicial falha. Há ainda problemas confirmados de validação, consistência de dados, enumeração de contas, duplicação por reenvio e tratamento de formulários.

A cobertura é ampla dentro das unidades solicitadas, mas não equivale a auditoria integral do repositório nem a teste concorrente ou validação de configuração de produção. A atualização abaixo regista alterações de código e testes direcionados; a Fase 3 permanece aberta e a suíte completa não está verde.

## Estado das correções — 17/11/2026

| Finding de severidade alta | Correção aplicada | Validação |
|---|---|---|
| Conclusão da revisão financeira sujeita a alteração concorrente | Escritas HTTP autenticadas e conclusão da revisão partilham lock transacional por utilizador; a conclusão volta a calcular os hashes dentro da transação. | Implementado; ainda sem teste concorrente multi-conexão. |
| Estorno de pagamento da dívida inicial não atualizava o contador | Adicionada referência explícita `opening_debt_card_id`; o estorno reduz `opening_debt_paid` em cêntimos. Pagamentos antigos só são inferidos se descrição e cartão coincidirem exatamente. | Teste de aceitação cobre pagamento, estorno, saldo bancário/cartão e compatibilidade com registo antigo sem referência. |
| Movimentos liquidados podiam ser alterados | API e formulário legacy rejeitam alterações financeiras a liquidados; elimina-se a substituição do original. Reatribuição de conta continua possível apenas sem mudança dos restantes dados e sem fatura ligada. | Testes cobrem rejeição HTTP e preservação de valores/saldo. O formulário apresenta aviso. |
| Prestação de obrigação cancelada podia ser paga | Pagamento bloqueia dívidas e prestações `cancelled`/`paid`, com lock da obrigação antes da prestação. | Teste de aceitação confirma erro e ausência de novo movimento. |
| Pagamento inicial podia falhar após criar o compromisso | Criação e pagamento inicial são um único pedido/transação; exige conta bancária/dinheiro ativa e resposta de erro visível; a interface evita reenvio por clique repetido. | Testes de sucesso parcial e rollback total quando o pagamento excede a prestação. |

**Defeito adicional encontrado ao implementar o estorno:** o fluxo marcava o original como `void` e criava o contra-lançamento como `settled`; como saldos ignoram `void`, o contra-lançamento isolado alterava o saldo pelo dobro. Agora o original permanece `settled`, o contra-lançamento liquidado compensa-o e a eliminação de qualquer parte do par é bloqueada.

**Testes:** `php artisan test --filter='FinancialAcceptanceTest|FinancialCoreServiceTest'` — **PASS, 35 testes / 172 assertions**. A execução completa `php artisan test` — **FAIL, 46 falhas / 68 testes aprovados / 373 assertions**; há falhas em recorrências, CSRF em testes que não desativam esse middleware e assertions de constraints SQLite. Não se afirma que sejam todas preexistentes; ficam por triagem na próxima sessão. Nenhuma fase foi marcada como concluída.

## Metodologia e limites

A síntese usa exclusivamente os resultados estruturados das auditorias fornecidas. Essas auditorias descrevem leitura estática integral dos ficheiros indicados em cada unidade, rastreamento de handlers, formulários, rotas e referências necessárias (middleware, serviços, modelos, migrações e testes, conforme aplicável). Foram usadas também enumerações de rotas reportadas por `route:list` em algumas unidades.

Os findings originais continuam limitados aos explicitamente classificados como confirmados e suportados por evidência localizada; gaps e hipóteses não foram promovidos a defeitos. A análise de origem foi estática e não executou testes. Após as correções, foram executadas as duas suítes indicadas acima; não foram feitos testes concorrentes multi-conexão, verificação de deployment/HTTPS/cookies, inspeção integral de `.env`, nem validação operacional de email/cache/base de dados.

## Cobertura por área

| Área | Cobertura observada | Rotas/fluxos verificados | Findings confirmados |
|---|---|---|---:|
| Autenticação, registo, recuperação e sessão (`authentication-account`) | Sete ficheiros solicitados integralmente; referências a User, auth/session, middleware, bootstrap, layout, migração, AuthFlowTest e handlers de inatividade. 11 endpoints no âmbito, incluindo `/`. | Login, registo, recuperação/reset, logout, atividade de sessão. | 2 |
| Revisão inicial de dados financeiros (`financial-data-review`) | Dois endpoints próprios, dois formulários POST, controller, rotas, vista, middleware e referências a migração/alias/relações. Sem varrimento de handlers de manutenção nem suite de testes. | GET `/revisao-dados`; POST `/revisao-dados/etapa`. | 2 |
| Dashboard e consulta de movimentos (`dashboard-movements`) | Quatro ficheiros pedidos integralmente; todos os handlers de `public/js/dashboard.js`; layout, controllers, serviço e middleware. 15 endpoints efetivamente rastreados, mais endpoints auxiliares enumerados. | Dashboard, consulta/paginação, CRUD legacy, compromissos e ações de movimento invocadas pela interface. | 2 |
| Rota financeira e registo de movimento (`financial-route`) | Três ficheiros pedidos e referências a controller, serviços, cartão, projeção, middleware, modelo, migração e teste de transferência. Sete endpoints. | GET da página, GET/POST/PUT/DELETE de movimentos, reverse e void. | 3 |
| Contas, cartões e núcleo financeiro (`account-pages-and-core`) | Sete ficheiros pedidos e serviços/modelos/configuração referenciados. 28 endpoints enumerados e examinados no âmbito descrito; endpoints exclusivos de compromissos ficam fora. | Páginas de contas/cartões, contas, cartões, faturas e movimentos. | 2 |
| Compromissos, prestações e recorrências (`commitments-recurrences`) | Seis ficheiros pedidos, handlers/services, formulários de criação/edição/pagamento e fluxo de recorrência. Sete endpoints JSON mais página. | CRUD/pagamento/cancelamento de compromissos e criação/estado de recorrências. | 4 |
| Categorias (`categories`) | Cinco endpoints, handlers, vista, middleware, modelos, migração, consumo em movimentos e testes dedicados lidos. | Listagem/criação/edição/estado/remoção. | 1 |
| Registos de despesas e receitas (`income-expense-records`) | 12 endpoints legacy enumerados e handlers CRUD; middleware e percursos alternativos relevantes de compromisso/cartão. | CRUD de despesas e receitas; submissões condicionais relacionadas. | 1 |

**Totais:** 8 áreas; 17 registos nas auditorias de origem, deduplicados em 16 problemas confirmados únicos (5 altos, 9 médios, 2 baixos); falhas da auditoria: nenhuma reportada. Os totais excluem lacunas e observações não classificadas como findings.

## Matriz completa rotas ↔ formulários ↔ ações

A matriz regista os destinos e ações descritos nas auditorias. “Não invocado pela interface auditada” significa que o endpoint foi enumerado/inspecionado, mas os formulários e handlers considerados não o chamam; não implica que não tenha outros consumidores.

| Área | Rota(s) | Formulário/origem | Ação/handler descrito |
|---|---|---|---|
| Autenticação | `GET /` | Navegação raiz | Closure redireciona utilizador autenticado a dashboard e visitante a login. |
| Autenticação | `GET /login`, `POST /login` | Formulário combinado em `resources/views/auth/login.blade.php` | `AccountController@login`; valida email/password, normaliza email, autentica e regenera sessão no sucesso. |
| Autenticação | `GET /registo`, `POST /registo` | Mesmo formulário combinado, alternado por JavaScript | `AccountController@register`; cria e autentica utilizador. A rota GET serve `auth.login`, não a vista separada `auth.register`. |
| Autenticação | `GET /password/forgot`, `POST /password/email` | `resources/views/auth/forgot.blade.php` | `AccountController@sendReset` via broker; resposta distingue status de sucesso de erros. |
| Autenticação | `GET /password/reset/{token}`, `POST /password/reset` | `resources/views/auth/reset.blade.php` | `AccountController@reset`; valida token, email, confirmação e password. |
| Autenticação | `POST /logout` | Menus desktop/mobile em `resources/views/layouts/app.blade.php` | Logout autenticado; invalida sessão e regenera token CSRF. |
| Autenticação | `POST /session/activity` | Nenhum chamador identificado nos scripts lidos | Closure autenticada responde `204`; o JS de inatividade não chama o endpoint. |
| Revisão financeira | `GET /revisao-dados` | Apresentação da revisão | `FinancialDataReviewController@index`; inicializa/verifica snapshots e renderiza etapas. |
| Revisão financeira | `POST /revisao-dados/etapa` | Formulário de etapa ou de conclusão, ambos com CSRF | `FinancialDataReviewController@completeStep`; valida decisão/aceitação e, no final, hashes/completude. |
| Dashboard/movimentos | `GET /dashboard` | Página/atalhos do dashboard | `DashboardController::__invoke`. |
| Dashboard/movimentos | `GET /dashboard/movimentos`, `GET /dashboard/movimentos/dados` | Filtros, pesquisa local e carregar mais | `paginaMovimentos` e `movimentos`; endpoint de dados com throttle reportado. |
| Dashboard/movimentos | `POST /despesas`, `POST /receitas` | `dashboardMovementForm` em `movements.blade.php` | `FinanceController@store`; criação legacy ou encaminhamento de casos deferidos/parcelados para compromisso. |
| Dashboard/movimentos | `PUT /despesas/{id}`, `PUT /receitas/{id}` | Formulário de edição com `_method=PUT` | `FinanceController@update`. |
| Dashboard/movimentos | `DELETE /despesas/{id}`, `DELETE /receitas/{id}` | `deleteMovementForm`/fallback legacy | `FinanceController@destroy`. |
| Dashboard/movimentos | `POST /api/financial/commitments` | Fluxo de criação parcelada/deferida | `FinancialCoreController@storeCommitment`. |
| Dashboard/movimentos | `PUT /api/financial/movements/{id}/settle` | `settleMovementForm` | `FinancialCoreController@settleMovement`. |
| Dashboard/movimentos | `DELETE /api/financial/movements/{id}/recurrence-scope`, `DELETE /api/financial/movements/{id}` | Formulário de remoção | Remoção por âmbito recorrente ou de movimento. |
| Dashboard/movimentos | `GET/POST /api/financial/movements`, `PUT /{id}`, `PUT /{id}/confirm`, `PUT /{id}/void`, `POST /{id}/reverse` | Sem chamada identificada na interface desta unidade, salvo destinos listados acima | Rotas e handlers verificados; não se infere uso pela interface auditada. |
| Rota financeira | `GET /rota-financeira` | `#routeMovementForm` e vista da rota | `FinancialRouteController`; seleciona mês/modo e apresenta projeção. |
| Rota financeira | `GET /api/financial/movements` | Carregamento da página | `FinancialCoreController@movements`. |
| Rota financeira | `POST /api/financial/movements` | `#routeMovementForm` em modo criação | `storeMovement`; despesa/receita, valor, data, categoria, notas e conta fonte/destino. |
| Rota financeira | `PUT /api/financial/movements/{id}` | Mesmo formulário em edição | `updatePendingMovement`; campos editáveis conforme handler. |
| Rota financeira | `DELETE /api/financial/movements/{id}` | Ação de detalhe/exclusão | `destroyMovement`. |
| Rota financeira | `POST /api/financial/movements/{id}/reverse` | Ação de detalhe quando liquidado | `reverseMovement`. |
| Rota financeira | `PUT /api/financial/movements/{id}/void` | Ação de detalhe para recorrência não liquidada | `voidMovement`. |
| Contas/cartões | `GET /contas`, `GET /contas/{id}` | Navegação/listagem e detalhe | Controllers de página de contas. |
| Contas/cartões | `POST /api/financial/accounts`, `PUT /api/financial/accounts/{id}` | Criação de conta/cartão e edição de nome | `storeAccount`, `updateAccount`. |
| Contas/cartões | `PUT /api/financial/accounts/{id}/settings`, `PUT /api/financial/accounts/{id}/opening-balance` | Definições e saldo inicial | `updateAccountSettings`, `setOpeningBalance`. |
| Contas/cartões | `POST /api/financial/accounts/{id}/close` | Encerramento de conta | `closeAccount`. |
| Contas/cartões | `PUT /api/financial/accounts/{id}/card-settings` | Edição de configurações de cartão | `updateCardSettings`; a interface executa também pedido separado para atualizar conta. |
| Contas/cartões | `POST /api/financial/cards/{id}/status`, `POST /api/financial/cards/{id}/reopen`, `DELETE /api/financial/cards/{id}` | Estado, reabertura e eliminação de cartão | `setCardStatus`, `reopenCard`, `deleteUnusedCard`. |
| Contas/cartões | `POST /api/financial/cards/{id}/pay-opening-debt` | Pagamento de dívida inicial | `payOpeningCardDebt`. |
| Contas/cartões | `GET /api/financial/invoices`, `POST /api/financial/invoices/{id}/pay` | Listagem/pagamento de fatura | `invoices`, `payInvoice`. |
| Contas/cartões | `POST /api/financial/cards/purchases` | Compra em cartão | `storeCardPurchase`. |
| Contas/cartões | `GET/POST /api/financial/movements`, `PUT /{id}/settle`, `/confirm`, `/void`, `PUT /{id}`, `POST /{id}/reverse`, `DELETE /{id}`, `DELETE /{id}/recurrence-scope` | CRUD/ações de movimentos; formulários concretos conforme vista | Handlers do `FinancialCoreController`; detalhes de compromissos exclusivos ficaram fora do âmbito desta unidade. |
| Compromissos/recorrências | `GET /compromissos`, `GET /api/financial/commitments` | Página e lista | `FinancialCommitmentsPageController`, `commitments`. |
| Compromissos/recorrências | `POST /api/financial/commitments`, `PUT /api/financial/commitments/{id}`, `POST /{id}/cancel` | Formulário de criação/edição/cancelamento | `storeCommitment`, `updateCommitment`, `cancelCommitment` via `FinancialCommitmentService`. |
| Compromissos/recorrências | `POST /api/financial/commitments/{id}/installments/{installmentId}/pay` | Pagamento total/parcial de prestação e pagamento inicial condicional | `payCommitmentInstallment` → `payInstallment`. |
| Compromissos/recorrências | `POST /api/financial/recurrences`, `PATCH /api/financial/recurrences/{id}/status` | Formulário/modal de recorrência | `storeRecurrence` e `setRecurrenceStatus`; geração/deduplicação dentro da regra. |
| Categorias | `GET /categorias` | Listagem/cartões | `CategoriaController@index`. |
| Categorias | `POST /categorias`, `PUT /categorias/{id}` | Modal comum de criar/editar | `store`, `update`. |
| Categorias | `PATCH /categorias/{id}/estado` | Formulário de ativar/desativar | `toggle`. |
| Categorias | `DELETE /categorias/{id}` | Formulário de remoção, visível quando contagem zero | `destroy`. |
| Despesas/receitas | `GET /despesas`, `/despesas/criar`, `GET /despesas/{id}/editar`, `POST /despesas`, `PUT /despesas/{id}`, `DELETE /despesas/{id}` | Listagem, criação, edição e remoção de despesa | `FinanceController` CRUD. O formulário pode encaminhar não-cash para compromisso. |
| Despesas/receitas | `GET /receitas`, `/receitas/criar`, `GET /receitas/{id}/editar`, `POST /receitas`, `PUT /receitas/{id}`, `DELETE /receitas/{id}` | Listagem, criação, edição e remoção de receita | `FinanceController` CRUD. |
| Despesas/receitas | `POST /api/financial/commitments`, `POST /api/financial/commitments/{id}/installments/{installmentId}/pay`, `POST /api/financial/cards/purchases` | Fluxos alternativos invocados conforme modalidade/pagamento/cartão | Endpoints e serviços relevantes rastreados como percursos condicionais. |

## Findings confirmados, priorizados

### Severidade alta

#### A conclusão da revisão financeira pode aceitar alterações concorrentes — Revisão inicial de dados financeiros
**Evidência:** `FinancialDataReviewController.php:137-145` calcula snapshots e compara `reviewed_hash` antes da transação; a transação só começa em `:147` e grava estado de conclusão e limpa `users.financial_review_required` em `:148-153`, sem repetir a comparação dentro dela nem coordenar escritores das tabelas financeiras.
**Impacto:** uma alteração entre validação e gravação pode deixar a revisão concluída sem refletir o estado efetivo no momento da conclusão.
**Recomendação:** verificar hashes e concluir numa estratégia transacional/concorrente coordenada com os caminhos de escrita, limpando a flag apenas se a validação for serializável em relação às alterações.

#### Estorno de pagamento da dívida inicial não desfaz o contador de dívida paga — Contas, cartões e núcleo financeiro
**Evidência:** `FinancialCoreController.php:413-417` chama `FinancialMovementService::reverse`; `FinancialMovementService.php:280-295` cria reversão sem preservar `debt_id` nem identificar pagamento de saldo inicial e `:296-299` anula o original. `CardInvoiceService.php:135-143` incrementa `contas_financeiras.opening_debt_paid` no pagamento; o estorno não atualiza/recalcula esse contador. O remanescente é `opening_debt_amount - opening_debt_paid` (`FinancialAccountsPageController.php:48`; `CardInvoiceService.php:129-133`).
**Impacto:** o saldo pode ser revertido enquanto o contador continua a considerar o pagamento, subestimando dívida remanescente e afetando interface/validação.
**Recomendação:** associar pagamento inicial a identificador/razão tipada e ajustar atomicamente o contador no estorno; alternativamente, bloquear estorno direto e fornecer correção atómica.

#### Endpoint de update aceita alterar movimentos já liquidados — Contas, cartões e núcleo financeiro
**Evidência:** `routes/web.php:73` aponta `PUT /api/financial/movements/{id}` a `FinancialCoreController::updatePendingMovement` (`:483-518`), que chama `FinancialMovementService::updatePending` (`:495`). Apesar do nome, o serviço aceita `settled` e `pending` (`FinancialMovementService.php:199-219`) e altera montante/data/descrição/categoria sem validar pagamentos associados ou criar contra-lançamento.
**Impacto:** uma chamada direta pode alterar retroativamente movimento liquidado, inclusive compra ligada a fatura paga, divergindo saldo e valor de fatura.
**Recomendação:** restringir no serviço a movimentos `pending` sob lock; alterações a liquidados devem passar por correção/estorno que trate pagamentos e totais de fatura atomicamente.

#### É possível pagar prestação de compromisso cancelado — Compromissos, prestações e recorrências
**Evidência:** `FinancialCommitmentService.php:86-96` carrega/bloqueia a prestação, mas não verifica estado cancelado da dívida nem rejeita prestação `cancelled`. `FinancialCommitmentService.php:75-82` cancela a dívida e altera prestações pending/partial para cancelled sem as eliminar; `FinancialCoreController.php:37-39` resolve relações do utilizador e chama o serviço.
**Impacto:** um pedido posterior pode criar movimento expense liquidado e aumentar `paid_amount` de obrigação encerrada.
**Recomendação:** sob transação e locks compatíveis, bloquear a dívida e rejeitar dívida `cancelled`/`paid` e prestações `cancelled`.

#### Pagamento inicial de compromisso pode falhar sem impedir o fluxo de sucesso — Despesas e receitas / compromissos
**Evidência:** `resources/views/finance/form.blade.php:134` cria primeiro o compromisso e, se `paid_now=1`, faz segundo fetch para pagar, mas não verifica `response.ok` nem trata falha antes do redirecionamento. `FinancialCoreController.php:30-39` processa pagamento separadamente; `FinancialCommitmentService.php:90-96` pode rejeitar conta ou valor. O formulário permite seleção de conta geral (`form.blade.php:103`), incluindo cartão, enquanto `payInstallment` só aceita `bank/cash`.
**Impacto:** o compromisso pode permanecer pendente embora a interface saia como se o fluxo tivesse sucesso; os dois pedidos não partilham transação.
**Recomendação:** restringir conta a banco/dinheiro elegíveis, validar valor, verificar e apresentar falha do segundo pedido; preferir operação transacional ou comunicar claramente o estado pendente.

### Severidade média

#### Formulário servido regista sem confirmação e aceita mínimo de seis caracteres — Autenticação
**Evidência:** `routes/web.php:22` serve `auth.login` em `/registo`, não `auth.register`. O formulário efetivo exige nome/email/password e define `minlength=6`, sem confirmação (`resources/views/auth/login.blade.php:87-103,109-123`); `AccountController.php:18-20` usa `PasswordRule::min(6)` e não valida confirmação. A vista alternativa `resources/views/auth/register.blade.php:47-56` pede confirmação e oito caracteres, mas não é destino do GET.
**Impacto:** erro de digitação pode bloquear imediatamente utilizador; passwords de seis/sete caracteres são aceites apesar da proteção aparente da vista alternativa.
**Recomendação:** usar uma única vista, alinhar regra de servidor e UI e validar confirmação com testes.

#### Pedido de recuperação diferencia email existente de inexistente — Autenticação
**Evidência:** `AccountController@sendReset` devolve status apenas em `RESET_LINK_SENT` e os restantes estados como erros (`AccountController.php:25-28`); a vista diferencia status/erros (`resources/views/auth/forgot.blade.php:10-15`).
**Impacto:** visitantes podem enumerar contas por endereço email.
**Recomendação:** resposta e fluxo equivalentes para existentes/inexistentes; detalhes apenas em logs protegidos; testes de não enumeração.

#### Inicialização lazy do registo de revisão pode colidir em GET concorrente — Revisão inicial de dados financeiros
**Evidência:** `FinancialDataReviewController.php:56-69` consulta e insere sem transação, tratamento de violação única ou upsert. A migração `2026_11_15_232000_create_financial_data_review.php:19-22` impõe `user_id` único.
**Impacto:** dois primeiros GET simultâneos podem causar violação de unicidade e erro de servidor num deles.
**Recomendação:** usar inicialização concorrente segura (upsert/first-or-create com recuperação e releitura) e manter a constraint única.

#### Bootstrap do conversor no dashboard tem script aninhado/inválido — Dashboard e movimentos
**Evidência:** `resources/views/dashboard.blade.php:113-117` abre `<script>` em `:114` e contém outro `<script>window.meuBolsoHome ...</script>` em `:115` sem fechar o wrapper. O fecho termina o primeiro elemento, deixando o segundo `<script>` como código JavaScript inválido; `dashboard-home.js:21` recorre a EUR quando configuração falta.
**Impacto:** conversão pode usar EUR como moeda base para utilizadores com outra moeda.
**Recomendação:** remover wrapper redundante ou separar scripts corretamente e testar utilizador não-EUR.

#### Campos de pagamento apresentados não são persistidos em criação de despesa paga — Dashboard e movimentos
**Evidência:** `resources/views/movements.blade.php:175-183` expõe `payment_date`, `payment_account_id` e `actual_payment_method`; `public/js/dashboard.js:794-804` envia `payment_account_id`, mas `FinanceController.php:296-309` remove `payment_date` e `actual_payment_method`, e `canonicalPayload` usa `expense_date` como `movement_date` (`:154-172,301-309`); `payment_amount` também é removido.
**Impacto:** movimento liquidado pode ficar com data da despesa e sem método efetivo, diferindo dos dados inseridos.
**Recomendação:** definir/persistir semântica de datas e método ou remover controlos não suportados; acrescentar teste de integração para `paid_now=1`.

#### Categoria de tipo incompatível pode ser associada a movimento — Rota financeira
**Evidência:** `resources/views/financial-route.blade.php:233,282` filtra opções no cliente; `FinancialCoreController.php:321-333` e `:485-492` validam propriedade/existência da categoria, mas não correspondência de `categories.type` com o tipo do movimento.
**Impacto:** pedido manipulado pode gravar classificação contraditória e prejudicar listagens/relatórios.
**Recomendação:** validar no servidor ownership e compatibilidade do tipo em POST/PUT, permitindo `null` quando apropriado.

#### Observação enviada na criação é descartada — Rota financeira
**Evidência:** `resources/views/financial-route.blade.php:116` define `notes`, payload inclui o campo em `:287`; validação de store em `FinancialCoreController.php:321-333` não inclui `notes`, e apenas campos validados chegam ao serviço (`:336,350`). Edição aceita notas em `:485-492`; serviço grava-as se recebidas (`FinancialMovementService.php:30-52,66-84`).
**Impacto:** notas de criação desaparecem sem aviso, embora edição as possa guardar.
**Recomendação:** incluir `notes` na validação POST com limites coerentes com coluna e regra equivalente no PUT.

#### Reenvio do formulário da rota financeira cria lançamentos duplicados — Rota financeira
**Evidência:** `resources/views/financial-route.blade.php:283-292` impede submit HTML mas não desativa botão nem marca envio em curso; cada execução faz POST. `FinancialCoreController.php:319-352` cria por pedido sem idempotência; a migração `2026_11_12_190100_create_financial_core_tables.php:113-154` não impõe unicidade para lançamentos normais (apenas vínculo legacy em `:136-138`).
**Impacto:** duplo clique, reenvio ou repetição de rede pode duplicar movimento/efeito financeiro.
**Recomendação:** desativar botão e implementar idempotência persistente por operação/utilizador.

#### Reenvio de pagamento parcial pode criar pagamentos distintos — Compromissos, prestações e recorrências
**Evidência:** `FinancialCoreController.php:30-40` não recebe chave idempotente; `FinancialCommitmentService.php:94-109` aceita cada valor até ao saldo restante e cria movimento por pedido. Lock evita ultrapassar saldo final, mas não identifica repetição parcial. `commitments.blade.php:209-220` só desativa botão no cliente.
**Impacto:** timeout/reenvio pode debitar duas vezes um valor parcial ainda permitido pelo saldo.
**Recomendação:** chave de idempotência persistida, constraint única e retorno da resposta anterior; UI é proteção complementar.

#### POST repetido cria regras de recorrência duplicadas — Compromissos, prestações e recorrências
**Evidência:** `FinancialCoreController.php:70-85` encaminha cada POST a `RecurrenceService::create`; `RecurrenceService.php:29-45` cria sempre regra nova. `generateRule.php:219-240` deduplica somente dentro da mesma regra por `recurrence_id + recurrence_occurrence_date`; não há idempotência/uniqueness para reenvio da criação.
**Impacto:** repetição pode criar regras e previsões concorrentes com datas/descrições iguais.
**Recomendação:** idempotência por utilizador/operação e geração serializada por regra.

### Severidade baixa

#### Validação de ícone aceita identificadores não renderizados — Categorias
**Evidência:** `CategoriaController.php:92` valida padrão de caracteres, não pertença a catálogo Lucide; a vista usa `data-lucide` (`resources/views/categorias/index.blade.php:58`) e o catálogo client-side limita opções (`:172-178`). `CategoryManagementTest.php:29-34` aceita `bi-heart`, e migração `2026_11_11_150000_create_categorias_table.php:30-45` semeia nomes `bi-*`.
**Impacto:** ícones podem não aparecer, inclusive para categorias semeadas.
**Recomendação:** lista permitida partilhada ou conversão dos valores legados; testar desconhecidos e dados semeados.

## Gaps e pendências — não são findings

As observações desta secção delimitam o que não foi verificado; não constituem defeitos confirmados.

### Gerais
- Análise estática; sem execução de suite, browser, requisições com sessão real ou testes de concorrência, salvo que testes existentes foram lidos como evidência.
- Não se afirma cobertura de todo o repositório: cada área se limita ao âmbito e rotas enumerados.
- Deployment, HTTPS, cookies finais, `.env`, infraestrutura de mail e backend de rate-limit/cache não foram confirmados operacionalmente quando indicado.
- Restrições físicas do banco, comportamento sob nível de isolamento de produção e consistência concorrente não podem ser inferidos além do que as leituras de código/migrações citadas demonstram.

### Autenticação e revisão financeira
- CSRF observado nos formulários e middleware `web`, sem bypass identificado; não houve teste de abuso CSRF em runtime.
- Envio real de email de recuperação não verificado; expiração/throttle do broker foram observados na configuração, mas limites não foram testados sob concorrência.
- Endpoint `/session/activity` responde `204` e não é chamado pelo JS lido; não foi estabelecido requisito de renovação/telemetria. Temporizador cliente de 15 minutos difere da sessão configurada em 120 minutos, sem intenção de produto confirmada.
- Revisão: não foram auditados todos os caminhos de escrita financeiros que possam concorrer, nem comprovada a suficiência semântica de todos os campos selecionados nos hashes. Não foram encontrados testes dedicados de revisão nos resultados da busca; a suite não foi executada.
- Evidência por etapa e snapshot não foi testada sob concorrência/perda de atualização; isolamento de produção desconhecido.

### Dashboard e rota financeira
- Filtro de conta e pesquisa no dashboard são locais aos movimentos carregados; não são transmitidos ao endpoint. Não se confirmou comportamento com dados concorrentes.
- Migrations, `CardInvoiceService`, `RecurrenceService`, `FinancialCommitmentService`, modelos completos e todos os efeitos de recorrência/cartão não foram cobertos pela unidade dashboard.
- Endpoints confirm/void/reverse e outros endpoints foram enumerados/handlers conferidos, mas não invocados pelos formulários auditados.
- Rota financeira: ownership e validação de contas foram rastreados nos serviços; não foram executados testes. Não se inferiram requisitos de negócio para categorias sem tipo ou demais campos além das definições lidas.

### Contas, cartões e núcleo financeiro
- Não foram executados testes concorrentes nem auditadas integralmente migrations/constraints/configuração operacional. Serviços com transações e locks foram observados; isto não prova ausência de todas as corridas.
- A política `assertCanDebit` é intencionalmente vazia conforme comentário do serviço; não foi tratada como bug sem requisito que determine o contrário.
- Algumas ações JavaScript residuais não têm elemento correspondente na vista; não foram tratadas como fluxos expostos.
- Endpoints exclusivos de compromissos ficaram fora desta unidade; há sobreposição transversal com despesas deferred/parceladas e movimentos recorrentes, descrita no âmbito.

### Compromissos, recorrências, categorias e CRUD legacy
- Ownership, regras de montante, transações e várias proteções foram observadas por leitura; não houve exploração dinâmica nem cobertura integral de todos os caminhos alternativos.
- Testes de recorrência e pagamento foram consultados, não executados; não foi auditada toda sincronização por jobs ou configuração de produção.
- A lista de compromissos mostra a página corrente (20 por página); paginação permite alcançar outras páginas, e não se confirmou impacto além da apresentação.
- Em categorias, ownership de update/toggle/delete e concorrência em colisões únicas não foram testados em execução; não foram percorridos todos os writers de movimentos para provar ausência de caminhos alternativos.
- Nos CRUD legacy, não foi auditado o comportamento integral de recorrências/sincronização; modal e comportamento dependente de `form.dataset.modal` não foram confirmados em runtime.

## Conclusão de auditoria

As oito áreas produziram cobertura estática substancial e uma lista delimitada de 16 problemas confirmados únicos. As correções de severidade alta foram implementadas e passaram nos testes direcionados descritos. Nesta sessão, foram também corrigidos o harness CSRF dos testes, a persistência de despesas legacy com conta selecionada, a serialização de datas do endpoint de movimentos e a remoção recorrente sem anular movimentos liquidados. A suíte completa ficou em **109 testes passados, 5 skipped e 495 assertions**; os cinco skipped são constraints `CHECK` específicas de `ALTER TABLE` não suportadas pelo SQLite local. O teste de aceitação ficou em **19 testes passados e 92 assertions**.

Continuam pendentes os testes concorrentes multi-conexão, a validação operacional no motor de produção e a reconciliação de dados reais. As leituras legacy não foram removidas e a Fase 3 não é declarada concluída. Os gaps acima permanecem pendências de verificação, não bugs confirmados.

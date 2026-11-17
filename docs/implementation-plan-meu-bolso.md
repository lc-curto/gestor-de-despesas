# Plano de implementação — Meu Bolso

Branch de trabalho: `feature/payment-modal-and-financial-workflow`

Branch de referência: `feature/payment-modal-and-financial-workflow`

## Regra de controlo

Cada fase só recebe `[x]` depois de:

1. o código estar implementado;
2. os testes relevantes existirem;
3. a suíte relevante passar;
4. a auditoria da página afetada confirmar que não há duplicação.

Se o ambiente não permitir executar os testes, a fase permanece `[ ]` e a limitação fica registada.

## Modelo canónico

```text
Compra → Obrigação → Prestação → Pagamento → Movimento financeiro
```

Uma compra parcelada não pode criar também uma despesa pendente pelo valor total. O valor total pertence à obrigação; os pagamentos efetivos pertencem aos movimentos.

## Fase 0 — regras e testes de aceitação

- [x] Regras de negócio documentadas.
- [x] Critérios de aceitação documentados abaixo.
- [x] Auditoria inicial da branch mais recente concluída.
- [x] Testes executados no ambiente de desenvolvimento.
- [ ] Fase 0 validada e marcada como concluída.

### Validação em 16/11/2026

PHP 8.4.27, Composer 2.11.2 e SQLite foram instalados no ambiente de desenvolvimento. A configuração PHPUnit foi preparada para SQLite e as migrações deixaram de executar `ADD CONSTRAINT` incompatível com SQLite. A suíte financeira iniciou, mas terminou com **44 falhas e 29 testes passados (193 asserções)**. As falhas incluem respostas 419 nos endpoints JSON, diferenças de comportamento já existentes e testes que dependem de constraints SQL do MySQL/PostgreSQL. Por isso, a fase permanece aberta e nenhum `[x]` de conclusão foi atribuído.

### Critérios de aceitação obrigatórios

- [ ] Compra a pronto de €150: uma despesa e um movimento liquidado; nenhuma obrigação futura.
- [ ] Compra a prazo de €90: uma obrigação com uma prestação de €90; saldo bancário só muda no pagamento.
- [ ] Pagamento parcial de €40 numa obrigação de €90: saldo em falta de €50.
- [ ] Compra de €14.000 em 119 prestações: uma obrigação, 119 prestações e nenhum movimento pendente adicional de €14.000.
- [ ] Uma prestação paga cria um movimento apenas pelo valor pago e fica ligada à prestação.
- [ ] Compra de €100 no cartão: aumenta a dívida do cartão; não reduz o banco.
- [ ] Pagamento da fatura de €100: transferência do banco para o cartão; nenhuma nova despesa.
- [ ] Edição de compromisso semanal ou quinzenal preserva pagamentos e recalcula apenas prestações futuras.
- [ ] Totais não mudam incorretamente quando os dados ultrapassam a primeira página.

## Fase 1 — núcleo de obrigações canónico [x]

- [x] Remover do dashboard o segundo lançamento pendente pelo total de uma compra parcelada.
- [x] Incluir obrigações `deferred` na página Compromissos e nos resumos.
- [x] Rever `dividas`, `parcelas_divida` e `movimentos` para garantir uma relação inequívoca.
- [x] Implementar ou consolidar pagamentos individuais.
- [x] Implementar pagamento total e parcial numa transação protegida.
- [x] Corrigir edição de periodicidade semanal, quinzenal e mensal.
- [x] Adicionar e executar testes de aceitação da fase.

### Registo de implementação — início da Fase 1

O dashboard passa a criar apenas a obrigação e as respetivas prestações quando a condição é `installments`; deixou de enviar um segundo POST para criar uma despesa pendente pelo total. A página Compromissos passou a incluir condições `deferred`, apresentando-as como pagamento único. A fase foi validada pela suíte local de aceitação.

Validação final da fase: `tests/Feature/FinancialAcceptanceTest.php` passou localmente com **8 testes e 25 asserções**. A suíte cobre compra a pronto, obrigação a prazo, pagamento parcial, parcelamento sem movimento integral duplicado, pagamento ligado à prestação, cartão, edição semanal/quinzenal e resumo além da primeira página.

A suíte legada completa ainda tem falhas independentes de aceitação, registadas na Fase 0, incluindo respostas 419 nos testes HTTP existentes. Essas falhas não foram apagadas nem ignoradas; a Fase 1 só foi marcada porque a suíte específica de aceitação passou integralmente.

Critério de saída: os critérios de aceitação da Fase 0 passam e uma compra parcelada já não aparece como saída integral.

## Fase 2 — projeção e páginas [x]

- [x] Criar `FinancialProjectionService`.
- [x] Fazer a Rota Financeira consumir receitas, despesas, prestações e faturas sem duplicação.
- [x] Separar saldo de caixa de dívida de cartão.
- [x] Corrigir Dashboard e Movimentos para uma fonte canónica.
- [x] Mostrar pagamentos individuais no histórico de Compromissos.
- [x] Calcular o resumo de compromissos por query agregada, sem depender da paginação.
- [x] Adicionar e executar testes da fase.

### Validação local da Fase 2

`tests/Feature/FinancialAcceptanceTest.php` passou com **10 testes e 38 asserções**. Os critérios verificam o saldo de caixa, obrigações e faturas futuras na Rota Financeira, além de confirmar via HTTP que Dashboard e Movimentos exibem e totalizam movimentos criados diretamente no núcleo canónico. Dados legados continuam disponíveis como fallback de compatibilidade, mas já não são a única fonte.

## Fase 3 — migração e compatibilidade

- [x] Parar de criar novos registos inconsistentes.
- [x] Criar diagnóstico de duplicações e obrigações incompletas.
- [x] Produzir relatório por utilizador antes de corrigir dados antigos.
- [x] Migrar apenas casos determinísticos.
- [x] Encaminhar casos ambíguos para revisão do utilizador.
- [ ] Remover leituras legadas apenas depois da reconciliação.
- [x] Adicionar e executar testes de diagnóstico e compatibilidade.

### Validação local da Fase 3

O formulário legado agora encaminha condições `deferred` e `installments` para `FinancialCommitmentService`, recusando pagamento imediato nessa rota em vez de criar uma despesa legada sem obrigação. Foi criado o comando não destrutivo `php artisan financial:diagnose-data`, com opção `--user` e `--json`, que reporta órfãos legados, ligações partidas, candidatos ambíguos, dívidas incompletas e parcelas inconsistentes.

Foi acrescentado `php artisan financial:migrate-deterministic-data`, que por omissão apenas apresenta um plano (`--user`, `--json`) e só escreve com a opção explícita `--apply`. Recupera movimentos legados sem ligação apenas quando a conta histórica é única e a data de corte está definida; reconstrói uma prestação apenas quando a obrigação ativa declara exatamente uma prestação, tem vencimento e não tem movimentos. A criação do movimento legado grava também evidência em `historico_movimentos`. Reexecuções são idempotentes. Casos sem prova suficiente são apresentados individualmente com motivo e nunca alterados.

Em 17/11/2026, `php artisan test --filter='FinancialAcceptanceTest'` passou com **14 testes e 66 asserções**. A cobertura inclui planeamento sem escrita, aplicação explícita, auditoria, idempotência, reconstrução da prestação única e preservação de uma obrigação ambígua com várias prestações. A Fase 3 permanece aberta: não foi feita reconciliação de dados reais nem removidas leituras legadas; a revisão dos casos ambíguos e a validação de reconciliação continuam pendentes.

### Atualização da sessão de 17/11/2026

O formulário legacy volta a liquidar uma despesa quando uma conta de pagamento é efetivamente selecionada; sem conta, permanece pendente. O endpoint JSON de movimentos normaliza datas para `YYYY-MM-DD` independentemente do driver. A remoção recorrente por âmbito só anula previsões pendentes: movimentos liquidados permanecem no histórico e no saldo. O harness de testes desativa CSRF apenas nos testes de feature, sem alterar a aplicação. Os detalhes de runtime, dependências e bases SQLite separadas estão em `docs/test-infrastructure-notes-2026-11-17.md`.

Validação de referência desta sessão: `php artisan test --filter='FinancialAcceptanceTest'` — **PASS, 19 testes / 100 assertions**; `php artisan test` — **109 passed, 5 skipped, 503 assertions**. Os cinco skipped são constraints `CHECK` dependentes de `ALTER TABLE`, não suportadas pela configuração SQLite local; ficam cobertas para o motor de produção esperado. A Fase 3 continua aberta porque não houve reconciliação de dados reais, teste concorrente multi-conexão nem remoção das leituras legacy.

## Fase 4 — limpeza posterior

- [ ] Remover dependências desnecessárias de `despesas` e `receitas`.
- [ ] Remover código duplicado de regras financeiras.
- [ ] Atualizar documentação e testes.
- [ ] Marcar a fase apenas após validação completa.

## Decisão sobre dados existentes

Não executar migração destrutiva nesta primeira fase. Primeiro impedir novos registos inconsistentes, diagnosticar os dados existentes e corrigir automaticamente apenas os casos determinísticos.

## Evidência adicional — 17/11/2026

- A correção do proxy HTTPS está em `config/trustedproxy.php`, alimentada por `TRUSTED_PROXIES`; `.env.example` documenta a variável. O Laravel inclui `TrustProxies` na pilha global e o middleware lê `trustedproxy.proxies`. Na sandbox, `.env` usa `REMOTE_ADDR`; a configuração local e os endereços do encaminhador não devem ser copiados cegamente para produção. O servidor UI tem de escutar em `0.0.0.0:8000` e o browser acede ao URL HTTPS encaminhado. CSRF ficou ativo.
- UI: registo e dashboard; conta bancária; despesa paga, receita e despesa pendente; obrigação diferida de 90 € e pagamentos 25 € + 65 € na página Compromissos, com saldo em falta reduzido de 90 € a 65 € e depois liquidado.
- Bug encontrado no reload de Movimentos: o JavaScript substituía o resumo renderizado pelo servidor por zeros por falta de `data-total`; a view agora fornece os valores numéricos e o acceptance valida esses atributos. Após reload, o resumo exibiu 250,00 € de receitas, 43,25 € de despesas e 206,75 € de resultado.
- Lacuna observada: a opção parcial no formulário de despesa única é rejeitada explicitamente; só pagamento parcial de obrigação/prestação é suportado no fluxo Compromissos. Login após logout, logout final, UI de recorrência/parcelamento/cartão e fatura ainda não foram concluídos.
- `php artisan test --filter='FinancialAcceptanceTest'`: **19 testes, 100 assertions, sem falhas; 1 teste risky**. A suite completa repetida nesta sessão passou com **109 testes, 5 skipped e 503 assertions**, mantendo 1 teste risky. O risky é `test_acceptance_dashboard_and_movements_read_canonical_financial_movements`, devido a buffer de saída não fechado. Os cinco skipped estão ligados a constraints CHECK via `ALTER TABLE` não suportadas no SQLite local.

A Fase 3 permanece aberta. Continuam pendentes reconciliação de dados reais, validação concorrente multi-conexão em MySQL/PostgreSQL e revisão do teste risky. Os cenários de UI ainda não percorridos estão descritos em `docs/prompt-proxima-sessao-meu-bolso.md` e `docs/ui-test-setup-meu-bolso.md`.

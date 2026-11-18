# Auditoria de interface — Mobile-first

## Objetivo e limites

- Rever todas as views Blade e os estilos partilhados.
- Corrigir responsividade mobile e consistência dos componentes.
- Preservar a estrutura, identidade visual e fluxos existentes.
- Usar Bootstrap 5 e CSS próprio compatível; não introduzir Tailwind.
- Não alterar regras financeiras, rotas, validações ou comportamento funcional.

## Superfície auditada

Views:
- `welcome`
- `layouts/app`, `layouts/guest`, `components/navigation-links`
- `auth/login`, `auth/register`, `auth/forgot`, `auth/reset`
- `dashboard`, `movements`, `finance/index`, `finance/form`
- `financial/accounts`, `financial/account-show`, `financial/cards`
- `financial/commitments`, `financial/invoices`, `financial-route`
- `categorias/index`, `financial-data-review`

Estilos e scripts de interface:
- `public/css/bootstrap.min.css`
- `public/css/styles.css`
- `public/css/app-shell.css`
- `public/css/design-system.css`
- `resources/css/app.css` e `resources/js/app.js` (rota de boas-vindas)
- JavaScript de dashboard, navegação, cartões e parcelamentos.

## Achados

1. As regras de responsividade estão distribuídas por vários CSS e por blocos `<style>` dentro das views, com breakpoints de 380, 575, 640, 767, 991 e 1199 px.
2. Há regras repetidas e sobrescritas, especialmente no shell, contas, rota financeira e parcelamentos. Isso dificulta prever o resultado final em cada viewport.
3. Alguns rodapés de modal e grupos de ações não permitem quebra de linha consistente. Em ecrãs estreitos, rótulos extensos podem comprimir os botões ou provocar desalinhamento.
4. Existem botões com alturas e dimensões diferentes, sobretudo entre ações principais, ações secundárias e controlos compactos.
5. A página de boas-vindas era a única interface a carregar o template Tailwind do Laravel. Foi convertida para o layout de convidado Bootstrap e o plugin Tailwind foi removido do pipeline Vite. As dependências de desenvolvimento Tailwind ainda permanecem no package.json/package-lock e podem ser removidas numa limpeza posterior.
6. A rota financeira aplica regras mobile genéricas a `.modal-dialog` no CSS da própria view. O escopo deve ser limitado aos modais dessa funcionalidade para não afetar componentes não relacionados.
7. As views de contas, cartões, parcelamentos e movimentos têm regras mobile específicas; precisam ser verificadas em conjunto com os estilos globais para evitar regressões.

## Ajustes iniciais nesta branch

- Criar uma camada responsiva partilhada, carregada depois do design system.
- Converter a página de boas-vindas para o layout Bootstrap e retirar Tailwind do pipeline Vite.
- Normalizar alvos de toque para ações iconográficas em listas e cartões.
- Normalizar alinhamento, quebra e dimensões dos botões sem impor empilhamento universal.
- Manter grupos de botões horizontais quando existe espaço e permitir quebra natural quando não existe.
- Garantir que rodapés de modais e formulários não comprimem nem sobrepõem ações.
- Preservar explicitamente layouts que já são intencionais, como ações de exclusão empilhadas e grelhas de ações.

## Critérios de aceitação

- Sem scroll horizontal acidental em larguras de 320, 360, 390, 768 e 1024 px.
- Nenhum botão sobreposto, cortado ou sem área de toque utilizável.
- Ações relacionadas permanecem lado a lado quando cabem; quebram de forma ordenada quando não cabem.
- Botões primários, secundários, compactos e iconográficos mantêm hierarquia visual consistente.
- Formulários, listas, filtros, cartões, modais, navegação e estados vazios mantêm legibilidade.
- Testes JavaScript e testes Laravel continuam a passar.
- Nenhuma alteração a regras de negócio ou operações financeiras.

## Estado

Auditoria estática das 20 views concluída. Foram aplicados os primeiros ajustes globais e de escopo. A validação visual em dispositivos reais/emuladores, a revisão final de cada breakpoint e a execução dos testes continuam pendentes.

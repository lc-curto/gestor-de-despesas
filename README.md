# Meu Bolso — Gestor de Despesas Pessoais

## Descrição do projeto

O **Meu Bolso** é uma aplicação web de gestão de finanças pessoais, desenvolvida no âmbito do projeto final da formação **Software Developer do CESAE Digital**.

A aplicação permite acompanhar receitas e despesas num único espaço, consultar o saldo de um período, organizar movimentos por categorias e gerir os registos associados à conta de cada utilizador.

O projeto foi desenvolvido com HTML, CSS e JavaScript, integrando serviços externos para autenticação, persistência de dados e consulta de taxas de câmbio. Inclui ainda funcionalidades de aplicação web progressiva (PWA), permitindo a instalação no dispositivo e o acesso a recursos previamente colocados em cache.

## Funcionalidades

- Criação de conta e autenticação de utilizadores.
- Gestão da sessão através do Supabase Auth.
- Seleção da moeda principal da conta: EUR, BRL, USD ou GBP.
- Registo, consulta, edição e eliminação de despesas.
- Registo, consulta, edição e eliminação de receitas.
- Histórico de movimentos com seleção de período.
- Filtros por categoria e tipo de movimento.
- Carregamento paginado dos registos.
- Resumo financeiro com total de despesas, total de receitas e saldo do período.
- Validação dos campos e dos valores introduzidos nos formulários.
- Alternância entre tema claro e tema escuro, com preferência guardada no navegador.
- Apresentação da data e hora localizadas para português.
- Conversor de moedas com consulta de taxas de câmbio através da API Frankfurter.
- Controlo de inatividade, com aviso e encerramento automático da sessão após 15 minutos.
- Comportamento offline limitado, através de cache dos recursos da aplicação e dos dados anteriormente carregados.
- Instalação como PWA em navegadores compatíveis.

## Tecnologias e serviços utilizados

- **HTML5** — estrutura e semântica das páginas.
- **CSS3** — estilos e adaptação da interface a diferentes dimensões de ecrã.
- **Bootstrap 5.3** — componentes e sistema de grelha responsivo.
- **Bootstrap Icons** — iconografia da interface.
- **JavaScript (ES Modules)** — lógica da aplicação, eventos, manipulação do DOM e organização por módulos.
- **Supabase Auth** — criação de contas e autenticação.
- **Supabase Database (PostgreSQL)** — armazenamento dos movimentos financeiros.
- **Frankfurter API** — consulta de taxas de câmbio.
- **Service Worker e Web App Manifest** — funcionalidades PWA e cache de recursos.
- **Git e GitHub** — controlo de versões e alojamento do código.
- **GitHub Pages** — publicação da aplicação.

## Organização do projeto

A lógica JavaScript está dividida por módulos, de acordo com as responsabilidades de cada componente:

```text
gestor-de-despesas/
├── index.html
├── manifest.json
├── service-worker.js
└── assets/
    ├── css/
    │   └── styles.css
    ├── images/
    │   ├── favicon.png
    │   └── pwa-icon.svg
    └── js/
        ├── main.js
        ├── auth.js
        ├── despesas.js
        ├── receitas.js
        ├── api.js
        ├── supabase.js
        └── tema.js
```

### Responsabilidade dos módulos

- **main.js** — coordena a aplicação, gere o estado da interface, os formulários, os filtros, a apresentação dos dados e os eventos.
- **auth.js** — trata da autenticação, verificação da sessão e monitorização de inatividade.
- **despesas.js** — disponibiliza as operações de consulta e gestão das despesas na base de dados.
- **receitas.js** — disponibiliza as operações de consulta e gestão das receitas na base de dados.
- **api.js** — comunica com a API Frankfurter para obter taxas de câmbio.
- **supabase.js** — configura e exporta o cliente Supabase utilizado pelos restantes módulos.
- **tema.js** — inicializa, aplica e alterna entre os temas claro e escuro.

## Como utilizar

1. Abrir a aplicação através da ligação de acesso.
2. Criar uma conta com nome, endereço de e-mail, palavra-passe e moeda principal.
3. Iniciar sessão com as credenciais da conta.
4. Consultar o resumo financeiro apresentado no painel principal.
5. Escolher entre **Despesa** e **Receita** para criar um movimento.
6. Preencher a descrição, o valor, a categoria e a data e guardar o registo.
7. Consultar o histórico e selecionar o período pretendido.
8. Aplicar os filtros disponíveis para visualizar despesas, receitas ou movimentos de uma categoria.
9. Utilizar as ações de edição e eliminação para gerir os movimentos existentes.
10. Consultar taxas de câmbio no conversor, selecionando as moedas de origem e destino.
11. Alterar o tema da interface através do botão correspondente.
12. Terminar a sessão através do menu da conta.

## Dados e persistência

A autenticação é assegurada pelo Supabase Auth. Os movimentos financeiros são armazenados nas tabelas `despesas` e `receitas` da base de dados PostgreSQL do Supabase, associados ao identificador do utilizador autenticado.

As operações de leitura e escrita são realizadas pelos módulos específicos de despesas e receitas. A aplicação volta a carregar os dados após as operações de gestão para manter o histórico e os totais sincronizados com a base de dados.

A preferência de tema é guardada no `localStorage` do navegador. A sessão de autenticação utiliza o armazenamento de sessão configurado no cliente Supabase.

## PWA e funcionamento offline

O projeto inclui um Web App Manifest e um Service Worker. Em navegadores compatíveis, a aplicação pode ser instalada no dispositivo.

O Service Worker mantém em cache os principais recursos da interface e tenta servir recursos previamente armazenados quando não existe ligação à Internet. Os dados financeiros em cache correspondem apenas a informação anteriormente carregada; operações que alteram registos, como criar, editar ou eliminar, exigem ligação à Internet.

## Acesso à aplicação

**Aplicação publicada:**  
https://lc-curto.github.io/gestor-de-despesas/

**Repositório GitHub:**  
https://github.com/lc-curto/gestor-de-despesas

## Projeto académico

**Projeto Final — Software Developer**  
CESAE Digital · 2026

<!doctype html>
<html lang="pt-PT" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#635bff">
    <title>@yield('title', 'Meu Bolso') · Meu Bolso</title>
    <link rel="icon" href="{{ asset('images/pwa-icon.svg') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app-shell.css') }}?v={{ filemtime(public_path('css/app-shell.css')) }}">
    @stack('styles')
</head>
<body>
    <div id="offlineStatus" class="alert alert-warning rounded-0 mb-0 d-none" role="status" aria-live="polite"></div>

    <div class="app-shell">
        <button type="button" class="mobile-sidebar-open" id="mobileSidebarOpen"
            aria-label="Abrir menu lateral" aria-controls="appSidebar" aria-expanded="false" title="Abrir menu">
            <i data-lucide="menu" aria-hidden="true"></i>
        </button>

        <aside class="app-sidebar" id="appSidebar" aria-label="Navegação lateral">
            <div class="app-sidebar-brand">
                <a class="app-brand" href="{{ route('dashboard') }}">
                    <span class="brand-mark" aria-hidden="true"><i data-lucide="wallet"></i></span>
                    <span class="app-brand-copy">
                        <strong>Meu Bolso</strong>
                        <small>Gestão Financeira Pessoal</small>
                    </span>
                </a>
                <button type="button" class="btn btn-outline-secondary sidebar-toggle" id="sidebarToggle"
                    aria-label="Recolher menu lateral" aria-controls="appSidebar" aria-expanded="true" title="Recolher menu lateral">
                    <i data-lucide="panel-left-close" aria-hidden="true"></i>
                </button>
            </div>

            <x-navigation-links />

            <div class="app-sidebar-footer">
                <div class="sidebar-utility-actions">
                    <button type="button" class="btn btn-outline-secondary sidebar-utility-button d-none"
                        id="installAppButton" aria-label="Instalar aplicação" title="Instalar aplicação">
                        <i data-lucide="download" aria-hidden="true"></i><span>Instalar aplicação</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary sidebar-utility-button"
                        id="themeToggle" aria-label="Alternar tema" title="Alternar tema">
                        <i data-lucide="moon-star" aria-hidden="true"></i><span>Tema</span>
                    </button>
                </div>

                <div class="dropdown account-dropdown dropup">
                    <button type="button" class="account-profile-button" id="accountMenuButton"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Abrir menu da conta">
                        <span class="account-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="account-profile-copy">
                            <strong>{{ auth()->user()->name }}</strong>
                            <small>Ver perfil e sessão</small>
                        </span>
                        <i data-lucide="chevron-up" class="account-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow account-dropdown-menu" aria-labelledby="accountMenuButton">
                        <div class="px-3 py-2">
                            <p class="fw-semibold mb-1">{{ auth()->user()->name }}</p>
                            <p class="small text-secondary mb-0">{{ auth()->user()->email }}</p>
                        </div>
                        <hr class="dropdown-divider">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger rounded-2">
                                <i data-lucide="log-out" class="me-2" aria-hidden="true"></i>Terminar sessão
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </aside>

        <button type="button" class="sidebar-backdrop" id="sidebarBackdrop"
            aria-label="Fechar menu lateral" tabindex="-1"></button>

        <main id="top" class="app-main">
            @if (session('success'))
                <div class="alert alert-success" role="alert">{{ session('success') }}</div>
            @endif
            @if (session('status'))
                <div class="alert alert-info" role="status">{{ session('status') }}</div>
            @endif
            @yield('content')
        </main>
    </div>


    <nav class="mobile-bottom-bar" aria-label="Navegação principal">
        <a class="mobile-bottom-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
            <i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Início</span>
        </a>
        <a class="mobile-bottom-link {{ request()->routeIs('dashboard.movimentos') ? 'active' : '' }}" href="{{ route('dashboard.movimentos') }}" @if(request()->routeIs('dashboard.movimentos')) aria-current="page" @endif>
            <i data-lucide="arrow-left-right" aria-hidden="true"></i><span>Movimentos</span>
        </a>
        <button class="mobile-bottom-link mobile-bottom-new" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNewMenu" aria-controls="mobileNewMenu">
            <span class="mobile-bottom-new-icon"><i data-lucide="plus" aria-hidden="true"></i></span><span>Novo</span>
        </button>
        <a class="mobile-bottom-link {{ request()->routeIs('financial.accounts.*') ? 'active' : '' }}" href="{{ route('financial.accounts.page') }}" @if(request()->routeIs('financial.accounts.*')) aria-current="page" @endif>
            <i data-lucide="wallet-cards" aria-hidden="true"></i><span>Contas</span>
        </a>
        <button class="mobile-bottom-link {{ request()->routeIs('categorias.*', 'financial.commitments.*') ? 'active' : '' }}" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMoreMenu" aria-controls="mobileMoreMenu">
            <i data-lucide="grid-2x2" aria-hidden="true"></i><span>Mais</span>
        </button>
    </nav>

    <div class="offcanvas offcanvas-bottom mobile-action-sheet" tabindex="-1" id="mobileNewMenu" aria-labelledby="mobileNewMenuTitle">
        <div class="offcanvas-header"><h2 class="offcanvas-title h5 fw-bold" id="mobileNewMenuTitle">Novo registo</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button></div>
        <div class="offcanvas-body pt-0">
            <a class="mobile-sheet-action" href="{{ route('despesas.create') }}"><i data-lucide="arrow-down-right" aria-hidden="true"></i><span><strong>Nova despesa</strong><small>Registar um pagamento ou compromisso</small></span></a>
            <a class="mobile-sheet-action" href="{{ route('receitas.create') }}"><i data-lucide="arrow-up-right" aria-hidden="true"></i><span><strong>Nova receita</strong><small>Registar dinheiro recebido</small></span></a>
        </div>
    </div>

    <div class="offcanvas offcanvas-bottom mobile-action-sheet mobile-more-sheet" tabindex="-1" id="mobileMoreMenu" aria-labelledby="mobileMoreMenuTitle">
        <div class="mobile-sheet-handle" aria-hidden="true"></div>
        <div class="offcanvas-header">
            <h2 class="offcanvas-title h5 fw-bold" id="mobileMoreMenuTitle">Mais</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
        </div>
        <div class="offcanvas-body pt-0">
            <nav class="mobile-more-list" aria-label="Mais funcionalidades">
                <a class="mobile-more-item" href="{{ route('financial.commitments.page') }}">
                    <i data-lucide="credit-card" aria-hidden="true"></i>
                    <span><strong>Cartões e compromissos</strong><small>Gerir faturas e pagamentos</small></span>
                    <i data-lucide="chevron-right" class="mobile-more-chevron" aria-hidden="true"></i>
                </a>
                <a class="mobile-more-item" href="{{ route('categorias.index') }}">
                    <i data-lucide="tags" aria-hidden="true"></i>
                    <span><strong>Categorias</strong><small>Gerir categorias</small></span>
                    <i data-lucide="chevron-right" class="mobile-more-chevron" aria-hidden="true"></i>
                </a>
            </nav>
            <div class="mobile-more-utilities">
                <button type="button" class="mobile-more-utility" id="mobileThemeToggle">
                    <i data-lucide="moon" aria-hidden="true"></i><span>Tema</span><i data-lucide="chevron-right" aria-hidden="true"></i>
                </button>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button type="submit" class="mobile-more-logout"><i data-lucide="log-out" aria-hidden="true"></i> Terminar sessão</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="inactivityModal" tabindex="-1" aria-labelledby="inactivityModalTitle"
        aria-describedby="inactivityModalDescription" aria-hidden="true"
        data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-4">
                <div class="modal-body text-center p-4 p-md-5">
                    <i data-lucide="clock-3" class="text-warning fs-1 mb-3" aria-hidden="true"></i>
                    <h2 class="h4 fw-bold mb-3" id="inactivityModalTitle">Ainda estás aí?</h2>
                    <p class="text-secondary mb-3" id="inactivityModalDescription">A tua sessão será encerrada por inatividade. Continua a utilizar a aplicação para manteres a sessão ativa.</p>
                    <div class="display-5 fw-bold text-warning mb-4"><span id="inactivityCountdown">01:00</span></div>
                    <button type="button" class="btn btn-primary w-100" id="continueSessionButton">Continuar sessão</button>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js" async data-lucide-library></script>
    <script src="{{ asset('js/app.js') }}" defer></script>
    @stack('scripts')
</body>
</html>

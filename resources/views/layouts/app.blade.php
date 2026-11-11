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
    <link rel="stylesheet" href="{{ asset('css/app-shell.css') }}">
    @stack('styles')
</head>
<body>
    <div id="offlineStatus" class="alert alert-warning rounded-0 mb-0 d-none" role="status" aria-live="polite"></div>

    <button type="button" class="btn btn-outline-secondary sidebar-toggle" id="sidebarToggle"
        aria-label="Recolher menu lateral" aria-expanded="true" title="Recolher menu lateral">
        <i data-lucide="panel-left-close" aria-hidden="true"></i>
    </button>

    <div class="app-shell">
        <aside class="app-sidebar" id="appSidebar" aria-label="Navegação lateral">
            <div class="app-sidebar-brand">
                <a class="app-brand" href="{{ route('dashboard') }}">
                    <span class="brand-mark" aria-hidden="true"><i data-lucide="wallet"></i></span>
                    <span class="app-brand-copy">
                        <strong>Meu Bolso</strong>
                        <small>Gestão Financeira Pessoal</small>
                    </span>
                </a>
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
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
    <script src="{{ asset('js/app.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
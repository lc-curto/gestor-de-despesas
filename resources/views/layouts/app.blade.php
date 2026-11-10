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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('css/styles.css') }}">
    @stack('styles')
</head>
<body>
    <div id="offlineStatus" class="alert alert-warning rounded-0 mb-0 d-none" role="status" aria-live="polite"></div>

    <nav class="navbar navbar-expand-lg bg-body border-bottom sticky-top" aria-label="Navegação principal">
        <div class="container py-2">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ route('dashboard') }}">
                <span class="brand-mark brand-mark-sm" aria-hidden="true"><i class="bi bi-wallet2"></i></span>
                <span>Meu Bolso</span>
            </a>
            <div class="navbar-actions d-flex align-items-center gap-2">
                <button type="button" class="btn btn-primary navbar-icon-btn d-none" id="installAppButton" aria-label="Instalar aplicação Meu Bolso" title="Instalar aplicação"><i class="bi bi-download"></i></button>
                <button type="button" class="btn btn-outline-secondary navbar-icon-btn" id="themeToggle" aria-label="Alternar entre tema claro e escuro" title="Alternar tema"><i class="bi bi-moon-stars"></i></button>
                <div class="dropdown">
                    <button type="button" class="btn btn-outline-secondary navbar-icon-btn rounded-circle" id="accountMenuButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Abrir menu da conta"><i class="bi bi-person"></i></button>
                    <div class="dropdown-menu dropdown-menu-end p-2 shadow" aria-labelledby="accountMenuButton">
                        <div class="px-3 py-2">
                            <p class="fw-semibold mb-1">{{ auth()->user()->name }}</p>
                            <p class="small text-secondary mb-0">{{ auth()->user()->email }}</p>
                        </div>
                        <hr class="dropdown-divider">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger rounded-2"><i class="bi bi-box-arrow-right me-2"></i>Terminar sessão</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    @if (session('success'))
        <div class="container mt-3"><div class="alert alert-success" role="alert">{{ session('success') }}</div></div>
    @endif
    @if (session('status'))
        <div class="container mt-3"><div class="alert alert-info" role="status">{{ session('status') }}</div></div>
    @endif

    <main id="top" class="container py-2 py-lg-5">
        @yield('content')
    </main>

    <footer class="site-footer container mt-5 pt-4 pb-4 border-top text-center">
        <div class="row gy-3 justify-content-center">
            <div class="col-12">
                <a class="footer-brand text-decoration-none" href="{{ route('dashboard') }}">
                    <span class="brand-mark brand-mark-sm me-2" aria-hidden="true"><i class="bi bi-wallet2"></i></span>
                    <span>Meu Bolso</span>
                </a>
            </div>
            <div class="col-12">
                <nav class="footer-nav d-flex flex-wrap justify-content-center gap-3" aria-label="Navegação do rodapé">
                    <a href="#top">Resumo</a>
                    <a href="#expensesList">Despesas</a>
                    <a href="#currencyTitle">Conversor</a>
                </nav>
            </div>
            <div class="col-12"><span class="small text-secondary">© {{ now()->year }} Meu Bolso</span></div>
        </div>
    </footer>

    <div class="modal fade" id="inactivityModal" tabindex="-1" aria-labelledby="inactivityModalTitle" aria-describedby="inactivityModalDescription" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-4">
                <div class="modal-body text-center p-4 p-md-5">
                    <i class="bi bi-clock-history text-warning fs-1 mb-3"></i>
                    <h2 class="h4 fw-bold mb-3" id="inactivityModalTitle">Ainda estás aí?</h2>
                    <p class="text-secondary mb-3" id="inactivityModalDescription">A tua sessão será encerrada por inatividade. Continua a utilizar a aplicação para manteres a sessão ativa.</p>
                    <div class="display-5 fw-bold text-warning mb-4"><span id="inactivityCountdown">01:00</span></div>
                    <button type="button" class="btn btn-primary w-100" id="continueSessionButton">Continuar sessão</button>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}" defer></script>
    @stack('scripts')
</body>
</html>

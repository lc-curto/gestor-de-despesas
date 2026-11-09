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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('css/styles.css') }}">
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-body border-bottom sticky-top">
        <div class="container py-2">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ route('dashboard') }}">
                <span class="brand-mark brand-mark-sm"><i class="bi bi-wallet2"></i></span>
                Meu Bolso
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Alternar navegação">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="nav">
                <ul class="navbar-nav me-auto ms-lg-4">
                    <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}">Painel</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('despesas.index') }}">Despesas</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('receitas.index') }}">Receitas</a></li>
                </ul>
                <div class="d-flex gap-2 align-items-center mt-3 mt-lg-0">
                    <button id="themeToggle" class="btn btn-outline-secondary" type="button" aria-label="Alternar tema">
                        <i class="bi bi-moon-stars"></i>
                    </button>
                    <span class="small">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm" type="submit">Sair</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4 py-lg-5">
        @if (session('success'))
            <div class="alert alert-success" role="alert">{{ session('success') }}</div>
        @endif

        @if (session('status'))
            <div class="alert alert-info" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    <footer class="container text-center text-secondary small pb-4">
        Meu Bolso · Gestão financeira pessoal
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/app.js') }}" defer></script>

    <div class="modal fade" id="inactivityModal" tabindex="-1" aria-labelledby="inactivityTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-4">
                <div class="modal-body text-center p-4">
                    <i class="bi bi-clock-history text-warning fs-1"></i>
                    <h2 class="h4 fw-bold mt-3" id="inactivityTitle">Sessão prestes a terminar</h2>
                    <p class="text-secondary">A sessão será encerrada por inatividade.</p>
                    <p class="display-5 fw-bold text-warning" id="inactivityCountdown">01:00</p>
                    <button type="button" class="btn btn-primary w-100" id="continueSessionButton">Continuar sessão</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

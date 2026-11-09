<!doctype html>
<html lang="pt-PT" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Meu Bolso — gestor pessoal de despesas e receitas">
    <meta name="theme-color" content="#635bff">
    <title>@yield('title') · Meu Bolso</title>
    <link rel="icon" href="{{ asset('images/pwa-icon.svg') }}" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="{{ asset('css/styles.css') }}">
</head>
<body>
    <main class="min-vh-100 d-flex align-items-center auth-view">
        <div class="container">
            <div class="row justify-content-center align-items-center">
                <div class="col-12 col-sm-10 col-md-7 col-lg-5 auth-column">
                    <section class="auth-panel auth-content">
                        <div class="brand-mark mb-4 mx-auto d-flex justify-content-center align-items-center" aria-hidden="true">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        @yield('content')
                    </section>
                </div>
            </div>
        </div>
    </main>
</body>
</html>

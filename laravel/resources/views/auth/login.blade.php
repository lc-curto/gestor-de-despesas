@extends('layouts.guest')

@section('title', 'Meu Bolso')

@section('content')
    <p class="text-uppercase small fw-semibold text-primary mb-2 text-center">Meu Bolso</p>
    <h1 class="h2 fw-bold mb-2 text-center" id="authTitle">Bem-vindo</h1>
    <p class="text-secondary mb-4 text-center" id="authDescription">
        Inicia sessão para acompanhares as tuas despesas.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" aria-live="polite">
            {{ $errors->first() }}
        </div>
    @endif
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <form id="loginForm" method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf
        <div class="mb-3 d-none" id="signupNameGroup">
            <label for="signupName" class="form-label">Nome completo</label>
            <input type="text" class="form-control form-control-lg" id="signupName" name="name"
                   value="{{ old('name') }}" placeholder="Ex.: Maria Silva" autocomplete="name"
                   maxlength="80">
        </div>

        <div class="mb-4 d-none" id="signupCurrencyGroup">
            <label for="signupCurrency" class="form-label">Moeda</label>
            <select class="form-select form-select-lg" id="signupCurrency" name="currency">
                <option value="EUR" @selected(old('currency', 'EUR') === 'EUR')>Euro (EUR €)</option>
                <option value="BRL" @selected(old('currency') === 'BRL')>Real brasileiro (BRL R$)</option>
                <option value="USD" @selected(old('currency') === 'USD')>Dólar americano (USD $)</option>
                <option value="GBP" @selected(old('currency') === 'GBP')>Libra esterlina (GBP £)</option>
            </select>
        </div>

        <div class="mb-4">
            <label for="loginEmail" class="form-label">Email</label>
            <input type="email" class="form-control form-control-lg" id="loginEmail" name="email"
                   value="{{ old('email') }}" placeholder="Introduz o teu endereço de email"
                   autocomplete="username" required>
        </div>

        <div class="mb-4">
            <label for="loginPassword" class="form-label">Senha</label>
            <input type="password" class="form-control form-control-lg" id="loginPassword" name="password"
                   placeholder="Introduz a tua palavra-passe" autocomplete="current-password"
                   minlength="6" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100" id="authSubmitButton">Entrar</button>
    </form>

    <p class="text-center text-secondary mt-4 mb-0">
        <span id="authToggleText">Ainda não tens uma conta?</span>
        <button type="button" class="btn btn-link p-0 text-decoration-none fw-semibold" id="authToggleButton">
            Criar conta
        </button>
    </p>

    <div id="authMessage" class="alert d-none mt-3 mb-0" role="alert" aria-live="polite"></div>

    <script>
    (() => {
        const form = document.getElementById('loginForm');
        const nameGroup = document.getElementById('signupNameGroup');
        const currencyGroup = document.getElementById('signupCurrencyGroup');
        const nameInput = document.getElementById('signupName');
        const currencyInput = document.getElementById('signupCurrency');
        const emailInput = document.getElementById('loginEmail');
        const passwordInput = document.getElementById('loginPassword');
        const title = document.getElementById('authTitle');
        const description = document.getElementById('authDescription');
        const submit = document.getElementById('authSubmitButton');
        const toggleText = document.getElementById('authToggleText');
        const toggleButton = document.getElementById('authToggleButton');
        const message = document.getElementById('authMessage');
        const registerUrl = @json(route('register.store'));
        const loginUrl = @json(route('login.store'));
        const registerPageUrl = @json(route('register'));
        const loginPageUrl = @json(route('login'));
        let registerMode = window.location.pathname === @json(parse_url(route('register'), PHP_URL_PATH));

        function setMode(register) {
            registerMode = register;
            form.action = registerMode ? registerUrl : loginUrl;
            nameGroup.classList.toggle('d-none', !registerMode);
            currencyGroup.classList.toggle('d-none', !registerMode);
            nameInput.required = registerMode;
            currencyInput.required = registerMode;
            if (!registerMode) nameInput.value = '';
            title.textContent = registerMode ? 'Criar conta' : 'Bem-vindo';
            description.textContent = registerMode
                ? 'Regista-te para começares a gerir as tuas despesas.'
                : 'Inicia sessão para acompanhares as tuas despesas.';
            submit.textContent = registerMode ? 'Criar conta' : 'Entrar';
            toggleText.textContent = registerMode ? 'Já tens uma conta?' : 'Ainda não tens uma conta?';
            toggleButton.textContent = registerMode ? 'Iniciar sessão' : 'Criar conta';
            passwordInput.autocomplete = registerMode ? 'new-password' : 'current-password';
            passwordInput.minLength = 6;
            window.history.replaceState({}, '', registerMode ? registerPageUrl : loginPageUrl);
            message.classList.add('d-none');
        }

        toggleButton.addEventListener('click', () => setMode(!registerMode));
        form.addEventListener('submit', (event) => {
            const email = emailInput.value.trim();
            const password = passwordInput.value;
            const fullName = nameInput.value.trim();
            if (!email || !password || (registerMode && !fullName)) {
                event.preventDefault();
                message.textContent = registerMode
                    ? 'Preenche o nome, o email e a palavra-passe.'
                    : 'Preenche o email e a palavra-passe.';
                message.className = 'alert alert-danger mt-3 mb-0';
                return;
            }
            submit.disabled = true;
            submit.textContent = 'A processar...';
        });

        setMode(registerMode);
    })();
    </script>
@endsection

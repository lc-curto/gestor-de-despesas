(() => {
    // Tema: utiliza a mesma chave e o mesmo comportamento do módulo tema.js original.
    const THEME_KEY = 'temaAplicacao';
    const root = document.documentElement;
    const themeButton = document.getElementById('themeToggle');

    function updateThemeButton(theme) {
        if (!themeButton) return;
        const icon = themeButton.querySelector('i');
        if (theme === 'dark') {
            if (icon) icon.className = 'bi bi-sun-fill';
            themeButton.setAttribute('aria-label', 'Mudar para tema claro');
            themeButton.setAttribute('aria-pressed', 'true');
        } else {
            if (icon) icon.className = 'bi bi-moon-stars-fill';
            themeButton.setAttribute('aria-label', 'Mudar para tema escuro');
            themeButton.setAttribute('aria-pressed', 'false');
        }
    }

    function applyTheme(theme) {
        root.setAttribute('data-bs-theme', theme);
        try {
            localStorage.setItem(THEME_KEY, theme);
        } catch (error) {
            console.warn('Não foi possível guardar a preferência de tema:', error);
        }
        updateThemeButton(theme);
    }

    function initializeTheme() {
        let storedTheme = null;
        try {
            storedTheme = localStorage.getItem(THEME_KEY);
        } catch (error) {
            console.warn('Não foi possível ler a preferência de tema:', error);
        }
        applyTheme(['light', 'dark'].includes(storedTheme) ? storedTheme : 'light');
    }

    themeButton?.addEventListener('click', () => {
        const currentTheme = root.getAttribute('data-bs-theme');
        applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
    });
    initializeTheme();

    // A data/hora é atualizada no cabeçalho à semelhança do main.js original.
    const currentDateTime = document.getElementById('currentDateTime');
    function updateDateTime() {
        if (!currentDateTime) return;
        currentDateTime.textContent = new Intl.DateTimeFormat('pt-PT', {
            dateStyle: 'full',
            timeStyle: 'short'
        }).format(new Date());
    }
    updateDateTime();
    window.setInterval(updateDateTime, 60000);

    // Instalação PWA: mantém o evento pendente até o utilizador escolher instalar.
    let pendingInstallEvent = null;
    const installButton = document.getElementById('installAppButton');
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        pendingInstallEvent = event;
        installButton?.classList.remove('d-none');
    });
    installButton?.addEventListener('click', async () => {
        if (!pendingInstallEvent) return;
        pendingInstallEvent.prompt();
        const choice = await pendingInstallEvent.userChoice;
        pendingInstallEvent = null;
        installButton.classList.add('d-none');
        if (choice.outcome === 'accepted') {
            console.info('O utilizador aceitou instalar o Meu Bolso.');
        }
    });
    window.addEventListener('appinstalled', () => {
        pendingInstallEvent = null;
        installButton?.classList.add('d-none');
    });

    // Monitorização original: 15 minutos de inatividade e aviso no último minuto.
    const INACTIVITY_LIMIT = 15 * 60 * 1000;
    const WARNING_TIME = 60 * 1000;
    const modalElement = document.getElementById('inactivityModal');
    if (modalElement && window.bootstrap) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const countdownElement = document.getElementById('inactivityCountdown');
        const continueButton = document.getElementById('continueSessionButton');
        const logoutForm = document.querySelector('form[action$="/logout"]');

        let timeout = null;
        let countdown = null;
        let lastActivity = null;
        let lastMouseMovement = 0;
        let monitoringActive = false;

        function logoutAfterInactivity() {
            if (!monitoringActive) return;
            stopInactivityMonitoring();
            modal.hide();
            if (logoutForm) logoutForm.submit();
            else window.location.assign('/login');
        }

        function updateCountdown() {
            const remaining = INACTIVITY_LIMIT - (Date.now() - lastActivity);
            const seconds = Math.ceil(remaining / 1000);
            if (seconds <= 0) {
                logoutAfterInactivity();
                return;
            }

            const minutes = Math.floor(seconds / 60);
            const rest = seconds % 60;
            if (countdownElement) {
                countdownElement.textContent = String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
            }
            modal.show();
        }

        function startCountdown() {
            clearInterval(countdown);
            updateCountdown();
            countdown = window.setInterval(updateCountdown, 1000);
        }

        function scheduleInactivity() {
            clearTimeout(timeout);
            clearInterval(countdown);

            const remaining = INACTIVITY_LIMIT - (Date.now() - lastActivity);
            if (remaining <= 0) {
                logoutAfterInactivity();
                return;
            }

            if (remaining > WARNING_TIME) {
                timeout = window.setTimeout(startCountdown, remaining - WARNING_TIME);
            } else {
                startCountdown();
            }
        }

        function registerActivity() {
            if (!monitoringActive) return;
            lastActivity = Date.now();
            modal.hide();
            scheduleInactivity();
        }

        function registerMouseMovement() {
            const now = Date.now();
            if (now - lastMouseMovement < 1000) return;
            lastMouseMovement = now;
            registerActivity();
        }

        function checkWhenReturning() {
            if (document.visibilityState === 'visible') scheduleInactivity();
        }

        function stopInactivityMonitoring() {
            clearTimeout(timeout);
            clearInterval(countdown);
            document.removeEventListener('click', registerActivity);
            document.removeEventListener('keydown', registerActivity);
            document.removeEventListener('mousemove', registerMouseMovement);
            document.removeEventListener('scroll', registerActivity);
            document.removeEventListener('touchstart', registerActivity);
            document.removeEventListener('visibilitychange', checkWhenReturning);
            window.removeEventListener('focus', checkWhenReturning);
            timeout = null;
            countdown = null;
            monitoringActive = false;
            modal.hide();
        }

        function startInactivityMonitoring() {
            stopInactivityMonitoring();
            monitoringActive = true;
            lastActivity = Date.now();
            lastMouseMovement = 0;
            document.addEventListener('click', registerActivity);
            document.addEventListener('keydown', registerActivity);
            document.addEventListener('mousemove', registerMouseMovement);
            document.addEventListener('scroll', registerActivity);
            document.addEventListener('touchstart', registerActivity);
            document.addEventListener('visibilitychange', checkWhenReturning);
            window.addEventListener('focus', checkWhenReturning);
            scheduleInactivity();
        }

        continueButton?.addEventListener('click', () => modal.hide());
        startInactivityMonitoring();
    }

    // O dashboard gere a apresentação do cache e a atualização dos movimentos offline.
    const offlineStatus = document.getElementById('offlineStatus');
    if (offlineStatus && !navigator.onLine && !document.getElementById('dashboardMovementForm')) {
        offlineStatus.textContent = 'Sem ligação à internet.';
        offlineStatus.classList.remove('d-none');
    }

    // Mantém o registo do Service Worker da aplicação Laravel.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/service-worker.js').catch((error) => {
                console.warn('Não foi possível registar o Service Worker:', error);
            });
        });
    }
})();
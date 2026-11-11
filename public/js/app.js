(() => {
    const root = document.documentElement;
    const themeButton = document.getElementById('themeToggle');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const sidebar = document.getElementById('appSidebar');
    const SIDEBAR_KEY = 'meu-bolso:sidebar-hidden';
    const THEME_KEY = 'temaAplicacao';
    const mobileNavigation = window.matchMedia('(max-width: 991.98px)');

    function renderIcons(scope = document) {
        if (!window.lucide?.createIcons) return;
        window.lucide.createIcons({ root: scope });
    }

    function updateThemeButton(theme) {
        if (!themeButton) return;
        const icon = themeButton.querySelector('i[data-lucide]');
        if (icon) icon.setAttribute('data-lucide', theme === 'dark' ? 'sun' : 'moon-star');
        const label = theme === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro';
        themeButton.setAttribute('aria-label', label);
        themeButton.title = label;
        renderIcons(themeButton);
    }

    function applyTheme(theme) {
        root.setAttribute('data-bs-theme', theme);
        try { localStorage.setItem(THEME_KEY, theme); }
        catch (error) { console.warn('Não foi possível guardar a preferência de tema:', error); }
        updateThemeButton(theme);
    }

    let storedTheme = null;
    try { storedTheme = localStorage.getItem(THEME_KEY); }
    catch (error) { console.warn('Não foi possível ler a preferência de tema:', error); }
    applyTheme(['light', 'dark'].includes(storedTheme) ? storedTheme : 'light');

    themeButton?.addEventListener('click', () => {
        applyTheme(root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark');
    });

    const legacyIcons = {
        'bi-grid-1x2':'layout-dashboard','bi-arrow-down-circle':'arrow-down-circle',
        'bi-arrow-up-circle':'arrow-up-circle','bi-tags':'tags','bi-plus-lg':'plus',
        'bi-search':'search','bi-pencil':'pencil','bi-pause-circle':'pause-circle',
        'bi-play-circle':'play-circle','bi-trash':'trash-2','bi-house':'house',
        'bi-cup-hot':'coffee','bi-car-front':'car-front','bi-heart-pulse':'heart-pulse',
        'bi-controller':'gamepad-2','bi-bag':'shopping-bag','bi-mortarboard':'graduation-cap',
        'bi-wallet2':'wallet','bi-shop':'store','bi-briefcase':'briefcase-business',
        'bi-graph-up-arrow':'chart-no-axes-combined','bi-arrow-counterclockwise':'undo-2',
        'bi-three-dots':'ellipsis','bi-arrow-up-right':'arrow-up-right',
        'bi-arrow-down-left':'arrow-down-left','bi-chevron-left':'chevron-left',
        'bi-chevron-right':'chevron-right','bi-arrow-left':'arrow-left',
        'bi-arrow-left-right':'arrow-left-right','bi-arrow-right':'arrow-right',
        'bi-download':'download','bi-person':'user-round','bi-box-arrow-right':'log-out',
        'bi-clock-history':'clock-3','bi-list':'menu','bi-moon-stars':'moon-star',
        'bi-sun-fill':'sun','bi-x-lg':'x','bi-tag':'tag'
    };

    function normalizeLegacyIcons(scope) {
        const candidates = [];
        if (scope.matches?.('i.bi')) candidates.push(scope);
        scope.querySelectorAll?.('i.bi').forEach((element) => candidates.push(element));
        candidates.forEach((element) => {
            const legacy = [...element.classList].find((name) => name.startsWith('bi-') && name !== 'bi');
            if (!legacyIcons[legacy]) return;
            element.classList.remove('bi', legacy);
            element.setAttribute('data-lucide', legacyIcons[legacy]);
        });
    }

    normalizeLegacyIcons(document);
    renderIcons(document);

    // Processa apenas o fragmento alterado; evita reprocessar todos os ícones da página.
    const iconObserver = new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node.nodeType !== Node.ELEMENT_NODE) return;
            normalizeLegacyIcons(node);
            const hasIcon = node.matches?.('i[data-lucide]') || node.querySelector?.('i[data-lucide]');
            if (hasIcon) renderIcons(node.matches?.('i[data-lucide]') ? node.parentElement : node);
        }));
    });
    iconObserver.observe(document.body, { childList: true, subtree: true });

    let sidebarHidden = false;
    try { sidebarHidden = localStorage.getItem(SIDEBAR_KEY) === 'true'; } catch (error) {}

    function setMobileSidebar(open) {
        document.body.classList.toggle('sidebar-open', open);
        sidebarToggle?.setAttribute('aria-expanded', String(open));
        sidebarToggle?.setAttribute('aria-label', open ? 'Fechar menu lateral' : 'Abrir menu lateral');
        if (sidebarToggle) sidebarToggle.title = open ? 'Fechar menu lateral' : 'Abrir menu lateral';
        const icon = sidebarToggle?.querySelector('i[data-lucide]');
        if (icon) {
            icon.setAttribute('data-lucide', open ? 'panel-left-close' : 'panel-left-open');
            renderIcons(sidebarToggle);
        }
    }

    function setDesktopSidebarHidden(hidden) {
        sidebarHidden = hidden;
        document.body.classList.toggle('sidebar-hidden', hidden);
        sidebarToggle?.setAttribute('aria-expanded', String(!hidden));
        sidebarToggle?.setAttribute('aria-label', hidden ? 'Abrir menu lateral' : 'Recolher menu lateral');
        if (sidebarToggle) sidebarToggle.title = hidden ? 'Abrir menu lateral' : 'Recolher menu lateral';
        const icon = sidebarToggle?.querySelector('i[data-lucide]');
        if (icon) {
            icon.setAttribute('data-lucide', hidden ? 'panel-left-open' : 'panel-left-close');
            renderIcons(sidebarToggle);
        }
        try { localStorage.setItem(SIDEBAR_KEY, String(hidden)); } catch (error) {}
    }

    if (mobileNavigation.matches) setMobileSidebar(false);
    else setDesktopSidebarHidden(sidebarHidden);

    sidebarToggle?.addEventListener('click', () => {
        if (mobileNavigation.matches) {
            setMobileSidebar(!document.body.classList.contains('sidebar-open'));
            return;
        }
        setDesktopSidebarHidden(!sidebarHidden);
    });
    sidebarBackdrop?.addEventListener('click', () => setMobileSidebar(false));
    sidebar?.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileNavigation.matches) setMobileSidebar(false);
        });
    });
    mobileNavigation.addEventListener?.('change', (event) => {
        if (event.matches) setMobileSidebar(false);
        else {
            document.body.classList.remove('sidebar-open');
            setDesktopSidebarHidden(sidebarHidden);
        }
    });

    const logoutForm = document.querySelector('form[action$="/logout"]');
    logoutForm?.addEventListener('submit', () => {
        try { localStorage.removeItem('meu-bolso:active-user'); }
        catch (error) { console.warn('Não foi possível atualizar a sessão local:', error); }
    });

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
        if (choice.outcome === 'accepted') console.info('O utilizador aceitou instalar o Meu Bolso.');
    });
    window.addEventListener('appinstalled', () => {
        pendingInstallEvent = null;
        installButton?.classList.add('d-none');
    });

    // Gestão de inatividade: evita executar hide() do modal em cada clique/scroll.
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
        let lastActivity = Date.now();
        let lastMouseMovement = 0;
        let monitoringActive = false;

        function hideWarning() {
            if (modalElement.classList.contains('show')) modal.hide();
        }
        function logoutAfterInactivity() {
            if (!monitoringActive) return;
            stopInactivityMonitoring();
            hideWarning();
            if (logoutForm) logoutForm.submit();
            else window.location.assign('/login');
        }
        function updateCountdown() {
            const seconds = Math.ceil((INACTIVITY_LIMIT - (Date.now() - lastActivity)) / 1000);
            if (seconds <= 0) return logoutAfterInactivity();
            if (countdownElement) {
                countdownElement.textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
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
            if (remaining <= 0) return logoutAfterInactivity();
            if (remaining > WARNING_TIME) timeout = window.setTimeout(startCountdown, remaining - WARNING_TIME);
            else startCountdown();
        }
        function registerActivity() {
            if (!monitoringActive) return;
            lastActivity = Date.now();
            hideWarning();
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
            hideWarning();
        }
        function startInactivityMonitoring() {
            stopInactivityMonitoring();
            monitoringActive = true;
            lastActivity = Date.now();
            lastMouseMovement = 0;
            document.addEventListener('click', registerActivity);
            document.addEventListener('keydown', registerActivity);
            document.addEventListener('mousemove', registerMouseMovement, { passive: true });
            document.addEventListener('scroll', registerActivity, { passive: true });
            document.addEventListener('touchstart', registerActivity, { passive: true });
            document.addEventListener('visibilitychange', checkWhenReturning);
            window.addEventListener('focus', checkWhenReturning);
            scheduleInactivity();
        }
        continueButton?.addEventListener('click', hideWarning);
        startInactivityMonitoring();
    }

    const offlineStatus = document.getElementById('offlineStatus');
    if (offlineStatus && !navigator.onLine && !document.getElementById('dashboardMovementForm')) {
        offlineStatus.textContent = 'Sem ligação à internet.';
        offlineStatus.classList.remove('d-none');
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/service-worker.js').catch((error) => {
                console.warn('Não foi possível registar o Service Worker:', error);
            });
        });
    }
})();
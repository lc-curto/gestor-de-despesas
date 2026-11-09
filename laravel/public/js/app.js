(() => {
  const root = document.documentElement;
  const themeButton = document.getElementById('themeToggle');
  const themeKey = 'meu-bolso-theme';
  const savedTheme = localStorage.getItem(themeKey);
  if (savedTheme === 'light' || savedTheme === 'dark') root.setAttribute('data-bs-theme', savedTheme);

  function updateThemeButton() {
    if (!themeButton) return;
    const dark = root.getAttribute('data-bs-theme') === 'dark';
    themeButton.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
  }
  themeButton?.addEventListener('click', () => {
    const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-bs-theme', next);
    localStorage.setItem(themeKey, next);
    updateThemeButton();
  });
  updateThemeButton();

  document.querySelector('form[action$="/logout"]')?.addEventListener('submit', () => {
    const activeUser = localStorage.getItem('meu-bolso:active-user');
    if (activeUser) {
      Object.keys(localStorage).filter((key) => key.startsWith('meu-bolso:dashboard:') && key.split(':')[2] === activeUser).forEach((key) => localStorage.removeItem(key));
    }
    localStorage.removeItem('meu-bolso:active-user');
  });

  // A instalação só é apresentada quando o navegador disponibiliza o evento PWA.
  let installPrompt = null;
  const installButton = document.getElementById('installAppButton');
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    installButton?.classList.remove('d-none');
  });
  installButton?.addEventListener('click', async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    installButton.classList.add('d-none');
  });
  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    installButton?.classList.add('d-none');
  });

  // O aviso começa aos 14 minutos; a sessão termina após mais 60 segundos.
  const modalElement = document.getElementById('inactivityModal');
  if (modalElement && window.bootstrap) {
    const modal = new bootstrap.Modal(modalElement);
    const logoutForm = document.querySelector('form[action$="/logout"]');
    const continueButton = document.getElementById('continueSessionButton');
    const counter = document.getElementById('inactivityCountdown');
    const warningMs = 14 * 60 * 1000;
    let idleTimer;
    let countdownTimer;
    let remaining = 60;

    function logoutNow() {
      if (logoutForm) logoutForm.requestSubmit();
      else window.location.assign('/login');
    }
    function resetTimer() {
      clearTimeout(idleTimer);
      clearInterval(countdownTimer);
      modal.hide();
      idleTimer = setTimeout(showWarning, warningMs);
    }
    function showWarning() {
      remaining = 60;
      counter.textContent = '01:00';
      modal.show();
      countdownTimer = setInterval(() => {
        remaining -= 1;
        counter.textContent = String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
        if (remaining <= 0) {
          clearInterval(countdownTimer);
          logoutNow();
        }
      }, 1000);
    }
    ['pointerdown', 'keydown', 'scroll', 'touchstart', 'click'].forEach((name) => {
      document.addEventListener(name, () => {
        if (!modalElement.classList.contains('show')) resetTimer();
      }, { passive: true });
    });
    continueButton?.addEventListener('click', async () => {
      try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch('/session/activity', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
          credentials: 'same-origin'
        });
        if (!response.ok) throw new Error('Sessão não renovada');
        resetTimer();
      } catch (_) {
        logoutNow();
      }
    });
    resetTimer();
  }

  const offlineStatus = document.getElementById('offlineStatus');
  function updateConnectionStatus() {
    if (!offlineStatus) return;
    if (!navigator.onLine) {
      offlineStatus.textContent = 'Sem ligação. Os dados financeiros guardados estão disponíveis apenas para consulta.';
      offlineStatus.classList.remove('d-none');
    } else {
      offlineStatus.classList.add('d-none');
    }
  }
  window.addEventListener('offline', updateConnectionStatus);
  window.addEventListener('online', updateConnectionStatus);
  updateConnectionStatus();

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/service-worker.js').catch((error) => {
        console.warn('Não foi possível registar o Service Worker:', error);
      });
    });
  }
})();

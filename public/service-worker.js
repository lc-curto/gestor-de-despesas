const CACHE_NAME = 'meu-bolso-static-v8';
const STATIC_ASSETS = [
  '/offline.html',
  '/css/bootstrap.min.css',
  '/css/styles.css',
  '/css/app-shell.css',
  '/js/bootstrap.bundle.min.js',
  '/js/app.js',
  'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
  '/js/dashboard.js',
  '/manifest.webmanifest',
  '/images/pwa-icon.svg',
  '/images/pwa-icon-192.png',
  '/images/pwa-icon-512.png',


];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await Promise.all(STATIC_ASSETS.map(async (asset) => {
      try {
        const response = await fetch(asset, { cache: 'reload', mode: 'no-cors' });
        if (response && (response.ok || response.type === 'opaque')) await cache.put(asset, response);
      } catch (_) {
        // Um CDN indisponível não deve impedir a instalação da PWA.
      }
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith('meu-bolso-') && name !== CACHE_NAME).map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  // Nunca guardar páginas autenticadas nem respostas com dados financeiros no Cache Storage.
  if (url.origin === self.location.origin && request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => {
      return (await caches.match('/offline.html')) || Response.error();
    }));
    return;
  }

  const isStatic = url.origin === self.location.origin
    ? STATIC_ASSETS.includes(url.pathname)
    : url.hostname === 'cdn.jsdelivr.net';

  if (isStatic) {
    event.respondWith((async () => {
      const cached = await caches.match(request);
      if (cached) return cached;
      const response = await fetch(request);
      if (response && (response.ok || response.type === 'opaque')) {
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, response.clone()).catch(() => {});
      }
      return response;
    })());
  }
});

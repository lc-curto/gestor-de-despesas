const CACHE_NAME = "meu-bolso-shell-v17";
const RECURSOS = [
  "./",
  "./index.html",
  "./assets/pwa/manifest.json",
  "./assets/css/styles.css",
  "./assets/js/main.js?v=final-2",
  "./assets/js/auth.js",
  "./assets/js/tema.js",
  "./assets/js/despesas.js",
  "./assets/js/receitas.js",
  "./assets/js/api.js",
  "./assets/js/supabase.js",
  "./assets/images/favicon.png",
  "./assets/images/pwa-icon.svg"
];

self.addEventListener("install", evento => {
  evento.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(RECURSOS))
  );
  self.skipWaiting();
});

self.addEventListener("activate", evento => {
  evento.waitUntil(
    caches.keys().then(chaves =>
      Promise.all(
        chaves
          .filter(chave => chave.startsWith("meu-bolso-shell-") && chave !== CACHE_NAME)
          .map(chave => caches.delete(chave))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", evento => {
  if (evento.request.method !== "GET") return;

  const url = new URL(evento.request.url);

  if (url.origin === self.location.origin) {
    evento.respondWith(
      fetch(evento.request).then(resposta => {
        if (resposta.ok) {
          const copia = resposta.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(evento.request, copia));
        }
        return resposta;
      }).catch(async () => {
        const guardada = await caches.match(evento.request);
        if (guardada) return guardada;

        if (evento.request.mode === "navigate") {
          return caches.match("./index.html");
        }

        return Response.error();
      })
    );
    return;
  }

  if (url.hostname === "cdn.jsdelivr.net") {
    evento.respondWith(
      caches.match(evento.request).then(guardada =>
        guardada || fetch(evento.request).then(resposta => {
          if (resposta.ok) {
            const copia = resposta.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(evento.request, copia));
          }
          return resposta;
        })
      )
    );
  }
});
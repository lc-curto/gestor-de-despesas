const CACHE_NAME = "meu-bolso-shell-v1";
const RECURSOS = [
  "./",
  "./index.html",
  "./assets/css/styles.css",
  "./assets/js/main.js",
  "./assets/js/auth.js",
  "./assets/js/tema.js",
  "./assets/js/despesas.js",
  "./assets/js/receitas.js",
  "./assets/js/api.js",
  "./assets/js/supabase.js",
  "./assets/images/favicon.png"
];

self.addEventListener("install", evento => {
  evento.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(RECURSOS)));
  self.skipWaiting();
});

self.addEventListener("activate", evento => {
  evento.waitUntil(
    caches.keys().then(chaves =>
      Promise.all(chaves.filter(chave => chave !== CACHE_NAME).map(chave => caches.delete(chave)))
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
      }).catch(() =>
        caches.match(evento.request).then(resposta => resposta || caches.match("./index.html"))
      )
    );
    return;
  }

  if (url.hostname === "cdn.jsdelivr.net") {
    evento.respondWith(
      caches.match(evento.request).then(cached =>
        cached || fetch(evento.request).then(resposta => {
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
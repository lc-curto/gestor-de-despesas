const CACHE_NAME = "meu-bolso-shell-v20";
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

// Apenas recursos estáticos listados no shell podem ser guardados em cache.
// Isto evita armazenar respostas de futuras rotas de API ou dados de utilizadores.
const RECURSOS_CACHEAVEIS = new Set(
  RECURSOS.map(recurso => new URL(recurso, self.location.href).pathname)
);

self.addEventListener("fetch", evento => {
  if (evento.request.method !== "GET") return;

  const url = new URL(evento.request.url);

  if (url.origin === self.location.origin) {
    const eNavegacao = evento.request.mode === "navigate";
    const eRecursoEstatico = RECURSOS_CACHEAVEIS.has(url.pathname);

    // Pedidos que não sejam navegação nem recursos estáticos seguem diretamente
    // para a rede, sem entrar na cache da aplicação.
    if (!eNavegacao && !eRecursoEstatico) return;

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

        if (eNavegacao) {
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
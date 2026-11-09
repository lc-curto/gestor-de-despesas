const CACHE_NAME = "meu-bolso-shell-v26";
const RECURSOS = [
  "./",
  "./index.html",
  "./assets/pwa/manifest.json",
  "./assets/css/styles.css",
  "./assets/js/main.js?v=final-2",
  "./assets/js/cache.js",
  "./assets/js/formatacao.js",
  "./assets/js/auth.js",
  "./assets/js/tema.js",
  "./assets/js/despesas.js",
  "./assets/js/receitas.js",
  "./assets/js/api.js",
  "./assets/js/supabase.js",
  "./assets/images/favicon.png",
  "./assets/images/pwa-icon.svg",
  "./assets/images/pwa-icon-192.png",
  "./assets/images/pwa-icon-512.png",
  "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css",
  "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css",
  "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js",
  "https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.119.0",
  "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"
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

const RECURSOS_CACHEAVEIS = new Set(
  RECURSOS
    .filter(recurso => !recurso.startsWith("http"))
    .map(recurso => new URL(recurso, self.location.href).pathname)
);

async function obterDaCacheOuRede(pedido) {
  const guardado = await caches.match(pedido, {
    ignoreVary: true,
    ignoreSearch: true,
  });
  if (guardado) return guardado;

  const resposta = await fetch(pedido);
  if (resposta.ok) {
    const copia = resposta.clone();
    const cache = await caches.open(CACHE_NAME);
    await cache.put(pedido, copia);
  }
  return resposta;
}

self.addEventListener("fetch", evento => {
  if (evento.request.method !== "GET") return;

  const url = new URL(evento.request.url);

  if (url.origin === self.location.origin) {
    const eNavegacao = evento.request.mode === "navigate";
    const eRecursoEstatico = RECURSOS_CACHEAVEIS.has(url.pathname);

    if (!eNavegacao && !eRecursoEstatico) return;

    if (eNavegacao) {
      evento.respondWith(
        fetch(evento.request).catch(async () => {
          const pagina = await caches.match("./index.html", { ignoreVary: true });
          return pagina || Response.error();
        })
      );
      return;
    }

    // Recursos estáticos usam cache-first: a aplicação não depende da rede
    // para recuperar CSS, JavaScript, imagens e o manifesto já instalados.
    evento.respondWith(
      obterDaCacheOuRede(evento.request).catch(() => Response.error())
    );
    return;
  }

  if (url.hostname === "cdn.jsdelivr.net") {
    // Dependências externas também usam cache-first para manter o layout
    // e os componentes disponíveis quando não existe ligação à internet.
    evento.respondWith(
      obterDaCacheOuRede(evento.request).catch(() => Response.error())
    );
  }
});

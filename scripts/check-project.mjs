import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, readdirSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const erros = [];
let verificacoes = 0;

function verificar(condicao, mensagem) {
  verificacoes += 1;
  if (!condicao) erros.push(mensagem);
}

function caminhoLocal(referencia, base = ROOT) {
  const semQuery = referencia.split(/[?#]/, 1)[0];
  return path.resolve(base, decodeURIComponent(semQuery));
}

function ler(caminho) {
  return readFileSync(path.join(ROOT, caminho), "utf8");
}

// 1. Verifica a sintaxe de todos os módulos JavaScript e do Service Worker.
const ficheirosJs = [
  ...readdirSync(path.join(ROOT, "assets/js"))
    .filter(nome => nome.endsWith(".js"))
    .map(nome => path.join("assets/js", nome)),
  "service-worker.js",
];

for (const ficheiro of ficheirosJs) {
  try {
    execFileSync(process.execPath, ["--check", path.join(ROOT, ficheiro)], {
      stdio: "pipe",
    });
    verificar(true, "");
    console.log("OK sintaxe:", ficheiro);
  } catch (erro) {
    verificar(false, `Erro de sintaxe em ${ficheiro}: ${erro.stderr?.toString() || erro.message}`);
  }
}

// 2. Confirma que os imports relativos dos módulos apontam para ficheiros existentes.
for (const ficheiro of ficheirosJs.filter(nome => nome.startsWith("assets/js/"))) {
  const conteudo = ler(ficheiro);
  const imports = [...conteudo.matchAll(/(?:from\s+|import\s*)["']([^"']+)["']/g)];

  for (const [, referencia] of imports) {
    if (!referencia.startsWith(".")) continue;
    const destino = caminhoLocal(referencia, path.dirname(path.join(ROOT, ficheiro)));
    verificar(
      existsSync(destino),
      `Import inexistente em ${ficheiro}: ${referencia}`,
    );
  }
}
console.log("OK imports relativos");

// 3. Confirma referências locais de CSS, JavaScript, imagens e manifesto no HTML.
const html = ler("index.html");
const referenciasHtml = [...html.matchAll(/(?:src|href)=["']([^"']+)["']/g)];

for (const [, referencia] of referenciasHtml) {
  if (
    referencia.startsWith("#") ||
    referencia.startsWith("http://") ||
    referencia.startsWith("https://") ||
    referencia.startsWith("data:")
  ) continue;

  verificar(
    existsSync(caminhoLocal(referencia)),
    `Referência local inexistente no index.html: ${referencia}`,
  );
}
console.log("OK referências locais do HTML");

// 4. Valida o JSON do manifesto e os caminhos dos ícones.
const manifesto = JSON.parse(ler("assets/pwa/manifest.json"));
verificar(Array.isArray(manifesto.icons) && manifesto.icons.length > 0, "Manifesto sem ícones.");

for (const icone of manifesto.icons || []) {
  verificar(
    existsSync(caminhoLocal(icone.src, path.join(ROOT, "assets/pwa"))),
    `Ícone inexistente no manifesto: ${icone.src}`,
  );
}
console.log("OK manifesto PWA");

// 5. Confirma que todos os recursos do precache do Service Worker existem.
const serviceWorker = ler("service-worker.js");
const blocoCache = serviceWorker.match(/const\s+RECURSOS\s*=\s*\[([\s\S]*?)\];/);

verificar(Boolean(blocoCache), "Não foi possível localizar a lista RECURSOS no Service Worker.");

if (blocoCache) {
  const recursos = [...blocoCache[1].matchAll(/["']([^"']+)["']/g)].map(match => match[1]);

  verificar(
    recursos.includes("./assets/js/cache.js"),
    "O módulo cache.js não está incluído no precache do Service Worker.",
  );

  verificar(
    recursos.includes("https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.119.0"),
    "O SDK UMD do Supabase não está incluído no precache.",
  );

  verificar(
    recursos.includes("https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"),
    "A fonte Bootstrap Icons não está incluída no precache.",
  );

  for (const recurso of recursos) {
    if (recurso.startsWith("http://") || recurso.startsWith("https://")) {
      verificar(
        recurso.startsWith("https://cdn.jsdelivr.net/"),
        `Origem externa não autorizada no precache: ${recurso}`,
      );
      continue;
    }

    const destino = caminhoLocal(recurso);
    verificar(
      existsSync(destino),
      `Recurso do precache inexistente: ${recurso}`,
    );
  }
}
console.log("OK recursos do Service Worker");

console.log(`\nResultado: ${verificacoes} verificações executadas, ${erros.length} erro(s).`);

if (erros.length) {
  for (const erro of erros) console.error("-", erro);
  process.exitCode = 1;
}

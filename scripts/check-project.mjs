import { execFileSync } from "node:child_process";
import {
    existsSync,
    readFileSync,
    readdirSync,
    statSync,
} from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    ".."
);

// Permite executar o verificador antes e depois da migração para a raiz.
const APP = existsSync(path.join(ROOT, "artisan"))
    ? ROOT
    : path.join(ROOT, "laravel");

const erros = [];
let verificacoes = 0;

function verificar(condicao, mensagem) {
    verificacoes++;

    if (!condicao) {
        erros.push(mensagem);
    }
}

function existe(caminho) {
    return existsSync(path.join(APP, caminho));
}

function ler(caminho) {
    return readFileSync(path.join(APP, caminho), "utf8").replace(/^\uFEFF/, "");
}

function lerJson(caminho) {
    try {
        return JSON.parse(ler(caminho));
    } catch (erro) {
        verificar(false, `JSON inválido em ${caminho}: ${erro.message}`);
        return null;
    }
}

console.log("\n=== Verificação do projeto Meu Bolso ===");
console.log("Diretório Laravel:", APP);

// 1. Verifica a estrutura essencial do Laravel.
const estrutura = [
    "artisan",
    "composer.json",
    "composer.lock",
    "app",
    "bootstrap",
    "config",
    "database",
    "public",
    "resources",
    "routes",
    "storage",
    "tests",
    "routes/web.php",
    "public/index.php",
    "public/service-worker.js",
    "public/manifest.webmanifest",
];

for (const caminho of estrutura) {
    verificar(
        existe(caminho),
        `Estrutura Laravel inexistente: ${caminho}`
    );
}

console.log("OK estrutura Laravel");

// 2. Valida os ficheiros de configuração JSON.
const composer = lerJson("composer.json");
const packageJson = lerJson(path.relative(APP, path.join(ROOT, "package.json")));
const manifesto = lerJson("public/manifest.webmanifest");

if (composer) {
    verificar(
        Boolean(composer.require?.["laravel/framework"]),
        "Laravel não está declarado nas dependências do Composer."
    );
}

if (packageJson) {
    verificar(
        Boolean(packageJson.scripts?.build),
        "Script build não definido no package.json."
    );

    verificar(
        Boolean(packageJson.scripts?.dev),
        "Script dev não definido no package.json."
    );

    verificar(
        Boolean(packageJson.scripts?.check),
        "Script check não definido no package.json."
    );
}

console.log("OK ficheiros JSON de configuração");

// 3. Verifica os ícones declarados no manifesto PWA.
if (manifesto) {
    verificar(
        Array.isArray(manifesto.icons) && manifesto.icons.length > 0,
        "Manifesto PWA sem ícones."
    );

    for (const icone of manifesto.icons || []) {
        const referencia = decodeURIComponent(
            String(icone.src || "").split(/[?#]/, 1)[0]
        );

        const caminho = path.resolve(
            APP,
            "public",
            referencia.replace(/^\/+/, "")
        );

        verificar(
            Boolean(icone.src) &&
            caminho.startsWith(path.resolve(APP, "public") + path.sep) &&
            existsSync(caminho) &&
            statSync(caminho).isFile(),
            `Ícone inexistente ou inválido no manifesto: ${icone.src}`
        );
    }
}

console.log("OK manifesto PWA");

// 4. Verifica a sintaxe dos ficheiros JavaScript públicos.
const diretorioJs = path.join(APP, "public", "js");
const ficheirosJs = [];

if (existsSync(diretorioJs)) {
    ficheirosJs.push(
        ...readdirSync(diretorioJs)
            .filter(nome => nome.endsWith(".js"))
            .map(nome => path.join(diretorioJs, nome))
    );
} else {
    verificar(false, "Diretório public/js inexistente.");
}

const serviceWorker = path.join(APP, "public", "service-worker.js");

if (existsSync(serviceWorker)) {
    ficheirosJs.push(serviceWorker);
}

for (const ficheiro of ficheirosJs) {
    try {
        execFileSync(process.execPath, ["--check", ficheiro], {
            stdio: "pipe",
        });

        verificar(true, "");
        console.log("OK sintaxe:", path.relative(APP, ficheiro));
    } catch (erro) {
        verificar(
            false,
            `Erro de sintaxe em ${path.relative(APP, ficheiro)}: ${
                erro.stderr?.toString() || erro.message
            }`
        );
    }
}

// 5. Resultado final.
console.log(
    `\nResultado: ${verificacoes} verificações executadas, ${erros.length} erro(s).`
);

if (erros.length > 0) {
    for (const erro of erros) {
        console.error("-", erro);
    }

    process.exitCode = 1;
} else {
    console.log("Projeto validado sem erros.");
}

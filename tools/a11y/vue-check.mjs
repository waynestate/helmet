#!/usr/bin/env node
/**
 * Vue under HELMET's default CSP, in a real Chromium browser (H51).
 *
 * README.md ("Raw PHP with a Vue (Vite) build") promises that a Vue app built
 * by Vite runs under HELMET's default policy (a per-request nonce plus
 * 'strict-dynamic', no 'unsafe-inline' for scripts, no 'unsafe-eval') when
 * Vite's html.cspNonce is set to HELMET's placeholder and the server swaps in
 * the response nonce. The PHP side of the swap is unit tested
 * (tests/FunctionsNonceSwapTest.php); this check proves the browser side:
 *
 *   1. Serves tools/vue-vite/dist (built by `npm run build` there) with the
 *      exact headers SecurityHeaders::build() produces (the JSON file that
 *      tools/a11y/corp-headers.php prints) and swaps the placeholder for the
 *      CSP's nonce the way helmet_csp_nonce_swap() does. Loads the app and
 *      asserts: no CSP violation, no failed request, no page error; the app
 *      mounted; its scoped stylesheet applied; the inlined data: image
 *      loaded; a click updates the page and a bound style; the lazily
 *      imported chunk rendered, and its stylesheet, added at runtime by
 *      Vite's preload helper, carries the response nonce read from
 *      <meta property="csp-nonce">.
 *
 *   2. Serves the same build without the swap (the placeholder stays, so the
 *      nonce does not match) and asserts that the browser refused the entry
 *      script and the app never mounted: the check watches the right thing.
 *
 *   3. Serves an in-memory probe page whose nonced module script imports
 *      Vue's full build (the one with the runtime template compiler) and
 *      mounts a component with a string template. Asserts the module ran,
 *      the browser refused the compiler's eval and nothing rendered: apps
 *      must precompile templates (single-file components do), because the
 *      default policy never allows 'unsafe-eval'.
 *
 *   4. With --laravel, serves the page tools/a11y/laravel-page.php rendered
 *      through Laravel and HELMET's service provider (the CSP header from
 *      the middleware, @vite tags nonced by Laravel's Vite helper, the
 *      csp-nonce meta tag the middleware injects) with the Laravel build's
 *      assets under /build/, and runs the same checks as part 1. An Inertia
 *      root view is a Blade view with the same @vite, so this covers it.
 *
 * Usage:  node tools/a11y/vue-check.mjs --headers=build/helmet-headers.json
 *           [--laravel=build/laravel-page.json]
 *         (paths may be relative to the repo root or absolute)
 * Exit:   0 pass, 1 a check failed, 2 tool error.
 */
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

import { chromium } from "playwright";

const MIME = {
  ".css": "text/css; charset=utf-8",
  ".html": "text/html; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".woff2": "font/woff2",
};

/** HELMET's default placeholder (helmet_csp_nonce_swap(), README.md). */
const PLACEHOLDER = "CSP_NONCE_SLOT_FOR_HELMET";
/** The fixture build and Vue's full build (template compiler included). */
const DIST_DIR = "tools/vue-vite/dist";
const VUE_FULL = "tools/vue-vite/node_modules/vue/dist/vue.esm-browser.prod.js";
/** The Laravel build's public folder: its assets live under build/. */
const LARAVEL_PUBLIC_DIR = "tools/vue-vite/dist-laravel";
/** Paths origin A answers from memory, not from the build. */
const UNSWAPPED_PAGE = "/__unswapped.html";
const COMPILER_PAGE = "/__compiler-probe.html";
const VUE_FULL_PATH = "/__vue-full.js";

/** The fixture's colors (tools/vue-vite/src/*.vue). */
const SCOPED_COLOR = "rgb(12, 84, 72)";
const PRESSED_COLOR = "rgb(255, 204, 51)";
const LAZY_BACKGROUND = "rgb(240, 247, 245)";

const SETTLE_MS = 750;
const TIMEOUT_MS = 90000;

function option(name) {
  const prefix = `--${name}=`;
  const hit = process.argv.slice(2).find((arg) => arg.startsWith(prefix));
  if (!hit) {
    console.error(`missing ${prefix}<path>`);
    process.exit(2);
  }
  return hit.slice(prefix.length);
}

function optional(name) {
  const prefix = `--${name}=`;
  const hit = process.argv.slice(2).find((arg) => arg.startsWith(prefix));
  return hit ? hit.slice(prefix.length) : null;
}

/** The nonce of a CSP header value; a policy that allows eval is refused. */
function cspNonce(csp, where) {
  if (/'unsafe-eval'/.test(csp)) {
    throw new Error(`the CSP in ${where} allows 'unsafe-eval', so the template compiler probe would prove nothing`);
  }
  const nonce = /'nonce-([^']+)'/.exec(csp)?.[1];
  if (!nonce) {
    throw new Error(`the CSP in ${where} carries no nonce`);
  }
  return nonce;
}

/**
 * Read the page tools/a11y/laravel-page.php printed:
 * {"headers": {name: value}, "html": "..."}.
 */
async function loadLaravelPage(file) {
  let raw;
  try {
    raw = JSON.parse(await readFile(file, "utf8"));
  } catch (err) {
    throw new Error(`cannot read the Laravel page JSON at ${file}: ${err.message}`);
  }
  if (raw === null || typeof raw !== "object" || typeof raw.html !== "string" || raw.headers === null || typeof raw.headers !== "object") {
    throw new Error("the Laravel page JSON must be {\"headers\": {...}, \"html\": \"...\"}; run tools/a11y/laravel-page.php");
  }
  const headers = {};
  for (const [name, value] of Object.entries(raw.headers)) {
    if (typeof value !== "string") {
      throw new Error(`the Laravel page JSON must map header names to strings (bad entry: ${JSON.stringify(name)})`);
    }
    headers[name.toLowerCase()] = value;
  }
  const csp = headers["content-security-policy"];
  if (csp === undefined) {
    throw new Error("the Laravel page carries no Content-Security-Policy, so HELMET's middleware did not run");
  }
  return { headers, html: raw.html, nonce: cspNonce(csp, "the Laravel page") };
}

/** Read the headers JSON; every entry must be a string name to a string value. */
async function loadHeaders(file) {
  let raw;
  try {
    raw = JSON.parse(await readFile(file, "utf8"));
  } catch (err) {
    throw new Error(`cannot read the headers JSON at ${file}: ${err.message}`);
  }
  if (raw === null || typeof raw !== "object" || Array.isArray(raw)) {
    throw new Error("the headers JSON must be an object of header name to value");
  }
  const headers = {};
  for (const [name, value] of Object.entries(raw)) {
    if (typeof name !== "string" || typeof value !== "string") {
      throw new Error(`the headers JSON must map strings to strings (bad entry: ${JSON.stringify(name)})`);
    }
    headers[name.toLowerCase()] = value;
  }
  const csp = headers["content-security-policy"];
  if (csp === undefined) {
    throw new Error("the headers JSON carries no Content-Security-Policy; run tools/a11y/corp-headers.php first");
  }
  return { headers, nonce: cspNonce(csp, "the headers JSON") };
}

/** The HTML escaping helmet_csp_nonce_swap() applies (htmlspecialchars, ENT_QUOTES). */
function escapeHtml(value) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&apos;");
}

/** Listen on an ephemeral 127.0.0.1 port; returns { server, base }. */
function listen(server) {
  return new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => resolve({ server, base: `http://127.0.0.1:${server.address().port}` }));
  });
}

/**
 * Serve the build with HELMET's headers on every answer, 404s included.
 * "/" is the swapped page; the memory map answers its paths before the disk.
 */
function serveBuild(distDir, headers, memory) {
  const server = createServer((req, res) => {
    (async () => {
      try {
        const pathname = decodeURIComponent(new URL(req.url, "http://127.0.0.1").pathname);
        const hit = memory.get(pathname === "/" ? "/index.html" : pathname);
        if (hit) {
          res.writeHead(200, { ...headers, "content-type": hit.type });
          res.end(hit.body);
          return;
        }
        const filePath = path.normalize(path.join(distDir, pathname));
        if (!filePath.startsWith(distDir + path.sep)) {
          throw new Error(`outside the build: ${pathname}`);
        }
        const body = await readFile(filePath);
        const type = MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream";
        res.writeHead(200, { ...headers, "content-type": type });
        res.end(body);
      } catch {
        res.writeHead(404, { ...headers, "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  return listen(server);
}

/**
 * A fresh context whose pages record CSP violations from the very start
 * (Playwright's init scripts are not subject to the page's CSP), plus the
 * failed requests, non-200 answers and page errors of origin A.
 */
async function watchedPage(browser, base) {
  const context = await browser.newContext();
  await context.addInitScript(() => {
    window.__violations = [];
    document.addEventListener("securitypolicyviolation", (event) => {
      window.__violations.push({ directive: event.effectiveDirective, blocked: event.blockedURI });
    });
  });
  const page = await context.newPage();
  const seen = { failed: [], non200: [], errors: [] };
  page.on("requestfailed", (req) => seen.failed.push(`${req.url()} (${req.failure()?.errorText ?? "unknown"})`));
  page.on("response", (res) => {
    if (res.url().startsWith(base) && res.status() !== 200) seen.non200.push(`${res.url()} answered ${res.status()}`);
  });
  page.on("pageerror", (err) => seen.errors.push(err.message));
  return { context, page, seen };
}

const violations = (page) => page.evaluate(() => window.__violations);
const describe = (list) => list.map((v) => `${v.directive} blocked ${v.blocked || "(inline)"}`).join("; ");

/**
 * Load the app at base + "/" and run every positive check on it: mounted,
 * styled, interactive, the lazy chunk and its nonced stylesheet, and no CSP
 * violation, failed request or page error. label names the recipe.
 */
async function exerciseApp(browser, base, nonce, record, label) {
  const say = (passed, text) => record(passed, `${label}: ${text}`);
  const { context, page, seen } = await watchedPage(browser, base);
  await page.goto(`${base}/`, { waitUntil: "load" });
  const lazy = await page.waitForSelector('[data-check="lazy"]', { timeout: 15000 }).then(() => true, () => false);
  say(lazy, lazy ? "the app mounted and the lazily imported chunk rendered" : "the lazily imported chunk never rendered");

  const state = await page.evaluate(() => {
    const css = (selector, prop) => {
      const el = document.querySelector(selector);
      return el ? getComputedStyle(el)[prop] : null;
    };
    const img = document.querySelector('[data-check="inlined"]');
    const lazyLink = [...document.querySelectorAll('link[rel="stylesheet"]')].find((l) => l.href.includes("LazyPanel"));
    return {
      mounted: document.querySelector('[data-check="mounted"]')?.textContent ?? null,
      scoped: css('[data-check="scoped"]', "color"),
      imgSrc: img ? img.getAttribute("src").slice(0, 5) : null,
      imgWidth: img ? img.naturalWidth : -1,
      lazyBackground: css('[data-check="lazy"]', "backgroundColor"),
      lazyLinkNonce: lazyLink ? lazyLink.nonce : null,
    };
  });
  say(state.mounted === "mounted", `the app rendered its heading (${state.mounted ?? "nothing"})`);
  say(state.scoped === SCOPED_COLOR, `the scoped stylesheet applied (color ${state.scoped}, expected ${SCOPED_COLOR})`);
  say(state.imgSrc === "data:" && state.imgWidth > 0, `the inlined data: image loaded (src starts ${state.imgSrc}, naturalWidth ${state.imgWidth})`);
  say(state.lazyBackground === LAZY_BACKGROUND, `the lazy chunk's stylesheet applied (background ${state.lazyBackground}, expected ${LAZY_BACKGROUND})`);
  say(state.lazyLinkNonce === nonce, state.lazyLinkNonce === nonce ? "Vite's preload helper put the response nonce on the lazy stylesheet" : `the lazy stylesheet's nonce is ${JSON.stringify(state.lazyLinkNonce)}, not the response nonce`);

  await page.click('[data-check="count"]');
  const after = await page.evaluate(() => ({
    text: document.querySelector('[data-check="count"]')?.textContent ?? null,
    border: getComputedStyle(document.querySelector('[data-check="bound-style"]')).borderLeftColor,
  }));
  say(after.text === "Pressed 1 times", `a click updated the page (${after.text})`);
  say(after.border === PRESSED_COLOR, `the bound style followed the state (border ${after.border}, expected ${PRESSED_COLOR})`);

  await page.waitForTimeout(SETTLE_MS);
  const found = await violations(page);
  say(found.length === 0, found.length === 0 ? "no CSP violation" : `CSP violations: ${describe(found)}`);
  say(seen.failed.length === 0, seen.failed.length === 0 ? "no request failed" : `requests failed: ${seen.failed.join("; ")}`);
  say(seen.non200.length === 0, seen.non200.length === 0 ? "every request answered 200" : `requests did not answer 200: ${seen.non200.join("; ")}`);
  say(seen.errors.length === 0, seen.errors.length === 0 ? "no page error" : `page errors: ${seen.errors.join("; ")}`);
  await context.close();
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const resolve = (arg) => (path.isAbsolute(arg) ? arg : path.resolve(repoRoot, arg));
  const { headers, nonce } = await loadHeaders(resolve(option("headers")));
  const distDir = resolve(DIST_DIR);

  let built;
  let vueFull;
  try {
    built = await readFile(path.join(distDir, "index.html"), "utf8");
  } catch (err) {
    throw new Error(`cannot read ${DIST_DIR}/index.html (run npm ci and npm run build in tools/vue-vite): ${err.message}`);
  }
  try {
    vueFull = await readFile(resolve(VUE_FULL));
  } catch (err) {
    throw new Error(`cannot read ${VUE_FULL} (run npm ci in tools/vue-vite): ${err.message}`);
  }

  const lines = [];
  const failures = [];
  const record = (passed, text) => {
    lines.push(passed ? `ok: ${text}` : `FAIL: ${text}`);
    if (!passed) failures.push(text);
  };

  const slots = built.split(PLACEHOLDER).length - 1;
  record(slots >= 2, `the build carries the placeholder ${slots} times (the meta tag and at least the entry script)`);
  const swapped = built.replaceAll(PLACEHOLDER, escapeHtml(nonce));
  record(!swapped.includes(PLACEHOLDER), "no placeholder is left after the swap");

  const html = MIME[".html"];
  const probe = `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>HELMET template compiler probe</title></head>
<body>
<div id="app"></div>
<script type="module" nonce="${escapeHtml(nonce)}">
import { createApp } from "${VUE_FULL_PATH}";
window.__probeRan = true;
createApp({ data: () => ({ msg: "compiled" }), template: '<p id="compiled">{{ msg }}</p>' }).mount("#app");
</script>
</body>
</html>`;
  const memory = new Map([
    ["/index.html", { type: html, body: swapped }],
    [UNSWAPPED_PAGE, { type: html, body: built }],
    [COMPILER_PAGE, { type: html, body: probe }],
    [VUE_FULL_PATH, { type: MIME[".js"], body: vueFull }],
  ]);
  const origin = await serveBuild(distDir, headers, memory);

  // The Laravel page carries its own headers and nonce (HELMET's middleware
  // made them); its assets come from the Laravel build, under /build/.
  const laravelArg = optional("laravel");
  const laravel = laravelArg === null ? null : await loadLaravelPage(resolve(laravelArg));
  let laravelOrigin = null;
  if (laravel !== null) {
    const laravelDir = resolve(LARAVEL_PUBLIC_DIR);
    laravelOrigin = await serveBuild(laravelDir, laravel.headers, new Map([["/index.html", { type: html, body: laravel.html }]]));
    record(
      laravel.html.includes(`<meta property="csp-nonce" nonce="${escapeHtml(laravel.nonce)}">`),
      "the Laravel page carries the csp-nonce meta tag HELMET's middleware injects",
    );
    const nonced = (laravel.html.match(/<script\b[^>]*\bnonce="([^"]*)"/g) ?? []).length;
    record(nonced > 0, `the Laravel page's @vite script tags carry a nonce (${nonced} found)`);
  }

  const browser = await chromium.launch();

  try {
    /* Part 1: the swapped build, under the default policy. */
    await exerciseApp(browser, origin.base, nonce, record, "raw PHP");

    /* Part 4 (run second, it is a positive one): the page Laravel and HELMET rendered. */
    if (laravel === null) {
      lines.push("skip: no --laravel page given, the Laravel recipe was not checked");
    } else {
      await exerciseApp(browser, laravelOrigin.base, laravel.nonce, record, "Laravel");
    }

    /* Part 2: the same build without the swap; the nonce cannot match. */
    {
      const { context, page } = await watchedPage(browser, origin.base);
      await page.goto(`${origin.base}${UNSWAPPED_PAGE}`, { waitUntil: "load" });
      await page.waitForTimeout(SETTLE_MS);
      const mounted = await page.evaluate(() => document.querySelector('[data-check="mounted"]') !== null);
      const found = await violations(page);
      const refused = found.some((v) => v.directive.startsWith("script-src"));
      record(!mounted, mounted ? "without the swap the app still mounted, so the check is not watching the nonce" : "without the swap the app never mounted");
      record(refused, refused ? `without the swap the browser refused the entry script (${describe(found)})` : "without the swap no script-src violation was reported");
      await context.close();
    }

    /* Part 3: Vue's runtime template compiler needs eval, which the policy refuses. */
    {
      const { context, page } = await watchedPage(browser, origin.base);
      await page.goto(`${origin.base}${COMPILER_PAGE}`, { waitUntil: "load" });
      await page.waitForTimeout(SETTLE_MS);
      const result = await page.evaluate(() => ({ ran: window.__probeRan === true, compiled: document.getElementById("compiled") !== null }));
      const found = await violations(page);
      const evalRefused = found.some((v) => v.directive.startsWith("script-src") && v.blocked === "eval");
      record(result.ran, result.ran ? "the nonced probe module ran and imported Vue's full build" : "the nonced probe module never ran");
      record(evalRefused, evalRefused ? "the browser refused the template compiler's eval" : `no eval was refused (${describe(found) || "no violations"})`);
      record(!result.compiled, result.compiled ? "a runtime-compiled template rendered, so the policy allows eval" : "the runtime-compiled template rendered nothing");
      await context.close();
    }
  } finally {
    await browser.close();
    origin.server.close();
    laravelOrigin?.server.close();
  }

  for (const line of lines) console.log(line);
  if (failures.length > 0) {
    console.log(`VUE CHECK: FAIL ${failures.join("; ")}`);
    process.exitCode = 1;
    return;
  }
  console.log("VUE CHECK: PASS");
  process.exitCode = 0;
}

/* Armed but unref'd: it fires only if something leaks a handle and keeps the
 * event loop alive after the check is done. */
const timeout = setTimeout(() => {
  console.error("tool error: the check took longer than 90 seconds");
  process.exit(2);
}, TIMEOUT_MS);
timeout.unref();
main().catch((err) => {
  console.error(`tool error: ${err && err.message ? err.message : err}`);
  process.exit(2);
});

#!/usr/bin/env node
/**
 * COEP readiness browser gate (H13).
 *
 * HELMET does not send Cross-Origin-Embedder-Policy. This check proves what
 * an app gets when it turns the header on itself, in a real Chromium
 * browser, not just in a header dump:
 *
 *   1. Serves the site folder on origin A with the exact headers that
 *      SecurityHeaders::build() produces (read from the JSON file that
 *      tools/a11y/corp-headers.php prints) plus
 *      Cross-Origin-Embedder-Policy: require-corp on every response (this
 *      value wins over any COEP in the JSON). HTML answers get the
 *      per-request CSP nonce injected into every script tag without one,
 *      exactly like the production front controller (deploy/public/index.php,
 *      ADR-0017), so the front page behaves as it does in production. Loads
 *      the front page and asserts that no request failed, that every
 *      same-origin subresource answered 200, that the document carries
 *      cross-origin-embedder-policy: require-corp, and that
 *      window.crossOriginIsolated is true (HELMET's default
 *      Cross-Origin-Opener-Policy: same-origin completes the isolation).
 *
 *   2. Origin B serves the brand avatar twice: /bare.png with no
 *      Cross-Origin-Resource-Policy and /opted.png with
 *      Cross-Origin-Resource-Policy: cross-origin. A small probe page held
 *      in memory on origin A (/__coep-probe.html, not a file) embeds both
 *      images, and the check asserts that the browser blocked the bare one
 *      while the opted-in one loaded, so the check watches the right thing.
 *
 * Usage:  node tools/a11y/coep-check.mjs --headers=coep.json
 *         (--headers may be relative to the repo root or absolute)
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
  ".gif": "image/gif",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".md": "text/markdown; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml; charset=utf-8",
};

/** The asset origin B serves twice, with and without the CORP opt-in. */
const PROBE_IMAGE = "brand/helmet-avatar-256.png";
/** The in-memory probe page origin A answers, and the COEP value under test. */
const PROBE_PAGE = "/__coep-probe.html";
const COEP_VALUE = "require-corp";

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
  if (!Object.hasOwn(headers, "cross-origin-opener-policy")) {
    throw new Error("the headers JSON carries no Cross-Origin-Opener-Policy; run tools/a11y/corp-headers.php first");
  }
  return headers;
}

/** Listen on an ephemeral 127.0.0.1 port; returns { server, base }. */
function listen(server) {
  return new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => resolve({ server, base: `http://127.0.0.1:${server.address().port}` }));
  });
}

/**
 * Put the CSP nonce on every <script start tag that lacks one, the same
 * rewrite deploy/public/index.php applies to HTML before sending it
 * (ADR-0017). Without it the strict-dynamic policy blocks the page's own
 * external scripts, which is a CSP matter, not a COEP one.
 */
function injectCspNonce(html, nonce) {
  if (!nonce) return html;
  return html.replace(/<script(?=[\s>])([^>]*)>/gi, (tag, attrs) => {
    if (/\bnonce\s*=/i.test(attrs)) return tag;
    return `<script nonce="${nonce}"${attrs}>`;
  });
}

/**
 * Serve rootDir over http; "/" maps to homePage. Every response, including
 * 404s, carries the given headers, and HTML answers get the CSP nonce
 * injected so they behave as they do behind the production front controller.
 * probePage, when given, is answered from memory before the disk is
 * consulted, with its own header set.
 */
async function serveSite(rootDir, homePage, headers, probePage) {
  const csp = headers["content-security-policy"] ?? "";
  const nonce = /'nonce-([^']+)'/.exec(csp)?.[1] ?? null;
  const server = createServer((req, res) => {
    (async () => {
      try {
        const url = new URL(req.url, "http://127.0.0.1");
        let pathname = decodeURIComponent(url.pathname);
        if (probePage && pathname === probePage.path) {
          res.writeHead(200, { ...probePage.headers, "content-type": MIME[".html"] });
          res.end(probePage.html);
          return;
        }
        if (pathname === "/") pathname = `/${homePage}`;
        const filePath = path.normalize(path.join(rootDir, pathname));
        if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) {
          throw new Error(`outside the site root: ${pathname}`);
        }
        const body = await readFile(filePath);
        const type = MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream";
        const payload = type === "text/html; charset=utf-8" ? injectCspNonce(body.toString("utf8"), nonce) : body;
        res.writeHead(200, { ...headers, "content-type": type });
        res.end(payload);
      } catch {
        res.writeHead(404, { ...headers, "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  return listen(server);
}

/**
 * Serve one PNG on its own origin: /bare.png with no CORP header and
 * /opted.png with Cross-Origin-Resource-Policy: cross-origin.
 */
function serveAvatar(png) {
  const server = createServer((req, res) => {
    const pathname = new URL(req.url, "http://127.0.0.1").pathname;
    if (pathname === "/bare.png" || pathname === "/opted.png") {
      const extra = pathname === "/opted.png" ? { "cross-origin-resource-policy": "cross-origin" } : {};
      res.writeHead(200, { "content-type": "image/png", ...extra });
      res.end(png);
      return;
    }
    res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
    res.end("not found");
  });
  return listen(server);
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const headersArg = option("headers");
  const headersFile = path.isAbsolute(headersArg) ? headersArg : path.resolve(repoRoot, headersArg);
  const headers = await loadHeaders(headersFile);
  const rootDir = path.resolve(repoRoot, "site");

  let avatar;
  try {
    avatar = await readFile(path.join(rootDir, PROBE_IMAGE));
  } catch (err) {
    throw new Error(`cannot read the probe image at site/${PROBE_IMAGE}: ${err.message}`);
  }

  const servedHeaders = { ...headers, "cross-origin-embedder-policy": COEP_VALUE };
  const originB = await serveAvatar(avatar);
  // The probe page keeps the header set but drops the site's CSP: that
  // policy only allows 'self' and wayneui images, so it would block the
  // probe's origin B images for a CSP reason, not a COEP one.
  const probeHeaders = { ...servedHeaders };
  delete probeHeaders["content-security-policy"];
  const probeHtml = `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>HELMET COEP probe</title></head>
<body>
<img id="bare" src="${originB.base}/bare.png" alt="">
<img id="opted" src="${originB.base}/opted.png" alt="">
</body>
</html>`;
  const originA = await serveSite(rootDir, "index.html", servedHeaders, { path: PROBE_PAGE, html: probeHtml, headers: probeHeaders });

  const browser = await chromium.launch();
  const failures = [];
  const lines = [];
  const record = (passed, text) => {
    lines.push(passed ? `ok: ${text}` : `FAIL: ${text}`);
    if (!passed) failures.push(text);
  };
  try {
    /* Part 1: the front page on its own origin, wearing the full header set plus COEP. */
    {
      const context = await browser.newContext();
      const page = await context.newPage();
      const failedRequests = [];
      const non200 = [];
      page.on("requestfailed", (req) => {
        failedRequests.push(`${req.url()} (${req.failure() ? req.failure().errorText : "unknown"})`);
      });
      page.on("response", (res) => {
        if (res.url().startsWith(originA.base) && res.status() !== 200) {
          non200.push(`${res.url()} answered ${res.status()}`);
        }
      });
      const doc = await page.goto(`${originA.base}/index.html`, { waitUntil: "load" });
      if (doc === null) {
        record(false, "the front page did not load");
      } else {
        const coep = doc.headers()["cross-origin-embedder-policy"];
        record(coep === COEP_VALUE, `the front page carries cross-origin-embedder-policy: ${coep === undefined ? "(none)" : coep}, expected ${COEP_VALUE}`);
        await page.evaluate(() => document.fonts.ready);
        const isolated = await page.evaluate(() => window.crossOriginIsolated);
        record(isolated === true, `window.crossOriginIsolated is ${isolated}, expected true (COOP same-origin plus COEP ${COEP_VALUE})`);
      }
      record(failedRequests.length === 0, failedRequests.length === 0 ? "no request failed on the front page" : `requests failed on the front page: ${failedRequests.join("; ")}`);
      record(non200.length === 0, non200.length === 0 ? "every same-origin subresource answered 200" : `same-origin subresources did not answer 200: ${non200.join("; ")}`);
      await context.close();
    }

    /* Part 2: the probe page, whose two images differ only by the CORP opt-in. */
    {
      const context = await browser.newContext();
      const page = await context.newPage();
      const blocked = new Map();
      page.on("requestfailed", (req) => {
        blocked.set(req.url(), req.failure() ? req.failure().errorText : "");
      });
      await page.goto(`${originA.base}${PROBE_PAGE}`, { waitUntil: "load" });
      const bareUrl = `${originB.base}/bare.png`;
      const widths = await page.evaluate(() => {
        const bare = document.getElementById("bare");
        const opted = document.getElementById("opted");
        return { bare: bare ? bare.naturalWidth : -1, opted: opted ? opted.naturalWidth : -1 };
      });
      const bareError = blocked.get(bareUrl);
      const bareBlocked = bareError !== undefined || widths.bare === 0;
      record(bareBlocked, bareBlocked ? `the third-party image without CORP was blocked (failure: ${bareError === undefined ? "none" : bareError}, naturalWidth: ${widths.bare})` : `the third-party image without CORP was not blocked (failure: none, naturalWidth: ${widths.bare})`);
      record(widths.opted > 0, widths.opted > 0 ? `the third-party image with Cross-Origin-Resource-Policy: cross-origin loaded (naturalWidth: ${widths.opted})` : `the third-party image with Cross-Origin-Resource-Policy: cross-origin did not load (naturalWidth: ${widths.opted})`);
      await context.close();
    }
  } finally {
    await browser.close();
    originA.server.close();
    originB.server.close();
  }

  for (const line of lines) console.log(line);
  if (failures.length > 0) {
    console.log(`COEP CHECK: FAIL ${failures.join("; ")}`);
    process.exitCode = 1;
    return;
  }
  console.log("COEP CHECK: PASS");
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

#!/usr/bin/env node
/**
 * CORP opt-in browser gate (H13).
 *
 * Proves that HELMET's Cross-Origin-Resource-Policy opt-in works in a real
 * Chromium browser, not just in a header dump:
 *
 *   1. Serves the site folder on origin A with the exact headers that
 *      SecurityHeaders::build() produces with corp => 'same-origin' (read
 *      from the JSON file that tools/a11y/corp-headers.php prints), on every
 *      response. HTML answers get the per-request CSP nonce injected into
 *      every script tag without one, exactly like the production front
 *      controller (deploy/public/index.php, ADR-0017), so the front page
 *      behaves as it does in production. Loads the front page and asserts
 *      that no request failed, that every same-origin subresource answered
 *      200, and that the document carries
 *      cross-origin-resource-policy: same-origin.
 *
 *   2. Serves a tiny page on origin B that embeds one image and one script
 *      from origin A, loads it, and asserts that the browser blocked both
 *      (a requestfailed with net::ERR_BLOCKED_BY_RESPONSE, or an image whose
 *      naturalWidth is 0).
 *
 * With corp => 'cross-origin' the second part must fail; run it by editing
 * the JSON by hand to prove this check watches the right thing.
 *
 * Usage:  node tools/a11y/corp-check.mjs --headers=corp.json
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

/** The two front-page assets origin B tries to steal. */
const EMBED_IMAGE = "brand/helmet-avatar-256.png";
const EMBED_SCRIPT = "assets/helmet.js";

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
  if (!Object.hasOwn(headers, "cross-origin-resource-policy")) {
    throw new Error("the headers JSON carries no Cross-Origin-Resource-Policy; run tools/a11y/corp-headers.php first");
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
 * external scripts, which is a CSP matter, not a CORP one.
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
 */
async function serveSite(rootDir, homePage, headers) {
  const csp = headers["content-security-policy"] ?? "";
  const nonce = /'nonce-([^']+)'/.exec(csp)?.[1] ?? null;
  const server = createServer((req, res) => {
    (async () => {
      try {
        const url = new URL(req.url, "http://127.0.0.1");
        let pathname = decodeURIComponent(url.pathname);
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

/** Serve one static HTML document on its own origin, with no security headers. */
async function serveStatic(html) {
  const server = createServer((req, res) => {
    res.writeHead(200, { "content-type": "text/html; charset=utf-8" });
    res.end(html);
  });
  return listen(server);
}

/** True when Playwright reports the fetch as blocked by a response header. */
function blockedByCorp(errorText) {
  return typeof errorText === "string" && errorText.includes("ERR_BLOCKED_BY_RESPONSE");
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const headersArg = option("headers");
  const headersFile = path.isAbsolute(headersArg) ? headersArg : path.resolve(repoRoot, headersArg);
  const headers = await loadHeaders(headersFile);
  const rootDir = path.resolve(repoRoot, "site");

  const originA = await serveSite(rootDir, "index.html", headers);
  const embedPage = `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>HELMET CORP embed</title></head>
<body>
<img id="corp-image" src="${originA.base}/${EMBED_IMAGE}" width="40" height="40" alt="">
<script src="${originA.base}/${EMBED_SCRIPT}"></script>
</body>
</html>`;
  const originB = await serveStatic(embedPage);

  const browser = await chromium.launch();
  const failures = [];
  const fail = (reason) => {
    failures.push(reason);
  };
  try {
    /* Part 1: the front page on its own origin, wearing the full header set. */
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
        fail("the front page did not load");
      } else {
        const corp = doc.headers()["cross-origin-resource-policy"];
        if (corp !== "same-origin") {
          fail(`the front page answered with cross-origin-resource-policy: ${corp === undefined ? "(none)" : corp}, expected same-origin`);
        }
      }
      await page.evaluate(() => document.fonts.ready);
      if (failedRequests.length > 0) {
        fail(`requests failed on the front page: ${failedRequests.join("; ")}`);
      }
      if (non200.length > 0) {
        fail(`same-origin subresources did not answer 200: ${non200.join("; ")}`);
      }
      await context.close();
    }

    /* Part 2: another origin trying to embed the front page's assets. */
    {
      const context = await browser.newContext();
      const page = await context.newPage();
      const blocked = new Map();
      page.on("requestfailed", (req) => {
        blocked.set(req.url(), req.failure() ? req.failure().errorText : "");
      });
      await page.goto(`${originB.base}/`, { waitUntil: "load" });
      const imageUrl = `${originA.base}/${EMBED_IMAGE}`;
      const scriptUrl = `${originA.base}/${EMBED_SCRIPT}`;
      const imageError = blocked.get(imageUrl);
      const scriptError = blocked.get(scriptUrl);
      const naturalWidth = await page.evaluate(() => {
        const el = document.getElementById("corp-image");
        return el ? el.naturalWidth : -1;
      });
      if (!blockedByCorp(imageError) && !(imageError === undefined && naturalWidth === 0)) {
        fail(`the cross-origin image was not blocked (failure: ${imageError === undefined ? "none" : imageError}, naturalWidth: ${naturalWidth})`);
      }
      if (!blockedByCorp(scriptError)) {
        fail(`the cross-origin script was not blocked (failure: ${scriptError === undefined ? "none" : scriptError})`);
      }
      await context.close();
    }
  } finally {
    await browser.close();
    originA.server.close();
    originB.server.close();
  }

  if (failures.length > 0) {
    console.log(`CORP CHECK: FAIL ${failures.join("; ")}`);
    process.exitCode = 1;
    return;
  }
  console.log("CORP CHECK: PASS");
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

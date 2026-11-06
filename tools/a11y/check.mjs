#!/usr/bin/env node
/**
 * Site accessibility gate.
 *
 * Serves the site folder on a local port, opens each page in a real Chromium
 * browser and runs axe-core against it with the WCAG 2.2 AA tag set, in light
 * mode and in dark mode (colorScheme emulation) at a narrow (360 px) and a
 * wide (1280 px) viewport. The page is analyzed with reduced motion, which is
 * the page's own static, fully visible state, so scroll reveals never hide
 * content from the check.
 *
 * Usage:  node tools/a11y/check.mjs [--root=site] [--page=index.html]
 *         --page may repeat, and a value may be a glob over the site tree
 *         (the shell scripts pass the front page plus every docs page).
 * Exit:   0 clean, 1 violations found, 2 tool error.
 */
import { createServer } from "node:http";
import { readdirSync } from "node:fs";
import { readFile } from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];
const MODES = ["light", "dark"];
const WIDTHS = [360, 1280];
const HEIGHT = 900;

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

function option(name, fallback) {
  const prefix = `--${name}=`;
  const hit = process.argv.slice(2).find((arg) => arg.startsWith(prefix));
  return hit ? hit.slice(prefix.length) : fallback;
}

/** Every --name=value on the command line, or [fallback] when there is none. */
function options(name, fallback) {
  const prefix = `--${name}=`;
  const hits = process.argv
    .slice(2)
    .filter((arg) => arg.startsWith(prefix))
    .map((arg) => arg.slice(prefix.length));
  return hits.length > 0 ? hits : [fallback];
}

/** Turn a glob (*, ?, **) into a RegExp over slash-separated relative paths. */
function globToRegExp(pattern) {
  let re = "";
  for (let i = 0; i < pattern.length; i++) {
    const ch = pattern[i];
    if (ch === "*") {
      if (pattern[i + 1] === "*" && (i + 2 >= pattern.length || pattern[i + 2] === "/")) {
        // ** crosses directory boundaries, matching zero or more of them.
        re += i + 2 < pattern.length ? "(?:[^/]+/)*" : ".*";
        i += i + 2 < pattern.length ? 2 : 1;
      } else {
        re += "[^/]*";
      }
    } else if (ch === "?") {
      re += "[^/]";
    } else {
      re += ch.replace(/[\\^$.*+?()[\]{}|]/g, "\\$&");
    }
  }
  return new RegExp(`^${re}$`);
}

/** Slash-separated relative paths of every file under dir. */
function listFiles(dir, base = dir, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      listFiles(full, base, out);
    } else {
      out.push(path.relative(base, full).split(path.sep).join("/"));
    }
  }
  return out;
}

/** Serve rootDir over http on an ephemeral port; "/" maps to homePage. */
async function serve(rootDir, homePage) {
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
        res.writeHead(200, { "content-type": type });
        res.end(body);
      } catch {
        res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const rootDir = path.resolve(repoRoot, option("root", "site"));
  const pageSpecs = options("page", "index.html");

  const allFiles = listFiles(rootDir);
  const pages = [];
  for (const spec of pageSpecs) {
    const matched = allFiles.filter((rel) => globToRegExp(spec).test(rel)).sort();
    if (matched.length === 0) {
      console.error(`no page under ${rootDir} matches --page=${spec}`);
      process.exit(2);
    }
    for (const rel of matched) {
      if (!pages.includes(rel)) pages.push(rel);
    }
  }

  const { server, base } = await serve(rootDir, pages[0]);
  const browser = await chromium.launch();
  let total = 0;
  try {
    for (const pageName of pages) {
      for (const scheme of MODES) {
        for (const width of WIDTHS) {
          const context = await browser.newContext({
            colorScheme: scheme,
            reducedMotion: "reduce",
            viewport: { width, height: HEIGHT },
          });
          const page = await context.newPage();
          await page.goto(`${base}/${encodeURI(pageName)}`, { waitUntil: "load" });
          await page.evaluate(() => document.fonts.ready);
          const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
          total += results.violations.length;
          console.log(`${pageName} ${scheme} ${width}px: ${results.violations.length} violation${results.violations.length === 1 ? "" : "s"}`);
          for (const violation of results.violations) {
            console.log(`  ${violation.id} (${violation.impact}): ${violation.help}`);
            for (const node of violation.nodes) {
              console.log(`    ${node.target.join(" ")}`);
            }
          }
          await context.close();
        }
      }
    }
  } finally {
    await browser.close();
    server.close();
  }
  console.log(total === 0 ? "0 violations" : `${total} violations`);
  process.exit(total === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(`tool error: ${err && err.message ? err.message : err}`);
  process.exit(2);
});

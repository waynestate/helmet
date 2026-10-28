#!/usr/bin/env node
/* ============================================================================
   HELMET README art kit.

   Renders the README images from this repo's own static front page (site/),
   brand files and PHP source, and writes them under docs/readme/. Nothing is
   drawn by hand: the hero and the gallery are screenshots of the real page,
   the cast is the marks resized (WayneUI composed on its green tile), the
   badges read their facts from composer.json, and the play diagram reads
   every header name and default value out of src/Headers/SecurityHeaders.php
   (cross-checked against config/helmet.php). If the PHP changes, the chalk
   changes with it; if the parse fails, the build fails. No guessing on the
   board.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
const FORBIDDEN = /GitLab|gitlab\.example|citadel/i; // internal names never ship in art

// The page's own palette (site/assets/helmet.css :root).
const C = {
  green: "#0C5449",
  gold: "#FFCC33",
  cream: "#F4F1EA",
  slate: "#15291F",
  slate2: "#0F1F18",
  chalk: "#EEF0E6",
  chalkDim: "#C3C9BD",
  berryChalk: "#F59AB9",
};

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

function assertTextClean(text, what) {
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

function esc(s) {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 40 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // HELMET's own mark is not in the shared marks folder; its avatar export is.
  if (name === "helmet") return path.join(siteDir, "brand", "helmet-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks WELD and SESAME.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) fail(`missing cast source ${path.relative(repoRoot, src)}`);
    const png = await sharp(readFileSync(src))
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read from composer.json, never typed: a badge that lies is a
// false start, and false starts cost five yards.
function buildBadges() {
  console.log("badges:");
  const composer = JSON.parse(readFileSync(path.join(repoRoot, "composer.json"), "utf8"));
  const psr15 = composer.require["psr/http-server-middleware"];
  const defs = [
    { key: "php", label: "php", message: composer.require.php },
    { key: "license", label: "licence", message: composer.license },
    // PSR-15 is what the middleware path implements; the badge only exists
    // while composer.json requires the PSR-15 interfaces.
    { key: "psr15", label: "psr-15", message: psr15 ? "middleware" : undefined },
  ];
  for (const d of defs) {
    if (!d.message) fail(`composer.json has no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: C.slate,
      color: C.gold,
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, Buffer.from(svg, "utf8"));
  }
}

/* --------------------------------------------- 3. the headers, from the PHP */

// Every name and value on the board comes from SecurityHeaders.php. Each
// regex below is a check as much as a read: if the source stops saying what
// the board says, the build stops.
function readHeaders() {
  const php = readFileSync(path.join(repoRoot, "src", "Headers", "SecurityHeaders.php"), "utf8").replace(/\r\n/g, "\n");
  const cfg = readFileSync(path.join(repoRoot, "config", "helmet.php"), "utf8").replace(/\r\n/g, "\n");

  function method(name) {
    const start = php.indexOf(`function ${name}(`);
    if (start < 0) fail(`SecurityHeaders.php: no function ${name}()`);
    const end = php.indexOf("\n    }\n", start);
    if (end < 0) fail(`SecurityHeaders.php: cannot find the end of ${name}()`);
    const text = php.slice(start, end);
    const bodyAt = text.indexOf("{\n");
    return { sig: text.slice(0, bodyAt), body: text.slice(bodyAt) };
  }
  function need(re, where, what) {
    const m = where.match(re);
    if (!m) fail(`SecurityHeaders.php: cannot read ${what} (${re})`);
    return m;
  }

  const build = method("build");
  const headers = []; // { name, value: string, note?: string }

  // Content-Security-Policy: enforced unless report-only is asked for.
  const cspNames = need(/\?\s*'([\w-]+)'\s*:\s*'([\w-]+)'/, build.body, "the CSP header name");
  need(/bool \$cspReportOnly = false/, build.sig, "the report-only default");
  const cspName = cspNames[2];
  const csp = method("defaultCspPolicy").body;
  const directives = [...csp.matchAll(/'([a-z-]+)' => \[([^\]]*)\]/g)].map((m) => ({
    name: m[1],
    sources: [...m[2].matchAll(/"([^"]*)"|'([^']*)'/g)].map((s) => s[1] ?? s[2]),
  }));
  if (directives.length < 5) fail(`defaultCspPolicy(): read only ${directives.length} directives`);
  // The lock-down directives: one keyword each ('self' or 'none').
  const locks = directives.filter((d) => d.sources.length === 1 && /^'(self|none)'$/.test(d.sources[0]));
  const cspBuild = method("buildCsp").body;
  need(/->nonce\(/, cspBuild, "the CSP nonce");
  need(/setStrictDynamic\('script-src', true\)/, cspBuild, "strict-dynamic");
  need(/function generateNonce\(\)/, php, "the per-request nonce");
  headers.push({
    name: cspName,
    value: locks.map((d) => `${d.name} ${d.sources[0]}`).join("; "),
    note: `+ ${directives.length - locks.length} more directives, a fresh nonce, 'strict-dynamic'`,
  });

  // The fixed three, straight out of the $headers literal.
  const literal = need(/\$headers = \[([\s\S]*?)\];/, build.body, "the $headers literal")[1];
  for (const m of literal.matchAll(/'([\w-]+)' => '([^']*)'/g)) headers.push({ name: m[1], value: m[2] });

  // Permissions-Policy: the default deny list, rendered feature=().
  need(/\$headers\['Permissions-Policy'\]/, build.body, "the Permissions-Policy header");
  const pp = method("buildPermissionsPolicy").body;
  need(/'\(\)'/, pp, "the empty allowlist form");
  need(/implode\(', ', \$parts\)/, pp, "the Permissions-Policy separator");
  const features = [...method("defaultPermissionsPolicy").body.matchAll(/'([a-z-]+)' => \[\]/g)].map((m) => m[1]);
  if (features.length === 0) fail("defaultPermissionsPolicy(): no features");
  headers.push({ name: "Permissions-Policy", value: features.map((f) => `${f}=()`).join(", ") });

  // Cross-Origin-Opener-Policy: on by default.
  const coop = need(/\?string \$coop = '([^']+)'/, build.sig, "the COOP default")[1];
  need(/\$headers\['Cross-Origin-Opener-Policy'\]/, build.body, "the COOP header");
  headers.push({ name: "Cross-Origin-Opener-Policy", value: coop });

  // Strict-Transport-Security: HTTPS responses only.
  const maxAge = need(/int \$hstsMaxAge = (\d+)/, build.sig, "the HSTS max-age default")[1];
  const subs = need(/bool \$hstsIncludeSubDomains = (true|false)/, build.sig, "the HSTS subdomain default")[1];
  const hsts = method("buildHsts").body;
  need(/'max-age=' \. \$maxAge/, hsts, "the HSTS max-age form");
  need(/'; includeSubDomains'/, hsts, "the includeSubDomains form");
  need(/if \(\$isHttps\) \{\s*\$headers\['Strict-Transport-Security'\]/, build.body, "the HTTPS-only HSTS rule");
  headers.push({
    name: "Strict-Transport-Security",
    value: `max-age=${maxAge}${subs === "true" ? "; includeSubDomains" : ""}`,
    note: "HTTPS responses only",
  });

  // Cross-Origin-Resource-Policy: off unless asked for. Not on the field.
  need(/\?string \$corp = null/, build.sig, "the CORP default");
  const bench = "Cross-Origin-Resource-Policy";

  // The published config must agree with the core's defaults, or the board
  // would show one app's defaults and the other path would ship another.
  const agree = [
    [new RegExp(`'HELMET_HSTS_MAX_AGE', ${maxAge}\\)`), "HSTS max-age"],
    [new RegExp(`'HELMET_HSTS_INCLUDE_SUBDOMAINS', ${subs}\\)`), "HSTS includeSubDomains"],
    [new RegExp(`'HELMET_COOP', '${coop}'\\)`), "COOP"],
    [/'HELMET_CSP_REPORT_ONLY', false\)/, "CSP report-only"],
    [/\$corp = env\('HELMET_CORP'\);/, "CORP"],
  ];
  for (const [re, what] of agree) if (!re.test(cfg)) fail(`config/helmet.php disagrees with the core on ${what}`);

  // Board order is the order build() sets them.
  const order = (h) => {
    const i = build.body.indexOf(h.name === cspName ? "'Content-Security-Policy'" : `'${h.name}'`);
    if (i < 0) fail(`build(): no ${h.name}`);
    return i;
  };
  headers.sort((a, b) => order(a) - order(b));
  if (headers.length !== 7) fail(`expected seven default headers, read ${headers.length}`);
  for (const h of headers) assertTextClean(`${h.name} ${h.value} ${h.note ?? ""}`, `header ${h.name}`);
  return { headers, bench };
}

/* ---------------------------------------------------------- 4. the play SVG */

const FONT_GRAD = readFileSync(path.join(siteDir, "assets", "fonts", "graduate-latin-400-normal.woff2")).toString("base64");
const FONT_MARK = readFileSync(path.join(siteDir, "assets", "fonts", "permanent-marker-latin-400-normal.woff2")).toString("base64");

const T = {
  name: { family: "Graduate", size: 19, lh: 28 },
  // Tracked out a little: untracked, Permanent Marker closes "()" into a "0".
  value: { family: "Permanent Marker", size: 17, lh: 25, track: 1.2 },
  note: { family: "Permanent Marker", size: 14, lh: 21 },
};

// Text widths come from the real fonts in a real browser, so the layout fits
// the chalk that will actually be drawn.
async function measurer(page) {
  await page.setContent("<!doctype html><html><body></body></html>");
  await page.evaluate(
    async ([g, m]) => {
      for (const [fam, b64] of [
        ["Graduate", g],
        ["Permanent Marker", m],
      ]) {
        const f = new FontFace(fam, `url(data:font/woff2;base64,${b64})`);
        await f.load();
        document.fonts.add(f);
      }
    },
    [FONT_GRAD, FONT_MARK],
  );
  return async (items) =>
    page.evaluate((list) => {
      const ctx = document.createElement("canvas").getContext("2d");
      return list.map(([text, fam, size, track = 0]) => {
        ctx.font = `${size}px "${fam}"`;
        ctx.letterSpacing = `${track}px`;
        return ctx.measureText(text).width;
      });
    }, items);
}

// Wrap a value at its own separators ("; " and ", "), never mid-token.
function wrap(tokens, widths, max) {
  const lines = [];
  let cur = "";
  let curW = 0;
  tokens.forEach((t, i) => {
    const w = widths[i];
    if (cur && curW + w > max) {
      lines.push(cur.trimEnd());
      cur = "";
      curW = 0;
    }
    cur += t;
    curW += w;
  });
  if (cur) lines.push(cur.trimEnd());
  return lines;
}

async function buildPlay(page, headers, bench) {
  console.log("play:");
  const measure = await measurer(page);

  const W = 1280;
  const H = 720;
  const LEFT = 64; // inner text margin
  const RIGHT = W - 64;
  const LOS_Y = 540;
  const O_Y = 576;
  const O_R = 17;
  const HOOK = 24; // route corner radius
  const SPACING = 72;
  const TOP = 128; // first label block
  const BOTTOM = LOS_Y - 30;

  // Slots: seven O's on the line. The centre four route left, the outer
  // three right; the further from centre, the lower and narrower the slot,
  // so no route crosses another or runs through a label.
  const slots = [];
  for (let i = 0; i < 7; i++) {
    const ox = 640 + (i - 3) * SPACING;
    if (i <= 3) slots.push({ ox, side: -1, rank: 3 - i });
    else slots.push({ ox, side: 1, rank: i - 4 });
  }
  for (const s of slots) {
    s.ex = s.ox + s.side * (HOOK + 10); // arrow tip
    s.tx = s.ex + s.side * 14; // text anchor
    s.avail = s.side < 0 ? s.tx - LEFT : RIGHT - s.tx;
  }

  // Measure every header: name, the value as tokens, the note.
  const prepared = [];
  for (const h of headers) {
    const tokens = h.value.split(/(?<=[;,] )/);
    const [nameW, valueW, ...tokW] = await measure([
      [h.name, T.name.family, T.name.size],
      [h.value, T.value.family, T.value.size, T.value.track],
      ...tokens.map((t) => [t, T.value.family, T.value.size, T.value.track]),
    ]);
    const [noteW] = h.note ? await measure([[h.note, T.note.family, T.note.size]]) : [0];
    prepared.push({ ...h, tokens, tokW, nameW, valueW, noteW, natural: Math.max(nameW, valueW, noteW) });
  }

  // Widest header to the widest slot.
  const bySize = [...prepared].sort((a, b) => b.natural - a.natural);
  const byRoom = [...slots].sort((a, b) => b.avail - a.avail || a.side - b.side);
  bySize.forEach((h, i) => {
    const s = byRoom[i];
    h.slot = s;
    if (h.nameW > s.avail || h.noteW > s.avail) fail(`${h.name} does not fit its slot (${Math.round(h.nameW)} > ${s.avail})`);
    h.lines = wrap(h.tokens, h.tokW, s.avail);
    h.width = Math.max(h.nameW, h.noteW, ...(lineWidths(h)));
    h.height = T.name.lh + h.lines.length * T.value.lh + (h.note ? T.note.lh : 0);
  });

  // Stack each side top to bottom by rank, spread evenly between TOP and BOTTOM.
  for (const side of [-1, 1]) {
    const col = prepared.filter((h) => h.slot.side === side).sort((a, b) => a.slot.rank - b.slot.rank);
    const total = col.reduce((n, h) => n + h.height, 0);
    const gap = col.length > 1 ? (BOTTOM - TOP - total) / (col.length - 1) : 0;
    if (gap < 12) fail(`labels on the ${side < 0 ? "left" : "right"} do not fit the board (gap ${gap})`);
    let y = TOP;
    for (const h of col) {
      h.top = y;
      y += h.height + gap;
    }
  }

  const build = prepared.map((h) => headers.indexOf(headers.find((x) => x.name === h.name)));
  const START = 1.7; // after the line and the O's are drawn
  const STEP = 0.95;

  const s = [];
  const desc = `A chalk play diagram. Seven circles on the line of scrimmage are the seven headers HELMET sends by default, and each runs a route to its name and value, in the order HELMET sets them: ${headers
    .map((h) => `${h.name}: ${h.value}${h.note ? ` (${h.note})` : ""}`)
    .join("; ")}. ${bench} waits on the bench: it is opt-in.`;

  s.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-labelledby="play-title play-desc">`);
  s.push(`  <title id="play-title">HELMET's default response headers, drawn as a play</title>`);
  s.push(`  <desc id="play-desc">${esc(desc)}</desc>`);
  s.push(`  <style>
    @font-face { font-family: "Graduate"; src: url(data:font/woff2;base64,${FONT_GRAD}) format("woff2"); }
    @font-face { font-family: "Permanent Marker"; src: url(data:font/woff2;base64,${FONT_MARK}) format("woff2"); }
    .nm { font-family: "Graduate", Rockwell, Georgia, serif; font-size: ${T.name.size}px; fill: ${C.gold}; }
    .vl { font-family: "Permanent Marker", "Segoe Print", cursive; font-size: ${T.value.size}px; letter-spacing: ${T.value.track}px; fill: ${C.chalk}; }
    .nt { font-family: "Permanent Marker", "Segoe Print", cursive; font-size: ${T.note.size}px; fill: ${C.chalkDim}; }
    .ttl { font-family: "Graduate", Rockwell, Georgia, serif; font-size: 30px; fill: ${C.chalk}; letter-spacing: .04em; }
    .eyb { font-family: "Permanent Marker", "Segoe Print", cursive; font-size: 18px; fill: ${C.gold}; }
    .los-l { font-family: "Permanent Marker", "Segoe Print", cursive; font-size: 14px; fill: rgba(238,240,230,.6); }
    .ln path, .ln line, .ln circle { fill: none; stroke-linecap: round; stroke-linejoin: round; }
    .hash line { stroke: rgba(238,240,230,.28); stroke-width: 2; }
    .los { stroke: rgba(238,240,230,.55); stroke-width: 2.5; stroke-dasharray: 12 9; }
    .o { stroke: ${C.chalk}; stroke-width: 4; }
    .rt { stroke: ${C.chalk}; stroke-width: 3; }
    .ah { stroke: ${C.chalk}; stroke-width: 3; }
    .pocket { stroke: rgba(255,204,51,.6); stroke-width: 2.5; stroke-dasharray: 3 7; }
    .bench { stroke: ${C.berryChalk}; stroke-width: 2.5; }
    .bench-t { font-family: "Permanent Marker", "Segoe Print", cursive; font-size: 15px; fill: ${C.berryChalk}; }
    .rv { transform-box: fill-box; transform-origin: 0 50%; }
    .qb { transform-box: fill-box; transform-origin: 50% 50%; }
    @keyframes draw { from { stroke-dashoffset: 1; } to { stroke-dashoffset: 0; } }
    @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes write { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    @keyframes snap { 0% { opacity: 0; transform: scale(.6); } 70% { opacity: 1; transform: scale(1.08); } 100% { opacity: 1; transform: scale(1); } }
    @media (prefers-reduced-motion: no-preference) {
      .a-draw { stroke-dasharray: 1; animation: draw var(--t, .5s) ease-out var(--d, 0s) both; }
      .a-fade { animation: fade .5s ease-out var(--d, 0s) both; }
      /* jump-start: the last jump lands mid-animation; a jump at the very end left clipPaths unrepainted at 13/14. */
      .a-write { animation: write var(--t, .6s) steps(14, jump-start) var(--d, 0s) both; }
      .a-snap { animation: snap .45s ease-out var(--d, 0s) both; }
    }
    @media (prefers-reduced-motion: reduce) { * { animation: none !important; } }
  </style>`);
  s.push(`  <defs>
    <linearGradient id="frame" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#C4CBC6"/><stop offset=".4" stop-color="#7D8681"/><stop offset=".6" stop-color="#B5BCB7"/><stop offset="1" stop-color="#6F7873"/>
    </linearGradient>
    <linearGradient id="slate" x1="0" y1="0" x2=".45" y2="1">
      <stop offset="0" stop-color="#1B3428"/><stop offset=".55" stop-color="${C.slate}"/><stop offset="1" stop-color="#11231A"/>
    </linearGradient>
    <radialGradient id="smudge" cx=".68" cy=".4" r=".55">
      <stop offset="0" stop-color="#EEF0E6" stop-opacity=".07"/><stop offset="1" stop-color="#EEF0E6" stop-opacity="0"/>
    </radialGradient>
    <filter id="dust" x="0" y="0" width="100%" height="100%">
      <feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="3" stitchTiles="stitch"/>
      <feColorMatrix values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 .07 0"/>
    </filter>
    <filter id="chalk" filterUnits="userSpaceOnUse" x="0" y="0" width="${W}" height="${H}">
      <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="2" seed="7" result="n"/>
      <feDisplacementMap in="SourceGraphic" in2="n" scale="2.4" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
    <filter id="chalk-t" filterUnits="userSpaceOnUse" x="0" y="0" width="${W}" height="${H}">
      <feTurbulence type="fractalNoise" baseFrequency="1.1" numOctaves="2" seed="3" result="n"/>
      <feDisplacementMap in="SourceGraphic" in2="n" scale="1.4" xChannelSelector="R" yChannelSelector="G"/>
    </filter>`);
  for (const [i, h] of prepared.entries()) {
    const x = h.slot.side < 0 ? h.slot.tx - h.width - 6 : h.slot.tx - 6;
    s.push(`    <clipPath id="w${i}"><rect class="rv a-write" style="--d:${(START + build[i] * STEP + 0.55).toFixed(2)}s" x="${x.toFixed(1)}" y="${h.top - 6}" width="${(h.width + 12).toFixed(1)}" height="${h.height + 14}"/></clipPath>`);
  }
  s.push(`  </defs>`);

  // The board: metal frame, slate, dust. Opaque on any README theme.
  s.push(`  <rect width="${W}" height="${H}" rx="22" fill="url(#frame)"/>`);
  s.push(`  <rect x="12" y="12" width="${W - 24}" height="${H - 24}" rx="12" fill="url(#slate)"/>`);
  s.push(`  <rect x="12" y="12" width="${W - 24}" height="${H - 24}" rx="12" fill="url(#smudge)"/>`);
  s.push(`  <rect x="12" y="12" width="${W - 24}" height="${H - 24}" rx="12" filter="url(#dust)"/>`);

  // Title.
  s.push(`  <g filter="url(#chalk-t)">`);
  s.push(`    <text class="eyb" x="${LEFT}" y="56" transform="rotate(-2 ${LEFT} 56)">every response, in the order HELMET sets them</text>`);
  s.push(`    <text class="ttl" x="${LEFT}" y="94">THE DEFAULT HEADERS</text>`);
  s.push(`  </g>`);

  s.push(`  <g class="ln" filter="url(#chalk)">`);
  // Hash marks down both sidelines, as on the page's own board.
  s.push(`    <g class="hash">`);
  for (let y = 130; y <= H - 40; y += 30) s.push(`      <line x1="26" y1="${y}" x2="40" y2="${y}"/><line x1="${W - 40}" y1="${y}" x2="${W - 26}" y2="${y}"/>`);
  s.push(`    </g>`);
  s.push(`    <line class="los a-fade" style="--d:.2s" x1="${LEFT - 12}" y1="${LOS_Y}" x2="${RIGHT + 12}" y2="${LOS_Y}"/>`);

  // The O's, then the routes, one header at a time.
  for (const [i, sl] of slots.entries()) {
    s.push(`    <circle class="o a-draw" style="--d:${(0.5 + i * 0.12).toFixed(2)}s;--t:.4s" pathLength="1" cx="${sl.ox}" cy="${O_Y}" r="${O_R}"/>`);
  }
  for (const [i, h] of prepared.entries()) {
    const { ox, ex, side } = h.slot;
    const hy = h.top + 10; // the middle of the name line
    const d0 = START + build[i] * STEP;
    s.push(`    <path class="rt a-draw" style="--d:${d0.toFixed(2)}s;--t:.5s" pathLength="1" d="M${ox} ${O_Y - O_R - 6} V${hy + HOOK} Q${ox} ${hy} ${ox + side * HOOK} ${hy} H${ex}"/>`);
    s.push(`    <path class="ah a-draw" style="--d:${(d0 + 0.48).toFixed(2)}s;--t:.15s" pathLength="1" d="M${ex - side * 9} ${hy - 8} L${ex} ${hy} L${ex - side * 9} ${hy + 8}"/>`);
  }
  // The pocket around the app.
  s.push(`    <path class="pocket a-draw" style="--d:1.2s;--t:.5s" pathLength="1" d="M584 ${O_Y + 74} A56 56 0 0 0 696 ${O_Y + 74}"/>`);
  // The bench: the one header HELMET can send but does not by default.
  const benchX = RIGHT - 8;
  const benchY = H - 52;
  s.push(`    <line class="bench a-draw" style="--d:${(START + 7 * STEP + 0.3).toFixed(2)}s;--t:.4s" pathLength="1" x1="${benchX - 330}" y1="${benchY + 12}" x2="${benchX}" y2="${benchY + 12}"/>`);
  s.push(`  </g>`);

  s.push(`  <text class="los-l a-fade" style="--d:.35s" x="${LEFT - 10}" y="${LOS_Y - 10}" filter="url(#chalk-t)">LOS</text>`);
  s.push(`  <text class="bench-t a-fade" style="--d:${(START + 7 * STEP).toFixed(2)}s" x="${benchX}" y="${benchY}" text-anchor="end" filter="url(#chalk-t)">bench: ${esc(bench)}, opt-in</text>`);

  // The labels, each written in after its route lands.
  for (const [i, h] of prepared.entries()) {
    const anchor = h.slot.side < 0 ? "end" : "start";
    const x = h.slot.tx;
    let y = h.top + 17;
    // Clip outside, filter inside: the reveal never resizes the filter's region.
    s.push(`  <g clip-path="url(#w${i})"><g filter="url(#chalk-t)" text-anchor="${anchor}">`);
    s.push(`    <text class="nm" x="${x}" y="${y}">${esc(h.name)}</text>`);
    y += T.name.lh - 2;
    for (const line of h.lines) {
      s.push(`    <text class="vl" x="${x}" y="${y}">${esc(line)}</text>`);
      y += T.value.lh;
    }
    if (h.note) s.push(`    <text class="nt" x="${x}" y="${y - 3}">${esc(h.note)}</text>`);
    s.push(`  </g></g>`);
  }

  // The app in the pocket: HELMET's own avatar export, embedded.
  const qb = await sharp(path.join(siteDir, "brand", "helmet-avatar-256.png")).resize(144, 144).png({ compressionLevel: 9 }).toBuffer();
  s.push(`  <g class="qb a-snap" style="--d:1s"><image x="604" y="${O_Y + 36}" width="72" height="72" href="data:image/png;base64,${qb.toString("base64")}"/></g>`);
  s.push(`</svg>`);

  const svg = s.join("\n") + "\n";
  if (/(?:href|src)=["'](?!data:|#)/.test(svg) || /url\((?!data:|#)/.test(svg)) fail("play.svg has an external reference");
  if (/<script/i.test(svg)) fail("play.svg has a script");
  assertTextClean(desc, "play.svg");
  record("play.svg", Buffer.from(svg, "utf8"));

  function lineWidths(h) {
    // Line widths from the token widths already measured.
    const out = [];
    let ti = 0;
    for (const line of h.lines) {
      let w = 0;
      let acc = "";
      while (ti < h.tokens.length && acc.trimEnd() !== line) {
        acc += h.tokens[ti];
        w += h.tokW[ti];
        ti++;
      }
      out.push(w);
    }
    return out;
  }
}

/* ------------------------------------------------------------- 5. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2,
    reducedMotion: "reduce", // the page is already drawn: nothing mid-snap
  });
  // Anything that is not a local file is refused: the build makes no network calls.
  await context.route(/^(?!file:|data:)/, (route) => route.abort());
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, { waitUntil: "networkidle" });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function buildHero(page) {
  console.log("hero:");
  await page.addStyleTag({ content: ".site-header, .toast { display: none !important; }" });
  assertTextClean(await page.locator(".hero").innerText(), "hero");
  const png = await page.locator(".hero").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

// A contact sheet of three existing mobile crops, set on the page's slate.
async function buildGallery(page) {
  console.log("gallery:");
  const crops = ["mobile-02-playbook-1.png", "mobile-05-quick-start-1.png", "mobile-07-film-1.png"];
  for (const c of crops) {
    if (!existsSync(path.join(siteDir, "screenshots", "sections", c))) fail(`missing crop ${c}`);
  }
  const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; }
  .sheet { width: fit-content; background: ${C.slate}; padding: 40px; display: flex; gap: 32px; border-radius: 0; }
  img {
    box-sizing: border-box;
    display: block;
    width: 360px;
    height: 640px;
    object-fit: cover;
    object-position: top;
    border-radius: 14px;
    border: 1px solid rgba(238, 240, 230, .3);
  }
</style>
</head>
<body>
<div class="sheet">
${crops.map((c) => `  <img src="${pathToFileURL(path.join(siteDir, "screenshots", "sections", c)).href}" alt="">`).join("\n")}
</div>
</body>
</html>`;
  await page.setContent(html, { waitUntil: "networkidle" });
  const png = await page.locator(".sheet").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("gallery.jpg", jpg);
}

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "helmet-board.png");
  if (!existsSync(src)) fail("site/brand/helmet-board.png is missing");
  let q = 82;
  let jpg = await sharp(src).resize({ width: 1400 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
  while (jpg.length > MAX_BYTES && q > 60) {
    q -= 6;
    jpg = await sharp(src).resize({ width: 1400 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
  }
  record("board.jpg", jpg);
}

/* -------------------------------------------------------------------- main */

async function main() {
  const { headers, bench } = readHeaders();
  await buildCast();
  buildBadges();
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildHero(page);
    await buildGallery(page);
    await buildPlay(page, headers, bench);
    await context.close();
  } finally {
    await browser.close();
  }

  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

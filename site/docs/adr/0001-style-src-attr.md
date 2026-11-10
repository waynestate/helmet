---
title: "ADR-0001: Inline style attributes in the CSP | HELMET docs"
description: "Whether HELMET's default CSP keeps style-src-attr 'unsafe-inline', and the flag that lets an app go stricter today."
url: https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# ADR-0001: Inline style attributes in the CSP [#](https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html#adr-0001-inline-style-attributes-in-the-csp)

Status: Accepted (HELMET-17, 2026-11-08). The flag ships in 1.0 with `inline` as the default; a later minor moves the default to `none` once WayneUI drops its inline `style` attributes.

## Context [#](https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html#context)

HELMET's default CSP keeps exactly one `'unsafe-inline'`: on `style-src-attr`, the directive that governs `style="..."` attributes and nothing else. `<style>` elements and stylesheets (`style-src-elem`) need the nonce or an allowed origin, and scripts never get `'unsafe-inline'`.

The allowance is there because WayneUI sets inline style attributes. At the WayneUI commit HELMET tracks, six of its PHP partials, ten of its example pages and its Vue skeleton loader write `style="..."`, several of them with values computed per call (a skeleton's width and height, a block's spacing). A browser that enforces `style-src-attr 'none'` drops those attributes silently: the page still loads, but spacing and placeholders are wrong, and nobody gets an error. HELMET's own front page leans on the same thing, about 140 inline custom properties that time its motion.

What an inline style attribute can do to an attacker's benefit is narrow but real: with an HTML injection and no script, `style` can overlay or hide content (a fake login over the real one), and CSS can leak some attribute values through background requests where `img-src` allows them. It cannot run script. The nonce, `strict-dynamic` and the absence of `'unsafe-inline'` on `script-src` are what stop script injection, and they are unchanged.

## Options [#](https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html#options)

| Option | What it does | Cost |
| --- | --- | --- |
| 1. Keep `'unsafe-inline'` (today) | WayneUI pages render as designed | the overlay and CSS-leak risks above stay open to any HTML injection |
| 2. `'none'` by default | blocks every inline style attribute | breaks WayneUI layouts silently, in every app at once, on upgrade |
| 3. `'unsafe-hashes'` plus hashes | allows only known attribute values | needs a hash per distinct value; WayneUI's computed values (widths, spacing) make the list endless, and every WayneUI release would change it |
| 4. Report first, then decide | enforce today's policy, and send a report-only probe with `style-src-attr 'none'` | one more header; reports need a report destination to be useful outside the browser console |
| 5. WayneUI moves to classes | WayneUI replaces inline styles with utility classes or CSS custom properties set from a nonce'd stylesheet | WayneUI's work, on WayneUI's schedule; computed sizes need a different technique |

## Decision [#](https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html#decision)

Accepted 2026-11-08. Ship options 1, 2 and 4 as one setting and keep option 1 as the default for 1.0:

- `style_src_attr` in `helmet_boot()`, `csp_style_src_attr` / `HELMET_CSP_STYLE_SRC_ATTR` in Laravel, and the last constructor argument of `SecurityHeadersMiddleware`: `inline` (default, today's header byte for byte), `report` or `none`.
- `report` keeps the enforced policy and adds `Content-Security-Policy-Report-Only: style-src-attr 'none'` (plus the app's report destination, when it has one), so the browser names each attribute a strict policy would block, and nothing else. On an app that already runs its whole policy report-only, the one report-only header carries `'none'` instead.
- `none` sends `style-src-attr 'none'`.
- Both only replace HELMET's default allowance. An app that sets `style-src-attr` itself in `csp` keeps its own value, so the setting never overrides a decision the app already made.
- The readiness report names the mode (`checks.headers.style_src_attr`), so a fleet scrape shows which apps run strict.
- Ask WayneUI (option 5) to move its inline styles to classes or nonce'd custom properties. When WayneUI's recipes render under `'none'`, a 1.x minor release moves HELMET's default to `none`, announced in the 1.0 notes so no app is surprised. An app that still needs inline style attributes then sets `style_src_attr` to `inline`, and runs `report` beforehand to see what `none` would block.

## Consequences [#](https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html#consequences)

Good: no app changes on upgrade; an app with no WayneUI inline styles (an API, a page that uses only WayneUI's stylesheet) can go strict with one variable today; `report` lets any app see its own exposure without risk; the path to a strict default is written down.

Bad: the default still allows inline style attributes, so BEST_PRACTICES H5 stays `Documented`, not `Enforced`. Two policies on one response (`report` mode) are harder to read in DevTools, and the probe reports only reach a server when the app has a CSP report URI.

Bad, later: moving the default to `none` in a minor is a behavior change inside 1.x. It is announced in the 1.0 notes and waits for WayneUI, so the pages it would break are gone first, but an app with inline styles of its own must set `inline` before it takes that minor.

## Decisions taken [#](https://helmet.apps.wayne.edu/docs/adr/0001-style-src-attr.html#decisions-taken)

1. 1.0 keeps `inline` as the default (2026-11-08). `report` and `none` are opt-in.
2. A later minor moves the default to `none`, once WayneUI drops its inline `style` attributes.

Still open: when to open the WayneUI request (option 5).

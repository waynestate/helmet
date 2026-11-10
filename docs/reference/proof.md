---
title: Proof
description: Proof runs against real Wayne State applications are on a private site, proof.apps.wayne.edu/helmet, behind a Wayne State employee sign-in.
---

# Proof and tests

How HELMET is proven against real Wayne State applications, and how to run its test suite on a machine with no PHP. Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://github.com/waynestate/helmet/blob/HEAD/MAINTAINING.md).

## Last run

| Check | Date | Commit | Result | Duration |
| --- | --- | --- | --- | --- |
| `composer ci` (validate, tests, Pint, PHPStan, agent-ready, docs, audit) | 2026-11-09 | 39197fd | pass: 871 tests, 2671 assertions, 1 skipped; Pint 127 files; PHPStan 50 files clean; 0 vulnerabilities | 00:05 (tests) |
| Header contract suite (`tests/Contract`) | 2026-11-07 | a86c8c8 | pass: 3 tests, 196 assertions | 00:19 |
| Test suite on PHP 8.5 (`php:8.5-cli`) | 2026-11-07 | a86c8c8 | pass: 779 tests, 2356 assertions, 1 skipped | 00:22 |
| Test suite on PHP 8.4 (`php:8.4-cli`) | 2026-11-07 | a86c8c8 | pass: 779 tests, 2356 assertions, 1 skipped | 00:23 |
| Test suite on PHP 8.3 (`php:8.3-cli`) | 2026-11-07 | a86c8c8 | pass: 779 tests, 2356 assertions, 1 skipped | 00:22 |

To refresh this table, run `composer ci` and the Docker commands below on the commit you want to record, then copy the summary lines into the rows. The proof runs against real Wayne State applications are not in the table: they live on the private site behind a Wayne State employee sign-in, so they cannot run on a developer machine.

## Accessibility

The front page and every docs page are checked with [axe-core](https://github.com/dequelabs/axe-core) in a real Chromium, through Playwright, at WCAG 2.2 AA, in light and dark, at 320 px and 1280 px, with the WCAG 1.4.10 reflow check. Two gates, both blocking, and both running the same checker, so they cannot drift apart:

- **Local, before a release:** `scripts/a11y.sh` (Linux, macOS, Git Bash) or `scripts/a11y.ps1` (Windows) runs `tools/a11y/check.mjs` in Docker. That file is a byte-for-byte copy of Preflight's a11y checker at the tag the pipeline pins (0.5.0), and `tests/A11yCheckerPinTest.php` fails `composer ci` if it drifts. Reduced motion is on, so scroll reveals never hide content from the check.
- **In the pipeline:** Preflight's `a11y` component (0.5.0) over the same pages.

Last run, 2026-11-08, on the branch that vendors Preflight's checker into the local gate (29 pages):

| Gate | Checks | Result |
| --- | --- | --- |
| `scripts/a11y.sh` (Preflight's checker, axe-core 4.14.0, 320 and 1280 px, reflow) | 116 | 0 violations; 116 items to review by hand |
| `scripts/a11y.ps1` (the same checker, Windows twin) | 116 | 0 violations; 116 items to review by hand |

The items to review are axe-core's "incomplete" results: it cannot compute contrast for text over a gradient or a background image (the gold underline bar on the header links and the brand name on the textured header). They were checked by hand in Chrome: light text `#eef0e6` on the header's `#0f1f18` is about 15:1, well past the 4.5:1 AA floor, and the gradient sits under the text, not behind it. The same run found two `aria-labelledby` attributes on plain `div`s on the front page (the headers list and the practice squad), where ARIA does not allow a name; both now carry `role="group"`. Keyboard focus shows a 3 px gold outline on every link and button, and the motion only runs with `prefers-reduced-motion: no-preference`.

In the pipeline, 2026-11-08: the `a11y` job of pipeline 4680 (Preflight 0.3.0, main) passed with no violations, and so did `secret-detection`, the first run of both on the 0.3.0 pins. That recorded run was on Preflight 0.3.0; the pipeline now pins Preflight 0.5.0, and the next proof run records the new pipeline number.

## Secret scanning

Gitleaks v8.30.1 with its built-in rules (the configuration Preflight's `secret-detection` component uses), 2026-11-07:

| Scan | Result |
| --- | --- |
| The tree (`gitleaks dir`, every tracked file) | no leaks |
| The full history (`gitleaks git --log-opts=--all`, 359 commits) | no leaks |
| A scratch copy of the tree with a planted fake cloud key | 1 leak found, exit 1 (the gate fails when it should) |

How to run the same scans is in [Secret scanning](../guides/secret-scanning.md).

## Browser checks

Three checks run HELMET's real headers (printed by `tools/a11y/corp-headers.php` from `SecurityHeaders::build()`) in Chromium, in the pipeline's `browser-headers` job and the same Playwright image as the accessibility gate:

- `tools/a11y/corp-check.mjs`: the `corp` opt-in keeps the front page whole and blocks another origin's embed of its assets (H13).
- `tools/a11y/coep-check.mjs`: under `Cross-Origin-Embedder-Policy: require-corp` the front page stays whole and cross-origin isolated, and a third-party image without CORP is blocked (H13).
- `tools/a11y/vue-check.mjs`: one small Vue app (`tools/vue-vite`), built by Vite for both recipes, runs under the default CSP with no violation, a lazily imported chunk and its runtime-added stylesheet included (H51). The raw PHP build has `html.cspNonce` set to HELMET's placeholder and gets the nonce swapped in; the Laravel build's page is rendered by `tools/a11y/laravel-page.php` through Laravel and HELMET's service provider, so its CSP header, `@vite` tags and `csp-nonce` meta tag are the real ones. Without the swap the raw PHP build never mounts, and a runtime-compiled template is refused.

Last run, 2026-11-10, on the branch that adds the Vue check (base e942c75, Playwright 1.65.0, Vite 6.4.5, Vue 3.5.45, Laravel 13.39.0):

| Check | Result |
| --- | --- |
| `corp-check.mjs` | `CORP CHECK: PASS` |
| `coep-check.mjs` | `COEP CHECK: PASS` (6 checks) |
| `vue-check.mjs --laravel=...` | `VUE CHECK: PASS` (33 checks: 12 per recipe, 4 on the build and the Laravel page, 5 on the two probes that must fail) |

## Proof

Proof runs against real Wayne State applications live on a private site, [proof.apps.wayne.edu/helmet](https://proof.apps.wayne.edu/helmet/), behind a Wayne State employee sign-in.

## Tests

The header contract suite (`vendor/bin/phpunit tests/Contract`) runs one request through raw PHP, PSR-15, Laravel HTML and Laravel JSON and checks that the security headers agree on every path.

No PHP on the machine? Fine. Docker runs the suite.

```bash tab="windows"
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

```bash tab="linux"
docker run --rm -v "$PWD:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer install --no-interaction
docker run --rm -v "$PWD:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

Style and static analysis come from [Pinstripe](https://github.com/waynestate/pinstripe), the stack's coding standard: `phpstan.neon` includes `vendor/waynestate/pinstripe/phpstan/raw.neon`, and `pint.json` uses the same `per` preset Pinstripe ships.

## Related

- [API](api.md)
- [Environment variables](configuration.md)
- [Changelog](../changelog.md)

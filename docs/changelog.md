---
title: "Changelog"
description: All notable changes to this project.
---

<!-- Generated from CHANGELOG.md by bin/docs-site.php. Edit CHANGELOG.md, not this file. -->

# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- Docs: BEST_PRACTICES, MAINTAINING and the Proof page name the pins in use (Preflight 0.5.0 for secret detection and the a11y checker, Pinstripe ^0.3, Squire 0.10.6 on main); they still said 0.4.0, ^0.1.4 and 0.10.2.
- Changed: the docs, agent-ready and deploy kits are Squire 0.10.6 (was 0.10.5): the docs builder and agent-ready generator see every file on Docker Desktop mounts.
- Site: `assets/stack-env.js` knows Parity, so a pre-production page links Parity's pre-production site and a dev page links its local `site/` folder. Until now Parity, of all parts, was the one link that ignored its environment.
- Site: Parity is out; the party list links its site.
- Changed: the deploy kit is Squire 0.10.5 (was 0.10.2): the template comments drop their internal item IDs, and Squire reports the parity group as deprecated (removed no sooner than 0.12.0; Parity runs Citadel on your computer now and carries the kit over with `parity init --from-squire`).
- Changed: the dev dependency requires waynestate/advisor ^0.3 (locked 0.3.0, was ^0.2 and 0.2.0); the same update moves CREED from 0.1.8 to 0.1.9.

## 1.3.1 (2026-11-09)

A patch release: no header, option or event changes. Docs, version mentions, HELMET's own CI and the front page's deploy kit catch up with the stack.

- Docs: Parity joins the party. The README cast, the instance runner page's team list and the front page's WELD depth chart (sideline, after Squire, at SCOUT with a "next" chip) carry Parity with its spirit-level mark: the stack's new tool that runs Citadel on your computer (its PHP image, its proxy and MySQL 5.6). 0.1.0 is coming and it has no site yet, so its row links nowhere. The scout team runs the other side's plays all week, so game day holds no surprises.
- Changed: refreshed version mentions. The install docs say Composer writes `^1.3`; the secret-scanning example, MAINTAINING and BEST_PRACTICES name Preflight 0.5.0, the pin CI already uses (BEST_PRACTICES records green pipeline 5199 on it). The dev dependencies move to ADVISOR ^0.2 and Pinstripe ^0.3 (Pinstripe 0.3.0 bans joined SQL and turned-off TLS checks), and the front page's deploy layer requires HELMET ^1.3, its own latest helmet size.
- Changed: the `composer-ci` and `wayneui-copies` jobs, and the Docker line in MAINTAINING, run Composer 2.11.1 pinned by its multi-arch list digest (was 2.10.3, which Squire 0.10.1 calls unmaintained). 2.11.1 fixes GHSA-c968-33g3-wh7p and GHSA-fm2w-xgf2-f9fh.
- Changed: the deploy kit is Squire 0.10.2 (was 0.10.1): the fallback base image names its images in full (`docker.io/library/...`) with Composer as a build argument, the kit's php.ini sets `session.cookie_samesite = Lax`, and the portable image leaves `node_modules/` out of its layers.
- Changed: the deploy layer requires CREED ^0.1.8 (locked 0.1.8, was ^0.1 and 0.1.7) and PROCTOR ^0.11.1 (locked 0.11.1, was ^0.11 and 0.11.0); the dev dependency on Pinstripe is ^0.1.4 (locked 0.1.4, was ^0.1 and 0.1.0, with phpstan-strict-rules 2.1.1). Pinstripe 0.1.4's base cut stops reporting `@var` tag mismatches, so phpstan.neon drops its two `varTag.type` ignores, which no longer matched anything.
- Docs: MAINTAINING names the maintainer as a role (the stack maintainer, reached through an issue on git.wayne.edu), not a person; internal item and decision IDs are replaced with plain words in the CI comments, `config/helmet.php`, the code comments, the tests and the metrics guide.

## 1.3.0 (2026-11-09)

A minor release, backward compatible. Two promises now hold on every consumer path: `HELMET_SESSION_SECURE` reaches the Laravel session cookie (it was validated but nothing read it), and the spoofed forwarded headers HELMET ignores are logged by the PSR-15 middleware and the Laravel adapter as well as by raw PHP. Same bucket, better chin strap.

- Added: the Laravel adapter reads `helmet.session.secure` (env `HELMET_SESSION_SECURE`) and maps it onto Laravel's own `session.secure` at boot. `auto` (the default) leaves it unset, so the session cookie is `Secure` on each request the trusted-proxy check calls HTTPS; `always` sets it to `true`, so the cookie is `Secure` on plain HTTP too. An app that sets `session.secure` itself (`SESSION_SECURE_COOKIE`) keeps its value. Before, the key was validated but nothing read it. `helmet_boot()` still reads no environment; raw PHP keeps the `session` option.
- Added: `SecurityHeadersMiddleware` takes an optional PSR-3 `logger` as its last constructor argument (default `null`, which logs nothing) and logs `helmet.forwarded_proto_rejected` through it, the same event and context (`event`, `reason`, `peer`) as `helmet_boot()`.
- Added: the Laravel adapter logs `helmet.forwarded_proto_rejected` from `SecurityHeaders` and `helmet.forwarded_for_rejected` from the metrics endpoint through the app's logger, with the same events and context keys as raw PHP. The header values are never logged. Every security event is now logged on every consumer path.
- Docs: the security-events tables in the README, BEST_PRACTICES (H27, H41) and the usage guides drop "raw PHP only"; the Laravel guide gains a Session cookie section, and the README and Laravel guide env tables gain an `HELMET_SESSION_SECURE` row; the front page's Laravel prompt no longer asks the agent to set `secure` in `config/session.php`.

## 1.2.0 (2026-11-09)

A minor release, backward compatible. Raw PHP apps get `helmet_client_error()`, the 4xx sibling of `helmet_forbidden()`, and Laravel apps can opt into the same fixed 4xx page with `HELMET_ERROR_PAGES` (off by default), so an exception message never reaches a visitor (H42 is now `Enforced`). The deploy layer moves to Squire's kit 0.10.1 and PROCTOR 0.11.

- Changed: the deploy kit is Squire 0.10.1 (was 0.10.0): the fallback base image copies Composer 2.11.1 (was 2.10.3), and the deploy layer requires PROCTOR ^0.11 (locked 0.11.0, was ^0.8 and 0.8.0).
- Changed: the deploy kit is Squire 0.10.0 (was 0.7.1): release.sh keeps Composer and npm download caches in the app folder and the gate takes SQUIRE_PHP_RUNTIME; the compose file, health.php and .env.squire.example follow the template, and the portable image files are added.
- Added: `helmet_client_error(int $status)` sends HELMET's fixed 4xx page and exits, and `helmet_client_error_response(int $status)` returns it as data, the 4xx siblings of `helmet_forbidden()`; a status outside 400 to 499 throws `\InvalidArgumentException`. The Laravel adapter answers every 4xx HTTP exception with the same page when `helmet.error_pages` is on (env `HELMET_ERROR_PAGES`, default false), leaving JSON clients, validation, authentication redirects and every 5xx to Laravel. BEST_PRACTICES H42 is now `Enforced`.
- Fixed: scripts/a11y.ps1 works on a Windows checkout with CRLF line endings; it strips the carriage returns from the shell command it hands to Docker, which sh rejected with a syntax error.

## 1.1.0 (2026-11-09)

A minor release, backward compatible. HELMET now logs every proxy-header spoofing attempt it ignores (a spoofed X-Forwarded-Proto joins X-Forwarded-For), `helmet_ribbon()` gets its new home next to `helmet_boot()` (the old name still works for one release), and the raw PHP ribbon stays hidden in production. CI and the deploy layer move to Preflight 0.4.0 and Squire's kit 0.7.1.

- Added: `helmet_boot()` logs `helmet.forwarded_proto_rejected` when a peer that is not a trusted proxy sends X-Forwarded-Proto.
- Changed: helmet_ribbon() moves to Waynestate\Helmet, next to helmet_boot(); the old Waynestate\Helmet\Ribbon\helmet_ribbon() still works for one release and raises E_USER_DEPRECATED.
- Docs: BEST_PRACTICES names the 0.4.0 Preflight pins in its tag table (it still said 0.3.0), records the first secret-detection and composer-audit runs in pipeline 4680 (gap 24 and H50 still called them owed or unseen), and marks gap 36 (no-store) done.
- Fixed: the raw PHP ribbon (`helmet_ribbon()`, `Ribbon`) no longer renders in production or for an environment HELMET does not know; it follows the Laravel adapter's list, now one constant (`Ribbon::ENVIRONMENTS`) matched in any case and widened with `stage`, `qa`, `uat`, `preprod`, `pre-prod` and `sandbox`, so those and `STAGING` show the ribbon while production, an empty name or a typo does not (H46). The README and the raw PHP guide show how to print it.
- Changed: CI pins the Preflight components to 0.4.0 (the proctor job clones the full history, so the override-approver check can read git blame).
- Docs: BEST_PRACTICES H58 names the Preflight pin the pipeline runs (0.3.1, was 0.3.0).
- Changed: the deploy layer runs Squire's kit 0.7.1 (was 0.6.0): `.squire/php.ini` no longer sets the deprecated `session.sid_*` lines, web requests get a `disable_functions` list and Apache hardening (`.squire/php-web.ini`, `.squire/apache-hardening.conf`), and opcache gets production settings with `SQUIRE_OPCACHE_VALIDATE` as the one switch.
- Docs: BEST_PRACTICES names the current release, 1.0.1 (it still said 1.0.0).
- Changed: the local a11y scripts run Preflight's own checker (vendored from 0.3.1 and pinned by a test), so they check what CI checks: axe-core 4.14.0, 320 and 1280 px, and the reflow check.
- Changed: CI pins the Preflight components to 0.3.1 (fixed cache keys, and caches saved even when a job fails; D-072).

## 1.0.1 (2026-11-08)

A patch release: no public function, option or header changes. It adds tests that hold the 1.0 promises (the CORP opt-in in a real browser, a bad config throwing before any header, BEST_PRACTICES citations), fixed CI cache keys, and docs and front-page fixes.

### Added

- Added: a real-browser check (`tools/a11y/corp-check.mjs`) proves the `corp` opt-in: own assets load, another origin's embed is blocked (H13).
- Added: a test keeps BEST_PRACTICES.md honest: one tag per practice, every Enforced practice names a test, and every test it names exists.
- Added: a test pins that a bad config makes `helmet_boot()` throw before any security header is sent (H14's last uncovered corner, on purpose: HELMET fails loudly instead of half-applying).

### Changed

- Changed: CI saves its Composer and npm caches even when a job fails, so a retry after a dropped download fetches only what is missing (D-072).
- Changed: CI caches Composer and npm downloads under a fixed key, so a lock change downloads only what changed (D-072).
- Fixed: the ten drifted citations in BEST_PRACTICES.md now name the tests that hold them: H10 (the two HeaderContractTest path-agreement tests), H28 (the cited `testBootThrowsWhenSessionActive` is really `testBootThrowsWhenSessionAlreadyActive`), H30 and H31 (the SessionHardeningTest name, lifetime and ini tests), H35 (the /up status and body tests), H36, H38, H39 and H40 (the LoggingTest redaction, request-id and context tests), and H54 (the core, Laravel and boot-level request-forgery tests).
- Fixed: BEST_PRACTICES.md and the Proof page record the first green pipeline on the Preflight 0.3.0 pins (pipeline 4680), say ADR-0001 is accepted, and name the 0.3.0 pins.
- Fixed: the docs menu closes with Escape and hands focus back to its button (docs kit synced from Squire).
- Fixed: the docs kit's copy button no longer covers the first line of code when it says Copied or Failed: over the code it keeps its resting width and shows a check or a cross when the word does not fit (docs kit synced from Squire).
- Fixed: the front page's version chip shows the full version the docs show (1.0.0, was 1.0). It carries `data-docs-version` now, so the docs builder (synced from Squire) rewrites it at every release and `composer docs:check` fails while it is stale.
- Changed: the deploy layer requires PROCTOR ^0.8 (locked 0.8.0).
- The front page header's version chip says 1.0 (it still said 0.3).
- The front page's deploy layer (`deploy/`) requires HELMET `^1.0` (was `^0.4`) and locks 1.0.0, so the site runs the release it describes.

## 1.0.0 (2026-11-08)

1.0 notes: from 1.0 HELMET follows semantic versioning, so a `^1.0` constraint gets no breaking change, with one default change announced here. Before you move from `^0.4`, know two things. An unknown quoted source in the CSP (a typo such as `'unsafe-inlined'` in the `csp` option or `HELMET_CSP`) now throws `\InvalidArgumentException` at boot, when the headers are built, instead of shipping a token the browser ignores, so boot the app once on a test host before you deploy. And `style_src_attr` stays `inline` by default: the policy still sends `style-src-attr 'unsafe-inline'` until WayneUI drops its inline `style` attributes. A later 1.x minor then moves the default to `none`; set `style_src_attr` to `inline` to keep today's header, or run `report` now to see what `none` would block. That plan is the decision recorded in [ADR-0001](https://github.com/waynestate/helmet/blob/main/docs/adr/0001-style-src-attr.md). Upgrade with `composer require waynestate/helmet:^1.0`.

### Added

- Readiness (HELMET-7): a "send me traffic?" answer on its own path, `/ready`, apart from the liveness answer on `/up`, which is unchanged. `Health\Readiness` runs named checks and answers JSON, `no-store`, 200 while none failed and 503 once one did. HELMET's own checks report config sanity: `headers` (the header set builds and carries a CSP, in `enforce` or `report-only` mode), `trusted_proxies` (a count and a broad count, never an address; warns when empty or broad) and `debug_output` (warns when on). App checks join by name; one that throws fails, and its message goes to the log as `helmet.readiness_check_failed`, never the body. Raw PHP gains the `readiness` option to `helmet_boot()` (off by default, validated on every request) and `helmet_ready()` for a `public/ready.php`; PSR-15 gains the `ReadinessCheck` middleware; Laravel gains `Route::helmetReady()` (invokable check classes, so `route:cache` works; no throttle by default). New docs guide: "Readiness", with probe settings for Citadel, OpenShift, Coolify and Docker. An app that sets nothing behaves exactly as before.
- Inline style attributes (HELMET-17, ADR-0001): a `style_src_attr` option (`helmet_boot()`, the last `SecurityHeadersMiddleware` argument, Laravel `csp_style_src_attr` / `HELMET_CSP_STYLE_SRC_ATTR`). `inline`, the default, sends exactly what earlier releases sent (`style-src-attr 'unsafe-inline'`, which WayneUI's recipes need). `report` keeps enforcing it and adds `Content-Security-Policy-Report-Only: style-src-attr 'none'` (plus the app's report destination), so the browser names each inline style attribute a strict policy would block. `none` sends `style-src-attr 'none'`. Both only replace HELMET's own allowance: an app that sets `style-src-attr` in `csp` keeps its value. An unknown mode throws at boot. The readiness report names the mode. New docs: "Inline style attributes" in the CSP guide, and the ADR under Decisions.
- Secret scanning (HELMET-5): HELMET's pipeline includes Preflight's `secret-detection` component (0.2.0), which runs Gitleaks v8.30.1, pinned by digest and pulled through the Dependency Proxy, over the tree; a finding fails the pipeline and the match is redacted. HELMET writes no scanner of its own. New docs guide: "Secret scanning", with the include for an app, the same scan on a developer machine (PowerShell and sh), a history scan, and what to do with a finding. HELMET's tree and its full history scan clean.
- Accessibility (HELMET-23): the pipeline includes Preflight's `a11y` component (0.2.0) over the front page and every docs page at WCAG 2.2 AA, light and dark, 320 px (with the reflow check) and 1280 px, blocking. The Proof page records the last run of both gates and what was checked by hand.

### Fixed

- The pipeline's PROCTOR jobs no longer pass `--policy`, which PROCTOR 0.7 refuses in CI; PROCTOR checks against the CREED policy it bundles.
- Front page: the headers list and the practice squad named themselves with `aria-labelledby` on a plain `div`, which ARIA does not allow (axe-core 4.14 flags it for review); both are now `role="group"`. The practice squad lists what is still on the backlog, now that readiness and secret scanning shipped.
- CSP: a quoted source in the `csp` option other than `'self'`, `'none'` and `'unsafe-inline'` was handed to the policy builder as a host, so `'sha256-...'` came out as `https://'sha256-...' http://'sha256-...' 'sha256-...'` and `'unsafe-eval'`, `'unsafe-hashes'`, `'wasm-unsafe-eval'`, `'report-sample'` and `'strict-dynamic'` the same way, none of which a browser accepts. Hashes, fixed nonces, every CSP keyword and the `https:`, `mediastream:` and `filesystem:` schemes now come out as written. An unknown quoted source (a typo such as `'unsafe-inlined'`) now throws `\InvalidArgumentException` when the headers are built, instead of shipping a token the browser ignores: check your `csp` / `HELMET_CSP` before you upgrade.
- The front page roster says Sourdough starts all five app shapes, from raw PHP to Inertia and Vue; it no longer says Blade and Inertia come later.

### Changed

- The GitLab pipeline pins every Preflight component to one release, 0.3.0 (was 0.2.0), with plain image names: Preflight 0.3.0 adds the Dependency Proxy prefix itself (`image_prefix`). A new test, `PreflightPinTest`, fails when the includes name more than one Preflight version or an input carries the proxy prefix.
- The front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer's pins move to HELMET `^0.4` (locked 0.4.0) and PROCTOR `^0.7` (locked 0.7.0).
- Docs: the Docker commands in the README and docs mount the shared `stack-composer-cache` volume, so Composer downloads are cached between runs.
- Docs: BEST_PRACTICES re-checked for 1.0 (HELMET-1, third pass): H34 is `Enforced` (the readiness check), H58 is `Checked` (Gitleaks through Preflight), H5 is `Documented` (the `style_src_attr` mode, with the default left to ADR-0001), backlog items 19, 24 and 31 are closed or moved, and the new "Third pass (2026-11-07)" section records the quoted-source fix, the accessibility gate and what is still unverified.
- CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.
- Docs: every page checked against the code. Fixed the preset log path (presets supply trusted proxies only, never a log path), the CSP report URI rule (a single-slash path relative to the page's origin is valid; an absolute URL must be `https` with a host), HSTS preload (opt-in, appended only with a `max-age` of at least a year and `includeSubDomains`), the HSTS constructor positions, the PSR-15 rethrow behavior, and the COOP `unsafe-none` wording. Completed the API reference with the members it missed: `styleSrcAttr` in `build()` and the middleware, `validateStyleSrcAttr()`, `STYLE_SRC_ATTR_MODES`, `Laravel\SecurityHeaders::styleSrcAttr()`, `Logging\Logging::addRequestContext()`, `Proxy\TrustedProxies::broadEntries()`, `Metrics\MetricsStorageException`, the `Health\Readiness` `PASS`/`WARN`/`FAIL` constants, `Metrics\MetricsStorage::PREFIX`, and the `RecordRequestMetrics`, `RequestMetricsBinding` and `RequestTimer` constructors. Made the README "Security events" a real heading so its anchor resolves.

## 0.4.0 (2026-11-07)

### Added

- ADVISOR module (`Waynestate\Helmet\Advisor\HelmetModule`): a coding agent that runs ADVISOR gains two read-only tools. `helmet_headers` returns the headers and CSP HELMET sends, built by `SecurityHeaders::build()`, each header and directive with its reason, plus how the app folder is wired to HELMET and the settings that change the answer. `helmet_php_support` reads the PHP constraint from the app's `composer.json` and reports the support status of its floor, and of every version it allows, from the CREED policy ADVISOR bundles, read with HELMET's own `JsonFilePolicySource` and `VersionStatus`. Both take one path inside the project, run in-process, write nothing and never read `.env`. `waynestate/advisor` is a dev dependency and a suggestion, never a requirement. New docs guide: "Your coding agent (ADVISOR)".
- `Headers\HeaderReasons`: one reason per header and CSP directive, next to the policy; a test fails when one is added without a reason.
- The Proof page records its last run.
- Logging: every web request record carries `extra.http` (method, path without the query string, client address as PHP saw it). Long token-like path segments are masked.
- Metrics: opt-in request metrics. `HELMET_METRICS_STORAGE` (`apcu`, `redis` or `sqlite`, each one of promphp's own adapters) turns on `helmet_http_request_duration_seconds`, a histogram by route group, method and status class whose `_count` is the request count, and `helmet_csp_violations_total` by directive (Laravel's CSP report endpoint counts each report it logs). Every label is bounded by HELMET, never by the client: unknown routes, methods and directives fold into `other`. Laravel gains the `RecordRequestMetrics` global middleware, added only when metrics are enabled with a store; raw PHP gains `helmet_metrics_track()` and `helmet_metrics_env()`. Off by default: an app that sets nothing behaves exactly as before.
- Metrics: `helmet_php_eol_timestamp_seconds` (the end of security support for the running PHP, from CREED's policy; days left is `(helmet_php_eol_timestamp_seconds - time()) / 86400`), `wsu_stack_info{package,version}` for every installed `waynestate/*` package (ADR-0011), and `helmet_metrics_storage_up`, so a store outage shows on the dashboard while the info metrics keep answering. Laravel's `/metrics` reads `helmet.policy_path` when no `PolicySource` is bound.
- Docs: the Metrics guide covers what each metric means, how labels stay bounded, picking a store, turning metrics on for one site (Laravel and raw PHP), the scrape config and PromQL; troubleshooting covers a 403 on Apache, empty request panels and an `unknown` support status.
- Grafana dashboard (`docs/grafana/helmet.json`): `job` and `instance` variables on every query, and new panels for targets down, days to PHP end of support, stack packages, requests by route group and by status class, latency p50 and p95, p95 by route group, server errors, metrics storage and CSP violations by directive. Checked by importing it into Grafana 12 through the API against a local Prometheus scraping three HELMET apps, one per store. Scrape health is a state timeline (up or down per target) and the version panels are bar charts.
- Added: an accessibility gate for the site (axe-core in Playwright, Docker). `scripts/a11y.sh` and `scripts/a11y.ps1` check `site/index.html` and every page under `site/docs/` in light and dark at 360 px and 1280 px, and fail on any WCAG 2.2 AA violation; `tools/a11y/check.mjs` is the runner.

### Fixed

- Metrics: `helmet_php_version_status` looked up the full PHP release (`8.3.12`) in CREED's policy, which names versions by major.minor (`8.3`), so every real app reported `unknown`. It now looks up major.minor.
- Policy: `JsonFilePolicySource` read each component as a flat version map, but CREED's `policy.json` (and its schema) keeps the versions under `versions`, so against the real file the ribbon never warned and the PHP support metrics said `unknown`. It now reads both shapes, skips a version with no `security_until`, and treats one with no `active_until` as security-only.
- Metrics: `helmet_info`'s `helmet_version` is the tagged version (`0.3.1`), the same form `wsu_stack_info` uses, not Composer's normalized `0.3.1.0`.
- Metrics: `helmet_metrics()` read the scraper's token only from `$_SERVER['HTTP_AUTHORIZATION']`, which Apache with mod_php (Citadel's images) never sets, so a correct token got 403 there. It now also reads `REDIRECT_HTTP_AUTHORIZATION` and `getallheaders()` (`BearerToken::fromServer()`). Found by scraping HELMET's own front page in a php:8.5 Apache container.
- Grafana dashboard: the data source was declared under `inputs`, which Grafana's import ignores, so every panel pointed at an unresolved `${DS_PROMETHEUS}`; it is now `__inputs` (with `__requires`). "Scrape health" joined `up` to `helmet_info`, which drops exactly the targets that are down; it now graphs `up` directly.
- Fixed: two violations the new gate found. A code block wider than its box now joins the tab order so a keyboard user can scroll it (axe `scrollable-region-focusable`, WCAG 2.1.1), and table-of-contents sub-items get a full-width link target of at least 24 px (axe `target-size`, WCAG 2.5.8). Both come from syncing the Squire-owned docs kit (`bin/docs-site.php`, `site/assets/docs.css`, `site/assets/docs.js`) to the current stack version, and the docs site and agent-ready files are rebuilt from it.

### Changed

- The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Changed: the docs site renders fenced blocks tagged `tab=` as a labeled tablist (the previous builder ignored the tag and stacked the blocks), and the changelog mirror keeps its top-level heading, per the current shared builder.

### Security

- The front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).

## 0.3.1 (2026-11-05)

No breaks: new boot warning for a broad trusted-proxy list, `helmet_csp_nonce_swap()`, `GenericErrorResponse::clientError()`, a Grafana dashboard, a contract test suite and the docs site, plus docs and deploy-layer refreshes.

- Docs: the docs theme sits closer to the front page and the brand board. Page titles carry the hero's play call in the coach's marker ("The playbook") and the board's wordmark rule (one thin line with a short gold bar, laid in after the title is chalked in) in place of the thick green rule, and the phone Menu is one of the hero's dashed call chips (solid gold on hover or open) instead of a solid green bar. Light and dark; motion only with `prefers-reduced-motion: no-preference`.

- Docs: BEST_PRACTICES H45 is `Documented`. `tests/Support/ConfigDocsTest.php` fails when a `helmet_boot()` option or a `config/helmet.php` key is missing from the README or the configuration reference, and both now list every option and key.
- Docs: BEST_PRACTICES H14 is `Partly enforced` with tests on every path (raw PHP over HTTP, PSR-15 error-middleware order, Laravel aborted and unmatched requests); the README and the PSR-15 guide say to mount HELMET outermost, after the error middleware in a Slim-style app.
- Added: `tests/Contract/HeaderContractTest.php`, a contract suite that runs the same request and config through raw PHP (`helmet_boot()` in a `php -S` child), the PSR-15 middleware, the Laravel `web` group and a Laravel JSON answer, and asserts the contract headers agree byte for byte apart from the nonce; README section "One contract, every path", BEST_PRACTICES H10 names the suite.
- Docs: the docs pages wear the front page. Every page under `site/docs/` carries the front page's slate header and main menu (Docs underlined in gold), Graduate titles, numbered gold binder tabs on each section (the "On this page" list carries the same numbers), the coach's marker on the sidebar groups with a berry "you are here" ring around the current page, code on the scoreboard's black glass with a gold Copy tab and the scoreboard's tabs over code tabs, callouts as stamped play cards, the depth chart's green bars on tables, previous and next as laminated play cards, and the end zone as the footer. Dark mode is the hero's chalkboard. The theme lives in `docs/theme/helmet.css` and `docs/_header.html`, named by the `theme` key in `docs/docs.json`; the shared builder and kit are resynced from SQUIRE `0778fed` (theme hook, callouts, code tabs, task lists, a copy button that reports a working copy, keyboard-reachable scrolling code) and not edited here. Motion only with `prefers-reduced-motion: no-preference`.
- Added: `helmet.broad_trusted_proxy` (warning, logged once at boot when the resolved trusted-proxy list holds a broad entry, an IPv4 range shorter than `/16` or an IPv6 range shorter than `/48`, with `event` and `entries` in the context), so a whole-network trust is hard to miss in the logs; `TrustedProxies::broadEntries()` does the detection, both the Laravel provider and the raw PHP `helmet_boot()` emit it through the configured logger, and a failing logger never breaks the boot. Opt out with `helmet.warn_broad_proxies` (env `HELMET_WARN_BROAD_PROXIES`, default true) or `warn_broad_proxies: false` in `helmet_boot()`. Tests: `tests/Proxy/BroadEntriesTest.php`, `tests/Laravel/BroadProxyWarningTest.php`, `tests/FunctionsBroadProxyTest.php`; BEST_PRACTICES H21 is now `Partly enforced`.
- Docs: README and the raw PHP guide gain "Readiness is a separate check": `/up` stays liveness-only, dependency checks go on a separate `/ready` path with a short timeout and a fixed 503 body, and Laravel apps keep them out of the `DiagnosingHealth` listener; BEST_PRACTICES H34 is `Documented`.
- Added: a Grafana dashboard for the HELMET metrics (`docs/grafana/helmet.json`, uid `helmet-fleet`) with panels for apps reporting, HELMET and PHP versions in use, PHP support status, apps by instance, and scrape health; it imports into any Grafana through a `DS_PROMETHEUS` datasource input, and `tests/Support/GrafanaDashboardTest.php` pins its shape. The metrics guide gains a "Graph it in Grafana" section with a sample `scrape_configs` stanza. `docs/` was never export-ignored, so the dashboard already ships in the dist archive.
- Added: `helmet_csp_nonce_swap(string $html, string $nonce, string $placeholder = 'CSP_NONCE_SLOT_FOR_HELMET')` for raw PHP apps serving a built Vue/Vite page: it replaces every occurrence of the build-time placeholder (the value Vite's `html.cspNonce` stood in for) with the response nonce from `helmet_boot()`, HTML-escaped exactly like `helmet_csp_nonce_meta()` escapes it. A placeholder shorter than 16 characters throws `InvalidArgumentException`, as does an empty nonce, and a page without the placeholder is returned unchanged. Pure function: no output, no headers, no globals. Covered by `tests/FunctionsNonceSwapTest.php`.
- Added: the docs site. `bin/docs-site.php` (Squire's shared builder) turns `docs/` into `site/docs/`: the Overview page carries the README opening and a generated table of every `HELMET_` environment variable the package reads. `composer docs` rebuilds it, `composer ci` checks it through `composer docs:check`, and the front page links it.
- Docs: BEST_PRACTICES H53 is `Documented` (SESAME owns the logout headers, `Clear-Site-Data` without `"cookies"`) and H41 is `Partly enforced` (three events are logged, authentication failures belong to SESAME).
- Docs: README section "Debug output stays off in production" names the two settings that decide whether a visitor sees a trace (PHP `display_errors`, Laravel `APP_DEBUG`), points at Squire's deploy `php.ini`, and documents the `helmet.warn_debug` opt-out; BEST_PRACTICES H43 is now `Documented`.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.

- Docs: the sample metrics output shows 0.3.0.
- Added: `GenericErrorResponse::clientError(int $status)` returns a fixed 4xx page (status, headers, body) for client faults, with its own title and sentence for 400, 401, 403, 404, 405, 410 and 429 and the Bad request text for any other 4xx; a status outside the 400s throws `InvalidArgumentException`, and nothing from the request or an exception reaches the body. An app calls it from its router; nothing wires it into `helmet_boot()` automatically.

## 0.3.0 (2026-11-03)

- This is a pre-1.0 minor: apps that pin `^0.2` stay on 0.2.x until they move their constraint to `^0.3`. The proxy and forwarded-header changes below make some requests resolve differently (an empty `HELMET_PROXIES=` now trusts nobody), so read the Security lines first.

- Docs: BEST_PRACTICES H14, H13 and H41 say what is tested and what is not (error page headers on the raw PHP path, WayneUI assets, session hardening events).
- Added: opt-in generic error page for raw PHP. `helmet_boot(['error_handler' => ['enabled' => true]])` installs an exception handler that logs the full detail at `error` level and shows a fixed 500 page with no exception text; `Waynestate\Helmet\Error\GenericErrorResponse::build()` returns the same response as data. Off by default; Laravel keeps its own handler.
- Added: two structured security events, `helmet.request_refused` (warning, logged when the request-forgery guard answers 403, with `event`, `method`, `path`, `sec_fetch_site` and `origin_present` in the context) and `helmet.forwarded_for_rejected` (warning, logged by `ClientIp::resolve()` when it fails closed on an unparseable `X-Forwarded-For` entry, with `event`, `reason` and `peer` in the context); the Origin value, cookies, Authorization, the header and the bad entry are never logged, and a failing logger never changes the 403 or the resolved IP.
- Added: `helmet.debug_enabled_in_production` (warning, logged once by the Laravel adapter at boot when the app boots with `app.debug` true in the production environment, with `event` in the context); opt out with `helmet.warn_debug` (env `HELMET_WARN_DEBUG`, default true), and a failing logger never breaks the boot.
- Security: `ClientIp` fails closed on an unparseable `X-Forwarded-For` entry (for example `203.0.113.5:443`): it returns the peer instead of walking on to an earlier, client-chosen entry, so a malformed header can no longer steer the resolved client IP.
- Security: IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) are unmapped before matching trusted proxies, for both the peer and the forwarded entries, so a dual-stack proxy still matches its IPv4 entry instead of slipping past the trust check.
- Security: `X-Forwarded-Proto` now follows one rule everywhere, the last non-empty value in `HttpsDetector` and the Laravel normalizer, and the normalizer decides trust with the list Laravel itself uses (`trustedproxy.proxies` when set, else `helmet.proxies`, with `*` and `**` trusting every peer as in Symfony), so HELMET and the framework can no longer disagree about which value to read or from whom to accept it.
- Security: an explicitly empty `HELMET_PROXIES=` now trusts nobody even when `HELMET_PRESET` is set, because only an unset variable falls back to the preset, so an app that clears the list on purpose is not silently re-opened by the platform default.
- Security: the CSP nonce meta tag is injected only into the real `<head>`, so a literal `<head>` inside a comment, an attribute value, or a script, style, textarea or title element no longer receives it.
- Security: an HTML response that carries a CSP nonce has `public` changed to `private` and `s-maxage` dropped from its `Cache-Control`, so a shared cache cannot store a page whose nonce differs per request.
- Fixed: the Vite dev origin must be a bare origin (no path, query, fragment or userinfo) and is normalized to `scheme://host[:port]`; a CSP directive the app removed is no longer recreated by the dev origin, which dropped its `default-src` fallback (it is copied from `default-src` first, or skipped).
- Fixed: the Laravel adapter resets Vite's CSP nonce when the request ends (after the mismatch check and the nonce meta tag, on success and when the inner handler throws), so a long-lived worker (Octane, Swoole, FrankenPHP) does not carry one request's nonce into the next.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Docs: the README and the `Presets` docblock now state the `citadel` preset's NAT assumption: `172.18.0.1` is the Docker bridge gateway, the preset is safe only when the front proxy reaches the app over that bridge, and a published app port that is source-NATed to the gateway address would make a direct client look like the proxy, so the platform must not publish the app's port.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.

## 0.2.6 (2026-11-02)

- Fixed: HTML rewrites (CSP nonce meta, environment ribbon) keep the response's view object, so `assertInertia()` and `assertViewHas()` work in apps that use HELMET.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).
- Docs: BEST_PRACTICES.md tags re-checked against the code; H22, H24 and H28 are now Enforced, H32 is Enforced, and the gap list line numbers are current.
- Changed: composer.json sets config.audit.abandoned to report, making the Composer audit policy explicit.
- Docs: README Docker test commands cover PHP 8.5, 8.4 and 8.3 on both PowerShell and sh, and the middleware error-path wording matches the default rethrow behaviour.

## 0.2.5 (2026-10-29)

- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.
- Added: HELMET_SESSION_SECURE=always keeps the session cookie Secure even when HTTPS is not detected.
- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- Changed: project names written as words except acronyms.
- Fixed: stale capability claims in the site and docs.

## 0.2.4 (2026-10-29)

- `HELMET_PRESET` (Laravel) now also comes from the private
  `waynestate/playbook` package when the environment has no value: the config
  reads it through `Waynestate\Helmet\Support\Setting`, which checks the
  environment first, then PLAYBOOK, then gives up. A missing preset is still
  not an error, and `waynestate/playbook` is suggested, never required. The
  README gains a "WSU values (PLAYBOOK)" section with the fetch commands.

## 0.2.3 (2026-10-29)

- Fixed: `helmet_boot()` removed `X-Powered-By` only after it had already
  answered the health check path, so a GET or HEAD to `/up` (the default
  `health_path`) still sent the header. The removal now runs before the
  health answer and the request-forgery rejection, so both early exits leave
  the response clean.

## 0.2.2 (2026-10-29)

- Every JSON log record now carries a request ID in `extra.request_id`: 16
  lowercase hex characters generated by HELMET (`Logging::requestId()`),
  stamped by a processor pushed before the redaction processor, so a record
  with a `password` context key still comes out redacted and keeps its ID.
  An inbound header is never trusted for the ID. `Logging::create()` takes an
  optional fourth argument for a caller-supplied ID (8 to 64 letters, digits,
  dots, underscores or dashes; anything else is ignored), and `helmet_boot()`
  accepts it as `log.request_id`, which must be a string. `helmet_boot()` now
  also returns the ID as `request_id`.

## 0.2.1 (2026-10-29)

- Upgrade note: a raw-PHP app that still used the default `PHPSESSID` cookie gets the new `WSUSESSID` name (`__Host-WSUSESSID` on HTTPS), so signed-in users sign in once more after deploying. Laravel apps are unaffected (Laravel names its own cookie).

- Docs: added a "Running off Citadel" section to the README with a table for
  Citadel, OpenShift, Coolify and raw Docker behind a compose proxy: what
  sits in front, what to put in `HELMET_PROXIES`, and where logs go. The
  README log example now leads with `php://stderr`, and the hard-coded
  `172.18.0.1` now lives only in that table, with the other references
  pointing at the `citadel` preset.

- `SessionHardening::apply()` now also sets the session cookie name,
  lifetime, path, domain and cache limiter: `cookie_lifetime=0` (a
  browser-session cookie), `cache_limiter=nocache`, `cookie_path=/`, an
  empty `cookie_domain` (host-only), and the name `__Host-WSUSESSID` on
  HTTPS or `WSUSESSID` on HTTP. The rename happens only while the name is
  still the PHP default `PHPSESSID`; an app-chosen name is left alone, and
  SESAME reads whatever `session_name()` returns.

## 0.2.0 (2026-10-29)

- Fixed the `<head>` match when injecting the csp-nonce meta tag into HTML
  responses: the old pattern also matched `<header>` and `<headline>` and
  injected the tag after the wrong element. Responses that carry a
  `Content-Length` header now have it refreshed to the new body length after
  injection, so a stale length cannot cut the page off mid-tag.

- Narrowed the trusted-header set in the Laravel adapter. `X-Forwarded-Host`,
  `X-Forwarded-Prefix` and now `X-Forwarded-Port` are no longer trusted by
  default. Port is out because Citadel's HA-Proxy never sends it, so a
  client-sent value would pass through untouched and change the port Laravel
  reports and the URLs it generates. Apps that need any of them choose the
  set themselves with `trustProxies(at: ..., headers: ...)` or
  `TrustProxies::withHeaders()`.

- Laravel now agrees with HELMET on multi-value `X-Forwarded-Proto`.
  Citadel's HA-Proxy appends the header instead of replacing it, so a client
  can produce `https, http`. Symfony reads the first value of such a list
  (the client's), while HELMET reads the last (the one the closest proxy
  appended). A new middleware prepended to the global chain rewrites the
  header to its last value when the request comes from a trusted proxy; a
  single value is left alone, and requests from untrusted peers are ignored
  as before.

- An unknown `HELMET_PRESET` now throws even when `HELMET_PROXIES` is set.
  A typo in the preset name fails loudly at boot instead of going silently
  unused.

- Docs: noted that the CSP nonce is added to `style-src` and
  `style-src-elem`. A browser ignores `'unsafe-inline'` once a nonce is
  present, so overriding `style-src` with `["'unsafe-inline'"]` loses inline
  styles instead of restoring them.

- Added HSTS `preload` support. Set `hsts_preload` / `HELMET_HSTS_PRELOAD` to
  `true` to append the `preload` directive to the
  `Strict-Transport-Security` header. Preload requires a `max-age` of at
  least one year (31536000) and `includeSubDomains`; a shorter max-age or
  missing `includeSubDomains` throws. Preload is hard to undo: once a browser
  has preloaded the domain it refuses plain-HTTP connections until the entry
  is removed.

- Added Trusted Types directives on the report-only CSP header. Set
  `csp_trusted_types.enabled` / `HELMET_CSP_TRUSTED_TYPES_ENABLED` to `true`
  to append `require-trusted-types-for 'script'` and
  `trusted-types <policies>` to the
  `Content-Security-Policy-Report-Only` header only (never the enforcing
  header). Policy names default to `["default"]`; set
  `csp_trusted_types.policies` / `HELMET_CSP_TRUSTED_TYPES_POLICIES` to
  override. Invalid policy names throw.

- Added opt-in `Cache-Control: no-store`. Set `no_store.enabled` /
  `HELMET_NO_STORE_ENABLED` to `true` to send `Cache-Control: no-store` on
  matching responses. `no_store.paths` / `HELMET_NO_STORE_PATHS` limits the
  header to specific path prefixes; empty means every response. An existing
  `Cache-Control` header is never overridden.

- Added `helmet_up()`: answers the `/up` health check with `200`,
  `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and
  the body `ok`, then exits. For a raw PHP app with no front controller,
   point the probe at a `public/up.php` that requires the autoloader and
   calls it. `helmet_boot()` now calls `helmet_up()` for the health path;
   behaviour is unchanged.

- Added an opt-in request-forgery (CSRF) guard. `helmet_boot()` accepts a
  `csrf` option, the PSR-15 core exposes `RequestForgeryGuard`, and the
  Laravel adapter registers a `RequestForgeryGuard` middleware when
  `helmet.csrf.enabled` is true. Safe methods (`GET`, `HEAD`, `OPTIONS`)
  always pass. For state-changing requests, `Sec-Fetch-Site` is used when
  present; otherwise the `Origin` header is checked against the request's own
  origin and `csrf.trusted_origins`. A rejected request receives `403` with
  `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and the
  body `Forbidden`. Config keys: `csrf.enabled`, `csrf.allow_same_site`,
  `csrf.trusted_origins`, `csrf.except`; env vars: `HELMET_CSRF_ENABLED`,
  `HELMET_CSRF_ALLOW_SAME_SITE`, `HELMET_CSRF_TRUSTED_ORIGINS`,
  `HELMET_CSRF_EXCEPT`.

- Added configurable `Cross-Origin-Opener-Policy` (COOP),
  `Cross-Origin-Resource-Policy` (CORP) and `Permissions-Policy` headers.
  COOP defaults to `same-origin` (set `coop` / `HELMET_COOP` to
  `same-origin-allow-popups`, `noopener-allow-popups` or `unsafe-none`, or
  `null` to send no header). CORP is opt-in (set `corp` / `HELMET_CORP` to
  `same-site`, `same-origin` or `cross-origin`). Permissions-Policy defaults
  to a deny list for `camera`, `geolocation`, `microphone`, `payment` and
  `usb` (set `permissions_policy` / `HELMET_PERMISSIONS_POLICY` to a feature
   map; an empty map sends no header). Invalid values throw.

- BEST_PRACTICES tags brought up to date. The Stack lines are now picture
  perfect.

- PHPStan and Pint now run from Pinstripe, the stack's coding standard,
  instead of a hand copy. `composer.json` requires `waynestate/pinstripe`
  (dev-main, until 0.1.0 is tagged) as a dev dependency, `phpstan.neon`
  includes `vendor/waynestate/pinstripe/phpstan/raw.neon` (keeping this
  repo's paths, PHP version range and three commented exceptions), and
  `pint.json` keeps the `per` preset Pinstripe ships. No behaviour change;
  the code is just cut from the same cloth now.

## 0.1.0 (2026-10-27)

- First tagged release.

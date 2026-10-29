# Changelog

All notable changes to this project are documented in this file.

## Unreleased

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

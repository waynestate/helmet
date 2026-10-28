# Changelog

All notable changes to this project are documented in this file.

## Unreleased

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

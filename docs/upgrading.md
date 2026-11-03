---
title: Upgrading
description: How to move an app from one HELMET release to the next.
---

# Upgrading

This page walks an app from one HELMET release to the next, newest first, using only what CHANGELOG.md says. One
section per released version; versions with a break get Breaking, Deprecated, Steps and How to verify. The current
release is 0.3.0.

## 0.3.0 (2026-11-03)

### Breaking

This is a pre-1.0 minor: apps that pin `^0.2` stay on 0.2.x until they move their constraint to `^0.3`. The proxy and
forwarded-header changes make some requests resolve differently, so read the Security lines first:

- An explicitly empty `HELMET_PROXIES=` now trusts nobody even when `HELMET_PRESET` is set, because only an unset
  variable falls back to the preset, so an app that clears the list on purpose is not silently re-opened by the
  platform default.
- `ClientIp` fails closed on an unparseable `X-Forwarded-For` entry (for example `203.0.113.5:443`): it returns the
  peer instead of walking on to an earlier, client-chosen entry, so a malformed header can no longer steer the
  resolved client IP.
- IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) are unmapped before matching trusted proxies, for both the peer and
  the forwarded entries, so a dual-stack proxy still matches its IPv4 entry instead of slipping past the trust check.
- `X-Forwarded-Proto` now follows one rule everywhere, the last non-empty value in `HttpsDetector` and the Laravel
  normalizer, and the normalizer decides trust with the list Laravel itself uses (`trustedproxy.proxies` when set,
  else `helmet.proxies`, with `*` and `**` trusting every peer as in Symfony), so HELMET and the framework can no
  longer disagree about which value to read or from whom to accept it.
- The CSP nonce meta tag is injected only into the real `<head>`, so a literal `<head>` inside a comment, an
  attribute value, or a script, style, textarea or title element no longer receives it.
- An HTML response that carries a CSP nonce has `public` changed to `private` and `s-maxage` dropped from its
  `Cache-Control`, so a shared cache cannot store a page whose nonce differs per request.

### Deprecated

None listed in the changelog for this release.

### Steps

1. Move the constraint in `composer.json` from `^0.2` to `^0.3`, then update:

   ```
   composer update waynestate/helmet
   ```

2. Check your environment for an explicitly empty `HELMET_PROXIES=`. If you want the preset's proxies, unset the
   variable entirely; if you want to trust nobody, keep it empty.
3. On Citadel, confirm the `citadel` preset's NAT assumption: `172.18.0.1` is the Docker bridge gateway, the preset
   is safe only when the front proxy reaches the app over that bridge, and a published app port that is
   source-NATed to the gateway address would make a direct client look like the proxy, so the platform must not
   publish the app's port.
4. If you rely on `X-Forwarded-For` values, expect the fail-closed behavior: an unparseable entry makes the resolved
   client IP the peer, and the warning `helmet.forwarded_for_rejected` is logged with `event`
   (`forwarded_for_rejected`), `reason` (`unparseable_entry`) and `peer` in the context. The Origin value, cookies,
   Authorization, the header and the bad entry are never logged.

### How to verify

```
composer ci
```

That runs `composer validate --strict`, the test suite, Pint, PHPStan, the agent-ready check, `composer docs:check`
and `composer audit`. Then ask the load balancer's question:

```
curl -sI http://localhost/up
```

Expected: `200 ok`, the baseline headers, and `Strict-Transport-Security` on a request that was really HTTPS. The
suite pins the new behavior in `tests/Proxy/ClientIpTest.php`, `tests/Proxy/HttpsDetectorTest.php`,
`tests/Proxy/TrustedProxiesTest.php`, `tests/Headers/SecurityHeadersTest.php` and
`tests/Laravel/SecurityHeadersTest.php`.

---

## 0.2.6 (2026-11-02)

No breaks: HTML rewrites (CSP nonce meta, environment ribbon) keep the response's view object, so `assertInertia()`
and `assertViewHas()` work in apps that use HELMET, and the rest of the release is fixes, deploy-layer refreshes and
docs. Upgrade with:

```
composer update waynestate/helmet
```

---

## 0.2.5 (2026-10-29)

No breaks: adds the agent-ready site (prompt blocks, Markdown twins, llms.txt) from Squire's kit and
`HELMET_SESSION_SECURE=always`, which keeps the session cookie Secure even when HTTPS is not detected, and moves the
proof page to a private site that needs a Wayne State sign-in. Upgrade with:

```
composer update waynestate/helmet
```

---

## 0.2.4 (2026-10-29)

No breaks: `HELMET_PRESET` (Laravel) now also comes from the private `waynestate/playbook` package when the
environment has no value; the config reads it through `Waynestate\Helmet\Support\Setting`, which checks the
environment first, then Playbook, then gives up. A missing preset is still not an error, and `waynestate/playbook`
is suggested, never required. Upgrade with:

```
composer update waynestate/helmet
```

---

## 0.2.3 (2026-10-29)

No breaks: a fix. `helmet_boot()` removed `X-Powered-By` only after it had already answered the health check path, so
a GET or HEAD to `/up` (the default `health_path`) still sent the header; the removal now runs before the health
answer and the request-forgery rejection, so both early exits leave the response clean. Upgrade with:

```
composer update waynestate/helmet
```

---

## 0.2.2 (2026-10-29)

No breaks: every JSON log record now carries a request ID in `extra.request_id`, 16 lowercase hex characters
generated by HELMET (`Logging::requestId()`), stamped by a processor pushed before the redaction processor. An
inbound header is never trusted for the ID. `Logging::create()` takes an optional fourth argument for a
caller-supplied ID (8 to 64 letters, digits, dots, underscores or dashes; anything else is ignored), and
`helmet_boot()` accepts it as `log.request_id`, which must be a string, and now also returns the ID as `request_id`.
Upgrade with:

```
composer update waynestate/helmet
```

---

## 0.2.1 (2026-10-29)

### Breaking

A raw-PHP app that still used the default `PHPSESSID` cookie gets the new `WSUSESSID` name (`__Host-WSUSESSID` on
HTTPS), so signed-in users sign in once more after deploying. Laravel apps are unaffected (Laravel names its own
cookie). The rename happens only while the name is still the PHP default `PHPSESSID`; an app-chosen name is left
alone, and SESAME reads whatever `session_name()` returns. `SessionHardening::apply()` also sets
`cookie_lifetime=0` (a browser-session cookie), `cache_limiter=nocache`, `cookie_path=/`, an empty `cookie_domain`
(host-only) and the cookie name.

### Deprecated

None listed in the changelog for this release.

### Steps

1. Update the package:

   ```
   composer update waynestate/helmet
   ```

2. If your app chose its own session cookie name, nothing changes for it.
3. Expect one more sign-in for raw-PHP users after the deploy; tell app owners it is expected.

### How to verify

```
curl -sI http://localhost/up
```

Expected: the `Set-Cookie` name is `__Host-WSUSESSID` on HTTPS and `WSUSESSID` on HTTP. Pinned in
`tests/Session/SessionHardeningTest.php`.

---

## 0.2.0 (2026-10-29)

### Breaking

- An unknown `HELMET_PRESET` now throws even when `HELMET_PROXIES` is set. A typo in the preset name fails loudly at
  boot instead of going silently unused.
- The trusted-header set in the Laravel adapter was narrowed. `X-Forwarded-Host`, `X-Forwarded-Prefix` and now
  `X-Forwarded-Port` are no longer trusted by default. Port is out because Citadel's HA-Proxy never sends it, so a
  client-sent value would pass through untouched and change the port Laravel reports and the URLs it generates. Apps
  that need any of them choose the set themselves with `trustProxies(at: ..., headers: ...)` or
  `TrustProxies::withHeaders()`.
- Laravel now agrees with HELMET on multi-value `X-Forwarded-Proto`. Citadel's HA-Proxy appends the header instead of
  replacing it, so a client can produce `https, http`. Symfony reads the first value of such a list (the client's),
  while HELMET reads the last (the one the closest proxy appended). A new middleware prepended to the global chain
  rewrites the header to its last value when the request comes from a trusted proxy; a single value is left alone,
  and requests from untrusted peers are ignored as before.
- The `<head>` match when injecting the csp-nonce meta tag was fixed: the old pattern also matched `<header>` and
  `<headline>` and injected the tag after the wrong element. Responses that carry a `Content-Length` header now have
  it refreshed to the new body length after injection, so a stale length cannot cut the page off mid-tag.

### Deprecated

None listed in the changelog for this release.

### Steps

1. Update the package:

   ```
   composer update waynestate/helmet
   ```

2. Check `HELMET_PRESET` for typos; the only names are `citadel` and `docker`.
3. If your app relied on `X-Forwarded-Host`, `X-Forwarded-Prefix` or `X-Forwarded-Port`, choose the set yourself with
   `trustProxies(at: ..., headers: ...)` or `TrustProxies::withHeaders()` in `bootstrap/app.php`.

### How to verify

```
composer ci
```

Then check the headers:

```
curl -sI http://localhost/up
```

Expected: `200 ok`, the baseline headers, and the csp-nonce meta tag in the real `<head>` of an HTML response. The
suite pins the adapter behavior in `tests/Laravel/SecurityHeadersTest.php` and the core in
`tests/Headers/SecurityHeadersTest.php`.

---

## 0.1.0 (2026-10-27)

First tagged release. There is nothing below it to upgrade from: install with

```
composer require waynestate/helmet
```

and follow the [Quick start](guides/quick-start.md).

## Related

- [Install](install.md)
- [Quick start](guides/quick-start.md)
- [Changelog](changelog.md)
- [Troubleshooting](troubleshooting.md)
- [Environment variables](reference/configuration.md)

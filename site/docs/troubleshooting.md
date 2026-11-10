---
title: "Troubleshooting | HELMET docs"
description: "What to look at when a header is missing, a proxy is not trusted, or the guard refuses a request that should pass."
url: https://helmet.apps.wayne.edu/docs/troubleshooting.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Troubleshooting [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#troubleshooting)

This page lists the symptoms HELMET's README, CHANGELOG and test suite justify, each with its cause, the fix and a check command. If your symptom is not here, start from [Install](https://helmet.apps.wayne.edu/docs/install.html) and verify `/up` first; the band's advice applies: short songs, no filler, every part earning its place.

## Check the baseline first [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#check-the-baseline-first)

Before anything else, ask the load balancer's question and confirm the floor is on:

linux

```bash
curl -sI http://localhost/up
```

windows

```powershell
Invoke-WebRequest http://localhost/up
```

Expected: `200 ok`, the baseline headers on the response (`Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and the rest), and no `X-Powered-By` header. If `/up` does not answer before your code runs, boot HELMET at the top of the front controller, before anything is echoed, as the [Quick start](https://helmet.apps.wayne.edu/docs/guides/quick-start.html) shows.

## Headers and the proxy [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#headers-and-the-proxy)

### A request that was really HTTPS is read as plain HTTP, and no HSTS header is sent [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#a-request-that-was-really-https-is-read-as-plain-http-and-no-hsts-header-is-sent)

Cause: HELMET only honors `X-Forwarded-Proto` when the connection came from a trusted proxy. A spoofed `X-Forwarded-Proto: https` from the public internet is ignored, and no HSTS header is sent for a request that was not really secure. The usual miss is a missing or wrong trusted-proxy list: the preset name is a typo, or an explicitly empty `HELMET_PROXIES=` is set (that trusts nobody, even when a preset is set; only an unset variable falls back to the preset).

Fix: use a platform preset (for example `citadel`, which trusts the Docker bridge gateway), or list only the address the front proxy connects from, never a whole private range. A broad range such as `10.0.0.0/8` also trusts other containers on the same network, and any of them could then set `X-Forwarded-Proto`. See [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html) and [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html).

Check:

```
curl -sI http://localhost/up
```

Expected: `Strict-Transport-Security` present on a request that arrived over HTTPS through the proxy, and absent for a request that was not really secure.

### Boot throws because of the preset name [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#boot-throws-because-of-the-preset-name)

Cause: an unknown `HELMET_PRESET` name throws at boot, so a typo fails loudly instead of silently trusting nothing. Since 0.2.0 it throws even when `HELMET_PROXIES` is set: a typo in the preset name fails loudly at boot instead of going silently unused. The only preset names are `citadel` and `docker`.

Fix: correct the value in the environment (or in the Playbook values if Laravel reads it from there), or skip the preset and pass your own list of the addresses your front proxy connects from.

Check:

```
composer ci
```

The suite includes `tests/Platform/PresetsTest.php`, which pins the preset names and the unknown-name failure.

### Headers are missing on routes outside `web` or on unmatched 404s (Laravel) [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#headers-are-missing-on-routes-outside-web-or-on-unmatched-404s-laravel)

Cause: `HELMET_SCOPE=web` keeps `SecurityHeaders` in the `web` group only; then routes outside `web` and unmatched 404s do not carry its headers. By default the scope is `global`, so 404s and JSON routes carry them too.

Fix: leave `HELMET_SCOPE` at its default `global`, or set it to `global` again, if you want every response to carry the headers.

Check:

```
curl -sI http://localhost/a-page-that-does-not-exist
```

Expected: the baseline headers on the 404 response when the scope is `global`. The suite pins both scopes in `tests/Laravel/GlobalScopeTest.php` and `tests/Laravel/WebScopeTest.php`.

## The session [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#the-session)

### Signed-in users sign in once more after deploying 0.2.1 or newer (raw PHP) [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#signed-in-users-sign-in-once-more-after-deploying-0-2-1-or-newer-raw-php)

Cause: `SessionHardening::apply()` renames the cookie while the name is still PHP's default `PHPSESSID`: it becomes `__Host-WSUSESSID` on HTTPS and `WSUSESSID` on HTTP. When an app first runs this version, existing sessions under the old name end: the next request still carries the old cookie, the app finds no session under the new name and starts a fresh one. An app that chose its own name keeps it, and SESAME reads whatever `session_name()` returns, so a changed name is safe for it. Laravel apps are unaffected (Laravel names its own cookie).

Fix: none needed; it is the expected behavior of the rename, and the `__Host-` prefix makes the browser reject the cookie unless it is `Secure`, `Path=/` and has no `Domain`. Tell app owners to expect one more sign-in after the deploy.

Check:

```
curl -sI http://localhost/up
```

Expected: the `Set-Cookie` name is `__Host-WSUSESSID` on HTTPS and `WSUSESSID` on HTTP. The values are pinned in `tests/Session/SessionHardeningTest.php`.

### The session cookie has no `Secure` flag on Citadel [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#the-session-cookie-has-no-secure-flag-on-citadel)

Cause: by default (`auto`) the Secure flag follows HELMET's HTTPS decision. On Citadel, HA-Proxy ends TLS before the request reaches PHP, so without a trusted proxy (no `citadel` preset, no `trusted_proxies`) the request looks plain even though the client connected over HTTPS.

Fix: boot with the `citadel` preset, so HELMET trusts the proxy's `X-Forwarded-Proto` and `auto` sets the flag. To force it regardless, pass `'session' => ['secure' => 'always']` to `helmet_boot()`; `helmet_boot()` reads no environment, so `HELMET_SESSION_SECURE` alone does not do it in raw PHP. In Laravel, `HELMET_PRESET=citadel` is enough for `auto`; `HELMET_SESSION_SECURE=always` forces the flag through Laravel's `session.secure`, unless the app set `session.secure` (`SESSION_SECURE_COOKIE`) itself, which wins. Plain HTTP development keeps `auto`. The cookie name still follows the request, so it never takes the `__Host-` prefix on a plain-HTTP request.

Check:

```
curl -sI http://localhost/up
```

Expected: the session cookie carries the `Secure` flag on HTTPS responses. See [The session](https://helmet.apps.wayne.edu/docs/guides/session.html).

## The request-forgery guard [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#the-request-forgery-guard)

### The guard answers 403 for a request that should pass [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#the-guard-answers-403-for-a-request-that-should-pass)

Cause: for state-changing requests, `Sec-Fetch-Site` is the primary signal when the browser sends it. `same-origin` and `none` pass, `same-site` passes only when `allow_same_site` is true, and `cross-site` or an unknown value is rejected. When `Sec-Fetch-Site` is absent, the `Origin` header is checked against the request's own origin and `trusted_origins`; `Origin: null` is rejected, an unparseable origin is rejected, and an absent origin passes. A rejected request receives `403`, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and the body `Forbidden`, and the answer never echoes request headers.

Fix: pick the one that matches the caller. Set `HELMET_CSRF_ALLOW_SAME_SITE=true` to let `Sec-Fetch-Site: same-site` pass (off by default because sibling subdomains are still same-site); add the caller's origin to `HELMET_CSRF_TRUSTED_ORIGINS`; or add a path pattern to `HELMET_CSRF_EXCEPT` (an exact path, a prefix ending in `*`, or `*` for every path).

Check: look for the warning `helmet.request_refused` in the structured log. Its context carries `event` (`request_refused`), `method` (upper case), `path` (no query string), `sec_fetch_site` and `origin_present`, which tells you exactly which rule rejected the request. The Origin value, cookies, Authorization, the `X-Forwarded-For` header, or the bad entry are never logged. The rule is pinned in `tests/Csrf/RequestForgeryGuardTest.php` and `tests/Laravel/RequestForgeryGuardTest.php`. See [The request-forgery guard](https://helmet.apps.wayne.edu/docs/guides/csrf.html).

## Content Security Policy [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#content-security-policy)

### Boot throws `Unknown CSP source` after upgrading to 1.0 [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#boot-throws-unknown-csp-source-after-upgrading-to-1-0)

Cause: since 1.0.0 a quoted source in the `csp` option or `HELMET_CSP` must be a CSP keyword (`'self'`, `'none'`, `'unsafe-inline'`, `'unsafe-eval'`, `'unsafe-hashes'`, `'wasm-unsafe-eval'`, `'report-sample'`, `'strict-dynamic'`), a hash (`'sha256-...'`, `'sha384-...'`, `'sha512-...'`) or a fixed nonce (`'nonce-...'`). Anything else, such as `'unsafe-inlined'`, throws `\InvalidArgumentException` naming the token and the directive. Before 1.0 the token shipped and the browser ignored it.

Fix: correct the token the message names, or drop it.

Check:

```
curl -sI http://localhost/up
```

Expected: the app answers, and the `Content-Security-Policy` header carries the source as you wrote it. See [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html).

### Inline styles vanished after overriding `style-src` with `'unsafe-inline'` [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#inline-styles-vanished-after-overriding-style-src-with-unsafe-inline)

Cause: the policy adds the per-request nonce to `style-src` and `style-src-elem`. A browser ignores `'unsafe-inline'` once a nonce is present, so setting `style-src` to `["'unsafe-inline'"]` does not restore inline styles; it removes them.

Fix: keep the nonce, move the styles into files, or give the inline styles a nonce.

Check:

```
curl -sI http://localhost/up
```

Expected: the `Content-Security-Policy` header carries the per-request nonce in `style-src` and `style-src-elem`. See [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html).

### Scripts or styles are blocked in a Laravel app that uses Vite or Inertia [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#scripts-or-styles-are-blocked-in-a-laravel-app-that-uses-vite-or-inertia)

Cause: one of the rules that keep the CSP honest is being broken. The CSP has no `'unsafe-inline'` for scripts, so every `<script>` without a `src` needs `nonce="{{ Vite::cspNonce() }}"`. Inertia's `<Head>` renders into the document head after the page loads, so a `<script>` there would not carry the nonce and the browser blocks it. If another package calls `Vite::useCspNonce()` after HELMET, the `@vite` tags carry a different nonce than the CSP header, and the middleware logs a warning when it detects this. A cached page carries a stale nonce, and the browser blocks its scripts.

Fix: put scripts in your layout, where `@vite` and the nonce reach them; remove the second nonce-producing package; and do not cache HTML at a layer that shares it across requests, because the nonce changes every request. In raw PHP, print the meta tag in `<head>` with the nonce `helmet_boot()` returned:

```php
echo helmet_csp_nonce_meta($helmet['nonce']);
```

Check:

```
curl -sI http://localhost/up
```

Expected: the nonce in the `Content-Security-Policy` header matches the `helmet.csp_nonce` request attribute (in a controller, `$request->attributes->get('helmet.csp_nonce')`). The agreement between paths is pinned in `tests/Headers/SecurityHeadersTest.php` and `tests/Laravel/SecurityHeadersTest.php`.

## Logs and metrics [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#logs-and-metrics)

### A JSON log record is missing its request ID [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#a-json-log-record-is-missing-its-request-id)

Cause: every JSON log record carries a request ID in `extra.request_id`: 16 lowercase hex characters generated by HELMET, stamped by a processor pushed before the redaction processor, so a record with a `password` context key still comes out redacted and keeps its ID. An inbound header is never trusted for the ID. If the ID is missing, the logger is not the one `helmet_boot()` configured, or the app is on a release before 0.2.2.

Fix: boot with the `log` option so `helmet_boot()` hands its own logger to the guard, and upgrade to 0.2.2 or newer. To reuse an ID you already have, set `request_id` (8 to 64 letters, digits, dots, underscores or dashes); `Logging::create()` takes it as an optional fourth argument, and anything else is ignored.

Check: send a request and read one line from the log stream. Expected shape:

```json
{"message":"login","context":{"user":"alice"},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-10-29T12:00:00.000000+00:00","extra":{"request_id":"9f2c1ab04d5e6f70"}}
```

Pinned in `tests/Logging/LoggingTest.php`.

### `/metrics` answers 404 even though `HELMET_METRICS_ENABLED=true` [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#metrics-answers-404-even-though-helmet-metrics-enabled-true)

Cause: with neither a token nor an allow list set, the endpoint returns `404` and logs a warning, so a misconfigured app does not hand its metrics to the whole network.

Fix: choose at least one way to prove who is asking: `HELMET_METRICS_TOKEN` (a bearer token, compared with `hash_equals`) or `HELMET_METRICS_ALLOW` (comma-separated IPs or CIDR ranges allowed to scrape without a token).

Check: scrape the endpoint with the token and expect the `helmet_info` gauge with the PHP and HELMET versions. Pinned in `tests/Metrics/MetricsGateTest.php` and `tests/Metrics/BearerTokenTest.php`. See [Metrics](https://helmet.apps.wayne.edu/docs/guides/metrics.html).

### `/metrics` answers 403 on Apache even with the right token [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#metrics-answers-403-on-apache-even-with-the-right-token)

Cause: Apache with mod_php does not copy the `Authorization` header into `$_SERVER`, so a release before this one never saw the token and denied the scrape.

Fix: upgrade. HELMET now reads the token from `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then the request headers themselves. No `.htaccess` rewrite is needed.

Check: scrape with the token and expect `200` and `helmet_info`. Pinned in `tests/Metrics/BearerTokenTest.php`.

### The request panels are empty, or `helmet_metrics_storage_up` is 0 [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#the-request-panels-are-empty-or-helmet-metrics-storage-up-is-0)

Cause: request and CSP metrics only count when `HELMET_METRICS_STORAGE` names a store (`apcu`, `redis` or `sqlite`). Unset, the endpoint serves the version and policy gauges only, on purpose. When it is set but the store cannot be used (the extension is missing, Redis is unreachable, the SQLite file is not writable), the app logs `metrics_storage_unusable` or `metrics_record_failed`, keeps serving pages, and reports `helmet_metrics_storage_up 0`.

Fix: set the store, and make sure its extension is loaded (`php -m` lists `apcu`, `redis` or `pdo_sqlite`) and, for Redis, that `HELMET_METRICS_REDIS_URL` points where the app can reach.

Check: load a page, then scrape: `helmet_http_request_duration_seconds_count` appears and `helmet_metrics_storage_up` is `1`. Pinned in `tests/Metrics/MetricsStorageTest.php` and `tests/FunctionsMetricsRequestsTest.php`. See [Pick a store](https://helmet.apps.wayne.edu/docs/guides/metrics.html#pick-a-store).

### `helmet_php_version_status` says `unknown` [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#helmet-php-version-status-says-unknown)

Cause: no policy is set, or the policy has no entry for the running PHP release. Status is looked up by major and minor version (`8.5`), in either the flat shape or CREED's `versions` shape.

Fix: set `HELMET_POLICY_PATH` to a CREED `policy.json` that lists the release.

Check: scrape and expect `helmet_php_version_status{status="active"} 1` (or `security`, or `eol`). Pinned in `tests/Policy/JsonFilePolicySourceTest.php`.

## Related [#](https://helmet.apps.wayne.edu/docs/troubleshooting.html#related)

- [Install](https://helmet.apps.wayne.edu/docs/install.html)
- [Quick start](https://helmet.apps.wayne.edu/docs/guides/quick-start.html)
- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html)
- [The session](https://helmet.apps.wayne.edu/docs/guides/session.html)
- [The request-forgery guard](https://helmet.apps.wayne.edu/docs/guides/csrf.html)
- [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html)
- [Metrics](https://helmet.apps.wayne.edu/docs/guides/metrics.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
- [Upgrading](https://helmet.apps.wayne.edu/docs/upgrading.html)
- [Changelog](https://helmet.apps.wayne.edu/docs/changelog.html)

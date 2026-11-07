---
title: Troubleshooting
description: What to look at when a header is missing, a proxy is not trusted, or the guard refuses a request that should pass.
---

# Troubleshooting

This page lists the symptoms HELMET's README, CHANGELOG and test suite justify, each with its cause, the fix and a check command. If your symptom is not here, start from [Install](install.md) and verify `/up` first; the band's advice applies: short songs, no filler, every part earning its place.

## Check the baseline first

Before anything else, ask the load balancer's question and confirm the floor is on:

```bash tab="linux"
curl -sI http://localhost/up
```

```powershell tab="windows"
Invoke-WebRequest http://localhost/up
```

Expected: `200 ok`, the baseline headers on the response (`Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, and the rest), and no `X-Powered-By` header. If `/up` does not answer before your code
runs, boot HELMET at the top of the front controller, before anything is echoed, as the
[Quick start](guides/quick-start.md) shows.

## Headers and the proxy

### A request that was really HTTPS is read as plain HTTP, and no HSTS header is sent

Cause: HELMET only honors `X-Forwarded-Proto` when the connection came from a trusted proxy. A spoofed
`X-Forwarded-Proto: https` from the public internet is ignored, and no HSTS header is sent for a request that was not
really secure. The usual miss is a missing or wrong trusted-proxy list: the preset name is a typo, or an explicitly
empty `HELMET_PROXIES=` is set (that trusts nobody, even when a preset is set; only an unset variable falls back to
the preset).

Fix: use a platform preset (for example `citadel`, which trusts the Docker bridge gateway), or list only the address
the front proxy connects from, never a whole private range. A broad range such as `10.0.0.0/8` also trusts other
containers on the same network, and any of them could then set `X-Forwarded-Proto`. See
[HTTPS, and why the proxy list matters](guides/https-and-proxies.md) and
[Platform presets](guides/platform-presets.md).

Check:

```
curl -sI http://localhost/up
```

Expected: `Strict-Transport-Security` present on a request that arrived over HTTPS through the proxy, and absent for a
request that was not really secure.

### Boot throws because of the preset name

Cause: an unknown `HELMET_PRESET` name throws at boot, so a typo fails loudly instead of silently trusting nothing.
Since 0.2.0 it throws even when `HELMET_PROXIES` is set: a typo in the preset name fails loudly at boot instead of
going silently unused. The only preset names are `citadel` and `docker`.

Fix: correct the value in the environment (or in the Playbook values if Laravel reads it from there), or skip the
preset and pass your own list of the addresses your front proxy connects from.

Check:

```
composer ci
```

The suite includes `tests/Platform/PresetsTest.php`, which pins the preset names and the unknown-name failure.

### Headers are missing on routes outside `web` or on unmatched 404s (Laravel)

Cause: `HELMET_SCOPE=web` keeps `SecurityHeaders` in the `web` group only; then routes outside `web` and unmatched 404s
do not carry its headers. By default the scope is `global`, so 404s and JSON routes carry them too.

Fix: leave `HELMET_SCOPE` at its default `global`, or set it to `global` again, if you want every response to carry the
headers.

Check:

```
curl -sI http://localhost/a-page-that-does-not-exist
```

Expected: the baseline headers on the 404 response when the scope is `global`. The suite pins both scopes in
`tests/Laravel/GlobalScopeTest.php` and `tests/Laravel/WebScopeTest.php`.

## The session

### Signed-in users sign in once more after deploying 0.2.1 or newer (raw PHP)

Cause: `SessionHardening::apply()` renames the cookie while the name is still PHP's default `PHPSESSID`: it becomes
`__Host-WSUSESSID` on HTTPS and `WSUSESSID` on HTTP. When an app first runs this version, existing sessions under the
old name end: the next request still carries the old cookie, the app finds no session under the new name and starts a
fresh one. An app that chose its own name keeps it, and SESAME reads whatever `session_name()` returns, so a changed
name is safe for it. Laravel apps are unaffected (Laravel names its own cookie).

Fix: none needed; it is the expected behavior of the rename, and the `__Host-` prefix makes the browser reject the
cookie unless it is `Secure`, `Path=/` and has no `Domain`. Tell app owners to expect one more sign-in after the
deploy.

Check:

```
curl -sI http://localhost/up
```

Expected: the `Set-Cookie` name is `__Host-WSUSESSID` on HTTPS and `WSUSESSID` on HTTP. The values are pinned in
`tests/Session/SessionHardeningTest.php`.

### The session cookie has no `Secure` flag on Citadel

Cause: by default (`auto`) the Secure flag follows the request. On Citadel, HA-Proxy ends TLS before the request
reaches PHP, so the request looks plain even though the client connected over HTTPS.

Fix: set `HELMET_SESSION_SECURE=always` so the cookie keeps its `Secure` flag. In Laravel the value lands in
`helmet.session.secure`; in raw PHP pass `'session' => ['secure' => 'always']` to `helmet_boot()`. Plain HTTP
development keeps `auto`. The cookie name still follows the request, so it never takes the `__Host-` prefix on a
plain-HTTP request.

Check:

```
curl -sI http://localhost/up
```

Expected: the session cookie carries the `Secure` flag on HTTPS responses. See [The session](guides/session.md).

## The request-forgery guard

### The guard answers 403 for a request that should pass

Cause: for state-changing requests, `Sec-Fetch-Site` is the primary signal when the browser sends it. `same-origin`
and `none` pass, `same-site` passes only when `allow_same_site` is true, and `cross-site` or an unknown value is
rejected. When `Sec-Fetch-Site` is absent, the `Origin` header is checked against the request's own origin and
`trusted_origins`; `Origin: null` is rejected, an unparseable origin is rejected, and an absent origin passes. A
rejected request receives `403`, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and the body
`Forbidden`, and the answer never echoes request headers.

Fix: pick the one that matches the caller. Set `HELMET_CSRF_ALLOW_SAME_SITE=true` to let `Sec-Fetch-Site: same-site`
pass (off by default because sibling subdomains are still same-site); add the caller's origin to
`HELMET_CSRF_TRUSTED_ORIGINS`; or add a path pattern to `HELMET_CSRF_EXCEPT` (an exact path, a prefix ending in `*`,
or `*` for every path).

Check: look for the warning `helmet.request_refused` in the structured log. Its context carries `event`
(`request_refused`), `method` (upper case), `path` (no query string), `sec_fetch_site` and `origin_present`, which tells
you exactly which rule rejected the request. The Origin value, cookies, Authorization, the `X-Forwarded-For` header,
or the bad entry are never logged. The rule is pinned in `tests/Csrf/RequestForgeryGuardTest.php` and
`tests/Laravel/RequestForgeryGuardTest.php`. See [The request-forgery guard](guides/csrf.md).

## Content Security Policy

### Inline styles vanished after overriding `style-src` with `'unsafe-inline'`

Cause: the policy adds the per-request nonce to `style-src` and `style-src-elem`. A browser ignores `'unsafe-inline'`
once a nonce is present, so setting `style-src` to `["'unsafe-inline'"]` does not restore inline styles; it removes
them.

Fix: keep the nonce, move the styles into files, or give the inline styles a nonce.

Check:

```
curl -sI http://localhost/up
```

Expected: the `Content-Security-Policy` header carries the per-request nonce in `style-src` and `style-src-elem`. See
[Customizing the CSP](guides/csp.md).

### Scripts or styles are blocked in a Laravel app that uses Vite or Inertia

Cause: one of the rules that keep the CSP honest is being broken. The CSP has no `'unsafe-inline'` for scripts, so
every `<script>` without a `src` needs `nonce="{{ Vite::cspNonce() }}"`. Inertia's `<Head>` renders into the document
head after the page loads, so a `<script>` there would not carry the nonce and the browser blocks it. If another
package calls `Vite::useCspNonce()` after HELMET, the `@vite` tags carry a different nonce than the CSP header, and
the middleware logs a warning when it detects this. A cached page carries a stale nonce, and the browser blocks its
scripts.

Fix: put scripts in your layout, where `@vite` and the nonce reach them; remove the second nonce-producing package;
and do not cache HTML at a layer that shares it across requests, because the nonce changes every request. In raw PHP,
print the meta tag in `<head>` with the nonce `helmet_boot()` returned:

```php
echo helmet_csp_nonce_meta($helmet['nonce']);
```

Check:

```
curl -sI http://localhost/up
```

Expected: the nonce in the `Content-Security-Policy` header matches the `helmet.csp_nonce` request attribute (in a
controller, `$request->attributes->get('helmet.csp_nonce')`). The agreement between paths is pinned in
`tests/Headers/SecurityHeadersTest.php` and `tests/Laravel/SecurityHeadersTest.php`.

## Logs and metrics

### A JSON log record is missing its request ID

Cause: every JSON log record carries a request ID in `extra.request_id`: 16 lowercase hex characters generated by
HELMET, stamped by a processor pushed before the redaction processor, so a record with a `password` context key still
comes out redacted and keeps its ID. An inbound header is never trusted for the ID. If the ID is missing, the logger
is not the one `helmet_boot()` configured, or the app is on a release before 0.2.2.

Fix: boot with the `log` option so `helmet_boot()` hands its own logger to the guard, and upgrade to 0.2.2 or newer.
To reuse an ID you already have, set `request_id` (8 to 64 letters, digits, dots, underscores or dashes);
`Logging::create()` takes it as an optional fourth argument, and anything else is ignored.

Check: send a request and read one line from the log stream. Expected shape:

```json
{"message":"login","context":{"user":"alice"},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-10-29T12:00:00.000000+00:00","extra":{"request_id":"9f2c1ab04d5e6f70"}}
```

Pinned in `tests/Logging/LoggingTest.php`.

### `/metrics` answers 404 even though `HELMET_METRICS_ENABLED=true`

Cause: with neither a token nor an allow list set, the endpoint returns `404` and logs a warning, so a misconfigured
app does not hand its metrics to the whole network.

Fix: choose at least one way to prove who is asking: `HELMET_METRICS_TOKEN` (a bearer token, compared with
`hash_equals`) or `HELMET_METRICS_ALLOW` (comma-separated IPs or CIDR ranges allowed to scrape without a token).

Check: scrape the endpoint with the token and expect the `helmet_info` gauge with the PHP and HELMET versions. Pinned
in `tests/Metrics/MetricsGateTest.php` and `tests/Metrics/BearerTokenTest.php`. See [Metrics](guides/metrics.md).

### `/metrics` answers 403 on Apache even with the right token

Cause: Apache with mod_php does not copy the `Authorization` header into `$_SERVER`, so a release before this one
never saw the token and denied the scrape.

Fix: upgrade. HELMET now reads the token from `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then the
request headers themselves. No `.htaccess` rewrite is needed.

Check: scrape with the token and expect `200` and `helmet_info`. Pinned in `tests/Metrics/BearerTokenTest.php`.

### The request panels are empty, or `helmet_metrics_storage_up` is 0

Cause: request and CSP metrics only count when `HELMET_METRICS_STORAGE` names a store (`apcu`, `redis` or
`sqlite`). Unset, the endpoint serves the version and policy gauges only, on purpose. When it is set but the store
cannot be used (the extension is missing, Redis is unreachable, the SQLite file is not writable), the app logs
`metrics_storage_unusable` or `metrics_record_failed`, keeps serving pages, and reports `helmet_metrics_storage_up 0`.

Fix: set the store, and make sure its extension is loaded (`php -m` lists `apcu`, `redis` or `pdo_sqlite`) and, for
Redis, that `HELMET_METRICS_REDIS_URL` points where the app can reach.

Check: load a page, then scrape: `helmet_http_request_duration_seconds_count` appears and
`helmet_metrics_storage_up` is `1`. Pinned in `tests/Metrics/MetricsStorageTest.php` and
`tests/FunctionsMetricsRequestsTest.php`. See [Pick a store](guides/metrics.md#pick-a-store).

### `helmet_php_version_status` says `unknown`

Cause: no policy is set, or the policy has no entry for the running PHP release. Status is looked up by major and
minor version (`8.5`), in either the flat shape or CREED's `versions` shape.

Fix: set `HELMET_POLICY_PATH` to a CREED `policy.json` that lists the release.

Check: scrape and expect `helmet_php_version_status{status="active"} 1` (or `security`, or `eol`). Pinned in
`tests/Policy/JsonFilePolicySourceTest.php`.

## Related

- [Install](install.md)
- [Quick start](guides/quick-start.md)
- [Platform presets](guides/platform-presets.md)
- [HTTPS, and why the proxy list matters](guides/https-and-proxies.md)
- [The session](guides/session.md)
- [The request-forgery guard](guides/csrf.md)
- [Customizing the CSP](guides/csp.md)
- [Metrics](guides/metrics.md)
- [Environment variables](reference/configuration.md)
- [Upgrading](upgrading.md)
- [Changelog](changelog.md)

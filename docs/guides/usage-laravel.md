---
title: Laravel
description: The Laravel adapter is a service provider, two always-on middlewares, an optional request-forgery guard, and a config file.
---

# Laravel

This page walks the Laravel adapter from `composer require` to a fully wired app: install the adapter, publish the config, trust the proxy, then turn the knobs. The adapter is a service provider, two always-on middlewares, an optional request-forgery guard, and a config file. It only wires and configures: every rule (what the headers say, how a nonce is made, how a CSP is merged, and how a request-forgery check is applied) lives in the core, so Laravel and raw PHP cannot drift apart. The suite runs against Laravel 13 with Orchestra Testbench. Helmet is the band, and the name fits: put it on before you ship.

## Install the adapter

Strap It On:

```bash
composer require waynestate/helmet
```

Package discovery registers `Waynestate\Helmet\Laravel\HelmetServiceProvider`, so there is nothing to add to `bootstrap/providers.php`. The provider merges the `helmet` config and joins the HTTP kernel's middleware:

- `SecurityHeaders` sends the headers in [The headers](headers.md) and strips `X-Powered-By`. By default it is global, so 404s and JSON routes carry them too.
- `InjectRibbon` adds the environment ribbon ([The ribbon](#the-ribbon)). It always stays in the `web` group, because it edits HTML.
- `RequestForgeryGuard` is opt-in. When `helmet.csrf.enabled` is true, it joins the `web` group after `InjectRibbon` and applies the check in [The request-forgery guard](csrf.md).

They are added through the HTTP kernel, so they survive another package adding to the same group. Set `HELMET_SCOPE=web` to keep `SecurityHeaders` in the `web` group only; then routes outside `web` and unmatched 404s do not carry its headers.

At boot the provider also registers two route macros: `Route::cspReport()` for the CSP report endpoint ([Customizing the CSP](csp.md)) and `Route::helmetMetrics()` for the metrics endpoint ([Metrics](metrics.md)). Both macros are always registered, but each route is added only when the app calls it, so both endpoints stay off by default.

## Publish the config

```bash
php artisan vendor:publish --tag=helmet-config
```

This copies `config/helmet.php` into your app. Do it: it is the best place to change the CSP, because the policy is a PHP array there, not JSON squeezed into `.env`. The config file is also the one place HELMET reads the environment (`env()`, plus `HELMET_PRESET` through `Setting`, which also checks Playbook), so `php artisan config:cache` keeps working.

## Trust the proxy

HELMET decides on its own whether a request was really HTTPS, from `HELMET_PROXIES`. Laravel has a separate trusted-proxy setting that drives `url()`, secure cookies and `$request->isSecure()`. The provider follows the same list: when the app has not set `trustedproxy.proxies` itself, it copies `helmet.proxies` over, so `HELMET_PROXIES` drives both. An app that sets `trustedproxy.proxies` keeps its own value, and an explicit `trustProxies(at: ...)` call in `bootstrap/app.php` still wins, because Laravel applies it before the config fallback.

The provider also narrows which forwarded headers Laravel believes from those proxies: `X-Forwarded-For` and `X-Forwarded-Proto`, and nothing else. Laravel's own default also trusts `X-Forwarded-Host`, `X-Forwarded-Prefix` and `X-Forwarded-Port`, and a client can pre-seed any of them on a platform whose proxy does not overwrite them, which poisons `url()`, redirects and password-reset links. Port is out for the same reason: Citadel's HA-Proxy never sends it at all, so a client-sent value would pass through untouched and change the port Laravel reports and the URLs it generates. If your app needs one of them, choose the set yourself with `trustProxies(at: ..., headers: ...)` in `bootstrap/app.php` (or `TrustProxies::withHeaders()`); HELMET then leaves your choice alone.

Citadel's HA-Proxy *appends* `X-Forwarded-Proto` instead of replacing it, so a client can arrive with two values, `https, http`. Symfony reads the first value of such a list (the client's), while HELMET reads the last (the one the closest proxy appended). To keep Laravel in agreement, the provider prepends a small middleware to the global chain that rewrites the header to its last value when the request comes from a trusted proxy. A single value is left alone, and requests from untrusted peers are ignored as before.

As in the [Raw PHP](usage-raw-php.md) path, use a [preset](platform-presets.md) or list only the address your front proxy connects from, never a whole private range. See [Running off Citadel](running-off-citadel.md) for the platform layout.

The `/up` health route is Laravel's own: keep `health: '/up'` in `withRouting()`. The adapter registers no route. Keep `/up` for liveness only. Readiness checks (database, cache) belong on a separate route: call `Route::helmetReady('/ready', ['database' => DatabaseReady::class])` in `withRouting(then: ...)`, where each check is an invokable class, so `route:cache` keeps working. See [Readiness](readiness.md). Do not put dependency checks in the `DiagnosingHealth` listener, which fires for `/up`.

## Environment variables

| Variable | Config key | Default | What it does |
| --- | --- | --- | --- |
| `HELMET_SCOPE` | `helmet.scope` | `global` | `global` puts `SecurityHeaders` in the kernel's global middleware; `web` keeps it in the `web` group only. `InjectRibbon` always stays in `web`. |
| `HELMET_PRESET` | `helmet.preset` | none | A platform preset name (`citadel` or `docker`) that supplies the trusted proxies. Read from the environment, else from Playbook (see [WSU values (Playbook)](wsu-values.md)). `HELMET_PROXIES` wins when both are set. An unknown name throws, even when `HELMET_PROXIES` is set. |
| `HELMET_PROXIES` | `helmet.proxies` | none | Comma-separated IPs or CIDR ranges allowed to set `X-Forwarded-Proto`. Wins over `HELMET_PRESET`. Also copied to `trustedproxy.proxies` when the app has not set it. An explicitly empty value (`HELMET_PROXIES=`) trusts nobody, even when `HELMET_PRESET` is set; only an unset variable falls back to the preset. |
| `HELMET_CSP` | `helmet.csp` | the core default policy | A JSON policy array, merged over the default (see below). Empty or invalid JSON keeps the default. |
| `HELMET_HSTS_MAX_AGE` | `helmet.hsts` | `31536000` | The HSTS `max-age` in seconds, sent on HTTPS only. It must not be negative. |
| `HELMET_HSTS_INCLUDE_SUBDOMAINS` | `helmet.hsts_include_subdomains` | `true` | Set `false` to omit the HSTS `includeSubDomains` directive. |
| `HELMET_HSTS_PRELOAD` | `helmet.hsts_preload` | `false` | Set `true` to append the `preload` directive to HSTS. Requires `max-age` of at least one year and `includeSubDomains`. |
| `HELMET_CSP_TRUSTED_TYPES_ENABLED` | `helmet.csp_trusted_types.enabled` | `false` | Set `true` to append Trusted Types directives to the report-only CSP header. |
| `HELMET_CSP_TRUSTED_TYPES_POLICIES` | `helmet.csp_trusted_types.policies` | `["default"]` | Comma-separated Trusted Types policy names. |
| `HELMET_NO_STORE_ENABLED` | `helmet.no_store.enabled` | `false` | Set `true` to send `Cache-Control: no-store` on matching responses. |
| `HELMET_NO_STORE_PATHS` | `helmet.no_store.paths` | none | Comma-separated path prefixes that carry no-store. Empty means every response. |
| `HELMET_RIBBON` | `helmet.ribbon` | `true` | Set `false` to switch the ribbon off everywhere. |
| `HELMET_POLICY_PATH` | `helmet.policy_path` | none | Path to a CREED `policy.json`. When set, the ribbon shows a version warning for the running PHP (and, in Laravel, the framework) version. A missing or invalid file shows no warning and never breaks the page. |
| `HELMET_DEV_ORIGIN` | `helmet.dev_origin` | none | The Vite dev server origin, used in `local` only when the dev server is running. Must be `http` or `https` on `localhost`, `127.0.0.1` or `[::1]`. Overrides the hot file. |
| `HELMET_CSP_REPORT_URI` | `helmet.csp_report_uri` | none | The URL browsers POST CSP violation reports to. Adds `report-uri` and `report-to` to the policy and a `Reporting-Endpoints` header. Must be `https`. |
| `HELMET_CSP_REPORT_ONLY` | `helmet.csp_report_only` | `false` | Set `true` to send the policy as `Content-Security-Policy-Report-Only` (observe violations without blocking them). |
| `HELMET_CSRF_ENABLED` | `helmet.csrf.enabled` | `false` | Set `true` to turn on the request-forgery guard for state-changing requests. |
| `HELMET_CSRF_ALLOW_SAME_SITE` | `helmet.csrf.allow_same_site` | `false` | Set `true` to let `Sec-Fetch-Site: same-site` pass. Off by default because sibling subdomains are still same-site. |
| `HELMET_CSRF_TRUSTED_ORIGINS` | `helmet.csrf.trusted_origins` | none | Comma-separated origins allowed to make state-changing requests when the `Origin` fallback is used. |
| `HELMET_CSRF_EXCEPT` | `helmet.csrf.except` | none | Comma-separated path patterns that skip the guard: an exact path, a prefix ending in `*`, or `*` for every path. |
| `HELMET_WARN_DEBUG` | `helmet.warn_debug` | `true` | Set `false` to stop the one boot-time warning the adapter logs when the app boots with `app.debug` true in `production`. |

> [!WARNING]
> Preload is hard to undo: once a browser has preloaded the domain it refuses plain-HTTP connections until the entry is removed.

## Customizing the CSP in Laravel

`helmet.csp` takes the same shape as the raw PHP `csp` option and follows the same rule: it is merged over the default, per directive. A directive you set replaces the default's, a directive you leave out keeps it, and `null` removes one. Open the published `config/helmet.php` and replace the `$csp = SecurityHeaders::defaultCspPolicy();` line:

```php
$csp = [
    'img-src' => ["'self'", 'data:', 'https://images.example.edu'],
    'form-action' => null,
];
```

If you would rather use the environment, `HELMET_CSP` takes the same array as JSON. In a `.env` file it needs double quotes and escaped inner quotes, which is the reason to publish the config instead:

```ini
HELMET_CSP="{\"img-src\":[\"'self'\",\"data:\"],\"form-action\":null}"
```

Invalid JSON is ignored and the default policy stays, so a typo in the environment cannot loosen the CSP. For the merge rule and report-only mode, see [Customizing the CSP](csp.md).

> [!WARNING]
> One gotcha when overriding: the policy adds the per-request nonce to `style-src` and `style-src-elem`. A browser ignores `'unsafe-inline'` once a nonce is present, so setting `style-src` to `["'unsafe-inline'"]` does not restore inline styles; it removes them.

## The nonce in Blade

The middleware puts the per-request nonce on the request as the attribute `helmet.csp_nonce`, the same name the PSR-15 middleware uses. It also hands the nonce to Laravel's Vite helper, so `@vite` needs nothing: the `script` and `style` tags it print already carry the nonce the CSP header sends.

For an inline script, print the nonce from the Vite helper:

```blade
<script nonce="{{ Vite::cspNonce() }}">
    // allowed by the nonce, and free to load more scripts (strict-dynamic)
</script>
```

In a controller it is `$request->attributes->get('helmet.csp_nonce')`. A new nonce is made for every request. Repetition is a fine Helmet song and a terrible nonce.

## Vite and Inertia

The middleware hands the per-request nonce to Laravel's Vite helper and injects a `csp-nonce` meta tag into `<head>`. `@vite` needs nothing. The meta tag lets Vite's dev runtime nonce the CSS and preload tags it adds after page load. When the request ends, HELMET resets the Vite nonce to a fresh random one, so a long-lived worker (Octane, Swoole, FrankenPHP) does not carry one request's nonce into the next.

A few rules keep the CSP honest:

- **Nonce every inline script.** The CSP has no `'unsafe-inline'` for scripts. Every `<script>` without a `src` needs `nonce="{{ Vite::cspNonce() }}"`.
- **No `'unsafe-eval'`.** The CSP never allows it. If your bundle needs `eval`, fix the bundle.
- **No scripts in Inertia's `<Head>`.** Inertia's `<Head>` renders into the document head after the page loads. A `<script>` there would not carry the nonce, and the browser would block it. Put scripts in your layout, where `@vite` and the nonce reach them.
- **No second nonce-producing package.** If another package calls `Vite::useCspNonce()` after HELMET, the `@vite` tags carry a different nonce than the CSP header, and the browser blocks the scripts. The middleware logs a warning when it detects this.
- **No shared caching of nonced HTML.** The nonce changes every request. A cached page would carry a stale nonce, and the browser would block its scripts. Do not cache HTML at a layer that shares it across requests.
- **Do not replace `style-src` with `'unsafe-inline'`.** The policy adds the per-request nonce to `style-src` and `style-src-elem`, and a browser ignores `'unsafe-inline'` once a nonce is present. An override that relies on it loses every inline style silently. Keep the nonce, move the styles into files, or give the inline styles a nonce.

In raw PHP, print the meta tag in `<head>` with the nonce `helmet_boot()` returned:

```php
echo helmet_csp_nonce_meta($helmet['nonce']);
```

The Vite dev server is allowed in the `local` environment only, and only when it is running (a hot file exists). The origin comes from `HELMET_DEV_ORIGIN` when set, otherwise from the hot file. It must be `http` or `https` on `localhost`, `127.0.0.1` or `[::1]`, with an optional port. An invalid origin is ignored and a warning is logged. In every other environment the dev server origin is never added.

## The ribbon

In `local`, `development`, `dev`, `testing`, `test` and `staging`, `InjectRibbon` adds `<div class="helmet-ribbon" data-environment="staging">staging</div>` right after the opening `<body>` tag of an HTML response, so nobody mistakes a test site for the real one. It never appears in `production`, on JSON or other non-HTML responses, or on HTML with no `<body>`. The package ships no stylesheet; style `.helmet-ribbon` in your own CSS. Even with `HELMET_RIBBON=true`, production never gets it, so there is no Aftertaste.

**Version warnings.** Point `HELMET_POLICY_PATH` at a CREED `policy.json` and the ribbon gains a warning line for each component version that is in its security-only phase or end of life. The raw PHP path checks the running PHP version; the Laravel adapter also checks the framework's major version. With no policy path there is no warning and no file read, and a missing, unreadable or invalid file shows no warning and never breaks the page. Style `.helmet-ribbon-warning` in your own CSS.

## Raw PHP and Laravel agree

Both paths call `SecurityHeaders::build()`, so for the same request and settings they send the same headers. A test pins that down: it compares what the Laravel middleware sends, name and value, with `build()`. The settings map like this:

| Setting | Raw PHP (`helmet_boot()`) | Laravel |
| --- | --- | --- |
| Trusted proxies | `trusted_proxies` | `HELMET_PROXIES` / `helmet.proxies` |
| CSP overrides | `csp` | `HELMET_CSP` / `helmet.csp`, same merge, `null` removes |
| HSTS max-age | `hsts_max_age` | `HELMET_HSTS_MAX_AGE` / `helmet.hsts` |
| HSTS subdomains | `hsts_include_subdomains` | `HELMET_HSTS_INCLUDE_SUBDOMAINS` / `helmet.hsts_include_subdomains`, default `true` |
| HSTS preload | `hsts_preload` | `HELMET_HSTS_PRELOAD` / `helmet.hsts_preload`, default `false` |
| Trusted Types | `csp_trusted_types` | `HELMET_CSP_TRUSTED_TYPES_*` / `helmet.csp_trusted_types.*`, default off |
| no-store | `no_store` | `HELMET_NO_STORE_*` / `helmet.no_store.*`, default off |
| CSP report URI | `csp_report_uri` | `HELMET_CSP_REPORT_URI` / `helmet.csp_report_uri` |
| CSP report-only | `csp_report_only` | `HELMET_CSP_REPORT_ONLY` / `helmet.csp_report_only`, default `false` |
| COOP | `coop` | `HELMET_COOP` / `helmet.coop`, default `same-origin`, `null` sends no header |
| CORP | `corp` | `HELMET_CORP` / `helmet.corp`, default `null` (no header) |
| Permissions-Policy | `permissions_policy` | `HELMET_PERMISSIONS_POLICY` / `helmet.permissions_policy`, default deny list, `[]` sends no header |
| Nonce | `$helmet['nonce']` | `request()->attributes->get('helmet.csp_nonce')` |
| Health check | `health_path`, answered by `helmet_boot()` | Laravel's `health: '/up'` |
| Readiness | `readiness` option, answered by `helmet_boot()`, default off | `Route::helmetReady()`, off until called |
| Request-forgery guard | `csrf` option to `helmet_boot()` | `helmet.csrf.*` / `HELMET_CSRF_*`; the middleware is registered only when enabled |
| Session hardening, JSON logs | applied by `helmet_boot()` | not applied by the adapter; Laravel's session and logging config apply |

For session hardening in Laravel, use the framework's own session config: set `secure`, `http_only`, and `same_site` in `config/session.php` rather than expecting the adapter to apply raw-PHP `SessionHardening`. For structured logs, use Laravel's log channels, for example the `stderr` channel, rather than expecting the adapter to configure a raw-PHP logger. The first two security events ([Security events](https://github.com/waynestate/helmet#security-events)) are written through the Log facade with the same message and context, and the third, `helmet.debug_enabled_in_production`, is written by the Laravel adapter at boot. The check runs at boot, not per request: the value is fixed for the life of the process, and a long-lived worker must not log it on every request. Both sides are checked strictly: `app.debug` must be the boolean `true`, and the environment must be exactly `production`. Opt out with `helmet.warn_debug` (env `HELMET_WARN_DEBUG`); a logging failure is swallowed and never breaks the boot.

## Related

- [The headers](headers.md)
- [Customizing the CSP](csp.md)
- [Configuring HSTS](hsts.md)
- [The request-forgery guard](csrf.md)
- [Platform presets](platform-presets.md)
- [WSU values (Playbook)](wsu-values.md)
- [Running off Citadel](running-off-citadel.md)
- [Metrics](metrics.md)
- [The session](session.md)
- [Environment variables](../reference/configuration.md)
- [Troubleshooting](../troubleshooting.md)

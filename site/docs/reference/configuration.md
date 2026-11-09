---
title: "Environment variables | HELMET docs"
description: "Every HELMET_ environment variable the package reads, its meaning, its default, and the file that reads it."
url: https://helmet.apps.wayne.edu/docs/reference/configuration.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Configuration[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#configuration)

Every `HELMET_` environment variable the package reads, its meaning, its default, and the file that reads it. The table below is generated from the code, so it cannot drift from what the package actually reads. To set a value, add it to your environment or `.env`; for how the platform presets choose the trusted proxies, see [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html).

## How a value is read[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#how-a-value-is-read)

Every setting except `HELMET_PRESET` is read from the environment with Laravel's `env()`; the config file treats an empty value as not set, so a blank value keeps the default in the table. `HELMET_PRESET` alone goes through `Waynestate\Helmet\Support\Setting::get()`: the real environment wins (getenv, then `$_ENV`, then `$_SERVER`), an empty string counts as not set, and when the environment has no value for the key the private `waynestate/playbook` package (Playbook) supplies it, if it is installed; when neither has it, null comes back and no preset is applied. Playbook is optional by design: the package never requires it, it only looks for the class. That is how a WSU value can arrive at an app without this public repo naming a single one of them.

## The config keys[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#the-config-keys)

Every value above lands in the `helmet` config repo, and every other part of the package reads it through `config()`. This lists every key, one level deep; the environment variable that fills each one is in [The variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html#the-variables) below. Publish the config (`php artisan vendor:publish --tag=helmet-config`) to edit a key directly instead of through the environment.

| Key | Meaning |
| --- | --- |
| `preset` | The platform preset name that fills `proxies` (see [Platform presets](https://helmet.apps.wayne.edu/docs/reference/configuration.html#platform-presets)). Read from `HELMET_PRESET`, else from Playbook; null when no preset is named. An unknown name throws at boot, even when `HELMET_PROXIES` is set. |
| `proxies` | The trusted proxies (IPs or CIDR ranges) that may set `X-Forwarded-Proto`. Filled from `HELMET_PROXIES`; only an unset variable falls back to the `preset` list. Empty trusts nobody. |
| `scope` | Where the `SecurityHeaders` middleware runs: `global` (the default) adds it to the kernel's global middleware, `web` keeps it in the `web` group only. Set with `HELMET_SCOPE`. |
| `csp` | The Content-Security-Policy as a policy array keyed by directive, merged over the core default per directive; a directive you set replaces the default's, one you leave out keeps it, and one set to null is removed. Filled from `HELMET_CSP` when it parses as a non-empty JSON array. |
| `csp_report_uri` | The CSP report destination: an absolute https URL with a host, or a path relative to the page's origin that starts with a single slash. null sends no reporting directives. Set with `HELMET_CSP_REPORT_URI`. |
| `csp_report_only` | true sends the policy as `Content-Security-Policy-Report-Only`, observing violations without blocking them. Set with `HELMET_CSP_REPORT_ONLY`. |
| `csp_trusted_types` | The Trusted Types directives, appended to the report-only header only, never the enforcing one. Off by default. |
| `csp_trusted_types.enabled` | Whether the Trusted Types directives are appended. Set with `HELMET_CSP_TRUSTED_TYPES_ENABLED`. |
| `csp_trusted_types.policies` | The Trusted Types policy names, default `["default"]`. Filled from `HELMET_CSP_TRUSTED_TYPES_POLICIES` (comma-separated). |
| `csp_style_src_attr` | The inline style attribute mode: `inline` (the default) keeps `style-src-attr 'unsafe-inline'` for WayneUI's recipes, `report` adds a report-only probe with `style-src-attr 'none'`, and `none` blocks inline style attributes. An app that sets `style-src-attr` in `csp` keeps its own value. Set with `HELMET_CSP_STYLE_SRC_ATTR`. See [Inline style attributes](https://helmet.apps.wayne.edu/docs/guides/csp.html#inline-style-attributes). |
| `coop` | The Cross-Origin-Opener-Policy value, default `same-origin`; null sends no COOP header. Set with `HELMET_COOP`. |
| `corp` | The Cross-Origin-Resource-Policy value; null (the default) sends no CORP header. Set with `HELMET_CORP`. |
| `permissions_policy` | The Permissions-Policy feature map: each feature maps to its allowlist, and an empty allowlist denies the feature. The default is a deny list; an empty map sends no header. Filled from `HELMET_PERMISSIONS_POLICY` when it parses as JSON. |
| `hsts` | The Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. Set with `HELMET_HSTS_MAX_AGE`. |
| `hsts_include_subdomains` | Whether the HSTS header carries the `includeSubDomains` directive. Set with `HELMET_HSTS_INCLUDE_SUBDOMAINS`. |
| `hsts_preload` | Whether the HSTS header carries the `preload` directive. Preload is hard to undo, so enable it only when every subdomain serves HTTPS. Set with `HELMET_HSTS_PRELOAD`. |
| `no_store` | The `Cache-Control: no-store` setting. Off by default. |
| `no_store.enabled` | Whether the `no-store` header is sent on matching responses. Set with `HELMET_NO_STORE_ENABLED`. |
| `no_store.paths` | Path prefixes that carry `no-store`; empty means every response. Filled from `HELMET_NO_STORE_PATHS` (comma-separated). |
| `session` | The session cookie settings, in the shape of `helmet_boot()`'s `session` option. Nothing in the package reads this key: the Laravel adapter leaves the framework's session configuration alone, and `helmet_boot()` takes only what it is passed, so a Laravel app that boots through it hands it `config('helmet.session')`. |
| `session.secure` | The session cookie Secure flag: `auto` (the default) follows the request's HTTPS detection, `always` forces it for apps whose TLS terminates in front of the app. Set with `HELMET_SESSION_SECURE`; an invalid value throws when the config loads. |
| `ribbon` | Whether the environment ribbon is injected into HTML responses in non-production environments. Set with `HELMET_RIBBON`. |
| `policy_path` | The path to a CREED `policy.json` the ribbon reads for version warnings; null means no warning and no file read. Set with `HELMET_POLICY_PATH`. |
| `dev_origin` | The Vite dev server origin, used in `local` only while the dev server is running; it overrides the hot file. Set with `HELMET_DEV_ORIGIN`. |
| `warn_debug` | Whether the Laravel adapter writes the `helmet.debug_enabled_in_production` warning when the app boots with debug enabled in production. Set with `HELMET_WARN_DEBUG`. |
| `warn_broad_proxies` | Whether HELMET writes the `helmet.broad_trusted_proxy` warning at boot when the trusted-proxy list holds a broad entry (an IPv4 range shorter than `/16` or an IPv6 range shorter than `/48`). Set with `HELMET_WARN_BROAD_PROXIES` (Laravel) or the `warn_broad_proxies` option of `helmet_boot()`. |
| `error_pages` | Whether the Laravel adapter answers a 4xx HTTP exception (a missing page, a wrong method, `abort(403)`, a throttle) with HELMET's fixed client-error page, the one `helmet_client_error_response()` builds, instead of the framework's error view. Off by default. Requests that expect JSON, validation errors, authentication redirects and every 5xx stay with Laravel. Set with `HELMET_ERROR_PAGES`. |
| `metrics` | The `/metrics` endpoint, in the Prometheus text exposition format. Off by default. |
| `metrics.enabled` | Whether the endpoint is on. Set with `HELMET_METRICS_ENABLED`. |
| `metrics.token` | The bearer token a scraper must present (`Authorization: Bearer <token>`); compared with `hash_equals`. Set with `HELMET_METRICS_TOKEN`. |
| `metrics.allow` | Client IPs or CIDR ranges allowed to read the endpoint without a token. Filled from `HELMET_METRICS_ALLOW` (comma-separated). |
| `metrics.storage` | The store the request metrics share between PHP workers: `apcu`, `redis` or `sqlite`. null (the default) leaves request metrics off and the endpoint reports the request-free gauges only. Set with `HELMET_METRICS_STORAGE`. See [Metrics](https://helmet.apps.wayne.edu/docs/guides/metrics.html). |
| `metrics.redis_url` | For `redis`: `redis://[:password@]host[:port][/database]`; null means `redis://127.0.0.1:6379`. Set with `HELMET_METRICS_REDIS_URL`. |
| `metrics.sqlite_path` | For `sqlite`: the database file; null means `helmet-metrics.sqlite` in the system temp folder. Set with `HELMET_METRICS_SQLITE_PATH`. |
| `csrf` | The request-forgery guard. Off by default. |
| `csrf.enabled` | Whether the guard is on. Set with `HELMET_CSRF_ENABLED`. |
| `csrf.allow_same_site` | Whether `Sec-Fetch-Site: same-site` passes. Off by default because sibling subdomains are still same-site. Set with `HELMET_CSRF_ALLOW_SAME_SITE`. |
| `csrf.trusted_origins` | Origins that may make state-changing requests to the app. Filled from `HELMET_CSRF_TRUSTED_ORIGINS` (comma-separated). |
| `csrf.except` | Paths that skip the guard: an exact path, a prefix ending in `*`, or `*` for every path. Filled from `HELMET_CSRF_EXCEPT` (comma-separated). |

## Platform presets[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#platform-presets)

A preset names a platform's trusted-proxy list, so an app does not hard-code an address that belongs to the platform, not the app. The presets live in `Waynestate\Helmet\Platform\Presets`:

| Preset | Trusted proxies |
| --- | --- |
| `citadel` | the Docker bridge gateway to the HA-Proxy front (see [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html)) |
| `docker` | none: a plain Docker network with no front proxy |

Laravel reads the preset from `HELMET_PRESET` (Playbook supplies it when the environment has none); an explicit `HELMET_PROXIES` always wins over the preset. Off the WSU platform, skip the preset and pass your own list of the addresses your front proxy connects from. An unknown preset name throws at boot, so a typo fails loudly instead of silently trusting nothing.

## The variables[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#the-variables)

| Variable | Meaning | Default | Read in |
| --- | --- | --- | --- |
| HELMET_COOP | Value of the Cross-Origin-Opener-Policy header; an empty value sends no header. | same-origin | config/helmet.php |
| HELMET_CORP | Value of the Cross-Origin-Resource-Policy header; unset sends no header. | none | config/helmet.php |
| HELMET_CSP | JSON policy array merged over the default CSP per directive; empty or invalid JSON keeps the default. | the core default policy | config/helmet.php |
| HELMET_CSP_REPORT_ONLY | true sends the policy as Content-Security-Policy-Report-Only, observing violations without blocking them. | false | config/helmet.php |
| HELMET_CSP_REPORT_URI | URL browsers POST CSP violation reports to; adds report-uri and report-to to the policy plus a Reporting-Endpoints header. An absolute URL must be https with a host, or a path relative to the page's origin that starts with a single slash. | none | config/helmet.php |
| HELMET_CSP_STYLE_SRC_ATTR | Inline style attribute mode: inline keeps style-src-attr 'unsafe-inline' for WayneUI's recipes, report adds a report-only probe with 'none', none blocks inline style attributes. | inline | config/helmet.php |
| HELMET_CSP_TRUSTED_TYPES_ENABLED | true appends Trusted Types directives to the report-only CSP header. | false | config/helmet.php |
| HELMET_CSP_TRUSTED_TYPES_POLICIES | Comma-separated Trusted Types policy names; replaces the default list. | \["default"\] | config/helmet.php |
| HELMET_CSRF_ALLOW_SAME_SITE | true lets Sec-Fetch-Site: same-site pass; off by default because sibling subdomains are still same-site. | false | config/helmet.php |
| HELMET_CSRF_ENABLED | true turns on the request-forgery guard for state-changing requests. | false | config/helmet.php |
| HELMET_CSRF_EXCEPT | Comma-separated path patterns that skip the guard: an exact path, a prefix ending in \*, or \* for every path. | none | config/helmet.php |
| HELMET_CSRF_TRUSTED_ORIGINS | Comma-separated origins allowed to make state-changing requests when the Origin fallback is used. | none | config/helmet.php |
| HELMET_DEV_ORIGIN | Vite dev server origin, used in local only while the dev server is running; must be http or https on localhost, 127.0.0.1 or \[::1\]. Overrides the hot file. | none | config/helmet.php |
| HELMET_ERROR_PAGES | true makes the Laravel adapter answer a 4xx HTTP exception with HELMET's fixed client-error page; JSON clients, validation, authentication redirects and 5xx stay with Laravel. | false | config/helmet.php |
| HELMET_HSTS_INCLUDE_SUBDOMAINS | false omits the HSTS includeSubDomains directive. | true | config/helmet.php |
| HELMET_HSTS_MAX_AGE | HSTS max-age in seconds, sent on HTTPS only; must not be negative. | 31536000 | config/helmet.php |
| HELMET_HSTS_PRELOAD | true appends preload to HSTS; requires a year of max-age and includeSubDomains, and is hard to undo. | false | config/helmet.php |
| HELMET_METRICS_ALLOW | Comma-separated IPs or CIDR ranges allowed to scrape /metrics without a token. | none | config/helmet.php |
| HELMET_METRICS_ENABLED | true exposes the /metrics endpoint; with neither a token nor an allow list it returns 404 and logs a warning. | false | config/helmet.php |
| HELMET_METRICS_REDIS_URL | For HELMET_METRICS_STORAGE=redis: redis://\[:password@\]host\[:port\]\[/database\]. | redis://127.0.0.1:6379 | config/helmet.php |
| HELMET_METRICS_SQLITE_PATH | For HELMET_METRICS_STORAGE=sqlite: the database file. | helmet-metrics.sqlite in the system temp folder | config/helmet.php |
| HELMET_METRICS_STORAGE | apcu, redis or sqlite turns on the request metrics (counts and latency by route group, CSP violations) and names the store every PHP worker shares; unset leaves them off. Needs HELMET_METRICS_ENABLED. | none | config/helmet.php |
| HELMET_METRICS_TOKEN | Bearer token the scraper sends as Authorization: Bearer ; compared with hash_equals. | none | config/helmet.php |
| HELMET_NO_STORE_ENABLED | true sends Cache-Control: no-store on matching responses. | false | config/helmet.php |
| HELMET_NO_STORE_PATHS | Comma-separated path prefixes that carry no-store; empty means every response. | none | config/helmet.php |
| HELMET_PERMISSIONS_POLICY | JSON feature map for the Permissions-Policy header; the default is a deny list and an empty object sends no header. | the default deny list | config/helmet.php |
| HELMET_POLICY_PATH | Path to a CREED policy.json; the ribbon then shows a version warning for the running PHP (and, in Laravel, framework) version, and /metrics reports the PHP support status and end date. A missing or invalid file never breaks the page. | none | config/helmet.php |
| HELMET_PRESET | Platform preset name (citadel or docker) that supplies the trusted proxies; read from the environment, else from Playbook. HELMET_PROXIES wins when both are set; an unknown name throws. | none | config/helmet.php |
| HELMET_PROXIES | Comma-separated IPs or CIDR ranges allowed to set X-Forwarded-Proto; wins over HELMET_PRESET and is copied to trustedproxy.proxies when the app has not set it. An explicitly empty value trusts nobody. | none | config/helmet.php |
| HELMET_RIBBON | false switches the environment ribbon off everywhere. | true | config/helmet.php |
| HELMET_SCOPE | global puts SecurityHeaders in the kernel's global middleware; web keeps it in the web group only. InjectRibbon always stays in web. | global | config/helmet.php |
| HELMET_SESSION_SECURE | auto sets the Secure flag only when the request is HTTPS; always forces it for apps whose TLS terminates in a proxy. Anything else throws. | auto | config/helmet.php |
| HELMET_WARN_BROAD_PROXIES | true makes HELMET log one helmet.broad_trusted_proxy warning at boot when the trusted-proxy list holds a broad entry (an IPv4 range shorter than /16 or an IPv6 range shorter than /48). | true | config/helmet.php |
| HELMET_WARN_DEBUG | true makes the Laravel adapter log one warning when the app boots with debug enabled in production. | true | config/helmet.php |

Seven of these variables are also read by the raw PHP path: `helmet_metrics_env()` in `src/functions.php` reads `HELMET_METRICS_ALLOW`, `HELMET_METRICS_ENABLED`, `HELMET_METRICS_REDIS_URL`, `HELMET_METRICS_SQLITE_PATH`, `HELMET_METRICS_STORAGE`, `HELMET_METRICS_TOKEN` and `HELMET_POLICY_PATH` through the same `Setting::get()` (environment first, then Playbook), so the same values drive `helmet_metrics()` and `helmet_metrics_track()` outside Laravel. The table's "Read in" column names the first reader it finds, which is why those rows point at `config/helmet.php`.

## Example .env[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#example-env)

```
HELMET_PRESET=citadel
HELMET_CSP_REPORT_URI=https://app.apps.wayne.edu/csp-report
HELMET_METRICS_ENABLED=true
HELMET_METRICS_TOKEN=change-me
```

Warning

`HELMET_METRICS_TOKEN` is a secret. Set a different value per environment and never commit it; the scraper sends it as `Authorization: Bearer <token>` and the package compares it with `hash_equals`.

## Related[#](https://helmet.apps.wayne.edu/docs/reference/configuration.html#related)

- [API](https://helmet.apps.wayne.edu/docs/reference/api.html)
- [Proof](https://helmet.apps.wayne.edu/docs/reference/proof.html)
- [Install](https://helmet.apps.wayne.edu/docs/install.html)
- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [WSU values (Playbook)](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html)

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

The Laravel adapter reads every value through `Waynestate\Helmet\Support\Setting::get()`. The real environment wins (getenv, then `$_ENV`, then `$_SERVER`); an empty string counts as not set, so a blank value falls through to the next source. When the environment has no value for the key, the private `waynestate/playbook` package (Playbook) supplies it, if it is installed. When neither has it, null comes back and the default in the table applies. Playbook is optional by design: the package never requires it, it only looks for the class. That is how a WSU value can arrive at an app without this public repo naming a single one of them.

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
| HELMET_CSP_REPORT_URI | URL browsers POST CSP violation reports to; adds report-uri and report-to to the policy plus a Reporting-Endpoints header. Must be https. | none | config/helmet.php |
| HELMET_CSP_TRUSTED_TYPES_ENABLED | true appends Trusted Types directives to the report-only CSP header. | false | config/helmet.php |
| HELMET_CSP_TRUSTED_TYPES_POLICIES | Comma-separated Trusted Types policy names; replaces the default list. | \["default"\] | config/helmet.php |
| HELMET_CSRF_ALLOW_SAME_SITE | true lets Sec-Fetch-Site: same-site pass; off by default because sibling subdomains are still same-site. | false | config/helmet.php |
| HELMET_CSRF_ENABLED | true turns on the request-forgery guard for state-changing requests. | false | config/helmet.php |
| HELMET_CSRF_EXCEPT | Comma-separated path patterns that skip the guard: an exact path, a prefix ending in \*, or \* for every path. | none | config/helmet.php |
| HELMET_CSRF_TRUSTED_ORIGINS | Comma-separated origins allowed to make state-changing requests when the Origin fallback is used. | none | config/helmet.php |
| HELMET_DEV_ORIGIN | Vite dev server origin, used in local only while the dev server is running; must be http or https on localhost, 127.0.0.1 or \[::1\]. Overrides the hot file. | none | config/helmet.php |
| HELMET_HSTS_INCLUDE_SUBDOMAINS | false omits the HSTS includeSubDomains directive. | true | config/helmet.php |
| HELMET_HSTS_MAX_AGE | HSTS max-age in seconds, sent on HTTPS only; must not be negative. | 31536000 | config/helmet.php |
| HELMET_HSTS_PRELOAD | true appends preload to HSTS; requires a year of max-age and includeSubDomains, and is hard to undo. | false | config/helmet.php |
| HELMET_METRICS_ALLOW | Comma-separated IPs or CIDR ranges allowed to scrape /metrics without a token. | none | config/helmet.php |
| HELMET_METRICS_ENABLED | true exposes the /metrics endpoint; with neither a token nor an allow list it returns 404 and logs a warning. | false | config/helmet.php |
| HELMET_METRICS_TOKEN | Bearer token the scraper sends as Authorization: Bearer ; compared with hash_equals. | none | config/helmet.php |
| HELMET_NO_STORE_ENABLED | true sends Cache-Control: no-store on matching responses. | false | config/helmet.php |
| HELMET_NO_STORE_PATHS | Comma-separated path prefixes that carry no-store; empty means every response. | none | config/helmet.php |
| HELMET_PERMISSIONS_POLICY | JSON feature map for the Permissions-Policy header; the default is a deny list and an empty object sends no header. | the default deny list | config/helmet.php |
| HELMET_POLICY_PATH | Path to a CREED policy.json; the ribbon then shows a version warning for the running PHP (and, in Laravel, framework) version. A missing or invalid file never breaks the page. | none | config/helmet.php |
| HELMET_PRESET | Platform preset name (citadel or docker) that supplies the trusted proxies; read from the environment, else from Playbook. HELMET_PROXIES wins when both are set; an unknown name throws. | none | config/helmet.php |
| HELMET_PROXIES | Comma-separated IPs or CIDR ranges allowed to set X-Forwarded-Proto; wins over HELMET_PRESET and is copied to trustedproxy.proxies when the app has not set it. An explicitly empty value trusts nobody. | none | config/helmet.php |
| HELMET_RIBBON | false switches the maintenance ribbon off everywhere. | true | config/helmet.php |
| HELMET_SCOPE | global puts SecurityHeaders in the kernel's global middleware; web keeps it in the web group only. InjectRibbon always stays in web. | global | config/helmet.php |
| HELMET_SESSION_SECURE | auto sets the Secure flag only when the request is HTTPS; always forces it for apps whose TLS terminates in a proxy. Anything else throws. | auto | config/helmet.php |
| HELMET_WARN_BROAD_PROXIES | true makes HELMET log one helmet.broad_trusted_proxy warning at boot when the trusted-proxy list holds a broad entry (an IPv4 range shorter than /16 or an IPv6 range shorter than /48). | true | config/helmet.php |
| HELMET_WARN_DEBUG | true makes the Laravel adapter log one warning when the app boots with debug enabled in production. | true | config/helmet.php |

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

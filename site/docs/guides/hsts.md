---
title: "Configuring HSTS | HELMET docs"
description: "The default HSTS is max-age=31536000 with includeSubDomains, sent on HTTPS responses only, and it rolls out in stages."
url: https://helmet.apps.wayne.edu/docs/guides/hsts.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Configuring HSTS [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#configuring-hsts)

This page is for tuning the `Strict-Transport-Security` header: what it sends by default, how to roll it out in stages, and the exact options on each consumer path. When you are done here, move on to [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html) for the rest of the baseline.

## The default [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#the-default)

The default is `max-age=31536000; includeSubDomains` (one year, all subdomains), sent on HTTPS responses only. A request that was not really secure never carries the header, because HELMET decides HTTPS from the trusted proxy list; see [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html).

## Roll it out in stages [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#roll-it-out-in-stages)

Roll it out in stages: start with a short `max-age`, raise it once nothing breaks, and keep `includeSubDomains` only when every subdomain serves HTTPS.

## Raw PHP [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#raw-php)

Set `hsts_max_age` (seconds) and `hsts_include_subdomains` (raw PHP):

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'hsts_max_age' => 600,
    'hsts_include_subdomains' => false,
]);
```

## Middleware and Laravel [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#middleware-and-laravel)

In the PSR-15 middleware, pass them to the `SecurityHeadersMiddleware` constructor: `hstsMaxAge` is the fifth argument, `hstsIncludeSubDomains` the sixth and `hstsPreload` the twelfth; named arguments avoid the counting. In Laravel, use the environment variables below; the config keys are `helmet.hsts`, `helmet.hsts_include_subdomains` and `helmet.hsts_preload`.

| Variable | Config key | Default | What it does |
| --- | --- | --- | --- |
| `HELMET_HSTS_MAX_AGE` | `helmet.hsts` | `31536000` | The HSTS `max-age` in seconds, sent on HTTPS only. It must not be negative. |
| `HELMET_HSTS_INCLUDE_SUBDOMAINS` | `helmet.hsts_include_subdomains` | `true` | Set `false` to omit the HSTS `includeSubDomains` directive. |
| `HELMET_HSTS_PRELOAD` | `helmet.hsts_preload` | `false` | Set `true` to append the `preload` directive to HSTS. Requires `max-age` of at least one year and `includeSubDomains`. Preload is hard to undo: once a browser has preloaded the domain it refuses plain-HTTP connections until the entry is removed. |

## Preload [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#preload)

`preload` is a decision for the domain owner, not for one app, so HELMET never adds it by default. If your domain is in the preload list, set `HELMET_HSTS_PRELOAD=true` (or `hsts_preload` in raw PHP) with a `max-age` of at least one year and `includeSubDomains`; a shorter `max-age` or missing `includeSubDomains` throws.

Warning

Preload is hard to undo: once a browser has preloaded the domain it refuses plain-HTTP connections until the entry is removed.

## Check the header [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#check-the-header)

Ask for a response over HTTPS and look at the header line:

```
curl -sI https://app.example.edu/
```

Expected: `Strict-Transport-Security: max-age=31536000; includeSubDomains` by default, or the value you configured. The same request over plain HTTP carries no HSTS header.

## Related [#](https://helmet.apps.wayne.edu/docs/guides/hsts.html#related)

- [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html)
- [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html)
- [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html)
- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)

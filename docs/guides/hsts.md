---
title: Configuring HSTS
description: The default HSTS is max-age=31536000 with includeSubDomains, sent on HTTPS responses only, and it rolls out in stages.
---

# Configuring HSTS

This page is for tuning the `Strict-Transport-Security` header: what it sends by default, how to roll it out in stages, and the exact options on each consumer path. When you are done here, move on to [The headers](headers.md) for the rest of the baseline.

## The default

The default is `max-age=31536000; includeSubDomains` (one year, all subdomains), sent on HTTPS responses only. A request that was not really secure never carries the header, because HELMET decides HTTPS from the trusted proxy list; see [HTTPS, and why the proxy list matters](https-and-proxies.md).

## Roll it out in stages

Roll it out in stages: start with a short `max-age`, raise it once nothing breaks, and keep `includeSubDomains` only when every subdomain serves HTTPS.

## Raw PHP

Set `hsts_max_age` (seconds) and `hsts_include_subdomains` (raw PHP):

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'hsts_max_age' => 600,
    'hsts_include_subdomains' => false,
]);
```

## Middleware and Laravel

In the PSR-15 middleware, pass the last two constructor arguments of `SecurityHeadersMiddleware`. In Laravel, use the environment variables below; the config keys are `helmet.hsts`, `helmet.hsts_include_subdomains` and `helmet.hsts_preload`.

| Variable | Config key | Default | What it does |
| --- | --- | --- | --- |
| `HELMET_HSTS_MAX_AGE` | `helmet.hsts` | `31536000` | The HSTS `max-age` in seconds, sent on HTTPS only. It must not be negative. |
| `HELMET_HSTS_INCLUDE_SUBDOMAINS` | `helmet.hsts_include_subdomains` | `true` | Set `false` to omit the HSTS `includeSubDomains` directive. |
| `HELMET_HSTS_PRELOAD` | `helmet.hsts_preload` | `false` | Set `true` to append the `preload` directive to HSTS. Requires `max-age` of at least one year and `includeSubDomains`. Preload is hard to undo: once a browser has preloaded the domain it refuses plain-HTTP connections until the entry is removed. |

## Preload

`preload` is a decision for the domain owner, not for one app, so HELMET never adds it by default. If your domain is in the preload list, set `HELMET_HSTS_PRELOAD=true` (or `hsts_preload` in raw PHP) with a `max-age` of at least one year and `includeSubDomains`; a shorter `max-age` or missing `includeSubDomains` throws.

> [!WARNING]
> Preload is hard to undo: once a browser has preloaded the domain it refuses plain-HTTP connections until the entry is removed.

## Check the header

Ask for a response over HTTPS and look at the header line:

```
curl -sI https://app.example.edu/
```

Expected: `Strict-Transport-Security: max-age=31536000; includeSubDomains` by default, or the value you configured. The same request over plain HTTP carries no HSTS header.

## Related

- [The headers](headers.md)
- [Customizing the CSP](csp.md)
- [HTTPS, and why the proxy list matters](https-and-proxies.md)
- [Platform presets](platform-presets.md)
- [Running off Citadel](running-off-citadel.md)
- [Environment variables](../reference/configuration.md)

---
title: PSR-15 middleware
description: The same core, mounted in a middleware pipeline, sends the identical headers.
---

# Use HELMET as PSR-15 middleware

This page mounts the same HELMET core in a PSR-15 middleware pipeline. Copy the pipeline in [Mount the pipeline](#mount-the-pipeline), then decide which middlewares your app needs and how errors should flow.

## Mount the pipeline

The same core, mounted in a middleware pipeline:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Waynestate\Helmet\Csrf\RequestForgeryGuard;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Health\HealthCheck;
use Waynestate\Helmet\Platform\Presets;
use Waynestate\Helmet\Proxy\TrustedProxies;

$factory = new Psr17Factory();
$proxies = new TrustedProxies(Presets::proxies('citadel'));

$middleware = [
    new HealthCheck($factory, $factory, '/up'),
    new RequestForgeryGuard($proxies, $factory, $factory, except: ['/api/webhooks/*']),
    new SecurityHeadersMiddleware($proxies),
];
```

## The middlewares

- `HealthCheck` answers the health path, `/up` in the example.
- `RequestForgeryGuard` is opt-in: mounting it turns the check on for that pipeline. The example passes `except: ['/api/webhooks/*']`. Its options are described in [The request-forgery guard](csrf.md).
- `SecurityHeadersMiddleware` sets the request attribute `helmet.csp_nonce` so a downstream handler can read the same nonce the CSP header carries.

On every request, `SecurityHeadersMiddleware`:

- decides whether the request is HTTPS from the trusted-proxy list,
- builds the headers, including a per-request CSP nonce,
- exposes the nonce to the application as the `helmet.csp_nonce` request attribute,
- removes the `X-Powered-By` header.

The headers are sent even when the inner handler throws. When no-store is enabled and the request path matches (`noStorePaths` is empty for every response) and the response carries no `Cache-Control` header, the middleware adds `Cache-Control: no-store`.

## Errors and rethrow

By default the middleware rethrows, so the framework's error handler still runs and produces the error page, and the middleware attaches the headers only to responses that come back through it; pass `rethrow: false` (and a PSR-17 `ResponseFactory`) to make the middleware itself return a `500` carrying the same headers. The raw PHP path needs no such flag: `helmet_boot()` sends the headers before your code runs, so they are already on the wire by the time an exception can happen.

The `500` response carries `Content-Type: text/plain; charset=utf-8`. If `rethrow` is false and no `ResponseFactory` was passed, the middleware throws a `\RuntimeException` when it needs to build the `500`.

## Put HELMET outermost

Order decides whether an error page gets the headers. A response built by a layer inside HELMET comes back through HELMET and is stamped; a response built by a layer outside HELMET never is. So HELMET has to be the outermost layer. In a Slim-style app, where the middleware added last runs first, that means adding HELMET after the error middleware. If the error middleware sits outside HELMET, the exception passes straight through (HELMET rethrows by default) and the error middleware builds a bare `500` with no security headers. `tests/Headers/SecurityHeadersMiddlewareTest.php` pins both orders with a stand-in error middleware.

## Constructor options

| Option | Default | Meaning |
| --- | --- | --- |
| `proxies` | required | the `TrustedProxies` list used to decide whether the request is HTTPS |
| `cspPolicy` | `null` | merged per directive over the default policy; a directive set to `null` is removed; `null` uses the default policy unchanged |
| `rethrow` | `true` | rethrow when the inner handler throws so the framework's error handler runs; when `false`, return a `500` with the security headers instead |
| `responseFactory` | `null` | builds the `500` response when `rethrow` is false; only needed on that path |
| `hstsMaxAge` | `31536000` | the HSTS max-age in seconds, sent only on HTTPS |
| `hstsIncludeSubDomains` | `true` | whether HSTS covers subdomains, sent only on HTTPS |
| `cspReportUri` | `null` | the CSP report destination, or `null` to send no reporting directives |
| `cspReportOnly` | `false` | when true, the policy is sent as `Content-Security-Policy-Report-Only` instead of `Content-Security-Policy` |
| `coop` | `'same-origin'` | the Cross-Origin-Opener-Policy value, or `null` to send no COOP header |
| `corp` | `null` | the Cross-Origin-Resource-Policy value, or `null` to send no CORP header |
| `permissionsPolicy` | `null` | the Permissions-Policy feature map, or `null` to use the default deny list; an empty array sends no Permissions-Policy header |
| `hstsPreload` | `false` | whether to append the preload directive to HSTS, sent only on HTTPS |
| `trustedTypesEnabled` | `false` | whether to append the Trusted Types directives to the report-only CSP header |
| `trustedTypesPolicies` | `['default']` | the Trusted Types policy names, used when `trustedTypesEnabled` is true |
| `noStoreEnabled` | `false` | whether to send `Cache-Control: no-store` on matching responses |
| `noStorePaths` | `[]` | path prefixes that carry no-store, or empty for every response |

> [!NOTE]
> The cross-origin isolation settings are validated at construction, not per request: a bad `coop`, `corp`, `permissionsPolicy` or `trustedTypesPolicies` value is a config error, and it fails where the app starts, not on the first request, with an `\InvalidArgumentException`.

## Related

- [Quick start](quick-start.md)
- [Raw PHP](usage-raw-php.md)
- [Laravel](usage-laravel.md)
- [The request-forgery guard](csrf.md)
- [The headers](headers.md)

---
title: "The request-forgery guard | HELMET docs"
description: "SameSite=Lax is a layer, not a wall: the optional request-forgery guard checks state-changing requests before they reach the app."
url: https://helmet.apps.wayne.edu/docs/guides/csrf.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The request-forgery guard[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#the-request-forgery-guard)

This page is for the opt-in request-forgery (CSRF) guard: the rule HELMET applies to state-changing requests before they reach the app, and how to turn it on in raw PHP, PSR-15 and Laravel. Read it before you enable the guard, then wire it up in the section that matches your stack.

`SameSite=Lax` is a layer, not a wall. The guard is the second layer: it checks the request, and if the check fails the request never reaches your code.

## The goal[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#the-goal)

Turn the guard on and let the same rule run on every consumer path. The guard is off by default; when it is on, it is the same rule on every consumer path, because the raw PHP path and the Laravel adapter both call the one `allows()` decision in `Waynestate\Helmet\Csrf\RequestForgeryGuard`.

## The rule[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#the-rule)

For `GET`, `HEAD` and `OPTIONS` the request passes. Those are the only methods in `SAFE_METHODS`. For anything else:

1. `Sec-Fetch-Site` is the primary signal when the browser sends it. `same-origin` and `none` pass, `same-site` passes only when `allow_same_site` is true, and `cross-site` or an unknown value is rejected.
2. When `Sec-Fetch-Site` is absent, the `Origin` header is checked against the request's own origin and `trusted_origins`. `Origin: null` is rejected, an unparseable origin is rejected, and an absent origin passes, because a non-browser client authenticates some other way.
3. A path in `except` skips the guard. A pattern is an exact path, a prefix ending in `*`, or `*` for every path.

The request's own origin comes from `HTTP_HOST` plus the same trusted-proxy HTTPS decision the security headers use, so a spoofed `X-Forwarded-Proto` from an untrusted peer cannot change the origin. Origins are compared in canonical form: lowercase `scheme://host:port`, with the default port dropped (`443` for https, `80` for http), and user info, a query, a fragment, or a path all rejected, so a URL with a path cannot silently become an origin.

## The rejection answer[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#the-rejection-answer)

A rejected request receives `403`, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and the body `Forbidden`. The answer never echoes request headers.

When a logger is set, a refused request is also logged as the `helmet.request_refused` security event. Its context carries `event` (`request_refused`), `method` (upper case), `path` (no query string), `sec_fetch_site` (`same-origin`, `same-site`, `cross-site`, `none`, `other`, or null when the header is absent), and `origin_present` (bool). It never carries the Origin value, cookies, Authorization, or any other header value, and a logger that throws never changes the `403` answer.

## Raw PHP[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#raw-php)

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'csrf' => [
        'enabled' => true,
        'allow_same_site' => false,
        'trusted_origins' => ['https://forms.example.edu'],
        'except' => ['/api/webhooks/*'],
    ],
]);
```

`helmet_boot()` runs the check after the health path and before the security headers. A rejected request stops there.

## PSR-15[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#psr-15)

```php
use Waynestate\Helmet\Csrf\RequestForgeryGuard;

$guard = new RequestForgeryGuard(
    $proxies,
    $factory,
    $factory,
    false,
    ['https://forms.example.edu'],
    ['/api/webhooks/*'],
);
```

The guard needs the PSR-17 response and stream factories only when it rejects a request. A PSR-15 user passes a `LoggerInterface` as the last constructor argument `$logger` to log refusals.

Note

The constructor validates its configuration. A trusted origin that is not a valid `http` or `https` origin, or an `except` pattern that is not a non-empty string, throws `InvalidArgumentException` at construction, so a typo fails loudly instead of letting a forged request through.

## Laravel[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#laravel)

Set the config or the environment:

```
HELMET_CSRF_ENABLED=true
HELMET_CSRF_TRUSTED_ORIGINS=https://forms.example.edu
HELMET_CSRF_EXCEPT=/api/webhooks/*
```

The provider registers the `RequestForgeryGuard` middleware in the `web` group only when `helmet.csrf.enabled` is true. It calls the same core rule, so a Laravel app and a raw PHP app with the same settings make the same decision.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/csrf.html#related)

- [The session](https://helmet.apps.wayne.edu/docs/guides/session.html)
- [Metrics](https://helmet.apps.wayne.edu/docs/guides/metrics.html)

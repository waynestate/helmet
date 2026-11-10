---
title: Install
description: Add waynestate/helmet with Composer, then verify the headers are on the wire.
---

# Install

## Requirements

PHP `^8.3`. `composer.json` declares no `ext-*` extensions, so plain PHP 8.3 or newer is enough; Composer pulls the
rest (Monolog, the PSR interfaces, csp-builder, the Prometheus client and symfony/http-foundation).

## Install

```
composer require waynestate/helmet
```

Composer picks the latest release, 1.3.1, and writes `^1.3` into `composer.json`. Coming from 0.4? Read
[Upgrading](upgrading.md) first: an unknown quoted CSP source now throws at boot.

The same line works for a raw PHP app, a Laravel app, or a PSR-15 stack: HELMET ships one package for every platform,
so the adapters come with it, nothing extra to require.

> [!NOTE]
> The example everywhere in these docs uses `'preset' => 'citadel'`, which trusts the Docker bridge gateway in front
> of HA-Proxy. Off the WSU platform, skip the preset and pass your own list of the addresses your front proxy
> connects from.

## Verify

Boot the package as the [Quick start](guides/quick-start.md) does, then ask the load balancer's question:

```
curl -sI http://localhost/up
```

Expected: `200 ok`, the baseline headers on the response (`Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, and the rest), and no `X-Powered-By` header. If `/up` answers before your code runs,
HELMET is on the field.

## Next steps

- [Quick start](guides/quick-start.md): the whole thing in four steps.
- Usage by stack: [Raw PHP](guides/usage-raw-php.md), [PSR-15 middleware](guides/usage-psr15.md), [Laravel](guides/usage-laravel.md).
- What goes on the wire: [The headers](guides/headers.md).
- Every knob: [Environment variables](reference/configuration.md).

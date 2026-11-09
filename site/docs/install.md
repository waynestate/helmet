---
title: "Install | HELMET docs"
description: "Add waynestate/helmet with Composer, then verify the headers are on the wire."
url: https://helmet.apps.wayne.edu/docs/install.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install[#](https://helmet.apps.wayne.edu/docs/install.html#install)

## Requirements[#](https://helmet.apps.wayne.edu/docs/install.html#requirements)

PHP `^8.3`. `composer.json` declares no `ext-*` extensions, so plain PHP 8.3 or newer is enough; Composer pulls the rest (Monolog, the PSR interfaces, csp-builder, the Prometheus client and symfony/http-foundation).

## Install[#](https://helmet.apps.wayne.edu/docs/install.html#install-1)

```
composer require waynestate/helmet
```

Composer picks the latest release, 1.3.0, and writes `^1.0` into `composer.json`. Coming from 0.4? Read [Upgrading](https://helmet.apps.wayne.edu/docs/upgrading.html) first: an unknown quoted CSP source now throws at boot.

The same line works for a raw PHP app, a Laravel app, or a PSR-15 stack: HELMET ships one package for every platform, so the adapters come with it, nothing extra to require.

Note

The example everywhere in these docs uses `'preset' => 'citadel'`, which trusts the Docker bridge gateway in front of HA-Proxy. Off the WSU platform, skip the preset and pass your own list of the addresses your front proxy connects from.

## Verify[#](https://helmet.apps.wayne.edu/docs/install.html#verify)

Boot the package as the [Quick start](https://helmet.apps.wayne.edu/docs/guides/quick-start.html) does, then ask the load balancer's question:

```
curl -sI http://localhost/up
```

Expected: `200 ok`, the baseline headers on the response (`Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and the rest), and no `X-Powered-By` header. If `/up` answers before your code runs, HELMET is on the field.

## Next steps[#](https://helmet.apps.wayne.edu/docs/install.html#next-steps)

- [Quick start](https://helmet.apps.wayne.edu/docs/guides/quick-start.html): the whole thing in four steps.
- Usage by stack: [Raw PHP](https://helmet.apps.wayne.edu/docs/guides/usage-raw-php.html), [PSR-15 middleware](https://helmet.apps.wayne.edu/docs/guides/usage-psr15.html), [Laravel](https://helmet.apps.wayne.edu/docs/guides/usage-laravel.html).
- What goes on the wire: [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html).
- Every knob: [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html).

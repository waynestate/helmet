---
title: Quick start
description: A raw PHP app behind the HELMET baseline in four steps: require, boot, verify, nonce.
---

# Quick start

This is the raw PHP path, the shortest one. Laravel and PSR-15 mount the same core as middleware; see the
[Next steps](#next-steps) section at the bottom.

1. **Add the package.**

   ```
   composer require waynestate/helmet
   ```

   Expected: Composer adds `waynestate/helmet` to `composer.json` and installs it with its dependencies.

2. **Boot it once**, at the top of the front controller, before anything is echoed:

   ```php
   use function Waynestate\Helmet\helmet_boot;

   $helmet = helmet_boot([
       'preset' => 'citadel',
   ]);
   ```

   Expected: `helmet_boot()` sends the headers, strips `X-Powered-By`, and answers `/up` with `200 ok` before your
   code runs. It returns the nonce, the logger and the request ID.

3. **Ask the load balancer's question.**

   ```
   curl -sI http://localhost/up
   ```

   Expected: `200 ok`, with the baseline headers on the response and no `X-Powered-By`.

4. **Put the nonce in your scripts.** `$helmet['nonce']` is the per-request CSP nonce: print the meta tag in `<head>`
   and put the nonce in your `<script>` tags.

   ```php
   echo helmet_csp_nonce_meta($helmet['nonce']);
   ```

   Expected: inline scripts tagged with the nonce pass the CSP, because `script-src` carries the nonce plus
   `strict-dynamic`.

## What you built

A raw PHP front controller where every response carries the baseline security headers, `X-Powered-By` is stripped,
`/up` answers the load balancer before your code runs, and inline scripts carry the per-request CSP nonce. One call,
and the floor is set.

## Next steps

- Go deeper by stack: [Raw PHP](usage-raw-php.md), [PSR-15 middleware](usage-psr15.md), [Laravel](usage-laravel.md).
- What goes on the wire: [The headers](headers.md).
- Platform presets and proxies: [Platform presets](platform-presets.md), [HTTPS, and why the proxy list matters](https-and-proxies.md).
- Every knob: [Environment variables](../reference/configuration.md).

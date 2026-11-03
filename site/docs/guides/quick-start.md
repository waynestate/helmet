---
title: "Quick start | HELMET docs"
description: "A raw PHP app behind the HELMET baseline in four steps: require, boot, verify, nonce."
url: https://helmet.apps.wayne.edu/docs/guides/quick-start.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://helmet.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

This is the raw PHP path, the shortest one. Laravel and PSR-15 mount the same core as middleware; see the [Next steps](https://helmet.apps.wayne.edu/docs/guides/quick-start.html#next-steps) section at the bottom.

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
   Expected: `helmet_boot()` sends the headers, strips `X-Powered-By`, and answers `/up` with `200 ok` before your code runs. It returns the nonce, the logger and the request ID.
3. **Ask the load balancer's question.**
   ```
   curl -sI http://localhost/up
   ```
   Expected: `200 ok`, with the baseline headers on the response and no `X-Powered-By`.
4. **Put the nonce in your scripts.** `$helmet['nonce']` is the per-request CSP nonce: print the meta tag in `<head>` and put the nonce in your `<script>` tags.
   ```php
   echo helmet_csp_nonce_meta($helmet['nonce']);
   ```
   Expected: inline scripts tagged with the nonce pass the CSP, because `script-src` carries the nonce plus `strict-dynamic`.

## What you built[#](https://helmet.apps.wayne.edu/docs/guides/quick-start.html#what-you-built)

A raw PHP front controller where every response carries the baseline security headers, `X-Powered-By` is stripped, `/up` answers the load balancer before your code runs, and inline scripts carry the per-request CSP nonce. One call, and the floor is set.

## Next steps[#](https://helmet.apps.wayne.edu/docs/guides/quick-start.html#next-steps)

- Go deeper by stack: [Raw PHP](https://helmet.apps.wayne.edu/docs/guides/usage-raw-php.html), [PSR-15 middleware](https://helmet.apps.wayne.edu/docs/guides/usage-psr15.html), [Laravel](https://helmet.apps.wayne.edu/docs/guides/usage-laravel.html).
- What goes on the wire: [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html).
- Platform presets and proxies: [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html), [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html).
- Every knob: [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html).

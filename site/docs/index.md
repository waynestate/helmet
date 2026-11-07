---
title: "Overview | HELMET docs"
description: "HELMET is the Wayne State app stack's baseline runtime: security headers, a hardened session, an honest proxy check, /up and structured logs."
url: https://helmet.apps.wayne.edu/docs/
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

[![HELMET's front page: the headline 'HELMET. Put it on before you ship.' and the composer require command beside a chalkboard play where five rushers, XSS, clickjack, fake HTTPS, fixation and false down, are stopped at the line by CSP, frames, proxies, session and /up, in front of the HELMET mark marked 'your app'. Sacks allowed: 0.](https://helmet.apps.wayne.edu/docs/readme/hero.jpg)](https://helmet.apps.wayne.edu/index.html)

# HELMET[#](https://helmet.apps.wayne.edu/docs/#helmet)

[Three members of the band Helmet, two guitarists and a drummer at a Tama kit, tinted blue against a hazy orange and gold backdrop](https://media.tenor.com/WmJKjN9NQqoAAAAM/helmet-band.gif) GIF via [Tenor](https://tenor.com/view/helmet-band-wilmas-rainbow-helmet-band-music-gif-25658120)

![php ^8.3](https://helmet.apps.wayne.edu/docs/readme/badges/php.svg) ![license MIT](https://helmet.apps.wayne.edu/docs/readme/badges/license.svg) ![psr-15: middleware](https://helmet.apps.wayne.edu/docs/readme/badges/psr15.svg) [GitHub CI](https://github.com/waynestate/helmet/actions/workflows/ci.yml)

**Put it on before you ship.** Security headers, a hardened session and an honest proxy check, snapped on before the first page is served.

HELMET is the Wayne State app stack's baseline runtime: one small framework-free PHP core that sends the security headers, hardens the session, decides whether the request really arrived over HTTPS, answers the load balancer's `/up` ping, and writes structured logs.

## Pick your path[#](https://helmet.apps.wayne.edu/docs/#pick-your-path)

- **New here?** The [Quick start](https://helmet.apps.wayne.edu/docs/guides/quick-start.html) puts a raw PHP app behind the baseline in four steps.
- **Installing it now?** [Install](https://helmet.apps.wayne.edu/docs/install.html) has the requirements, the Composer line and the verify step.
- **Looking for a setting or a function?** The Reference group starts with [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html), every `HELMET_` variable the package reads.

## Install[#](https://helmet.apps.wayne.edu/docs/#install)

```
composer require waynestate/helmet
```

Then boot it once at the top of the front controller, before anything is echoed:

```php
use function Waynestate\Helmet\helmet_boot;

$helmet = helmet_boot([
    'preset' => 'citadel',
]);
```

`helmet_boot()` sends the headers, strips `X-Powered-By`, and answers `/up` with `200 ok` before your code runs. It returns the nonce, the logger and the request ID so the rest of the app can use them.

Version 0.3.1, MIT licensed.

Agents: the machine-readable map of these docs lives at [https://helmet.apps.wayne.edu/llms.txt](https://helmet.apps.wayne.edu/llms.txt).

Milquetoast is a Helmet song. It is not a Content-Security-Policy.

Every `HELMET_` environment variable the package reads, with its meaning, default and the file that reads it, is in [the configuration reference](https://helmet.apps.wayne.edu/docs/reference/configuration.html).

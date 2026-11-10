---
title: Overview
description: HELMET is the Wayne State app stack's baseline runtime: security headers, a hardened session, an honest proxy check, /up and structured logs.
---

<div align="center">
  <a href="../index.html"><img src="readme/hero.jpg" width="100%" alt="HELMET's front page: the headline 'HELMET. Put it on before you ship.' and the composer require command beside a chalkboard play where five rushers, XSS, clickjack, fake HTTPS, fixation and false down, are stopped at the line by CSP, frames, proxies, session and /up, in front of the HELMET mark marked 'your app'. Sacks allowed: 0."></a>
</div>

# HELMET

![Three members of the band Helmet, two guitarists and a drummer at a Tama kit, tinted blue against a hazy orange and gold backdrop](https://media.tenor.com/WmJKjN9NQqoAAAAM/helmet-band.gif)
<sub>GIF via [Tenor](https://tenor.com/view/helmet-band-wilmas-rainbow-helmet-band-music-gif-25658120)</sub>

<p>
  <img src="readme/badges/php.svg" alt="php ^8.3">
  <img src="readme/badges/license.svg" alt="license MIT">
  <img src="readme/badges/psr15.svg" alt="psr-15: middleware">
  <a href="https://github.com/waynestate/helmet/actions/workflows/ci.yml"><img src="https://github.com/waynestate/helmet/actions/workflows/ci.yml/badge.svg" alt="GitHub CI"></a>
</p>

**Put it on before you ship.** Security headers, a hardened session and an honest proxy check, snapped on before
the first page is served.

HELMET is the Wayne State app stack's baseline runtime: one small framework-free PHP core that sends the security
headers, hardens the session, decides whether the request really arrived over HTTPS, answers the load balancer's
`/up` ping, and writes structured logs.

## Pick your path

- **New here?** The [Quick start](guides/quick-start.md) puts a raw PHP app behind the baseline in four steps.
- **Installing it now?** [Install](install.md) has the requirements, the Composer line and the verify step.
- **Working with a coding agent?** [Your coding agent (ADVISOR)](guides/advisor.md) gives it two read-only tools:
  the headers HELMET sends and why, and how long the app's PHP floor stays supported.
- **Looking for a setting or a function?** The Reference group starts with [Environment variables](reference/configuration.md),
  every `HELMET_` variable the package reads.

## Install

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

`helmet_boot()` sends the headers, strips `X-Powered-By`, and answers `/up` with `200 ok` before your code runs.
It returns the nonce, the logger and the request ID so the rest of the app can use them.

Version 1.3.1, MIT licensed.

Agents: the machine-readable map of these docs lives at [https://helmet.apps.wayne.edu/llms.txt](https://helmet.apps.wayne.edu/llms.txt).

Milquetoast is a Helmet song. It is not a Content-Security-Policy.

Every `HELMET_` environment variable the package reads, with its meaning, default and the file that reads it, is in [the configuration reference](reference/configuration.md).

---
title: Cross-origin isolation and feature policy
description: Four more headers round out the baseline: Cross-Origin-Opener-Policy and Permissions-Policy are on by default, Cross-Origin-Resource-Policy and Cross-Origin-Embedder-Policy are opt-in.
---

# Cross-origin isolation and feature policy

This page covers the four headers that round out the baseline beyond the CSP: `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `Cross-Origin-Embedder-Policy` and `Permissions-Policy`. Two are on by default, two are opt-in. When you are done here, see [The headers](headers.md) for the full set.

## Cross-Origin-Opener-Policy

`Cross-Origin-Opener-Policy` defaults to `same-origin`, which puts the page in its own browsing-context group so an opener or parent page cannot read it. Set `coop` (raw PHP) or `HELMET_COOP` (Laravel) to `same-origin-allow-popups` or `noopener-allow-popups` to keep the isolation but let a popup window (such as an OAuth login popup to the IdP) stay cross-origin, or to `unsafe-none` to drop the isolation while still sending the header with that value. Set it to `null` to send no COOP header at all.

## Cross-Origin-Resource-Policy

`Cross-Origin-Resource-Policy` is opt-in: HELMET sends no CORP header by default, because a blanket `same-site` can break an app whose assets are read cross-origin. Set `corp` (raw PHP) or `HELMET_CORP` (Laravel) to `same-site`, `same-origin` or `cross-origin` to send it.

## Cross-Origin-Embedder-Policy

`Cross-Origin-Embedder-Policy` is opt-in, and the strictest of the four. CORP is the resource saying who may load it; COEP is the page saying what it is willing to load. With `require-corp` the browser blocks every cross-origin resource that does not opt in with CORP or CORS, so a WayneUI font, a CDN image or an embedded video from another origin can stop loading. `credentialless` loads those resources without cookies instead of blocking them. Together with COOP `same-origin`, either value makes the page cross-origin isolated, which a few browser features (such as `SharedArrayBuffer`) require.

HELMET sends no COEP header by default. Set `coep` (raw PHP) or `HELMET_COEP` (Laravel) to `require-corp`, `credentialless` or `unsafe-none` to send it, and load every page of the app in a browser first: blocked resources show up in the console as `ERR_BLOCKED_BY_RESPONSE`.

## Permissions-Policy

`Permissions-Policy` defaults to a deny list: `camera`, `geolocation`, `microphone`, `payment` and `usb` are all denied, so an injected script cannot use a feature the page has switched off. Set `permissions_policy` (raw PHP) or `HELMET_PERMISSIONS_POLICY` (Laravel) to a feature map to change it: each feature maps to an allowlist of `self` and https origins, and an empty allowlist denies the feature. An empty map sends no Permissions-Policy header.

## Example

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'coop' => 'same-origin-allow-popups',
    'corp' => 'same-site',
    'coep' => 'credentialless',
    'permissions_policy' => [
        'camera' => ['self'],
        'geolocation' => [],
    ],
]);
```

## Settings

| Setting | Raw PHP (`helmet_boot()`) | Laravel |
| --- | --- | --- |
| COOP | `coop` | `HELMET_COOP` / `helmet.coop`, default `same-origin`, `null` sends no header |
| CORP | `corp` | `HELMET_CORP` / `helmet.corp`, default `null` (no header) |
| COEP | `coep` | `HELMET_COEP` / `helmet.coep`, default `null` (no header) |
| Permissions-Policy | `permissions_policy` | `HELMET_PERMISSIONS_POLICY` / `helmet.permissions_policy`, default deny list, `[]` sends no header |

## Validation

An invalid COOP, CORP or COEP value, or a Permissions-Policy feature name or allowlist entry that is not `self` or an https origin, throws, so a typo fails loudly instead of sending a header the browser will ignore.

## Related

- [The headers](headers.md)
- [Customizing the CSP](csp.md)
- [Configuring HSTS](hsts.md)
- [Raw PHP](usage-raw-php.md)
- [Laravel](usage-laravel.md)
- [Environment variables](../reference/configuration.md)

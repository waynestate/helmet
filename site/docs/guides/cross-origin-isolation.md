---
title: "Cross-origin isolation and feature policy | HELMET docs"
description: "Three more headers round out the baseline: Cross-Origin-Opener-Policy and Permissions-Policy are on by default, Cross-Origin-Resource-Policy is opt-in."
url: https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Cross-origin isolation and feature policy[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#cross-origin-isolation-and-feature-policy)

This page covers the three headers that round out the baseline beyond the CSP: `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` and `Permissions-Policy`. Two are on by default, one is opt-in. When you are done here, see [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html) for the full set.

## Cross-Origin-Opener-Policy[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#cross-origin-opener-policy)

`Cross-Origin-Opener-Policy` defaults to `same-origin`, which puts the page in its own browsing-context group so an opener or parent page cannot read it. Set `coop` (raw PHP) or `HELMET_COOP` (Laravel) to `same-origin-allow-popups` or `noopener-allow-popups` to keep the isolation but let a popup window (such as an OAuth login popup to the IdP) stay cross-origin, or to `unsafe-none` to drop the isolation while still sending the header with that value. Set it to `null` to send no COOP header at all.

## Cross-Origin-Resource-Policy[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#cross-origin-resource-policy)

`Cross-Origin-Resource-Policy` is opt-in: HELMET sends no CORP header by default, because a blanket `same-site` can break an app whose assets are read cross-origin. Set `corp` (raw PHP) or `HELMET_CORP` (Laravel) to `same-site`, `same-origin` or `cross-origin` to send it.

## Permissions-Policy[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#permissions-policy)

`Permissions-Policy` defaults to a deny list: `camera`, `geolocation`, `microphone`, `payment` and `usb` are all denied, so an injected script cannot use a feature the page has switched off. Set `permissions_policy` (raw PHP) or `HELMET_PERMISSIONS_POLICY` (Laravel) to a feature map to change it: each feature maps to an allowlist of `self` and https origins, and an empty allowlist denies the feature. An empty map sends no Permissions-Policy header.

## Example[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#example)

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'coop' => 'same-origin-allow-popups',
    'corp' => 'same-site',
    'permissions_policy' => [
        'camera' => ['self'],
        'geolocation' => [],
    ],
]);
```

## Settings[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#settings)

| Setting | Raw PHP (`helmet_boot()`) | Laravel |
| --- | --- | --- |
| COOP | `coop` | `HELMET_COOP` / `helmet.coop`, default `same-origin`, `null` sends no header |
| CORP | `corp` | `HELMET_CORP` / `helmet.corp`, default `null` (no header) |
| Permissions-Policy | `permissions_policy` | `HELMET_PERMISSIONS_POLICY` / `helmet.permissions_policy`, default deny list, `[]` sends no header |

## Validation[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#validation)

An invalid COOP or CORP value, or a Permissions-Policy feature name or allowlist entry that is not `self` or an https origin, throws, so a typo fails loudly instead of sending a header the browser will ignore.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/cross-origin-isolation.html#related)

- [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html)
- [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html)
- [Configuring HSTS](https://helmet.apps.wayne.edu/docs/guides/hsts.html)
- [Raw PHP](https://helmet.apps.wayne.edu/docs/guides/usage-raw-php.html)
- [Laravel](https://helmet.apps.wayne.edu/docs/guides/usage-laravel.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)

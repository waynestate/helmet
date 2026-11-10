---
title: The headers
description: Every response gets the same set of security headers, built by one shared function.
---

# The headers

Every response gets the same set, built by one shared function. This page shows the set, the default Content-Security-Policy exactly as the code defines it, and the rule behind each header. To change the policy itself, see [Customizing the CSP](csp.md).

![A chalkboard play of HELMET's default headers. Seven O's on the line of scrimmage each run a route to one header: Content-Security-Policy (default-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'self', plus 7 more directives, a fresh nonce and 'strict-dynamic'), X-Content-Type-Options nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy camera=(), geolocation=(), microphone=(), payment=(), usb=(), Cross-Origin-Opener-Policy same-origin, and Strict-Transport-Security max-age=31536000; includeSubDomains on HTTPS responses only. Cross-Origin-Resource-Policy is on the bench, opt-in. The HELMET mark sits in the pocket.](../readme/play.svg)

The play is read from `src/Headers/SecurityHeaders.php`: seven routes, one per default header, drawn in the order HELMET sets them. Cross-Origin-Resource-Policy waits on the bench until you opt in. With reduced motion on, you get the finished play.

## The set

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | the WayneUI default policy, plus a per-request nonce and `strict-dynamic` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), geolocation=(), microphone=(), payment=(), usb=()` by default, configurable |
| `Cross-Origin-Opener-Policy` | `same-origin` by default, configurable, `null` sends no header |
| `Cross-Origin-Resource-Policy` | not sent by default, opt-in |
| `Cross-Origin-Embedder-Policy` | not sent by default, opt-in |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` by default, HTTPS only, configurable |

The default CSP is the union of the WayneUI recipes, with `frame-ancestors 'self'`, `base-uri 'self'`, `object-src 'none'` and `form-action 'self'` added so an app can never be framed by a stranger, have its base tag hijacked, load plugins, or post its forms to an attacker. `script-src` carries a per-request nonce plus `strict-dynamic`, so a script loaded with the nonce can load other scripts without opening the door to `'unsafe-inline'`.

Milquetoast is a Helmet song. It is not a Content-Security-Policy.

## The default policy, exactly

`SecurityHeaders::defaultCspPolicy()` returns the full default, directive by directive:

| Directive | Sources |
| --- | --- |
| `default-src` | `'self'` |
| `style-src` | `'self'`, `https://wayneui.apps.wayne.edu` |
| `style-src-attr` | `'unsafe-inline'` |
| `style-src-elem` | `'self'`, `https://wayneui.apps.wayne.edu` |
| `script-src` | `'self'`, `https://wayneui.apps.wayne.edu` |
| `font-src` | `'self'`, `data:`, `https://wayneui.apps.wayne.edu` |
| `img-src` | `'self'`, `data:`, `https://wayneui.apps.wayne.edu` |
| `connect-src` | `'self'`, `https://wayneui.apps.wayne.edu` |
| `base-uri` | `'self'` |
| `object-src` | `'none'` |
| `form-action` | `'self'` |
| `frame-ancestors` | `'self'` |

Where each piece comes from:

- `default-src`, `style-src`, `style-src-attr`, `script-src`, `font-src`, `img-src` and `connect-src` come from the raw PHP recipe.
- `style-src-elem` comes from the Laravel/Blade recipe. It does not carry `'unsafe-inline'`; only `style-src-attr` does, and only for the inline `style="..."` attributes the recipes rely on (block spacing). `style-src-attr` governs only the style attribute, not `<style>` elements or stylesheets, so this is the narrowest allowance that keeps those recipes working. An app that does not need it can report on it or block it with one setting: see [Inline style attributes](csp.md#inline-style-attributes).
- `frame-ancestors 'self'` covers clickjacking at the CSP layer.
- `base-uri 'self'`, `object-src 'none'` and `form-action 'self'` close the base-tag, plugin and form-targeting gaps that `default-src` alone does not cover in every browser.
- `data:` appears on `font-src` and `img-src`: Vite and Vue inline small fonts as `data:` URIs, and the nonce does not cover font sources.

## The nonce

`SecurityHeaders::generateNonce()` makes a new nonce for every request: 18 random bytes, base64 encoded. When the header is built, the nonce is bound to `script-src`, and to `style-src` and `style-src-elem` when those directives are present in the merged policy. An app may remove a directive with `null`, so the nonce only goes on the directives that are there. Vite and Vue inject `<style>` and `<link>` tags that carry the nonce, which is why the style directives need it too.

`strict-dynamic` rides along on `script-src`: it lets a script that was loaded with the nonce load other scripts dynamically, without opening `script-src` to `'unsafe-inline'`.

## Permissions-Policy

The default denies the browser features a typical app never uses: `camera`, `geolocation`, `microphone`, `payment` and `usb`. An injected script cannot use a feature the page has disabled, so the deny list targets the features most useful to an attacker and least useful to a form-filling app. `interest-cohort` is not in it: the browser vendors dropped it, so denying it buys nothing.

The header renders as `feature=(allowlist)`: an empty list denies the feature, `self` allows the page's own origin unquoted, and an https origin is quoted. Passing `null` (the default) uses the deny list above; an empty map sends no `Permissions-Policy` header at all.

## Cross-Origin-Opener-Policy, Cross-Origin-Resource-Policy and Cross-Origin-Embedder-Policy

COOP puts the page in its own browsing context group, so a cross-origin page cannot reach it through `window.opener` or the cross-origin Web APIs. The default is `same-origin`: only same-origin pages may open it. The accepted values are `unsafe-none`, `same-origin-allow-popups`, `same-origin` and `noopener-allow-popups`; anything else throws. Set COOP to `null` to send no header.

CORP declares who may load this page's resources cross-origin. It is the resource-side half of the cross-origin isolation pair with COOP, and its accepted values are `same-site`, `same-origin` and `cross-origin`. CORP is off by default: pass a value to send it.

COEP is the page-side half: it says which cross-origin resources the page is willing to load. `require-corp` blocks every one that does not opt in with CORP or CORS, `credentialless` loads them without cookies, and `unsafe-none` is the browser's default. COEP is off by default, because `require-corp` can break third-party fonts, images and embeds; pass a value to send it. See [Cross-origin isolation and feature policy](cross-origin-isolation.md) for the three working together.

## Strict-Transport-Security

The default is `max-age=31536000; includeSubDomains` (one year, all subdomains), sent on HTTPS responses only. Roll it out in stages: start with a short `max-age`, raise it once nothing breaks, and keep `includeSubDomains` only when every subdomain serves HTTPS.

`preload` is a decision for the domain owner, not for one app, so HELMET never adds it by default. It is opt-in, and it is only appended when the `max-age` is at least a year and `includeSubDomains` is on, because a browser refuses a preload HSTS header that does not meet both. A negative `max-age` throws. The knobs and the staged rollout are on [Configuring HSTS](hsts.md).

## One shared function

`SecurityHeaders` is the single place that defines HELMET's security headers and the default Content-Security-Policy. Both the PSR-15 middleware and the framework-free `helmet_boot()` path build their headers from this class, so the two consumer paths always agree. That is what lets the [Laravel](usage-laravel.md) and [Raw PHP](usage-raw-php.md) pages promise identical behavior: they call the same `build()`.

## Related

- [Customizing the CSP](csp.md)
- [Configuring HSTS](hsts.md)
- [Cross-origin isolation and feature policy](cross-origin-isolation.md)
- [Laravel](usage-laravel.md)
- [Raw PHP](usage-raw-php.md)
- [PSR-15 middleware](usage-psr15.md)
- [API](../reference/api.md)
- [Troubleshooting](../troubleshooting.md)

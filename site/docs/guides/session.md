---
title: "The session | HELMET docs"
description: "SessionHardening::apply() sets the session ini values, including a __Host- prefixed cookie name on HTTPS, before the session starts."
url: https://helmet.apps.wayne.edu/docs/guides/session.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The session[#](https://helmet.apps.wayne.edu/docs/guides/session.html#the-session)

This page covers the session hardening HELMET applies before the first `session_start()`: the `ini` values it sets, the cookie name rule, and how Secure mode works. Read it before you deploy a raw PHP app, then move on to [The request-forgery guard](https://helmet.apps.wayne.edu/docs/guides/csrf.html) for the next layer.

A session is the one thing you want nobody to forge, steal, or pin down early. HELMET tightens the screws before the first `session_start()`, so the cookie is as hard to misuse as the headers are to spoof.

## The goal[#](https://helmet.apps.wayne.edu/docs/guides/session.html#the-goal)

Call `SessionHardening::apply()` once, before the session starts, and let it set the session `ini` values that the platform's shared `php.ini` leaves at insecure defaults. It refuses to run once a session is active, because changing them mid-session does nothing. In raw PHP the call happens inside `helmet_boot()`; in Laravel the adapter does not apply it, so the framework's own session config applies instead.

## The settings[#](https://helmet.apps.wayne.edu/docs/guides/session.html#the-settings)

`SessionHardening::apply()` sets these `ini` values before the session starts:

| Setting | Value | Why |
| --- | --- | --- |
| `session.use_strict_mode` | `1` | Rejects session IDs the server did not issue, which blocks fixation. |
| `session.use_only_cookies` | `1` | The session ID travels only in the cookie, never in a URL. |
| `session.cookie_httponly` | `1` | JavaScript cannot read the session cookie. |
| `session.cookie_samesite` | `Lax` | Cuts cross-site session reuse. |
| `session.cookie_secure` | `1` on HTTPS, or always in `always` mode, unset otherwise | The cookie is sent only over TLS, and plain-HTTP setups keep working. |
| `session.cookie_lifetime` | `0` | A browser-session cookie: it ends when the browser closes. |
| `session.cache_limiter` | `nocache` | Pages cannot be cached and replayed with the session attached. |
| `session.cookie_path` | `/` | The usual scope, and required by the `__Host-` prefix. |
| `session.cookie_domain` | empty | Host-only: the cookie is not sent to sibling subdomains. |
| `session.name` | `__Host-WSUSESSID` on HTTPS, `WSUSESSID` on HTTP | A generic name reveals less, and the `__Host-` prefix makes the browser reject the cookie unless it is `Secure`, `Path=/` and has no `Domain`. |

Note

`session.sid_length` is deliberately not touched. It is deprecated as of PHP 8.4 and the platform image sets it to 26, so changing it here would fight the platform. That change is left to the Upgrade project.

## The cookie name rule[#](https://helmet.apps.wayne.edu/docs/guides/session.html#the-cookie-name-rule)

The name rule applies only while the name is still PHP's default `PHPSESSID`. An app that chose its own name keeps it, and SESAME reads whatever `session_name()` returns, so a changed name is safe for it. When an app first runs this version, existing sessions under the old name end: the next request still carries the old cookie, the app finds no session under the new name and starts a fresh one.

Warning

Deploying this version ends every session that still uses the default `PHPSESSID` name. Signed-in users sign in once more after the deploy.

## Secure mode[#](https://helmet.apps.wayne.edu/docs/guides/session.html#secure-mode)

By default (`auto`) the Secure flag follows HELMET's HTTPS decision, the same one the headers use. On Citadel, HA-Proxy ends TLS before the request reaches PHP; with the `citadel` preset HELMET trusts the proxy's `X-Forwarded-Proto`, so `auto` still sets `Secure` on HTTPS pages. Where TLS ends in front of an app that trusts no proxy, the request looks plain; force the flag with `'session' => ['secure' => 'always']` to `helmet_boot()`. `helmet_boot()` reads no environment, so `HELMET_SESSION_SECURE` only fills `helmet.session.secure` in the Laravel config, which the adapter does not read; a Laravel app that boots through `helmet_boot()` can hand it `['secure' => config('helmet.session.secure')]`.

The cookie name still follows the request, so it never takes the `__Host-` prefix on a plain-HTTP request, and plain HTTP development keeps `auto`.

Note

For session hardening in Laravel, use the framework's own session config: set `secure`, `http_only`, and `same_site` in `config/session.php` rather than expecting the adapter to apply raw-PHP `SessionHardening`.

## Failure behavior[#](https://helmet.apps.wayne.edu/docs/guides/session.html#failure-behavior)

`apply()` throws a `LogicException` if a session is already active, and a `RuntimeException` naming the first setting that could not be changed if any `ini` value refuses to be set. A setting that cannot be changed is a loud failure, not a silent insecure default.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/session.html#related)

- [The request-forgery guard](https://helmet.apps.wayne.edu/docs/guides/csrf.html)
- [Metrics](https://helmet.apps.wayne.edu/docs/guides/metrics.html)

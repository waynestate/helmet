# HELMET best practices

Checked against primary sources on 2026-10-27.

## What this covers

HELMET is the baseline runtime for PHP apps on Citadel, WSU's Docker platform. It covers six areas: response headers and the Content-Security-Policy (CSP), HTTPS and proxy trust, session hardening, the health check, logging and errors, and environment and config. This guide is for WSU developers who install HELMET and for the people who maintain it. It lists what good practice looks like in each area, what the research says, and whether the stack holds each practice today.

The platform matters for every item below. Citadel terminates TLS at an HA-Proxy in front of the app, the proxy appends `X-Forwarded-For` and `X-Forwarded-Proto` and strips nothing, and the shared `php.ini` leaves `session.use_strict_mode` at 0, `session.cookie_httponly` empty and `expose_php` On. HELMET exists to close the gaps that app code can close.

### How to read the tags

Each practice ends with exactly one tag.

| Tag | Meaning |
| --- | --- |
| `Enforced` | HELMET's code does it or refuses to run without it, and a test covers it. This holds only for apps that call `helmet_boot()` or mount the middleware. The repository has no CI configuration yet, so the tests run by hand until a pipeline exists. |
| `Checked` | PROCTOR or CI warns or fails. |
| `Documented` | Written down in the README or a design note, with no code behind it. |
| `Not yet` | A gap. |

HELMET is at its first scaffold step. The Laravel adapter, the environment ribbon and the version warning are planned and not built.

---

## 1. Content-Security-Policy

### H1. Send a fresh CSP nonce on every response
- **Practice:** Generate an unpredictable nonce per response, at least 128 bits, and put the same value in the `script-src` directive and on each allowed `<script>` tag.
- **Why:** A nonce that repeats or can be guessed gives an attacker a way to run injected script. MDN says it "must be different for every HTTP response, and must not be predictable".
- **Sources:** [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [web.dev strict CSP](https://web.dev/articles/strict-csp), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Enforced`. `SecurityHeaders::generateNonce()` returns 18 random bytes (144 bits), base64 encoded, on every request. `testNonceMatchesRequestAttribute` checks that the header and the request attribute agree.

### H2. Put the nonce only on tags you wrote
- **Practice:** Add the nonce in templates, on the specific script tags you intend to allow. Do not rewrite every script tag in the output.
- **Why:** Blanket insertion hands a valid nonce to any script an attacker injects.
- **Sources:** [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Documented`. The README says to put `$helmet['nonce']` in your `<script>` tags. Nothing stops an app from doing it wrongly.

### H3. Move toward a strict, nonce-based policy
- **Practice:** Use `script-src 'nonce-...' 'strict-dynamic'`, `object-src 'none'` and `base-uri 'none'` instead of a list of allowed hosts.
- **Why:** Host allowlists are hard to get right and are bypassed in most real configurations.
- **Sources:** [web.dev strict CSP](https://web.dev/articles/strict-csp), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Not yet`. The default policy in `SecurityHeaders::defaultCspPolicy()` lists the WayneUI host in `script-src` and adds a nonce. It has no `strict-dynamic`. Whether `paragonie/csp-builder` can emit `strict-dynamic` was not confirmed: its README does not mention it.

### H4. Set the directives that do not fall back to `default-src`
- **Practice:** Set `base-uri`, `form-action` and `object-src` explicitly. `frame-ancestors` also has no fallback.
- **Why:** Without them, an injected `<base>` tag or form can redirect relative URLs or form posts to an attacker.
- **Sources:** [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Not yet`. Only `frame-ancestors` is set. `base-uri`, `form-action` and `object-src` are missing from the default policy.

### H5. Avoid `'unsafe-inline'`
- **Practice:** Move inline scripts and styles to files, or allow them by nonce or hash.
- **Why:** `'unsafe-inline'` lets any injected inline code run, which removes most of the policy's value for that directive.
- **Sources:** [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Not yet`. The default policy allows `'unsafe-inline'` in `style-src-attr` and `style-src-elem`. The scripts are covered, the styles are not. See the gap list.

### H6. Use `frame-ancestors`, and keep `X-Frame-Options` for old browsers
- **Practice:** Send `frame-ancestors 'self'` (or `'none'` if the app is never framed) and `X-Frame-Options` with a matching value.
- **Why:** `frame-ancestors` replaces `X-Frame-Options` in modern browsers and is more flexible.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Enforced`. Both headers are sent: `frame-ancestors 'self'` and `X-Frame-Options: SAMEORIGIN`. `testSetsAllSecurityHeaders` covers the header set.

### H7. Roll out policy changes in Report-Only mode with a reporting endpoint
- **Practice:** Send a stricter policy as `Content-Security-Policy-Report-Only` first, collect violation reports, then enforce it. Use `report-to` with `report-uri` as a fallback.
- **Why:** It shows what a tighter policy would break before users see it.
- **Sources:** [web.dev strict CSP](https://web.dev/articles/strict-csp), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Not yet`. `SecurityHeaders::build()` has no Report-Only mode and no report endpoint setting.

### H8. Do not cache pages that carry a nonce
- **Practice:** Do not let a proxy, CDN or the app cache HTML that includes a nonce. For static or cached pages, use hashes instead.
- **Why:** A cached page repeats its nonce, which breaks the per-response rule.
- **Sources:** [web.dev strict CSP](https://web.dev/articles/strict-csp), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Not yet`. Nothing in HELMET or its README says so. The raw PHP path sets no cache headers on normal pages.

### H9. Let apps extend the baseline policy, not replace it
- **Practice:** An app that needs one more source should add it to the default policy and keep the rest.
- **Why:** A replacement policy silently drops protections such as `frame-ancestors`.
- **Sources:** [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Not yet`. The `csp` option in `helmet_boot()` and the second constructor argument of `SecurityHeadersMiddleware` replace the whole default. `testCustomCspPolicyIsUsed` shows a policy with only `default-src 'none'` being accepted.

---

## 2. Other response headers

### H10. Send `nosniff` and an explicit Referrer-Policy
- **Practice:** `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`.
- **Why:** The first stops browsers from guessing a content type. The second limits what leaks in the `Referer` header. OWASP says to set the policy explicitly rather than rely on browser defaults.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. Both are in `SecurityHeaders::build()`.

### H11. Turn off browser features the app does not use
- **Practice:** Send a Permissions-Policy that disables camera, microphone, geolocation and any other feature the app never needs, such as `payment` and `usb`.
- **Why:** An injected script cannot use a feature the page has disabled. Features you do not list keep their defaults.
- **Sources:** [MDN Permissions-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Permissions-Policy), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. `camera=(), geolocation=(), microphone=()` is sent. Only those three are covered.

### H12. Do not send `X-XSS-Protection`, and remove version banners HELMET can reach
- **Practice:** Leave `X-XSS-Protection` unset (or `0`). Remove `X-Powered-By`.
- **Why:** The legacy filter can add vulnerabilities, and banners help attackers fingerprint the stack.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. `X-XSS-Protection` is never set. `header_remove('X-Powered-By')` runs in `helmet_boot()` and the middleware calls `withoutHeader`. `testXPoweredByIsRemoved` covers the middleware.

### H13. Consider cross-origin isolation headers
- **Practice:** Send `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Resource-Policy: same-site` where the app does not need cross-origin embedding.
- **Why:** They keep the page out of shared browsing contexts and limit who can read its responses. `Cross-Origin-Embedder-Policy: require-corp` breaks resources that do not opt in, so test before using it.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Not yet`. None of the three is sent. Adding them needs a test against WayneUI assets and any third-party embeds.

### H14. Send the security headers on error responses too
- **Practice:** Mount the header middleware outermost, so a response produced by an exception handler also carries the headers.
- **Why:** Error pages are still pages. A missing CSP on a 500 page is a gap.
- **Sources:** [OWASP Error Handling](https://cheatsheetseries.owasp.org/cheatsheets/Error_Handling_Cheat_Sheet.html), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Not yet`. `SecurityHeadersMiddleware` adds headers after `$handler->handle()` returns. If the handler throws, an outer error handler builds a response without them. The README does not say where to mount it.

### H15. Fix banners and server tokens at the platform
- **Practice:** Set `expose_php=Off` in `php.ini` and `ServerTokens Prod` and `TraceEnable Off` in the Apache config.
- **Why:** These settings cannot be changed from app code. `expose_php` is a `php.ini`-only directive, so `header_remove` only hides one of its effects.
- **Sources:** [PHP ini list](https://www.php.net/manual/en/ini.list.php), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Not yet`. It needs a Citadel change, planned as an upstream request by SQUIRE.

---

## 3. HTTPS and HSTS

### H16. Send HSTS only when the request really was HTTPS
- **Practice:** Send `Strict-Transport-Security` on HTTPS responses only. Decide "HTTPS" from a trusted signal, not from a header a client can set.
- **Why:** Browsers ignore HSTS over plain HTTP, so sending it there does nothing. Deciding wrongly the other way means a spoofed header could trigger it.
- **Sources:** [MDN HSTS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security)
- **Stack:** `Enforced`. `HttpsDetector::isHttps()` feeds `SecurityHeaders::build()`. `testHstsSentWhenHttps` and `testNoHstsWhenNotHttps` cover both sides.

### H17. Roll out HSTS in stages, and know what `includeSubDomains` reaches
- **Practice:** Start with a short `max-age`, raise it once nothing breaks, and use `includeSubDomains` only when every subdomain serves HTTPS. Treat `preload` as a decision for the domain owner, not for one app.
- **Why:** A mistake persists until `max-age` runs out. A policy with `includeSubDomains` makes every host below it HTTPS-only.
- **Sources:** [MDN HSTS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Not yet`. The value is fixed at `max-age=31536000; includeSubDomains` and cannot be configured. That is safe for a default app host. It deserves thought for an app on a vanity domain, because Citadel allows vanity domains under two parent domains.

---

## 4. Proxy trust

### H18. Trust forwarded headers only from known proxy peers
- **Practice:** Read `X-Forwarded-Proto` (and `X-Forwarded-For`) only when the connection's own address, `REMOTE_ADDR`, is a configured proxy.
- **Why:** Any part of these headers not added by a proxy you control can be forged by the client.
- **Sources:** [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For), [RFC 7239](https://www.rfc-editor.org/rfc/rfc7239), [Laravel requests](https://laravel.com/docs/12.x/requests)
- **Stack:** `Enforced`. `HttpsDetector` checks `TrustedProxies::isTrusted($remoteAddr)` first. `testSpoofedForwardedProtoFromUntrustedPeerIsIgnored` covers the spoof case.

### H19. An empty list trusts nothing, and the list is required
- **Practice:** Make the trusted proxy list required config. Never treat "not set" as "trust all".
- **Why:** A default of `*` turns a convenience setting into a spoofing hole.
- **Sources:** [Laravel requests](https://laravel.com/docs/12.x/requests), [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For)
- **Stack:** `Enforced`. `helmet_boot()` throws `InvalidArgumentException` when `trusted_proxies` is missing. `HttpsDetector` returns false for an empty list. `testEmptyTrustedListTrustsNothing` covers it.

### H20. Read only the forwarded headers you need
- **Practice:** Read `X-Forwarded-Proto`. Ignore `X-Forwarded-Port`, `X-Forwarded-Host` and the standard `Forwarded` header unless a proxy you control sets them.
- **Why:** Every extra header read is extra spoofing surface. Citadel's proxy does not set the port header at all.
- **Sources:** [RFC 7239](https://www.rfc-editor.org/rfc/rfc7239), [Laravel requests](https://laravel.com/docs/12.x/requests)
- **Stack:** `Enforced`. `HttpsDetector` reads only `HTTP_X_FORWARDED_PROTO`. `testForwardedPortIsNeverRead` covers the port header.

### H21. Trust the proxy's address, not its whole network
- **Practice:** List the exact proxy address, or the smallest range that holds only proxies. Do not list a whole private network, `0.0.0.0/0` or `*`.
- **Why:** On Citadel every app container shares one flat network, so trusting that network trusts every other app, and any of them can send a forged header.
- **Sources:** [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For), [Laravel requests](https://laravel.com/docs/12.x/requests)
- **Stack:** `Not yet`. The README example is `10.0.0.0/8`, which is probably not the address the app sees from HA-Proxy. `TrustedProxies` accepts a `/0` range. See the gap list.

### H22. Read multi-value headers from the proxy's end
- **Practice:** When a header can hold several values, take the value your own proxy added, which is the rightmost one. Parse repeated headers as one list.
- **Why:** The proxy appends to what the client sent, so the left side is client controlled.
- **Sources:** [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For)
- **Stack:** `Not yet`. `HttpsDetector` compares the whole value to `https`. The proxy appends its own value and Apache joins repeated headers with a comma (inferred), so a real HTTPS request that arrived with a client-sent `X-Forwarded-Proto: http` would read as `http, https` and be treated as plain HTTP. That fails closed, so no HSTS or `Secure` flag is lost to an attacker, but a user can degrade their own session.

### H23. Never use the leftmost `X-Forwarded-For` value for a security decision
- **Practice:** Get a client IP by walking the list from the right, skipping trusted proxies. Do not use the leftmost value for rate limits, allow lists or audit identity.
- **Why:** The leftmost value is whatever the client wrote.
- **Sources:** [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For), [Laravel requests](https://laravel.com/docs/12.x/requests)
- **Stack:** `Not yet`. HELMET offers no client-IP function. The design says it would reuse `akrabat/ip-address-middleware`. That package is in `composer.json` and nothing calls it.

### H24. Validate the proxy list strictly
- **Practice:** Reject bad entries at boot with a clear error instead of skipping them.
- **Why:** A silently ignored entry looks like working config and trusts nothing, or the wrong thing.
- **Sources:** [Laravel requests](https://laravel.com/docs/12.x/requests)
- **Stack:** `Not yet`. A bad CIDR throws. A bad exact entry such as `*`, an IPv6 address or a typo is stored as a string that never matches, and non-string or empty entries are skipped.

### H25. Close the network path around the proxy
- **Practice:** Make app containers reachable only through the proxy, or sign what the proxy asserts.
- **Why:** A trusted-address list cannot stop another container that can reach the app directly.
- **Sources:** [RFC 7239](https://www.rfc-editor.org/rfc/rfc7239)
- **Stack:** `Not yet`. It needs a Citadel-side fix. The stack research found app containers on one shared bridge with default inter-container traffic on.

---

## 5. Sessions

### H26. Harden the session settings before `session_start()`
- **Practice:** Set `use_strict_mode=1`, `use_only_cookies=1`, `cookie_httponly=1` and `cookie_samesite=Lax` (or `Strict`) before the session starts.
- **Why:** Strict mode rejects session IDs the server did not issue, which blocks fixation. The cookie flags limit script access and cross-site use.
- **Sources:** [PHP session security ini](https://www.php.net/manual/en/session.security.ini.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [PHP ini list](https://www.php.net/manual/en/ini.list.php)
- **Stack:** `Enforced`. `SessionHardening::apply()` sets all four, and `helmet_boot()` calls it. All four are runtime-settable. `testSetsHardenedIniValuesWhenHttps` covers it.

### H27. Set `Secure` from the real scheme
- **Practice:** Turn on `session.cookie_secure` only when the request really is HTTPS.
- **Why:** On Citadel, PHP sees plain HTTP behind the proxy. Setting `Secure` blindly can drop the session cookie. Never setting it leaves the cookie open to downgrade.
- **Sources:** [PHP session security ini](https://www.php.net/manual/en/session.security.ini.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- **Stack:** `Enforced`. `apply($https)` sets `cookie_secure=1` only when `HttpsDetector` says HTTPS. `testCookieSecureNotSetWhenNotHttps` covers the negative case.

### H28. Fail loudly when hardening cannot apply
- **Practice:** If the session is already active, or an `ini_set` fails, stop or log. Do not carry on with weak settings.
- **Why:** Silent failure leaves the app looking hardened when it is not.
- **Sources:** [PHP session security ini](https://www.php.net/manual/en/session.security.ini.php)
- **Stack:** `Not yet`. `SessionHardening::apply()` throws on an active session, which `testThrowsWhenSessionActive` covers. But `helmet_boot()` skips `apply()` without a word when a session is already active, and `apply()` ignores the return value of `ini_set`. The README says it "refuses to run". See the gap list.

### H29. Regenerate the session ID on login, expire sessions, and invalidate on logout
- **Practice:** Issue a new ID after login or any privilege change and destroy the old one. Enforce idle and absolute timeouts on the server. Invalidate the server-side session on logout.
- **Why:** This is what stops fixation and limits a stolen ID.
- **Sources:** [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- **Stack:** `Documented`. The auth library's design (SESAME) says it always regenerates the ID on login and invalidates first on logout. That library is not built. HELMET does none of it.

### H30. Use a host-only cookie with a generic name
- **Practice:** Do not set a cookie `Domain`. Prefer the `__Host-` prefix (needs `Secure`, `Path=/`, no `Domain`). Avoid a framework-revealing name such as `PHPSESSID`.
- **Why:** A host-only cookie is not sent to sibling subdomains. A generic name reveals less.
- **Sources:** [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- **Stack:** `Not yet`. HELMET does not touch the session name or cookie path and domain.

### H31. Set session lifetime, URL IDs and caching explicitly
- **Practice:** Use `session.cookie_lifetime=0`, keep session IDs out of URLs, and use `session.cache_limiter=nocache` for authenticated pages. Track session lifetime in your own code, since `gc_maxlifetime` is not reliable for expiry.
- **Why:** Each one closes a way for a session ID or an authenticated page to outlive its use.
- **Sources:** [PHP session security ini](https://www.php.net/manual/en/session.security.ini.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- **Stack:** `Not yet`. `use_only_cookies=1` already blocks URL IDs. The others are not set by HELMET and the Citadel `php.ini` values for them were not audited.

### H32. Leave `session.sid_length` alone
- **Practice:** Do not change `session.sid_length` or `session.sid_bits_per_character`.
- **Why:** PHP 8.4 deprecates changing them, and the storage backend should accept the default 32-character IDs.
- **Sources:** [PHP 8.4 deprecations](https://www.php.net/manual/en/migration84.deprecated.php)
- **Stack:** `Documented`. The `SessionHardening` docblock and `testSidLengthIsNotTouched` keep HELMET off these settings. The Citadel `php.ini` sets `sid_length=26`, which is routed to the upgrade work.

---

## 6. Health check

### H33. Make the liveness check framework-free and dependency-free
- **Practice:** Answer `/up` with a 200 before routing, auth, the session or the database is involved. Liveness should only say "this process can answer".
- **Why:** If the check depends on a database, a database outage restarts every healthy app at once. Citadel marks an app that keeps restarting as Failing and stops it.
- **Sources:** [Kubernetes probes](https://kubernetes.io/docs/concepts/configuration/liveness-readiness-startup-probes/), [Laravel deployment](https://laravel.com/docs/12.x/deployment), [Docker Dockerfile reference](https://docs.docker.com/reference/dockerfile/)
- **Stack:** `Enforced`. `HealthCheck` and `helmet_boot()` answer `GET` and `HEAD` on `/up` with `200 ok` and touch nothing else. `testGetHealthPathReturnsOk` and the pass-through tests cover it.

### H34. Keep liveness and readiness separate
- **Practice:** Put dependency checks, such as the database or a cache, on a different endpoint from the liveness check.
- **Why:** A failed dependency should take an app out of rotation, not kill it.
- **Sources:** [Kubernetes probes](https://kubernetes.io/docs/concepts/configuration/liveness-readiness-startup-probes/), [Laravel deployment](https://laravel.com/docs/12.x/deployment)
- **Stack:** `Not yet`. HELMET has only the liveness endpoint. Laravel's `DiagnosingHealth` event is the hook for readiness work in the Laravel adapter, which is not built.

### H35. Keep the response tiny, uncached and free of detail
- **Practice:** Return a short body, `Cache-Control: no-store`, and no version or configuration detail.
- **Why:** The endpoint is public and polled often.
- **Sources:** [Docker Dockerfile reference](https://docs.docker.com/reference/dockerfile/), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. The body is `ok`, `Cache-Control` is `no-store`, and `Content-Type` is plain text.

---

## 7. Logging

### H36. Write structured, one-event-per-line logs
- **Practice:** Log JSON, one object per line, with the same field names everywhere.
- **Why:** Structured output is searchable, and the encoder escapes line breaks, which blocks forged log lines.
- **Sources:** [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [Monolog handlers and formatters](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md)
- **Stack:** `Enforced`. `Logging::create()` returns a Monolog logger with `JsonFormatter` on a `StreamHandler`. No test covers the logger yet.

### H37. Treat logs as a stream and let the platform route them
- **Practice:** Write events to a stream (stderr or a configured path) and leave collection, rotation and retention to the platform.
- **Why:** The app should not manage log files. The twelve-factor rule is that the platform, not the app, routes and stores the output stream.
- **Sources:** [Twelve-Factor logs](https://12factor.net/logs), [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
- **Stack:** `Documented`. The README shows a `stream` that is a stream or a path, and the default is `php://stderr`. Citadel rotates daily and keeps 3 rotations at 10 MB, which is short for any audit need. Retention is a Citadel setting.

### H38. Keep secrets and session IDs out of logs
- **Practice:** Never log passwords, tokens, session IDs, keys or connection strings. Redact known keys in the formatter.
- **Why:** Logs are read by more people and kept longer than the secrets they would expose.
- **Sources:** [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [Monolog handlers and formatters](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md)
- **Stack:** `Not yet`. `Logging::create()` has no redaction. Monolog's `RedactingFormatter` is the described tool.

### H39. Add a request ID and request context
- **Practice:** Give each request a unique ID and include it, with the method, path and a trusted client address, in every event.
- **Why:** Related events can then be found together.
- **Sources:** [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [Monolog handlers and formatters](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md)
- **Stack:** `Not yet`. No processors are added. The client address would need the trusted-proxy walk from H23.

### H40. A logging failure must not take the request down
- **Practice:** Wrap handlers so that a full disk or an unwritable file is reported and swallowed.
- **Why:** Logging should never be the reason an app is unavailable.
- **Sources:** [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [Monolog handlers and formatters](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md)
- **Stack:** `Not yet`. `Logging::create()` uses a bare `StreamHandler`. Monolog's `WhatFailureGroupHandler` is the documented answer.

### H41. Log security-relevant events
- **Practice:** Log authentication and authorization failures, session failures, validation failures and trust-decision surprises, in proportion to risk.
- **Why:** These are the events an investigation starts from.
- **Sources:** [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
- **Stack:** `Not yet`. `helmet_boot()` returns a logger but logs nothing itself. For example, it does not record a rejected forwarded header or a skipped session hardening.

---

## 8. Errors

### H42. Show users a generic error and keep detail in the log
- **Practice:** Install a global exception handler that returns a neutral message and logs the full exception. Use 4xx for client faults and 5xx for server faults.
- **Why:** Stack traces, SQL errors and paths help an attacker.
- **Sources:** [OWASP Error Handling](https://cheatsheetseries.owasp.org/cheatsheets/Error_Handling_Cheat_Sheet.html)
- **Stack:** `Not yet`. HELMET has no error handler. The "E" in the name is still a plan.

### H43. Keep debug output off in production
- **Practice:** `display_errors=Off` and `APP_DEBUG=false` in production.
- **Why:** Debug pages expose configuration values to users.
- **Sources:** [Laravel deployment](https://laravel.com/docs/12.x/deployment), [Laravel configuration](https://laravel.com/docs/12.x/configuration), [PHP ini list](https://www.php.net/manual/en/ini.list.php)
- **Stack:** `Not yet`. The Citadel `php.ini` already has `display_errors=Off`. HELMET neither sets nor verifies it, and it does not look at `APP_DEBUG`. An app that turns it on at runtime is not stopped.

---

## 9. Environment and config

### H44. Fail at boot when required config is missing
- **Practice:** Check required settings at startup and stop with a clear error.
- **Why:** A missing setting found on the first request is found by a user.
- **Sources:** [Twelve-Factor config](https://12factor.net/config)
- **Stack:** `Enforced`, for one setting. `helmet_boot()` throws if `trusted_proxies` is not an array.

### H45. Keep config out of code and secrets out of git
- **Practice:** Read config from the environment or from files that are not committed. In Laravel, call `env()` only inside `config/` files, because it returns `null` once config is cached. Do not commit `.env`.
- **Why:** Config changes per deploy, and a repository leak should not expose credentials.
- **Sources:** [Twelve-Factor config](https://12factor.net/config), [Laravel configuration](https://laravel.com/docs/12.x/configuration), [Laravel deployment](https://laravel.com/docs/12.x/deployment)
- **Stack:** `Not yet`. The handbook chapter on the runtime says no rules are decided yet. A current WSU Laravel app follows this practice by its own convention.

### H46. Show the environment in non-production, never in production
- **Practice:** Add a visible ribbon on dev and test, and treat an unknown environment as production.
- **Why:** It prevents people from mistaking test data for live data, and a wrong guess must not reveal itself on a live site.
- **Sources:** [Twelve-Factor config](https://12factor.net/config)
- **Stack:** `Not yet`. It is a planned later step.

### H47. Run on a supported PHP version, and warn when support is ending
- **Practice:** Use a PHP version that still gets security fixes. PHP 8.2 reaches end of security support on 2026-12-31. 8.3 follows on 2027-12-31.
- **Why:** An unsupported version gets no security patches.
- **Sources:** [endoflife.date PHP](https://endoflife.date/php)
- **Stack:** `Checked`. PROCTOR's `php-floor` check fails an app whose lowest allowed PHP version is past support, using the policy file. HELMET's own runtime version warning is `Not yet`. HELMET's `composer.json` requires `^8.2`, and the design moves the floor to 8.3 on 2027-01-01.

---

## 10. Packaging and consumers

### H48. Keep one definition for every consumer path
- **Practice:** Build the headers from one function used by the raw PHP boot path and the middleware.
- **Why:** Two copies drift, and one path ends up weaker.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. Both paths call `SecurityHeaders::build()`. `testDefaultPolicyMatchesSharedDefault` checks the default policy.

### H49. Give Laravel the same proxy list and the same headers
- **Practice:** In Laravel, set `trustProxies(at: ...)` from the same list HELMET uses. Do not use `*`.
- **Why:** Two lists disagree, and `*` trusts any peer. On Citadel's flat network, that includes other apps' containers.
- **Sources:** [Laravel requests](https://laravel.com/docs/12.x/requests)
- **Stack:** `Not yet`. The Laravel adapter is step 2 and is not built.

### H50. Lock dependencies and audit them
- **Practice:** Commit `composer.lock`, bound every runtime constraint, and run `composer audit --locked` in CI and at release.
- **Why:** A lock file is what makes a release repeatable, and an audit finds known advisories.
- **Sources:** [Composer CLI](https://getcomposer.org/doc/03-cli.md)
- **Stack:** `Checked`. PROCTOR's `lock-committed`, `unbounded-constraints` and `composer-audit` checks do this for any app that runs PROCTOR.

---

## Gaps for the backlog

### Problems in the current code

Each of these follows from the research above. Items marked "inferred" depend on how Citadel's network works and need confirming.

1. `src/Headers/SecurityHeaders.php:37-38`: `'unsafe-inline'` in `style-src-attr` and `style-src-elem`. Move WayneUI inline styles to nonces or hashes, or document the exception.
2. `src/Headers/SecurityHeaders.php:35-43`: no `base-uri`, `object-src` or `form-action`. Add `base-uri 'none'`, `object-src 'none'` and `form-action 'self'`.
3. `src/Headers/SecurityHeaders.php:39` and `:73`: host allowlist plus nonce, no `strict-dynamic`. Check whether `csp-builder` can emit it.
4. `src/functions.php:47-49`: `helmet_boot()` silently skips session hardening when a session is already active, which contradicts the README line "It refuses to run once a session is active" (`README.md:104`). Log it or throw.
5. `src/Session/SessionHardening.php:36-44`: `ini_set` results are not checked, so a refused setting goes unnoticed.
6. `src/Proxy/TrustedProxies.php:92-94`: a `/0` range is accepted and trusts every IPv4 address. Reject prefixes shorter than a chosen minimum.
7. `src/Proxy/TrustedProxies.php:34` and `:41`: invalid exact entries (`*`, IPv6, typos) are stored or skipped without error. Validate them with `filter_var` and throw.
8. `README.md:35` and `:65`: the example list `10.0.0.0/8` is probably not the address the app sees from HA-Proxy (inferred: the Docker bridge gateway on `172.18.0.0/24`). Trusting the whole bridge would trust every other app's container. Replace with the exact proxy address, once the Citadel team confirms it.
9. `src/Proxy/HttpsDetector.php:47-50`: exact comparison of a possibly multi-value header. Take the last element.
10. `src/functions.php:74` and `src/Headers/SecurityHeadersMiddleware.php:24-28`: a custom `csp` replaces the default. Offer merge-by-default.
11. `composer.json:9`: `akrabat/ip-address-middleware` is required and unused. Use it for the client-IP function, or remove it.
12. `src/Logging/Logging.php:27-37`: no redaction, no processors, no failure-safe handler.
13. `composer.json:6`: `php ^8.2` loses security support on 2026-12-31. The planned floor change on 2027-01-01 is about nine weeks away.

### Missing features

14. Report-Only mode and a CSP report endpoint.
15. Configurable HSTS `max-age` and `includeSubDomains`, with a staged rollout note.
16. Permissions-Policy covering more features, such as `payment` and `usb`.
17. COOP and CORP headers, tested against WayneUI assets.
18. A client-IP function that walks the forwarded list from the right.
19. A readiness endpoint, separate from `/up`.
20. A global error handler that logs details and shows a generic page.
21. Request ID and context processors, and a redacting formatter.
22. A test for `Logging::create()` and for `helmet_boot()` itself.
23. The Laravel adapter, the environment ribbon and the version warning.
24. A CI pipeline for the repository.

### Needs Citadel

25. `expose_php=Off`, `ServerTokens Prod` and `TraceEnable Off` (SQUIRE upstream work).
26. A network fix so app containers are reachable only through the proxy.
27. Written rules in the handbook for runtime and headers, which today states none.

---

## Sources

All pages were read on 2026-10-27. Laravel pages are the 12.x versions. The 13.x versions were not read.

1. OWASP, HTTP Headers Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html
2. OWASP, Content Security Policy Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html
3. MDN, Content Security Policy guide: https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP
4. web.dev, Mitigate cross-site scripting with a strict CSP: https://web.dev/articles/strict-csp
5. MDN, Strict-Transport-Security: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security
6. MDN, Permissions-Policy: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Permissions-Policy
7. OWASP, Session Management Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
8. PHP manual, Session security ini settings: https://www.php.net/manual/en/session.security.ini.php
9. PHP manual, List of php.ini directives: https://www.php.net/manual/en/ini.list.php
10. PHP manual, Deprecated features in PHP 8.4: https://www.php.net/manual/en/migration84.deprecated.php
11. MDN, X-Forwarded-For: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For
12. RFC 7239, Forwarded HTTP Extension: https://www.rfc-editor.org/rfc/rfc7239
13. Laravel 12.x, HTTP Requests (trusted proxies): https://laravel.com/docs/12.x/requests
14. Laravel 12.x, Deployment (optimization, debug mode, health route): https://laravel.com/docs/12.x/deployment
15. Laravel 12.x, Configuration: https://laravel.com/docs/12.x/configuration
16. OWASP, Logging Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
17. OWASP, Error Handling Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Error_Handling_Cheat_Sheet.html
18. Monolog, Handlers, formatters and processors: https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md
19. paragonie/csp-builder README: https://github.com/paragonie/csp-builder
20. The Twelve-Factor App, Config: https://12factor.net/config
21. The Twelve-Factor App, Logs: https://12factor.net/logs
22. Kubernetes, Liveness, readiness and startup probes: https://kubernetes.io/docs/concepts/configuration/liveness-readiness-startup-probes/
23. Docker, Dockerfile reference (HEALTHCHECK): https://docs.docker.com/reference/dockerfile/
24. endoflife.date, PHP: https://endoflife.date/php
25. Composer, Command-line interface: https://getcomposer.org/doc/03-cli.md

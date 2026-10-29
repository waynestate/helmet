# HELMET best practices

Checked against primary sources on 2026-10-27. A second pass the same day added practices H51 to H58 and corrected several statements. See "Second pass (2026-10-27)" near the end.

## What this covers

HELMET is the baseline runtime for PHP apps on Citadel, WSU's Docker platform. It covers seven areas: response headers and the Content-Security-Policy (CSP), HTTPS and proxy trust, session hardening, the health check, logging and errors, environment and config, and metrics. This guide is for WSU developers who install HELMET and for the people who maintain it. It lists what good practice looks like in each area, what the research says, and whether the stack holds each practice today.

The platform matters for every item below. Citadel terminates TLS at an HA-Proxy in front of the app, the proxy appends `X-Forwarded-For` and `X-Forwarded-Proto` and strips nothing, and the shared `php.ini` leaves `session.use_strict_mode` at 0, `session.cookie_httponly` empty and `expose_php` On. HELMET exists to close the gaps that app code can close.

### How to read the tags

Each practice ends with exactly one tag.

| Tag | Meaning |
| --- | --- |
| `Enforced` | HELMET's code does it or refuses to run without it, and a test covers it. This holds only for apps that call `helmet_boot()` or mount the middleware. The repository now has a `.gitlab-ci.yml` that includes the PREFLIGHT `php-test` and `composer-audit` components, pinned to one commit. The README says git.wayne.edu has no runners today, so whether that pipeline has ever run was not verified. Treat the tests as run by hand until a pipeline run is on record. |
| `Checked` | PROCTOR or CI warns or fails. |
| `Documented` | Written down in the README or a design note, with no code behind it. |
| `Not yet` | A gap. |

HELMET is at its first scaffold step. The Laravel adapter and the environment ribbon are built, and the version warning is wired into the ribbon: when a policy file is configured, the ribbon shows a warning line for each component version that is in its security-only phase or end of life.

---

## 1. Content-Security-Policy

### H1. Send a fresh CSP nonce on every response
- **Practice:** Generate an unpredictable nonce per response, at least 128 bits, and put the same value in the `script-src` directive and on each allowed `<script>` tag.
- **Why:** A nonce that repeats or can be guessed gives an attacker a way to run injected script. MDN says it "must be different for every HTTP response, and must not be predictable". The CSP Level 3 spec (section 7.1) is the source for the size: the server MUST generate a unique value each time it sends a policy, and the value SHOULD be at least 128 bits before encoding, from a cryptographically secure generator. (The first pass credited the 128-bit figure to MDN, web.dev and OWASP. None of the pages read says it. The spec does.)
- **Sources:** [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [web.dev strict CSP](https://web.dev/articles/strict-csp), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [W3C CSP Level 3](https://w3c.github.io/webappsec-csp/)
- **Stack:** `Enforced`. `SecurityHeaders::generateNonce()` returns 18 random bytes (144 bits), base64 encoded, on every request. `testNonceMatchesRequestAttribute` checks that the header and the request attribute agree.

### H2. Put the nonce only on tags you wrote
- **Practice:** Add the nonce in templates, on the specific script tags you intend to allow. Do not rewrite every script tag in the output.
- **Why:** Blanket insertion hands a valid nonce to any script an attacker injects. OWASP says it plainly: you need a real templating engine to use nonces, and middleware that injects nonces into script tags would give them to attacker-injected scripts too. That applies to any future HELMET feature that rewrites HTML.
- **Sources:** [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Documented`. The README says to put `$helmet['nonce']` in your `<script>` tags. Nothing stops an app from doing it wrongly.

### H3. Move toward a strict, nonce-based policy
- **Practice:** Use `script-src 'nonce-...' 'strict-dynamic'`, `object-src 'none'` and `base-uri 'none'` instead of a list of allowed hosts.
- **Why:** Host allowlists are hard to get right and are bypassed in most real configurations. The Google study "CSP Is Dead, Long Live CSP!" (2016) reported bypasses in 94.72% of the distinct policies it analyzed (read as a search summary, not the full paper). MDN notes the trade-off: `strict-dynamic` lets a trusted script load more scripts, so it protects less if your own scripts build `<script>` elements from untrusted input.
- **Rollout detail:** web.dev says `strict-dynamic` needs `https:` as a fallback for older Safari, and `'unsafe-inline'` as a fallback for very old browsers. Recent browsers ignore `'unsafe-inline'` when a nonce or hash is present, so the fallbacks do not weaken the policy where `strict-dynamic` works.
- **Sources:** [web.dev strict CSP](https://web.dev/articles/strict-csp), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [Google Research, CSP Is Dead, Long Live CSP!](https://research.google/pubs/pub45542/)
- **Stack:** `Enforced`. `SecurityHeaders::buildCsp()` calls `setStrictDynamic('script-src', true)`, so the policy sends `script-src 'nonce-...' 'strict-dynamic'` alongside the nonce. `testBuildCspAddsStrictDynamicAlongsideNonce` asserts the keyword is present next to the nonce.

### H4. Set the directives that do not fall back to `default-src`
- **Practice:** Set `base-uri`, `form-action` and `object-src` explicitly. `frame-ancestors` also has no fallback.
- **Why:** Without them, an injected `<base>` tag or form can redirect relative URLs or form posts to an attacker.
- **Sources:** [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Enforced`. `defaultCspPolicy()` sets `base-uri 'self'`, `object-src 'none'` and `form-action 'self'` beside `frame-ancestors 'self'`. `testDefaultPolicyIncludesHardeningDirectives` covers all four.

### H5. Avoid `'unsafe-inline'`
- **Practice:** Move inline scripts and styles to files, or allow them by nonce or hash.
- **Why:** `'unsafe-inline'` lets any injected inline code run, which removes most of the policy's value for that directive.
- **Sources:** [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Not yet`. The default policy allows `'unsafe-inline'` in `style-src-attr` only; `style-src-elem` deliberately does not carry it. So inline `style` attributes are allowed, but `<style>` elements and stylesheets are not. See the gap list.

### H6. Use `frame-ancestors`, and keep `X-Frame-Options` for old browsers
- **Practice:** Send `frame-ancestors 'self'` (or `'none'` if the app is never framed) and `X-Frame-Options` with a matching value.
- **Why:** `frame-ancestors` replaces `X-Frame-Options` in modern browsers and is more flexible.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
- **Stack:** `Enforced`. Both headers are sent: `frame-ancestors 'self'` and `X-Frame-Options: SAMEORIGIN`. `testSetsAllSecurityHeaders` covers the header set.

### H7. Roll out policy changes in Report-Only mode with a reporting endpoint
- **Practice:** Send a stricter policy as `Content-Security-Policy-Report-Only` first, collect violation reports, then enforce it. Declare the endpoint in a `Reporting-Endpoints` header, point the policy at it with `report-to`, and keep `report-uri` beside it as a fallback.
- **Why:** It shows what a tighter policy would break before users see it. Both OWASP and MDN now call `report-uri` deprecated and `report-to` the current directive. MDN lists `Reporting-Endpoints` as Baseline "Newly available" since September 2024. (A secondary page read during the second pass said March 2026. MDN, the primary source, says September 2024, so that is the date used here.) Reports arrive as a `POST` with `Content-Type: application/reports+json`, so the endpoint must accept that type.
- **Sources:** [web.dev strict CSP](https://web.dev/articles/strict-csp), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [MDN Reporting-Endpoints](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Reporting-Endpoints)
- **Stack:** `Enforced`. `SecurityHeaders::build()` takes a report URI and a report-only flag. With a URI it adds `report-uri` and `report-to` to the policy and sends a `Reporting-Endpoints` header; with report-only on it sends the policy as `Content-Security-Policy-Report-Only`. The URI is validated (`https`, a host, no whitespace or quote) and an invalid one throws `InvalidCspReportUriException` at boot. The Laravel adapter reads `helmet.csp_report_uri` and `helmet.csp_report_only` and ships an opt-in `Route::cspReport()` endpoint that caps the body, accepts the two report content types, and logs one line per violation. `testReportOnlyModeSendsTheReportOnlyHeader`, `testReportUriSendsReportingEndpointsAndDirectives`, `testBootAcceptsReportUriAndReportOnly`, `testReportEndpointAcceptsCspReport` and `testReportEndpointRejectsOversizedBody` cover it.

### H8. Do not cache pages that carry a nonce
- **Practice:** Do not let a proxy, CDN or the app cache HTML that includes a nonce. For static or cached pages, use hashes instead.
- **Why:** A cached page repeats its nonce, which breaks the per-response rule. web.dev says to use a hash-based CSP for pages served statically or that need to be cached. OWASP's header sheet adds that protected content should send `Cache-Control: no-store` (or `private`) and not rely on default caching.
- **Sources:** [web.dev strict CSP](https://web.dev/articles/strict-csp), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced` when enabled. `no_store.enabled` / `HELMET_NO_STORE_ENABLED` sends `Cache-Control: no-store` on matching responses, and `no_store.paths` / `HELMET_NO_STORE_PATHS` limits it to path prefixes; empty means every response. On the raw PHP path `helmet_boot()` skips the header when a `Cache-Control` was already sent, while the Laravel middleware overrides the framework's default `no-cache, private`. It is off by default, so an app that does not enable it still decides its own caching. `testNoStoreEnabledSendsNoStoreHeader`, `testNoStorePathMatchingSendsHeader` and `testNoStoreDoesNotOverrideExistingCacheControl` cover it.

### H9. Let apps extend the baseline policy, not replace it
- **Practice:** An app that needs one more source should add it to the default policy and keep the rest.
- **Why:** A replacement policy silently drops protections such as `frame-ancestors`.
- **Sources:** [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- **Stack:** `Enforced`. `SecurityHeaders::mergePolicies()` merges a custom policy over the default per directive: a directive present in the custom policy replaces the default's value, a directive set to `null` is removed, and directives the app does not mention keep the default. Both `helmet_boot()` and `SecurityHeadersMiddleware` call it. `testMergePoliciesReplacesDirectiveAndKeepsTheRest`, `testMergePoliciesRemovesDirectiveWhenNull` and the middleware's `testCustomCspPolicyMergesOverDefault` cover it.

### H51. Share one nonce between HELMET and the JavaScript bundler
- **Practice:** When the page loads a bundled JS app (Vue with Vite), give the bundler HELMET's nonce for the current response. Do not let the bundler or the framework make a second one. In Laravel, call `Vite::useCspNonce($nonce)` from middleware (the Laravel docs show `useCspNonce` and `cspNonce()` for this). In a raw PHP page with a Vue SPA, set Vite's `html.cspNonce` to a placeholder and replace the placeholder on every request.
- **Why:** The CSP header carries one nonce. Every script and style tag the bundler writes must carry the same value, or the browser blocks it. Vite's own guide warns that the placeholder must be replaced "with a unique value for each request", or the nonce stops protecting anything. A build-time constant is the failure case. Vite also inlines small assets as `data:` URIs by default, so `data:` must be allowed for `img-src` and `font-src` (and never for `script-src`), or inlining must be turned off with `build.assetsInlineLimit: 0`.
- **Sources:** [Laravel Vite (CSP nonce)](https://laravel.com/docs/12.x/vite), [Vite features (CSP)](https://vite.dev/guide/features.html), [Vite shared options (html.cspNonce)](https://vite.dev/config/shared-options.html)
- **Stack:** `Enforced`. The Laravel middleware hands the nonce to Vite via `Vite::useCspNonce()` and injects a `csp-nonce` meta tag into `<head>`, so `@vite` needs nothing; `testViteCspNonceMatchesTheCspHeader` and `testHtmlResponseGetsTheCspNonceMetaTag` cover it. In raw PHP, `helmet_boot()` returns the nonce and `helmet_csp_nonce_meta()` prints the meta tag for Vite's dev runtime; for a production build the app sets `html.cspNonce` to a placeholder and replaces it with the nonce. The default policy allows `data:` in `font-src` and `img-src` for inlined assets; `testDefaultPolicyAllowsDataFonts` covers the font case.

### H52. Add Trusted Types in Report-Only mode for apps with a JS framework
- **Practice:** On pages that run Vue or other client code, try `Content-Security-Policy-Report-Only: require-trusted-types-for 'script'` and a `trusted-types` allowlist of policy names. Fix what the reports show before enforcing.
- **Why:** Trusted Types stops strings from reaching DOM XSS sinks such as `innerHTML`. web.dev says it "complements strict CSP very well" and covers some of its limits. MDN lists `require-trusted-types-for` as Baseline "Newly available" since February 2026. Vue's security guide adds that you should never use untrusted content as a component template and should never mount Vue on nodes holding server-rendered, user-supplied content. It does not mention CSP, so whether Vue needs `'unsafe-eval'` was not confirmed from the sources read.
- **Sources:** [MDN require-trusted-types-for](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/require-trusted-types-for), [web.dev strict CSP](https://web.dev/articles/strict-csp), [Vue security guide](https://vuejs.org/guide/best-practices/security.html)
- **Stack:** `Enforced` when enabled. `csp_trusted_types.enabled` / `HELMET_CSP_TRUSTED_TYPES_ENABLED` appends `require-trusted-types-for 'script'` and a `trusted-types` allowlist to the `Content-Security-Policy-Report-Only` header only, never the enforcing one. Policy names default to `default`; `csp_trusted_types.policies` / `HELMET_CSP_TRUSTED_TYPES_POLICIES` overrides them, and an invalid name throws when the headers are built. `testBuildAppendsTrustedTypesToReportOnlyCsp`, `testBuildDoesNotAppendTrustedTypesToEnforcingCsp` and `testTrustedTypesFollowsConfig` cover it. Only JS-framework apps need it.

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
- **Stack:** `Enforced`. `camera=(), geolocation=(), microphone=(), payment=(), usb=()` is sent. `testBuildPermissionsPolicyCoversPaymentAndUsb` covers the full list.

### H12. Do not send `X-XSS-Protection`, and remove version banners HELMET can reach
- **Practice:** Leave `X-XSS-Protection` unset (or `0`). Remove `X-Powered-By`.
- **Why:** The legacy filter can add vulnerabilities, and banners help attackers fingerprint the stack.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. `X-XSS-Protection` is never set. `header_remove('X-Powered-By')` runs in `helmet_boot()` and the middleware calls `withoutHeader`. `testXPoweredByIsRemoved` covers the middleware.

### H13. Consider cross-origin isolation headers
- **Practice:** Send `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Resource-Policy: same-site` where the app does not need cross-origin embedding.
- **Why:** They keep the page out of shared browsing contexts and limit who can read its responses. `Cross-Origin-Embedder-Policy: require-corp` breaks resources that do not opt in, so test before using it. COOP also offers `same-origin-allow-popups` and `noopener-allow-popups`, which keep the page isolated but let a popup window (such as an OAuth login popup to the IdP) stay cross-origin; plain `same-origin` moves the popup into a separate browsing context group and can break the popup's `postMessage` round-trip. Prior art: one US university web security baseline (UConn) lists COOP `same-origin` and CORP `same-origin` among its required headers, next to the same Referrer-Policy and Permissions-Policy values HELMET sends. OWASP's example CORP value is `same-site`. The choice between the two is open for Citadel, where many apps share parent domains.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html), [UConn Web Security Baseline](https://security.uconn.edu/?p=4004)
- **Stack:** `Enforced`, for COOP only. `SecurityHeaders::build()` sends `Cross-Origin-Opener-Policy: same-origin` by default; `coop` / `HELMET_COOP` switches it to `same-origin-allow-popups`, `noopener-allow-popups` or `unsafe-none`, and an explicit null omits the header. CORP is opt-in: `corp` / `HELMET_CORP` sends `same-site`, `same-origin` or `cross-origin`, and nothing is sent by default. COEP is still not sent. `testBuildSendsCoopByDefault`, `testBuildOmitsCorpByDefault` and `testBuildSendsConfiguredCorp` cover it. A test against WayneUI assets and any third-party embeds is still open.

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
- **What others do:** OWASP's header sheet now recommends `max-age=63072000; includeSubDomains; preload` (two years, with preload). MDN says `preload` needs a `max-age` of at least 31536000 and `includeSubDomains`, that the preload list is run by Google's service and is not part of the HSTS spec, and that the page does not explain removal. Opposing prior art: the UConn baseline requires `max-age=31536000` and warns against `includeSubDomains` unless every subdomain is confirmed ready, using host-specific HSTS on shared or umbrella domains. HELMET's value sits between the two: one year, with `includeSubDomains`, no `preload`. That matches the "preload is the domain owner's call" rule above.
- **Sources:** [MDN HSTS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security), [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html), [UConn Web Security Baseline](https://security.uconn.edu/?p=4004)
- **Stack:** `Enforced`. `SecurityHeaders::buildHsts()` takes max-age, `includeSubDomains` and `preload` from config (`hsts` / `HELMET_HSTS_MAX_AGE`, `hsts_include_subdomains` and `hsts_preload`; raw PHP `hsts_max_age`, `hsts_include_subdomains` and `hsts_preload` options), so an app can start short, raise the age and add `preload` as the final stage. Preload throws unless max-age is at least 31536000 and `includeSubDomains` is set. `testBuildHstsPreloadRejectsShortMaxAge`, `testBuildHstsPreloadRejectsNoIncludeSubDomains` and `testBuildSendsPreloadHstsOnHttps` cover it. It still deserves thought for an app on a vanity domain, because Citadel allows vanity domains under two parent domains.

---

## 4. Proxy trust

### H18. Trust forwarded headers only from known proxy peers
- **Practice:** Read `X-Forwarded-Proto` (and `X-Forwarded-For`) only when the connection's own address, `REMOTE_ADDR`, is a configured proxy.
- **Why:** Any part of these headers not added by a proxy you control can be forged by the client.
- **Failure story:** CVE-2026-55584 (phpSysInfo, up to 3.4.5, fixed in 3.4.6, published 2026-08-28, CVSS 7.5, CWE-290). Its IP allowlist trusted the client-sent `X-Forwarded-For` and `Client-IP` headers over `REMOTE_ADDR`, so a spoofed header naming an allowed address got in. The fix added a trusted-proxy setting, which is the pattern HELMET follows. The details came from a vulnerability database (Wiz); the NVD page did not load, so the primary advisory was not read. Laravel's own docs say IP addresses should be treated as untrusted, user-controlled input and used for information only.
- **Sources:** [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For), [RFC 7239](https://www.rfc-editor.org/rfc/rfc7239), [Laravel requests](https://laravel.com/docs/12.x/requests), [CVE-2026-55584 (Wiz)](https://www.wiz.io/vulnerability-database/cve/cve-2026-55584)
- **Stack:** `Enforced`. `HttpsDetector` checks `TrustedProxies::isTrusted($remoteAddr)` first. The address matching is done by `IpUtils` from `symfony/http-foundation`. `testSpoofedForwardedProtoFromUntrustedPeerIsIgnored` covers the spoof case.

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

### H55. Tell the framework which forwarded headers to trust, not only which peers
- **Practice:** In Laravel, pass the `headers:` argument to `trustProxies` and list only what the proxy sets (`Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO`). Leave out `HEADER_X_FORWARDED_HOST` and `HEADER_X_FORWARDED_PORT`, and do not enable the standard `Forwarded` header unless the proxy sends it.
- **Why:** The Laravel 13.x docs show `trustProxies(headers: ...)` with all of FOR, HOST, PORT, PROTO and the AWS ELB constant as the example, which is wider than a single HA-Proxy needs. Symfony's proxy docs (Symfony's request class is the base of Laravel's) warn: "Enabling the `Request::HEADER_X_FORWARDED_HOST` option exposes the application to HTTP Host header attacks. Make sure the proxy really sends an `x-forwarded-host` header." This is H20 applied to the framework.
- **Sources:** [Laravel requests 13.x](https://laravel.com/docs/13.x/requests), [Symfony: How to Configure Symfony to Work behind a Load Balancer or a Reverse Proxy](https://symfony.com/doc/current/deployment/proxies.html)
- **Stack:** `Not yet`. The Laravel adapter is not built. When it is, it should set both `at:` and `headers:` from HELMET's own config, so the two paths agree.

---

## 5. Sessions

### H26. Harden the session settings before `session_start()`
- **Practice:** Set `use_strict_mode=1`, `use_only_cookies=1`, `cookie_httponly=1` and `cookie_samesite=Lax` (or `Strict`) before the session starts.
- **Why:** Strict mode rejects session IDs the server did not issue, which blocks fixation. The cookie flags limit script access and cross-site use.
- **Sources:** [PHP session security ini](https://www.php.net/manual/en/session.security.ini.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [PHP ini list](https://www.php.net/manual/en/ini.list.php)
- **Failure stories:** Two 2026 session fixation reports show what the settings guard against. CVE-2026-33492 (AVideo, published 2026-03-23, CVSS 7.3): the session start function accepted a session ID from the `PHPSESSID` GET parameter and regeneration was skipped on some paths, so an attacker could fix a victim's ID before login (read on OSV, which links the GitHub advisory GHSA-x3pr-vrhq-vq43; the advisory itself was not opened). CVE-2026-11335 (a student-project app) was listed with `session.use_strict_mode = 1` as the hardening step (search summary only). `use_only_cookies=1` blocks the first kind of flaw for code that uses PHP's own session handling.
- **PHP 8.4 note:** The PHP 8.4 deprecations page says "changing the value" of `session.use_only_cookies` is deprecated. The php-src `PHP-8.4` source shows the notice fires only when the setting is turned off ("Disabling session.use_only_cookies INI setting is deprecated"). HELMET sets it to `1`, so it raises no deprecation on 8.4. The reviewer did not run it on 8.4 or 8.5.
- **Stack:** `Enforced`. `SessionHardening::apply()` sets all four, and `helmet_boot()` calls it. All four are runtime-settable. `testSetsHardenedIniValuesWhenHttps` covers it. The tests run on 8.4 and 8.2 per the README. They are not run on 8.5, which php.net lists as released on 2025-11-20 (see H57).

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
- **Why:** This is what stops fixation and limits a stolen ID. OWASP gives example timeouts: idle 2 to 5 minutes for high-value apps and 15 to 30 minutes for low-risk ones, and an absolute limit of roughly 4 to 8 hours for an office-day session. NIST SP 800-63B-4 (the session management page) says a session ends when either the overall or the inactivity timeout expires, that the relying party must reauthenticate periodically, and that secrets are erased or invalidated at logout. Its exact limits depend on the assurance level and sit in other sections that were not read. Vendor blogs disagree with each other on the numbers (12 hours or 24 hours, 30 minutes or 1 hour), so do not copy a figure from one until the NIST section is read.
- **Sources:** [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [NIST SP 800-63B-4 session management](https://pages.nist.gov/800-63-4/sp800-63b/session/)
- **Stack:** `Documented`. The auth library's design (SESAME) says it always regenerates the ID on login and invalidates first on logout. That library is not built. HELMET does none of it.

### H30. Use a host-only cookie with a generic name
- **Practice:** Do not set a cookie `Domain`. Prefer the `__Host-` prefix (needs `Secure`, `Path=/`, no `Domain`). Avoid a framework-revealing name such as `PHPSESSID`.
- **Why:** A host-only cookie is not sent to sibling subdomains. A generic name reveals less. NIST SP 800-63B-4 now says session cookies SHALL be limited to the minimum practical hostnames and paths, and SHOULD be HttpOnly, use the `__Host-` prefix with `Path=/`, and set SameSite to Lax or Strict. The `__Host-` prefix needs `Secure`, which on Citadel depends on the real scheme (H27), so the prefix fits only requests that arrive as HTTPS.
- **Sources:** [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [NIST SP 800-63B-4 session management](https://pages.nist.gov/800-63-4/sp800-63b/session/)
- **Stack:** `Enforced`. `SessionHardening::apply()` sets an empty `cookie_domain`, `cookie_path=/` and the name `__Host-WSUSESSID` on HTTPS or `WSUSESSID` on HTTP, but only while the name is still the PHP default `PHPSESSID`; an app-chosen name is left alone. Covered by the name tests in `tests/Session/SessionHardeningTest.php`.

### H31. Set session lifetime, URL IDs and caching explicitly
- **Practice:** Use `session.cookie_lifetime=0`, keep session IDs out of URLs, and use `session.cache_limiter=nocache` for authenticated pages. Track session lifetime in your own code, since `gc_maxlifetime` is not reliable for expiry.
- **Why:** Each one closes a way for a session ID or an authenticated page to outlive its use. NIST SP 800-63B-4 backs the last point: expiry settings SHALL NOT be relied on to enforce session timeouts.
- **Sources:** [PHP session security ini](https://www.php.net/manual/en/session.security.ini.php), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [NIST SP 800-63B-4 session management](https://pages.nist.gov/800-63-4/sp800-63b/session/)
- **Stack:** `Enforced`. `SessionHardening::apply()` sets `cookie_lifetime=0` and `cache_limiter=nocache` (on every page, which covers the authenticated ones), and `use_only_cookies=1` keeps session IDs out of URLs. Tracking session lifetime in app code remains the app's own job.

### H32. Leave `session.sid_length` alone
- **Practice:** Do not change `session.sid_length` or `session.sid_bits_per_character`.
- **Why:** PHP 8.4 deprecates changing them, and the storage backend should accept the default 32-character IDs.
- **Sources:** [PHP 8.4 deprecations](https://www.php.net/manual/en/migration84.deprecated.php)
- **Stack:** `Documented`. The `SessionHardening` docblock and `testSidLengthIsNotTouched` keep HELMET off these settings. The Citadel `php.ini` sets `sid_length=26`, which is routed to the upgrade work. Size check: OWASP asks for at least 64 bits of entropy in a session ID. Twenty-six characters at PHP's default 4 bits per character is 104 bits (this assumes the platform leaves `sid_bits_per_character` at its default, which was not checked). So the setting is hygiene for the 8.4 deprecation, not an exposure.

### H53. Clear browser data on logout
- **Practice:** Send `Clear-Site-Data` on the logout confirmation response, for example `Clear-Site-Data: "cache", "cookies", "storage", "executionContexts", "prefetchCache", "prerenderCache"`, after destroying the server-side session.
- **Why:** The server session is gone, but cached pages, prefetched content and local storage stay on a shared lab or library machine. OWASP's session sheet recommends the header at logout, and warns to use the `"cache"` value with care. MDN lists it as widely available since September 2023 and shows the same logout example.
- **Sources:** [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [MDN Clear-Site-Data](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Clear-Site-Data)
- **Stack:** `Not yet`. HELMET has no logout handling. The design puts login and logout in the unbuilt auth library (SESAME), so this belongs there, with HELMET offering at most a helper.

### H54. Add real CSRF defence next to `SameSite`
- **Practice:** Protect every state-changing request (POST, PUT, PATCH, DELETE) with a server-checked token, or with Fetch Metadata (`Sec-Fetch-Site`) plus an `Origin` check, or both. Treat `SameSite=Lax` as a second layer only.
- **Why:** OWASP's CSRF sheet calls SameSite "useful as a defense-in-depth control" that does not replace a real defence in most deployments. `Lax` still sends cookies on top-level GET navigations, it works at the registrable-domain level (so sibling subdomains count as same-site), and it does not stop client-side CSRF. The same sheet calls `Sec-Fetch-Site` the primary signal for modern browsers and says an `Origin`/`Referer` check is a mandatory fallback. Laravel 13.x does both in `PreventRequestForgery` (in the `web` group by default): it checks `Sec-Fetch-Site` first and falls back to token validation. Its docs note browsers send that header only over HTTPS, and the middleware exposes `originOnly` (check the `Origin` header instead of `Sec-Fetch-Site`) and `allowSameSite` (also trust `same-site` requests, not just `same-origin`) options. The raw PHP paths have no equivalent unless the app writes one.
- **Sources:** [OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html), [Laravel 13.x CSRF](https://laravel.com/docs/13.x/csrf)
- **Stack:** `Enforced` when enabled. HELMET sets `SameSite=Lax` (`Enforced`, see H26) and now also provides an opt-in request-forgery guard: `helmet_boot(['csrf' => ['enabled' => true]])`, the PSR-15 `RequestForgeryGuard`, and the Laravel `RequestForgeryGuard` middleware when `helmet.csrf.enabled` is true. It checks `Sec-Fetch-Site` first and falls back to `Origin` against the request's own origin and `csrf.trusted_origins`; `GET`, `HEAD` and `OPTIONS` always pass, and a rejected state-changing request receives `403 Forbidden`. It is off by default, so an app that does not enable it still needs its own CSRF defence.

---

## 6. Health check

### H33. Make the liveness check framework-free and dependency-free
- **Practice:** Answer `/up` with a 200 before routing, auth, the session or the database is involved. Liveness should only say "this process can answer".
- **Why:** If the check depends on a database, a database outage restarts every healthy app at once. Citadel marks an app that keeps restarting as Failing and stops it. The Kubernetes docs say liveness probes must be configured carefully to indicate unrecoverable failure such as a deadlock, and that a wrong one "can lead to cascading failures": restarts under high load, failed client requests and more load on the remaining pods. A well-known write-up (Sandor Szucs, "Liveness Probes are Dangerous") gives the database case: when the probe needs a working database connection, every pod reads as down during a database or network fault. Laravel's built-in `/up` is different from HELMET's. It returns 200 only if the framework booted without exceptions, so it needs the whole framework to load.
- **Sources:** [Kubernetes probes](https://kubernetes.io/docs/concepts/configuration/liveness-readiness-startup-probes/), [Laravel deployment](https://laravel.com/docs/12.x/deployment), [Docker Dockerfile reference](https://docs.docker.com/reference/dockerfile/), [Liveness Probes are Dangerous](https://srcco.de/posts/kubernetes-liveness-probes-are-dangerous.html)
- **Stack:** `Enforced`. `HealthCheck` and `helmet_boot()` answer `GET` and `HEAD` on `/up` with `200 ok` and touch nothing else; both share `helmet_up_response()`, and `helmet_up()` sends the same answer and exits for a raw PHP app with no front controller. `testGetHealthPathReturnsOk`, `testUpResponseReturnsStatusHeadersAndBody` and `testUpFunctionAnswersOkAndExits` cover it.

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
- **Stack:** `Enforced`, for several settings. `helmet_boot()` throws if `trusted_proxies` is not an array, and it also rejects a non-array `csp_trusted_types` or `no_store`, a non-bool `csp_trusted_types.enabled` or `no_store.enabled`, a non-list of policies or paths, a non-array `csrf`, an invalid `coop` or `corp` value, and `hsts_preload` with a max-age under 31536000 or without `includeSubDomains`. `testBootThrowsOnInvalidCoop`, `testBootThrowsOnInvalidTrustedTypesConfig`, `testBootThrowsOnInvalidNoStoreConfig` and `testBootHstsPreloadRejectsShortMaxAge` cover the new checks.

### H45. Keep config out of code and secrets out of git
- **Practice:** Read config from the environment or from files that are not committed. In Laravel, call `env()` only inside `config/` files, because it returns `null` once config is cached. Do not commit `.env`.
- **Why:** Config changes per deploy, and a repository leak should not expose credentials.
- **Sources:** [Twelve-Factor config](https://12factor.net/config), [Laravel configuration](https://laravel.com/docs/12.x/configuration), [Laravel deployment](https://laravel.com/docs/12.x/deployment)
- **Stack:** `Not yet`. The handbook chapter on the runtime says no rules are decided yet. A current WSU Laravel app follows this practice by its own convention.

### H46. Show the environment in non-production, never in production
- **Practice:** Add a visible ribbon on dev and test, and treat an unknown environment as production.
- **Why:** It prevents people from mistaking test data for live data, and a wrong guess must not reveal itself on a live site.
- **Sources:** [Twelve-Factor config](https://12factor.net/config)
- **Stack:** `Enforced`. The Laravel adapter's `InjectRibbon` middleware injects a `helmet-ribbon` div after the opening `<body>` tag in non-production environments (`local`, `development`, `dev`, `testing`, `test`, `staging`), never in production or for an unknown environment, and only when `helmet.ribbon` is enabled. `testRibbonIsInjectedAfterBodyInLocal`, `testRibbonIsAbsentInProduction` and `testRibbonIsAbsentWhenConfigIsDisabled` cover it.

### H47. Run on a supported PHP version, and warn when support is ending
- **Practice:** Use a PHP version that still gets security fixes. PHP 8.2 reaches end of security support on 2026-12-31. 8.3 follows on 2027-12-31.
- **Why:** An unsupported version gets no security patches. The dates were re-checked against php.net's supported-versions page (dated 2026-10-27), and they match: 8.2 active support ended 2024-12-31 and security support ends 2026-12-31; 8.3 active support ended 2025-12-31 and security ends 2027-12-31; 8.4 (released 2024-11-21) has active support to 2026-12-31 and security to 2028-12-31; 8.5 (released 2025-11-20) has active support to 2027-12-31 and security to 2029-12-31. So 8.3 is already in security-only support, and 8.4 enters it on 2027-01-01. 8.1 reached end of life on 2025-12-31 (from a secondary page, not php.net).
- **Sources:** [endoflife.date PHP](https://endoflife.date/php), [php.net supported versions](https://www.php.net/supported-versions.php)
- **Stack:** `Checked`. PROCTOR's `php-floor` check fails an app whose lowest allowed PHP version is past support, using the policy file. HELMET's own runtime version warning is wired into the environment ribbon: when `helmet.policy_path` (raw PHP `helmet_ribbon()`'s third argument) points at a CREED `policy.json`, the ribbon shows a warning line for each component version that is in its security-only phase or end of life (`src/Ribbon/Ribbon.php`, `src/Policy/VersionWarning.php`). `RibbonTest` covers the active, security-only, end-of-life, no-policy-path and broken-file cases, and `InjectRibbonTest` covers the Laravel wiring. HELMET's `composer.json` requires `^8.3` (platform `8.3.0`), so the floor is already at 8.3.

---

## 10. Packaging and consumers

### H48. Keep one definition for every consumer path
- **Practice:** Build the headers from one function used by the raw PHP boot path and the middleware.
- **Why:** Two copies drift, and one path ends up weaker.
- **Sources:** [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
- **Stack:** `Enforced`. Both paths call `SecurityHeaders::build()`. `testDefaultPolicyMatchesSharedDefault` checks the default policy.

### H49. Give Laravel the same proxy list and the same headers
- **Practice:** In Laravel, set `trustProxies(at: ...)` from the same list HELMET uses. Do not use `*`.
- **Why:** Two lists disagree, and `*` trusts any peer. On Citadel's flat network, that includes other apps' containers. The Laravel 13.x requests page was read in the second pass. Its trusted-proxy text matches 12.x, including the `*` option, which it offers for cloud balancers whose addresses you cannot know. Citadel's proxy address can be known, so `*` has no excuse here.
- **Sources:** [Laravel requests 12.x](https://laravel.com/docs/12.x/requests), [Laravel requests 13.x](https://laravel.com/docs/13.x/requests)
- **Stack:** `Enforced`. The Laravel adapter's `HelmetServiceProvider` copies `helmet.proxies` to `trustedproxy.proxies` when the app has not set it, and narrows the trusted headers to `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Port` (the same set as `Presets::TRUSTED_HEADERS`). The `SecurityHeaders` middleware builds the headers from the core `SecurityHeaders::build()`, so the two consumer paths agree. `testTrustedProxyProxiesFollowHelmetProxiesWhenTheAppHasNotSetThem`, `testRequestIsSecureBehindTheProxyHelmetTrusted`, `testProviderNarrowsTheTrustedHeaderSet` and `testForwardedHostIsNotTrusted` cover it.

### H50. Lock dependencies and audit them
- **Practice:** Commit `composer.lock`, bound every runtime constraint, and run `composer audit --locked` in CI and at release.
- **Why:** A lock file is what makes a release repeatable, and an audit finds known advisories.
- **Sources:** [Composer CLI](https://getcomposer.org/doc/03-cli.md)
- **Stack:** `Checked`. PROCTOR's `lock-committed`, `unbounded-constraints` and `composer-audit` checks do this for any app that runs PROCTOR. HELMET's own repository commits `composer.lock` and its `.gitlab-ci.yml` includes the PREFLIGHT `composer-audit` component, pinned to a commit. The pipeline run itself was not seen. The second pass searched for Monolog advisories from 2025 and 2026 and found none, but that search returned only generic vulnerability-database pages, so treat "none found" as weak. Run the audit and do not rely on this line.

### H56. Set the Composer audit policy on purpose
- **Practice:** Decide in `composer.json` how `composer audit` treats known advisories and abandoned packages, and keep the same settings in CI. Know the defaults: the current Composer config docs show `block-insecure` defaulting to `true` (updates to versions with known advisories are blocked), `block-abandoned` to `false`, and `abandoned` to `fail` since Composer 2.7. Those `audit` keys are now marked deprecated in favour of a new `config.policy` block (advisories, malware, abandoned packages), and the old keys are read only as a fallback.
- **Why:** An audit that passes on one machine and fails on another is a policy nobody chose. A package that is abandoned stops getting fixes, which matters most for a baseline library every app installs. Composer's config docs are the source here. The version that introduced `config.policy` was not found in what was read, so check the Composer version in the Citadel build image before writing the policy.
- **Sources:** [Composer config (audit, policy)](https://getcomposer.org/doc/06-config.md)
- **Stack:** `Not yet`. HELMET's `composer.json` sets no `audit` or `policy` config. The PREFLIGHT component's flags were not read.

### H57. Run the tests on every supported PHP version, and no unsupported one
- **Practice:** Test on each PHP branch the stack supports and drop a branch the day it loses security support. Today that is 8.3, 8.4 and 8.5, with 8.2 only until 2026-12-31.
- **Why:** The stack allows only non-EOL PHP and Citadel offers 8.4 and 8.5 images. A library tested on one version can break on another through a deprecation or a changed default. Session deprecations are one example (H26, H32).
- **Sources:** [php.net supported versions](https://www.php.net/supported-versions.php), [PHP 8.4 deprecations](https://www.php.net/manual/en/migration84.deprecated.php)
- **Stack:** `Checked`. The CI matrix (`.gitlab-ci.yml` and `.github/workflows/ci.yml`) tests on 8.3, 8.4 and 8.5, the three supported branches. The README's Docker commands cover 8.4 and 8.3; 8.5 is missing from the manual path. `composer.json` requires `^8.3` (gap 13 is done).

### H58. Scan for committed secrets in CI
- **Practice:** Run secret detection on every pipeline for every project in the stack, in addition to the dependency audit.
- **Why:** H45 says to keep secrets out of git. A scanner is how you find the ones that got in anyway. GitLab's secret detection docs list it for the Free, Premium and Ultimate tiers. The same docs say Dependency Scanning is Ultimate-tier, so on a lower tier `composer audit` is the dependency check. WSU's GitLab tier was not checked.
- **Sources:** [GitLab Secret Detection](https://docs.gitlab.com/user/application_security/secret_detection/), [GitLab Dependency Scanning](https://docs.gitlab.com/user/application_security/dependency_scanning/)
- **Stack:** `Not yet`. HELMET's pipeline includes `php-test` and `composer-audit` only. Whether PREFLIGHT offers a secret-detection component was not checked.

---

## 11. Metrics

### H59. Expose Prometheus metrics behind authentication, off by default
- **Practice:** Offer a `/metrics` endpoint in Prometheus exposition format (`text/plain; version=0.0.4`), disabled by default. When enabled, require a bearer token or an IP allowlist before serving data. Keep labels low-cardinality and free of personal data.
- **Why:** A metrics endpoint that is open by default leaks version numbers, uptime and request counts to anyone who finds the URL. The Prometheus exposition format is the de facto standard for PHP scrapers. The stack's metrics rule requires Prometheus format only, off by default, protected by a token or allowlist, with no personal data in labels.
- **Sources:** [Prometheus exposition format](https://prometheus.io/docs/instrumenting/exposition_formats/)
- **Stack:** `Enforced`. `Metrics::render()` produces the exposition format. `MetricsGate` returns `404` when disabled, `403` when unauthorized, and `404` plus a logged warning when enabled with neither token nor allowlist. The Laravel route is opt-in via `Route::helmetMetrics()`. The raw PHP helper `helmet_metrics()` applies the same gate. `MetricsGateTest`, `MetricsTest`, `FunctionsMetricsTest` and `Laravel\MetricsTest` cover the decision matrix.

---

## Gaps for the backlog

### Problems in the current code

Each of these follows from the research above. Items marked "inferred" depend on how Citadel's network works and need confirming.

1. Done. `'unsafe-inline'` was narrowed to `style-src-attr` only (not `style-src-elem`), and the exception is documented in `defaultCspPolicy()`: WayneUI's static path inlines a few style attributes, which `style-src-attr` governs, while `<style>` tags (`style-src-elem`) do not need it.
2. Done. `defaultCspPolicy()` now sets `base-uri 'self'`, `object-src 'none'` and `form-action 'self'` (`src/Headers/SecurityHeaders.php:56-58`); `testDefaultPolicyIncludesHardeningDirectives` covers it.
3. Done. `buildCsp()` calls `setStrictDynamic('script-src', true)` (`src/Headers/SecurityHeaders.php:113`), so the nonce is paired with `strict-dynamic`; `testBuildCspAddsStrictDynamicAlongsideNonce` covers it.
4. `src/functions.php:47-49`: `helmet_boot()` silently skips session hardening when a session is already active, which contradicts the README line "It refuses to run once a session is active" (`README.md:104`). Log it or throw.
5. `src/Session/SessionHardening.php:36-44`: `ini_set` results are not checked, so a refused setting goes unnoticed.
6. `src/Proxy/TrustedProxies.php:92-94`: a `/0` range is accepted and trusts every IPv4 address. Reject prefixes shorter than a chosen minimum.
7. `src/Proxy/TrustedProxies.php:34` and `:41`: invalid exact entries (`*`, IPv6, typos) are stored or skipped without error. Validate them with `filter_var` and throw.
8. Done: the README examples use the exact address `172.18.0.1` and warn against broad ranges such as `10.0.0.0/8`. That is the Docker bridge gateway the `citadel` preset trusts; on other hosts the address is a setting.
9. `src/Proxy/HttpsDetector.php:47-50`: exact comparison of a possibly multi-value header. Take the last element.
10. Done. Both `helmet_boot()` (`src/functions.php:112`) and the `SecurityHeadersMiddleware` constructor (`src/Headers/SecurityHeadersMiddleware.php:64`) call `SecurityHeaders::mergePolicies()`, so a custom `csp` is merged over the default rather than replacing it; `testMergePoliciesReplacesDirectiveAndKeepsTheRest` and `testMergePoliciesRemovesDirectiveWhenNull` cover it.
11. Done: `akrabat/ip-address-middleware` is no longer in the `require` list, so there is nothing unused to remove.
12. `src/Logging/Logging.php:27-37`: no redaction, no processors, no failure-safe handler.
13. Done: `composer.json` now requires `php: ^8.3` (platform `8.3.0`), so the floor is already at 8.3.

### Missing features

14. Report-Only mode and a CSP report endpoint.
15. Configurable HSTS `max-age` and `includeSubDomains`, with a staged rollout note.
16. Done. `build()` sends `Permissions-Policy` with `payment=()` and `usb=()` (`src/Headers/SecurityHeaders.php:239`); `testBuildPermissionsPolicyCoversPaymentAndUsb` covers it.
17. COOP and CORP headers, tested against WayneUI assets.
18. A client-IP function that walks the forwarded list from the right.
19. A readiness endpoint, separate from `/up`.
20. A global error handler that logs details and shows a generic page.
21. Request ID and context processors, and a redacting formatter.
22. A test for `Logging::create()` and for `helmet_boot()` itself.
23. Done. The version warning is wired into the environment ribbon: when a policy file is configured (`helmet.policy_path` / `HELMET_POLICY_PATH`, or `helmet_ribbon()`'s third argument), the ribbon shows a warning line for each component version that is in its security-only phase or end of life (`src/Ribbon/Ribbon.php`, `src/Policy/VersionWarning.php`). `RibbonTest` covers the active, security-only, end-of-life, no-policy-path and broken-file cases, and `InjectRibbonTest` covers the Laravel wiring.
24. A proven CI run. The `.gitlab-ci.yml` exists (PREFLIGHT `php-test` and `composer-audit`, pinned to a commit), but git.wayne.edu has no runners today. Get one pipeline run on record, add PHP 8.5 and 8.3 to the test matrix, and add secret detection.

### Needs Citadel

25. `expose_php=Off`, `ServerTokens Prod` and `TraceEnable Off` (SQUIRE upstream work).
26. A network fix so app containers are reachable only through the proxy.
27. Written rules in the handbook for runtime and headers, which today states none.

### Added in the second pass

Items 28 to 37 continue the numbering above.

28. Done. The Laravel middleware passes the nonce to Vite via `Vite::useCspNonce()` (`src/Laravel/SecurityHeaders.php:86`) and sets the `helmet.csp_nonce` request attribute; the default policy allows `data:` in `font-src` and `img-src` (`src/Headers/SecurityHeaders.php:53-54`); the raw PHP path prints the `csp-nonce` meta tag via `helmet_csp_nonce_meta()` (`src/functions.php:147`), and H51 documents the `html.cspNonce` placeholder swap.
29. Trusted Types in Report-Only mode for JS-framework apps (H52), after Report-Only mode exists (item 14).
30. CSP reporting through `Reporting-Endpoints` plus `report-to`, with `report-uri` as the fallback, and a report endpoint that accepts `application/reports+json` (H7, item 14).
31. A `Clear-Site-Data` logout helper, or a note for the auth library (H53).
32. Done. The opt-in request-forgery guard gives the raw PHP, PSR-15 and Laravel paths an `Origin` and `Sec-Fetch-Site` check (H54): `helmet_boot()` accepts a `csrf` option, `RequestForgeryGuard` is the PSR-15 middleware, and the Laravel adapter registers it when `helmet.csrf.enabled` is true.
33. Laravel adapter: set `trustProxies(headers: ...)` to FOR and PROTO only, from HELMET's config (H55).
34. Explicit Composer audit and policy settings in `composer.json` and CI (H56).
35. Done. `csp-builder` support is confirmed: `buildCsp()` calls `setStrictDynamic('script-src', true)` (`src/Headers/SecurityHeaders.php:113`), and `testBuildCspAddsStrictDynamicAlongsideNonce` covers it.
36. `Cache-Control: no-store` (or `private`) on pages that carry a nonce and on authenticated pages (H8, H31).
37. `src/Proxy/TrustedProxies.php` is IPv4 only by design (its docblock). An IPv6 range throws at boot. An exact IPv6 address is stored as a plain string and matches only by identical text, with no normalisation (`::1` and `0:0:0:0:0:0:0:1` differ). If the Citadel proxy ever reached an app over IPv6, requests could silently read as plain HTTP: no HSTS and no `Secure` flag. Add IPv6 support, or reject IPv6 entries with a clear message (see item 7).

---

## Sources

All pages were read on 2026-10-27. Laravel sources 13 to 15 are the 12.x versions. In the second pass the 13.x requests and CSRF pages were read (sources 31 and 32). The 13.x deployment, configuration and Vite pages were not read. Web search ran out of budget during the second pass, so several leads rest on search summaries and are marked as such where used.

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

Added in the second pass, all checked 2026-10-27. Items marked "(summary only)" were seen in a search summary and the page itself was not opened.

26. W3C, Content Security Policy Level 3 (Editor's Draft, section 7.1 Nonce Reuse), checked 2026-10-27: https://w3c.github.io/webappsec-csp/
27. php.net, Supported Versions, checked 2026-10-27: https://www.php.net/supported-versions.php
28. php-src, ext/session/session.c on branch PHP-8.4 (use_only_cookies deprecation logic), checked 2026-10-27: https://github.com/php/php-src/blob/PHP-8.4/ext/session/session.c
29. NIST SP 800-63B-4, Session Management, checked 2026-10-27: https://pages.nist.gov/800-63-4/sp800-63b/session/
30. OWASP, Cross-Site Request Forgery Prevention Cheat Sheet, checked 2026-10-27: https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
31. Laravel 13.x, HTTP Requests (trusted proxies), checked 2026-10-27: https://laravel.com/docs/13.x/requests
32. Laravel 13.x, CSRF Protection (PreventRequestForgery, Sec-Fetch-Site), checked 2026-10-27: https://laravel.com/docs/13.x/csrf
33. Laravel 12.x, Asset Bundling (Vite), CSP nonce section, checked 2026-10-27: https://laravel.com/docs/12.x/vite
34. Vite, Features guide (Content Security Policy), checked 2026-10-27: https://vite.dev/guide/features.html
35. Vite, Shared Options (html.cspNonce), checked 2026-10-27: https://vite.dev/config/shared-options.html
36. MDN, Reporting-Endpoints header (Baseline 2024, since September 2024), checked 2026-10-27: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Reporting-Endpoints
37. MDN, CSP require-trusted-types-for (Baseline 2026, since February 2026), checked 2026-10-27: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/require-trusted-types-for
38. MDN, Clear-Site-Data, checked 2026-10-27: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Clear-Site-Data
39. Vue.js, Security best practices, checked 2026-10-27: https://vuejs.org/guide/best-practices/security.html
40. Symfony, Reverse proxy and load balancer configuration, checked 2026-10-27: https://symfony.com/doc/current/deployment/proxies.html
41. Composer, Config (audit and policy), checked 2026-10-27: https://getcomposer.org/doc/06-config.md
42. GitLab, Secret Detection and Dependency Scanning (tiers), checked 2026-10-27: https://docs.gitlab.com/user/application_security/secret_detection/ and https://docs.gitlab.com/user/application_security/dependency_scanning/
43. UConn, Web Security Baseline (prior art at a university), checked 2026-10-27: https://security.uconn.edu/?p=4004
44. Szucs, Liveness Probes are Dangerous (failure story), checked 2026-10-27: https://srcco.de/posts/kubernetes-liveness-probes-are-dangerous.html
45. Wiz vulnerability database, CVE-2026-55584 (phpSysInfo, forwarded-header trust), checked 2026-10-27: https://www.wiz.io/vulnerability-database/cve/cve-2026-55584
46. OSV, CVE-2026-33492 (AVideo session fixation), checked 2026-10-27: https://osv.dev/vulnerability/CVE-2026-33492
47. Spagnuolo and Janc, CSP Is Dead, Long Live CSP! (summary only), checked 2026-10-27: https://research.google/pubs/pub45542/

---

## Second pass (2026-10-27)

A second reviewer re-checked the first pass against primary sources and the code on main, and searched for what the first pass missed. Web search ran out of budget partway, so the later checks used page fetches only.

### Added

- Eight practices, all tagged `Not yet`: H51 (one nonce shared with the Vite bundler), H52 (Trusted Types in Report-Only mode), H53 (`Clear-Site-Data` on logout), H54 (CSRF defence beside `SameSite`), H55 (restrict the forwarded headers Laravel trusts), H56 (set the Composer audit policy), H57 (test on every supported PHP version), H58 (secret detection in CI).
- Extra evidence on existing practices, with no tag change: prior art from one US university baseline (H13, H17), OWASP's current HSTS value (H17), failure stories for forwarded-header trust (H18), session fixation (H26) and liveness probes (H33), NIST SP 800-63B-4 session rules (H29, H30, H31), the session ID size check (H32), and a re-check of the PHP support dates (H47).
- Ten backlog items, numbered 28 to 37, and a rewrite of item 24.
- Twenty-two sources, numbered 26 to 47.

### Corrected

- **CI status.** The front matter said the repository had no CI configuration. It has a `.gitlab-ci.yml` with the PREFLIGHT `php-test` and `composer-audit` components. Whether it has run is not verified, so the claim now says that.
- **Nonce size source (H1).** The 128-bit figure was credited to MDN, web.dev and OWASP. The pages read do not state it. The W3C CSP Level 3 spec does (SHOULD be at least 128 bits, MUST be unique per policy sent). The code's 144-bit nonce still meets it.
- **Reporting (H7).** The practice said `report-to` with `report-uri` as a fallback, without the `Reporting-Endpoints` header that `report-to` needs. That header is now in the practice. Also, the `csp-builder` README does show `report-only`, `report-uri`, `report-to`, `base-uri` and `form-action` config keys, so the library is not the blocker the first pass implied.
- **Session ini note (H26).** The PHP 8.4 manual says "changing" `session.use_only_cookies` is deprecated. The php-src source shows the notice fires only when the setting is disabled. HELMET sets `1`, so it is not affected. The doc now says so.
- **Line references.** `composer.json` line numbers in gap items 11 and 13 were stale (`:9` and `:6`, now `:13` and `:12`).
- **Sources note.** It said the Laravel 13.x pages were not read. The 13.x requests and CSRF pages were read, and the trusted-proxy text matches 12.x.

### Verified and left alone

The nonce quote from MDN, the PHP 8.4 `sid_length` and `sid_bits_per_character` deprecation, the PHP 8.2 and 8.3 end-of-support dates, Laravel's `env()` and `DiagnosingHealth` notes, the Kubernetes liveness warning, OWASP's `X-XSS-Protection` and `Referrer-Policy` advice, and the line references for gap items 1 to 10 and 12.

### Not verified

- Whether `csp-builder` can emit `strict-dynamic`, or the `Reporting-Endpoints` header, was not tested.
- No Monolog or `paragonie/csp-builder` advisory was found, but the search was weak, so that is not a clean bill.
- The primary advisories for CVE-2026-55584 and CVE-2026-33492 were not opened. The CVE-2026-11335 detail is from a search summary only.
- Which PHP versions the PREFLIGHT `php-test` component runs, and whether PREFLIGHT has a secret-detection component, were not read.
- The 2016 Google CSP study figure (94.72%) came from a search summary of the paper.
- No code was run. PHP is not installed here, and the tests were not executed on 8.4 or 8.5.

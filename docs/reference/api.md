---
title: API
description: The public entry points of the core, from helmet_boot() to helmet_metrics(), plus the middleware and guard around them.
---

# API reference

Every public entry point of the `waynestate/helmet` core, with signatures exactly as they appear in the code under `src/`. For what each part does to a request, see the usage guides; for the environment variables behind the Laravel adapter, see [Environment variables](configuration.md).

## Global functions

File `src/functions.php`, namespace `Waynestate\Helmet`:

| Function | Signature |
| --- | --- |
| `helmet_boot()` | `function helmet_boot(array $config): array` |
| `helmet_up_response()` | `function helmet_up_response(): array` |
| `helmet_up()` | `function helmet_up(): never` |
| `helmet_ready()` | `function helmet_ready(Readiness $readiness): never` |
| `helmet_csp_report_response()` | `function helmet_csp_report_response(?LoggerInterface $logger = null, ?RequestMetrics $metrics = null, ?string $body = null): array` |
| `helmet_csp_report()` | `function helmet_csp_report(?LoggerInterface $logger = null, ?RequestMetrics $metrics = null): never` |
| `helmet_forbidden_response()` | `function helmet_forbidden_response(): array` |
| `helmet_forbidden()` | `function helmet_forbidden(): never` |
| `helmet_client_error_response()` | `function helmet_client_error_response(int $status): array` |
| `helmet_client_error()` | `function helmet_client_error(int $status): never` |
| `helmet_csp_nonce_meta()` | `function helmet_csp_nonce_meta(string $nonce): string` |
| `helmet_csp_nonce_swap()` | `function helmet_csp_nonce_swap(string $html, string $nonce, string $placeholder = 'CSP_NONCE_SLOT_FOR_HELMET'): string` |
| `helmet_metrics()` | `function helmet_metrics(array $config): array` |
| `helmet_metrics_env()` | `function helmet_metrics_env(): array` |
| `helmet_metrics_requests()` | `function helmet_metrics_requests(array $config): ?RequestMetrics` |
| `helmet_metrics_track()` | `function helmet_metrics_track(array $config): RequestTimer` |
| `helmet_ribbon()` | `function helmet_ribbon(string $environment, bool $enabled = true, ?string $policyPath = null): \Waynestate\Helmet\Ribbon\Ribbon` |

What each one returns, from the docblocks:

- `helmet_boot()` boots the core for raw PHP: it validates the config and returns `array{nonce: string, logger: LoggerInterface|null, request_id: string}`. It throws `UnknownPresetException` for an unknown preset, `\InvalidArgumentException` for a bad config value, `InvalidCspReportUriException` for a bad report URI, and `\LogicException` if a session is already active.
- `helmet_up_response()` and `helmet_forbidden_response()` return the answer as data, `array{status: int, headers: array<string, string>, body: string}`, without sending anything; the caller sends it. `helmet_up()` and `helmet_forbidden()` send the same answers (200, body "ok", and 403, body "Forbidden") and exit, so a small public file can answer a probe that never reaches `helmet_boot()`.
- `helmet_client_error_response()` returns the fixed 4xx page for a client fault as data, exactly what `GenericErrorResponse::clientError()` builds (the status, `Content-Type: text/html; charset=utf-8`, `Cache-Control: no-store`, a fixed body), and `helmet_client_error()` sends it and exits. Both take only the status code, and both throw `\InvalidArgumentException` for a status outside 400 to 499 before anything is built or sent.
- `helmet_ready()` sends a `Readiness` report (200 or 503, JSON, `no-store`) and exits, for a `public/ready.php` with no front controller. `helmet_boot()` calls it on the readiness path when the `readiness` option is enabled. See [Readiness](../guides/readiness.md).
- `helmet_csp_report_response()` answers a CSP violation report as data, `array{status: int, headers: array<string, string>, body: string}`, from `$_SERVER` and the request body (capped at 16 KiB plus one byte): `204` for a report it accepts, `415`, `413` or `400` for one it refuses, and `405` with `Allow: POST` for any other method. It logs one warning per violation through the optional logger and counts it in the optional `RequestMetrics`; a logger or store that throws never changes the answer. `helmet_csp_report()` sends the same answer and exits, for a `public/csp-report.php` that never calls `helmet_boot()`. Both go through `Headers\CspReport`, as the Laravel route does. See [Customizing the CSP](../guides/csp.md#receiving-the-reports-in-raw-php).
- `helmet_csp_nonce_meta()` builds the `<meta property="csp-nonce">` tag that Vite's dev runtime reads to nonce the CSS and preload tags it adds after page load. Print it in `<head>` of every page, with the same nonce `helmet_boot()` returned; the nonce is escaped so a quote cannot break out of the attribute.
- `helmet_csp_nonce_swap()` replaces every occurrence of the placeholder (default `CSP_NONCE_SLOT_FOR_HELMET`, the value a built Vue/Vite page carries where Vite's `html.cspNonce` stood) with the nonce, HTML-escaped exactly like `helmet_csp_nonce_meta()` escapes it. It is pure: no output, no headers, no globals. A placeholder shorter than 16 characters throws `\InvalidArgumentException`, as does an empty nonce, and a page without the placeholder is returned unchanged.
- `helmet_metrics()` builds the `/metrics` answer as data, `array{status: int, content_type: string, body: string}`. It is fail-closed: disabled gives 404, enabled but unauthorized gives 403, and enabled but misconfigured (no token and no allow list) gives 404 plus a warning. With `storage` set, the request metrics are read back from that store and added to the body.
- `helmet_metrics_env()` reads the `HELMET_METRICS_*` variables (and `HELMET_POLICY_PATH`, as a `policy` source) the same way the Laravel config does, through `Setting::get()`, and returns them as a config array for `helmet_metrics()` and `helmet_metrics_track()`. Everything is off when nothing is set.
- `helmet_metrics_requests()` returns the `RequestMetrics` recorder when `enabled` is true and `storage` names a shared store, else null. A store that cannot be used here is logged through `logger` and treated as off.
- `helmet_metrics_track()` starts a `RequestTimer` for this request and records it once at shutdown (so a page that ends with `exit` is counted). It is inert when request metrics are off.

File `src/Ribbon/functions.php`, namespace `Waynestate\Helmet\Ribbon`, deprecated since 1.1.0 (it raises `E_USER_DEPRECATED`; call `Waynestate\Helmet\helmet_ribbon()` above, which takes the same arguments):

| Function | Signature |
| --- | --- |
| `helmet_ribbon()` | `function helmet_ribbon(string $environment, bool $enabled = true, ?string $policyPath = null): Ribbon` |

A convenience helper for raw PHP apps: it creates a `Ribbon` for the given environment, which you render with its `html()` method and inject near the top of the page. When a policy path is given, the ribbon also shows a version warning for the running PHP version from CREED's policy.json; a missing, unreadable or invalid policy file produces no warning and never breaks the page.

## Advisor

### `Advisor\HelmetModule`

`final class HelmetModule implements Module` in `src/Advisor/HelmetModule.php`. HELMET's [ADVISOR](../guides/advisor.md) module: two read-only tools, `helmet_headers` and `helmet_php_support`, that answer from HELMET's own code, in-process, about an app inside the project root. It loads only inside ADVISOR (`waynestate/advisor` is a suggestion, never a requirement) when the app's `modules.json` names it.

| Member | Signature |
| --- | --- |
| `TOOL` | `public const TOOL = 'helmet';` |
| `VERDICT` | `public const VERDICT = 'HELMET reports; PROCTOR decides.';` |
| `NONCE_PLACEHOLDER` | `public const NONCE_PLACEHOLDER = 'PerRequestNonce';` |
| `__construct()` | `public function __construct(private readonly ModuleContext $context)` |
| `id()` | `public function id(): string` |
| `tools()` | `public function tools(): array` |
| `resources()` | `public function resources(): array` |
| `prompts()` | `public function prompts(): array` |

## Csrf

### `Csrf\RequestForgeryGuard`

`final class RequestForgeryGuard implements MiddlewareInterface` in `src/Csrf/RequestForgeryGuard.php`. PSR-15 middleware that rejects cross-site request forgery. Safe methods always pass; for every other method it reads `Sec-Fetch-Site` first (`same-origin` and `none` pass, `same-site` passes only when `allowSameSite` is true, cross-site or an unknown value is rejected), then falls back to the `Origin` header (the request's own origin and configured trusted origins pass, `Origin: null` and an unparseable origin are rejected, an absent origin passes). The rejection is 403, `text/plain`, `Cache-Control: no-store`, body "Forbidden"; it never echoes request headers.

| Member | Signature |
| --- | --- |
| `SAFE_METHODS` | `public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];` |
| `__construct()` | `public function __construct(private readonly TrustedProxies $proxies, private readonly ?ResponseFactoryInterface $responseFactory = null, private readonly ?StreamFactoryInterface $streamFactory = null, private readonly bool $allowSameSite = false, array $trustedOrigins = [], array $except = [], private readonly ?LoggerInterface $logger = null)` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |
| `allows()` | `public function allows(string $method, string $path, ?string $secFetchSite, ?string $origin, ?string $requestOrigin): bool` |
| `originFromServerParams()` | `public static function originFromServerParams(array $serverParams, TrustedProxies $proxies): ?string` |
| `canonicalOrigin()` | `public static function canonicalOrigin(string $origin): ?string` |

## Error

### `Error\GenericErrorResponse`

`final class GenericErrorResponse` in `src/Error/GenericErrorResponse.php`. Builds the generic error pages HELMET serves instead of a stack trace. For an uncaught exception the user gets a neutral 500 page that says nothing about what broke; the full detail goes to the log at error level, and a logger that throws never changes the answer. For a client fault the app asks for a fixed 4xx page by status code. The page text comes only from a constant map, so nothing from the request or an exception can ever reach the body.

| Member | Signature |
| --- | --- |
| `build()` | `public function build(\Throwable $e, ?LoggerInterface $logger = null): array` |
| `clientError()` | `public function clientError(int $status): array` |

## Headers

### `Headers\SecurityHeaders`

`final class SecurityHeaders` in `src/Headers/SecurityHeaders.php`. The header builders: the default CSP policy, per-request nonces, HSTS, cross-origin isolation, the permissions policy, and the full header set for one request.

| Member | Signature |
| --- | --- |
| `STYLE_SRC_ATTR_MODES` | `public const array STYLE_SRC_ATTR_MODES = ['inline', 'report', 'none'];` |
| `defaultCspPolicy()` | `public static function defaultCspPolicy(): array` |
| `defaultPermissionsPolicy()` | `public static function defaultPermissionsPolicy(): array` |
| `generateNonce()` | `public static function generateNonce(): string` |
| `buildCsp()` | `public static function buildCsp(array $policy, string $nonce, ?string $reportUri = null): string` |
| `validateReportUri()` | `public static function validateReportUri(string $uri): string` |
| `mergePolicies()` | `public static function mergePolicies(array $default, array $custom): array` |
| `build()` | `public static function build(array $policy, string $nonce, bool $isHttps, int $hstsMaxAge = 31536000, bool $hstsIncludeSubDomains = true, ?string $cspReportUri = null, bool $cspReportOnly = false, ?string $coop = 'same-origin', ?string $corp = null, ?array $permissionsPolicy = null, bool $hstsPreload = false, bool $trustedTypesEnabled = false, array $trustedTypesPolicies = ['default'], string $styleSrcAttr = 'inline'): array` |
| `validateStyleSrcAttr()` | `public static function validateStyleSrcAttr(string $mode): string` |
| `buildHsts()` | `public static function buildHsts(int $maxAge = 31536000, bool $includeSubDomains = true, bool $preload = false): string` |
| `buildTrustedTypes()` | `public static function buildTrustedTypes(array $policies): string` |
| `noStoreApplies()` | `public static function noStoreApplies(bool $enabled, string $path, array $paths): bool` |
| `buildCoop()` | `public static function buildCoop(string $value): string` |
| `buildCorp()` | `public static function buildCorp(string $value): string` |
| `buildPermissionsPolicy()` | `public static function buildPermissionsPolicy(array $policy): ?string` |

### `Headers\HeaderReasons`

`final class HeaderReasons` in `src/Headers/HeaderReasons.php`. Why HELMET sends each header and each CSP directive, a sentence or two apiece, kept next to the policy it explains. The ADVISOR tool `helmet_headers` and the docs read it, and a test fails when a header or directive is added without a reason.

| Member | Signature |
| --- | --- |
| `directive()` | `public static function directive(string $directive): ?string` |
| `header()` | `public static function header(string $header): ?string` |
| `directives()` | `public static function directives(): array` |
| `headers()` | `public static function headers(): array` |

### `Headers\SecurityHeadersMiddleware`

`final class SecurityHeadersMiddleware implements MiddlewareInterface` in `src/Headers/SecurityHeadersMiddleware.php`. PSR-15 middleware that sends HELMET's security headers. It decides whether the request is HTTPS from the trusted-proxy list, builds the headers (including a per-request CSP nonce), exposes the nonce to the application as the `helmet.csp_nonce` request attribute, and removes the `X-Powered-By` header. With the optional PSR-3 `logger` it logs `helmet.forwarded_proto_rejected` when a peer that is not a trusted proxy sends `X-Forwarded-Proto` (`null`, the default, logs nothing). When the inner handler throws, the default `rethrow` true lets the exception pass through to the framework's error handler, whose response carries no security headers; with `rethrow` false the middleware itself returns a 500 response carrying the same headers.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly TrustedProxies $proxies, ?array $cspPolicy = null, private readonly bool $rethrow = true, private readonly ?ResponseFactoryInterface $responseFactory = null, private readonly int $hstsMaxAge = 31536000, private readonly bool $hstsIncludeSubDomains = true, private readonly ?string $cspReportUri = null, private readonly bool $cspReportOnly = false, private readonly ?string $coop = 'same-origin', private readonly ?string $corp = null, private readonly ?array $permissionsPolicy = null, private readonly bool $hstsPreload = false, private readonly bool $trustedTypesEnabled = false, private readonly array $trustedTypesPolicies = ['default'], private readonly bool $noStoreEnabled = false, private readonly array $noStorePaths = [], private readonly string $styleSrcAttr = 'inline', private readonly ?LoggerInterface $logger = null)` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |

### `Headers\InvalidCspReportUriException`

`final class InvalidCspReportUriException extends InvalidArgumentException` in `src/Headers/InvalidCspReportUriException.php`. Thrown when the configured CSP report URI is not a valid report destination: it must be an absolute `https://` URL with a host, or a path relative to the page's origin that starts with a single slash. A report endpoint the browser cannot reach is worse than none, and a malformed value can break the Reporting-Endpoints header or inject a second header line.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(string $uri)` |

### `Headers\CspReport`

`final class CspReport` in `src/Headers/CspReport.php`. The framework-free half of the CSP report endpoint, shared by the Laravel controller, `CspReportMiddleware` and the raw PHP `helmet_csp_report()`. `parse()` is pure: it checks the media type (415), the declared and then the real length (413) and the JSON shape (400), in that order, and pulls out the violations, each as `array{directive: string, blocked_host: string, document_path: string}`; a refusal carries none. `answer()` adds the POST-only method check (405 with `Allow: POST`), one warning per violation with the three fields as context, and one metrics tick per violation. Every answer carries `Content-Type: text/plain; charset=utf-8` and `Cache-Control: no-store`.

| Member | Signature |
| --- | --- |
| `MAX_BODY_BYTES` | `public const int MAX_BODY_BYTES = 16 * 1024;` |
| `MAX_REPORTS` | `public const int MAX_REPORTS = 20;` |
| `MAX_FIELD_BYTES` | `public const int MAX_FIELD_BYTES = 200;` |
| `parse()` | `public static function parse(string $contentType, string $body, ?int $declaredLength = null): array` |
| `answer()` | `public static function answer(string $method, string $contentType, string $body, ?int $declaredLength, ?LoggerInterface $logger = null, ?RequestMetrics $metrics = null): array` |
| `logLine()` | `public static function logLine(array $violation): string` |
| `declaredLength()` | `public static function declaredLength(mixed $value): ?int` |

### `Headers\CspReportMiddleware`

`final class CspReportMiddleware implements MiddlewareInterface` in `src/Headers/CspReportMiddleware.php`. PSR-15 middleware that receives CSP violation reports. On its path (default `/csp-report`) it answers the request itself through `CspReport::answer()`, so it agrees with `Route::cspReport()` and `helmet_csp_report()`, and the app never sees the request; on any other path it passes through untouched. The body is read at most `CspReport::MAX_BODY_BYTES` plus one byte deep. Mount it before `RequestForgeryGuard` and any sign-in middleware.

| Member | Signature |
| --- | --- |
| `DEFAULT_PATH` | `public const string DEFAULT_PATH = '/csp-report';` |
| `__construct()` | `public function __construct(private readonly ResponseFactoryInterface $responseFactory, private readonly StreamFactoryInterface $streamFactory, private readonly string $path = self::DEFAULT_PATH, private readonly ?LoggerInterface $logger = null, private readonly ?RequestMetrics $metrics = null)` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |

## Health

### `Health\HealthCheck`

`final class HealthCheck implements MiddlewareInterface` in `src/Health/HealthCheck.php`. Answers the health check path (default `/up`) with 200 and the body "ok" without touching the application, so a running container reports up even while the app is slow. Any other request is passed through unchanged.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly ResponseFactoryInterface $responseFactory, private readonly StreamFactoryInterface $streamFactory, private readonly string $path = '/up')` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |

### `Health\Readiness`

`final class Readiness` in `src/Health/Readiness.php`. The readiness report: named checks, each `pass`, `warn` or `fail`, answered as JSON with 200 while none failed and 503 once one did. Immutable; every `with*()` returns a copy. HELMET's own checks are `headers` (the header set builds and carries a CSP, and in which mode), `trusted_proxies` (a count and a broad count, never an address; warns when empty or broad) and `debug_output` (warns when on). An app check returns true or null to pass and false to fail; one that throws fails, and its class and message go to the logger as `helmet.readiness_check_failed`, never to the body. Check names are lowercase letters, digits and underscores; a bad or duplicate name throws `\InvalidArgumentException`.

| Member | Signature |
| --- | --- |
| `DEFAULT_PATH` | `public const string DEFAULT_PATH = '/ready';` |
| `PASS` | `public const string PASS = 'pass';` |
| `WARN` | `public const string WARN = 'warn';` |
| `FAIL` | `public const string FAIL = 'fail';` |
| `__construct()` | `public function __construct(private readonly ?LoggerInterface $logger = null)` |
| `standard()` | `public static function standard(TrustedProxies $proxies, callable $buildHeaders, bool $cspReportOnly = false, string $styleSrcAttr = 'inline', ?LoggerInterface $logger = null): self` |
| `displayErrorsOn()` | `public static function displayErrorsOn(): bool` |
| `withHeaders()` | `public function withHeaders(callable $buildHeaders, bool $cspReportOnly = false, string $styleSrcAttr = 'inline', bool $registered = true): self` |
| `withTrustedProxies()` | `public function withTrustedProxies(TrustedProxies $proxies): self` |
| `withDebugOutput()` | `public function withDebugOutput(bool $on): self` |
| `withCheck()` | `public function withCheck(string $name, callable $check): self` |
| `names()` | `public function names(): array` |
| `response()` | `public function response(): array` |

### `Health\ReadinessCheck`

`final class ReadinessCheck implements MiddlewareInterface` in `src/Health/ReadinessCheck.php`. Answers a GET or HEAD on the readiness path (default `/ready`) with the `Readiness` report; any other request is passed through unchanged. Put it next to `HealthCheck`, never in its place.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly ResponseFactoryInterface $responseFactory, private readonly StreamFactoryInterface $streamFactory, private readonly Readiness $readiness, private readonly string $path = Readiness::DEFAULT_PATH)` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |

## Laravel

The Laravel adapter. The service provider wires the config into the app; the four middlewares put the core classes into the request pipeline; the three route classes register the optional endpoints (CSP reports, metrics, readiness), which stay off until an app opts in.

### `Laravel\HelmetServiceProvider`

`final class HelmetServiceProvider extends ServiceProvider` in `src/Laravel/HelmetServiceProvider.php`. Merges and publishes `config/helmet.php`, copies `helmet.proxies` into Laravel's trusted-proxy list when the app has not set `trustedproxy.proxies` itself (an explicit `trustProxies(at: ...)` call still wins), registers the `cspReport`, `helmetMetrics` and `helmetReady` route macros, and adds `ClientErrorPages` to the exception handler (inert until `helmet.error_pages` is on). It maps `helmet.session.secure` onto Laravel's `session.secure` at boot (`always` sets it to `true`, `auto` leaves it unset; an app value wins). It registers no routes of its own; the `/up` health route is left to Laravel's built-in `health: '/up'` option.

| Member | Signature |
| --- | --- |
| `register()` | `public function register(): void` |
| `boot()` | `public function boot(): void` |

### `Laravel\ClientErrorPages`

`final class ClientErrorPages` in `src/Laravel/ClientErrorPages.php`. With `helmet.error_pages` on (env `HELMET_ERROR_PAGES`, default off), answers a 4xx HTTP exception with the page `helmet_client_error_response()` builds, keeping the exception's own headers (`Allow`, `Retry-After`) under HELMET's `Content-Type` and `Cache-Control`. It returns null, so Laravel answers as usual, for every 5xx, for a request that expects JSON, and while the switch is off; a `ValidationException` or an `AuthenticationException` is not an HTTP exception, so it never reaches the callback. The service provider adds it to the framework's exception handler as a renderable callback.

| Member | Signature |
| --- | --- |
| `register()` | `public static function register(ExceptionHandler $handler): void` |
| `render()` | `public static function render(HttpExceptionInterface $e, Request $request): ?Response` |

### `Laravel\SecurityHeaders`

`final class SecurityHeaders` in `src/Laravel/SecurityHeaders.php`. The Laravel adapter for the security headers. It logs `helmet.forwarded_proto_rejected` through the app's logger when a peer that is not a trusted proxy sends `X-Forwarded-Proto`, as `helmet_boot()` does. It reads the `helmet` config and merges per-directive CSP overrides over the core default, sends the same header set as the core middleware, resets the Vite nonce to a fresh random one after the response (a nonce that outlives its request is a nonce an attacker may have seen), and injects a `<meta property="csp-nonce">` tag right after the opening `<head>` tag in HTML responses. A nonce page differs per request, so on an HTML response whose `Cache-Control` names `public` or `s-maxage` it replaces `public` with `private` and drops `s-maxage`; every other directive, and every non-HTML response, is left exactly as written.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |
| `configuredHeaders()` | `public static function configuredHeaders(array $policy, string $nonce, bool $isHttps): array` |
| `styleSrcAttr()` | `public static function styleSrcAttr(): string` |

`configuredHeaders()` builds the header set from the `helmet` config for a given policy, nonce and scheme; the readiness controller uses it to prove the config builds.

### `Laravel\RequestForgeryGuard`

`final class RequestForgeryGuard` in `src/Laravel/RequestForgeryGuard.php`. The Laravel adapter for the request-forgery guard. When it is enabled it applies the same rule the core PSR-15 middleware and `helmet_boot()` use, through the core `RequestForgeryGuard`, so every consumer path agrees; otherwise the request passes through untouched.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\ForwardedProtoNormalizer`

`final class ForwardedProtoNormalizer` in `src/Laravel/ForwardedProtoNormalizer.php`. Makes Laravel agree with HELMET's HTTPS detection. When the request comes from a trusted proxy and `X-Forwarded-Proto` carries more than one value, the header is rewritten to the last value (the one the closest proxy appended) before any code reads it, because Symfony's `Request::isSecure()` reads the first value, which is the client's. Requests from untrusted peers are left alone.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\InjectRibbon`

`final class InjectRibbon` in `src/Laravel/InjectRibbon.php`. Injects the environment ribbon into HTML responses so a developer or operator can see at a glance that they are not looking at production. It is injected only when `config('helmet.ribbon')` is true, the response `Content-Type` is HTML, and the environment is one of the non-production ones (local, development, dev, testing, test, staging); it is never injected for production, prod, live, or an empty environment. The ribbon HTML goes right after the opening `<body>` tag, and a ribbon error never breaks the page.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\CspReportController`

`final class CspReportController` in `src/Laravel/CspReportController.php`. Receives browser CSP violation reports. Reports can be numerous and can be large, so the controller caps the body size, accepts only the two report content types, and logs one line per violation (the violated directive, the blocked URI's host, and the document path), never the full body. The legacy `application/csp-report` body is a single object with the report under `csp-report` (kebab-case fields); the Reporting API `application/reports+json` body is a JSON array of report objects, and only the csp-violation entries are logged because browsers batch other report types into the same POST.

| Member | Signature |
| --- | --- |
| `MAX_BODY_BYTES` | `public const int MAX_BODY_BYTES = CspReport::MAX_BODY_BYTES;` |
| `MAX_REPORTS` | `public const int MAX_REPORTS = CspReport::MAX_REPORTS;` |
| `__invoke()` | `public function __invoke(Request $request): Response` |

### `Laravel\CspReportRoute`

`final class CspReportRoute` in `src/Laravel/CspReportRoute.php`. Registers the `Route::cspReport()` macro and the POST route it creates. The route carries no authentication of its own (an app that wants to keep the endpoint private should wrap it in its own middleware or route group), excludes the web group's CSRF middleware (browsers POST reports without a CSRF token, and the middleware would reject them with 419), and is throttled by default because the endpoint is public and its input is attacker-influenced; pass `null` as the second argument to register it without a throttle.

| Member | Signature |
| --- | --- |
| `DEFAULT_PATH` | `public const string DEFAULT_PATH = '/csp-report';` |
| `ROUTE_NAME` | `public const string ROUTE_NAME = 'helmet.csp-report';` |
| `DEFAULT_THROTTLE` | `public const string DEFAULT_THROTTLE = 'throttle:60,1';` |
| `registerMacro()` | `public static function registerMacro(): void` |
| `register()` | `public static function register(string $path = self::DEFAULT_PATH, ?string $throttle = self::DEFAULT_THROTTLE): \Illuminate\Routing\Route` |

### `Laravel\MetricsController`

`final class MetricsController` in `src/Laravel/MetricsController.php`. Serves the metrics endpoint, fail-closed. When `helmet.metrics.enabled` is false (the default) it answers 404 so the route is not advertised; when it is enabled, a request is served only if it presents the configured bearer token or comes from an allowed client IP, anything else is 403; when it is enabled but neither a token nor an allow list is configured, it answers 404 and logs a warning, because a misconfiguration must not serve metrics. The client IP is resolved through the `helmet.proxies` trusted-proxy list, so a spoofed `X-Forwarded-For` from an untrusted peer cannot grant access; an unparseable entry from a trusted proxy fails closed on the peer and logs `helmet.forwarded_for_rejected` through the app's logger.

| Member | Signature |
| --- | --- |
| `__invoke()` | `public function __invoke(Request $request): Response` |

The PHP support metrics come from a bound `PolicySource`, else from `helmet.policy_path`. The request metrics come from `RequestMetricsBinding` when `helmet.metrics.storage` is set.

### `Laravel\RecordRequestMetrics`

`final class RecordRequestMetrics` in `src/Laravel/RecordRequestMetrics.php`. The outermost global middleware, added by the provider only when `helmet.metrics.enabled` is true and `helmet.metrics.storage` names a store. It records in `terminate()`, after the response is sent. The route group is the route name, else its URI template, else `unmatched`; the path is never a label.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly RequestMetricsBinding $binding)` |
| `handle()` | `public function handle(Request $request, Closure $next): mixed` |
| `terminate()` | `public function terminate(Request $request, Response $response): void` |
| `routeGroup()` | `public static function routeGroup(Request $request): string` |

### `Laravel\RequestMetricsBinding`

`final class RequestMetricsBinding` in `src/Laravel/RequestMetricsBinding.php`. A container singleton that builds the `RequestMetrics` recorder from `helmet.metrics` once and memoizes it (or its absence), for the middleware, `MetricsController` and `CspReportController`, which counts each violation it logs.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly ?LoggerInterface $logger = null)` |
| `configured()` | `public static function configured(): bool` |
| `get()` | `public function get(): ?RequestMetrics` |

### `Laravel\MetricsRoute`

`final class MetricsRoute` in `src/Laravel/MetricsRoute.php`. Registers the `Route::helmetMetrics()` macro and the GET route it creates. The route is a plain GET to `MetricsController` and carries no authentication of its own: the controller enforces the bearer token and the client IP allow list from the `helmet.metrics` config. It is throttled by default because, even protected, it is a public URL that a scraper or a curious client can hit; pass `null` as the second argument to register it without a throttle.

| Member | Signature |
| --- | --- |
| `DEFAULT_PATH` | `public const string DEFAULT_PATH = '/metrics';` |
| `ROUTE_NAME` | `public const string ROUTE_NAME = 'helmet.metrics';` |
| `DEFAULT_THROTTLE` | `public const string DEFAULT_THROTTLE = 'throttle:60,1';` |
| `registerMacro()` | `public static function registerMacro(): void` |
| `register()` | `public static function register(string $path = self::DEFAULT_PATH, ?string $throttle = self::DEFAULT_THROTTLE): \Illuminate\Routing\Route` |

### `Laravel\ReadinessRoute`

`final class ReadinessRoute` in `src/Laravel/ReadinessRoute.php`. Registers the `Route::helmetReady()` macro and the GET route it creates (name `helmet.ready`). App checks are a map of name to invokable class name, carried as a route default so `route:cache` works. No throttle by default: a throttled probe reads as "not ready". A check that is not a name and class-name pair throws `\InvalidArgumentException`.

| Member | Signature |
| --- | --- |
| `ROUTE_NAME` | `public const string ROUTE_NAME = 'helmet.ready';` |
| `CHECKS_DEFAULT` | `public const string CHECKS_DEFAULT = 'helmet_ready_checks';` |
| `registerMacro()` | `public static function registerMacro(): void` |
| `register()` | `public static function register(string $path = Readiness::DEFAULT_PATH, array $checks = [], ?string $throttle = null): \Illuminate\Routing\Route` |

### `Laravel\ReadinessController`

`final class ReadinessController` in `src/Laravel/ReadinessController.php`. Runs HELMET's checks against the app's config (the header middleware is registered and the set builds, the trusted-proxy count, `app.debug` in production) plus the route's app checks, each resolved from the container, and answers with the report.

| Member | Signature |
| --- | --- |
| `__invoke()` | `public function __invoke(Request $request): Response` |

## Logging

### `Logging\Logging`

`final class Logging` in `src/Logging/Logging.php`. Creates a Monolog logger that writes JSON lines at the given minimum level, easy to ship to a log aggregator and to grep on the host. Secrets stay out of the logs: a processor redacts known sensitive keys (passwords, tokens, session IDs, keys, connection strings) from the record's context and extra before anything is written. A logging failure never takes the request down: the configured stream is wrapped in a fallback, so if it cannot be written the record goes to `php://stderr` instead. Every record also carries a request ID in `extra.request_id`, generated by HELMET and never taken from an inbound header, so the lines of one request can be tied together.

| Member | Signature |
| --- | --- |
| `create()` | `public static function create(string $channel, string $streamOrPath, string $level = 'info', ?string $requestId = null): LoggerInterface` |
| `requestId()` | `public static function requestId(): string` |
| `isValidRequestId()` | `public static function isValidRequestId(string $id): bool` |
| `addRequestId()` | `public static function addRequestId(LogRecord $record): LogRecord` |
| `addRequestContext()` | `public static function addRequestContext(LogRecord $record): LogRecord` |
| `redact()` | `public static function redact(LogRecord $record): LogRecord` |

## Metrics

### `Metrics\Metrics`

`final class Metrics` in `src/Metrics/Metrics.php`. Framework-free builder of the metrics payload: it knows nothing about Laravel, raw PHP, or any web framework. It builds a CollectorRegistry of request-free gauges (`helmet_info`, `wsu_stack_info` and, when a policy source is given, `helmet_php_version_status` and `helmet_php_eol_timestamp_seconds`, looked up by the running PHP's major.minor), adds the request metrics when a `RequestMetrics` is given (with `helmet_metrics_storage_up`, 0 when the store did not answer), and renders the result in the Prometheus text exposition format. The request-free gauges stay in an InMemory registry built for each scrape, so a stale label never outlives a restart.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(?Adapter $storage = null, ?PolicySource $policy = null, ?DateTimeInterface $now = null, ?string $version = null, ?RequestMetrics $requests = null, ?array $stackPackages = null)` |
| `render()` | `public function render(): string` |
| `mimeType()` | `public static function mimeType(): string` |
| `phpMinor()` | `public static function phpMinor(): string` |

### `Metrics\RequestMetrics`

`final class RequestMetrics` in `src/Metrics/RequestMetrics.php`. The opt-in request metrics on shared storage: `helmet_http_request_duration_seconds` (a histogram by `route`, `method` and `status` class; its `_count` is the request count) and `helmet_csp_violations_total` (by `directive`). Every label is bounded by HELMET: a route group must match `^[a-z0-9._/{}:-]{1,64}$` or becomes `other`, a method outside the standard verbs is `OTHER`, a status is its class (`2xx`), and a directive outside the CSP Level 3 list is `other`.

| Member | Signature |
| --- | --- |
| `BUCKETS` | `public const array BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];` |
| `UNMATCHED` | `public const string UNMATCHED = 'unmatched';` |
| `__construct()` | `public function __construct(Adapter $storage)` |
| `observe()` | `public function observe(string $route, string $method, int $status, float $seconds): void` |
| `cspViolation()` | `public function cspViolation(string $directive): void` |
| `samples()` | `public function samples(): array` |
| `routeLabel()` | `public static function routeLabel(string $route): string` |
| `methodLabel()` | `public static function methodLabel(string $method): string` |
| `statusLabel()` | `public static function statusLabel(int $status): string` |
| `directiveLabel()` | `public static function directiveLabel(string $directive): string` |

### `Metrics\MetricsStorage`

`final class MetricsStorage` in `src/Metrics/MetricsStorage.php`. Picks the shared store for the request metrics from promphp's own adapters: `apcu` (APCng), `redis` (ext-redis) or `sqlite` (PDO). Anything else means no request metrics. A store that is named but cannot be used throws `MetricsStorageException`.

| Member | Signature |
| --- | --- |
| `DRIVERS` | `public const array DRIVERS = ['apcu', 'redis', 'sqlite'];` |
| `PREFIX` | `public const string PREFIX = 'helmet_metrics';` |
| `isOn()` | `public static function isOn(?string $driver): bool` |
| `create()` | `public static function create(?string $driver, ?string $redisUrl = null, ?string $sqlitePath = null): ?Adapter` |
| `defaultSqlitePath()` | `public static function defaultSqlitePath(): string` |
| `redisOptions()` | `public static function redisOptions(string $url): array` |

### `Metrics\MetricsStorageException`

`final class MetricsStorageException extends \RuntimeException` in `src/Metrics/MetricsStorageException.php`. Thrown when a named store cannot be used. It has no public members of its own.

### `Metrics\RequestTimer`

`final class RequestTimer` in `src/Metrics/RequestTimer.php`. Times one raw PHP request and records it once. The route group defaults to `app`; a recording failure is logged and swallowed, because the page has already been served.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(?RequestMetrics $metrics, float $startedAt, string $method, ?LoggerInterface $logger = null)` |
| `off()` | `public static function off(): self` |
| `isOn()` | `public function isOn(): bool` |
| `route()` | `public function route(string $group): self` |
| `finish()` | `public function finish(?int $status = null, ?float $now = null): void` |

### `Metrics\MetricsGate`

`final class MetricsGate` in `src/Metrics/MetricsGate.php`. Decides whether a request may read the metrics endpoint. The decision is fail-closed: disabled gives 404 (the route is not advertised), enabled but neither a token nor an allow list configured gives 404 plus a warning (a misconfiguration must not serve metrics), a correct bearer token or an allowed client IP gives 200, and anything else gives 403. The token is compared with `hash_equals` (constant time); the client IP is the one resolved by `ClientIp`, which already honors the trusted-proxy list. An empty token is the same as no token.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly bool $enabled, ?string $token, private readonly IpAllowList $allow)` |
| `decide()` | `public function decide(?string $requestToken, string $clientIp): MetricsDecision` |

### `Metrics\MetricsDecision`

`final class MetricsDecision` in `src/Metrics/MetricsDecision.php`. The outcome of the metrics gate: one of the four states above, with its status code and reason.

| Member | Signature |
| --- | --- |
| `disabled()` | `public static function disabled(): self` |
| `misconfigured()` | `public static function misconfigured(): self` |
| `denied()` | `public static function denied(): self` |
| `allowed()` | `public static function allowed(): self` |
| `status()` | `public function status(): int` |
| `reason()` | `public function reason(): string` |
| `isAllowed()` | `public function isAllowed(): bool` |

### `Metrics\BearerToken`

`final class BearerToken` in `src/Metrics/BearerToken.php`. Extracts the bearer token from an authorization header value, or returns null when there is none. `fromServer()` finds the header for a framework-free app: `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then `getallheaders()`, because Apache with mod_php keeps it out of `$_SERVER`.

| Member | Signature |
| --- | --- |
| `from()` | `public static function from(?string $authorizationHeader): ?string` |
| `fromServer()` | `public static function fromServer(array $server, ?array $headers = null): ?string` |

## Platform

### `Platform\Presets`

`final class Presets` in `src/Platform/Presets.php`. The platform presets: each preset names the trusted proxies for a deployment platform, so an app does not hard-code addresses that belong to the platform. `citadel` trusts the Docker bridge gateway to the HA-Proxy front (a published app port that is source-NATed to the gateway address would make a direct client look like the proxy); `docker` trusts nothing, for a plain Docker deployment with no front proxy. The headers a trusted front proxy may set are the same for every preset: `X-Forwarded-For` and `X-Forwarded-Proto`. `X-Forwarded-Host` and `X-Forwarded-Prefix` are deliberately not in the set (a platform that rewrites the host would let a client pre-seed them), and `X-Forwarded-Port` is out too, because Citadel's HA-Proxy never sends it.

| Member | Signature |
| --- | --- |
| `TRUSTED_HEADERS` | `public const TRUSTED_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Proto'];` |
| `proxies()` | `public static function proxies(string $name): array` |
| `exists()` | `public static function exists(string $name): bool` |
| `names()` | `public static function names(): array` |

### `Platform\UnknownPresetException`

`final class UnknownPresetException extends \InvalidArgumentException` in `src/Platform/UnknownPresetException.php`. Thrown when a config names a preset the package does not know, so a typo fails loudly at boot instead of silently trusting nothing.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(string $name)` |

## Policy

### `Policy\PolicySource`

`interface PolicySource` in `src/Policy/PolicySource.php`. The interface a component-version policy source implements.

| Member | Signature |
| --- | --- |
| `components()` | `public function components(): array;` |

### `Policy\JsonFilePolicySource`

`final class JsonFilePolicySource implements PolicySource` in `src/Policy/JsonFilePolicySource.php`. Reads a CREED-format policy file: a JSON object with a `schema_version` and a `components` object, each component's versions under `versions` (CREED's schema) or, in an older flat file, directly on the component. A version with no `security_until` is skipped; one with no `active_until` counts as security-only. It throws `PolicyReadException` on any problem it finds: a missing or unreadable file, invalid JSON, a schema_version it does not know, or a missing `components` object. Callers that must not throw (such as the version warning at request time) catch `PolicyReadException`.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly string $path)` |
| `components()` | `public function components(): array` |

### `Policy\PolicyReadException`

`final class PolicyReadException extends \RuntimeException` in `src/Policy/PolicyReadException.php`. Thrown by `JsonFilePolicySource` when the policy file cannot be read or parsed. It has no public members of its own.

### `Policy\VersionStatus`

`final class VersionStatus` in `src/Policy/VersionStatus.php`. Determines the support status of a component version on a given date: `active` on or before its active_until date, `security` after active_until and on or before its security_until date, `eol` after security_until, and `unknown` when the component or version is not in the policy. The date is injected, so the result is a pure function of the policy, the component, the version, and the date, which makes the boundaries testable without waiting for real support windows to close.

| Member | Signature |
| --- | --- |
| `ACTIVE` | `public const ACTIVE = 'active';` |
| `SECURITY` | `public const SECURITY = 'security';` |
| `EOL` | `public const EOL = 'eol';` |
| `UNKNOWN` | `public const UNKNOWN = 'unknown';` |
| `__construct()` | `public function __construct(private readonly DateTimeInterface $now, private readonly array $components)` |
| `status()` | `public function status(string $component, string $version): string` |
| `securityUntil()` | `public function securityUntil(string $component, string $version): ?DateTimeImmutable` |

### `Policy\VersionWarning`

`final class VersionWarning` in `src/Policy/VersionWarning.php`. Produces a human-readable warning when a component version is in its security-only phase or is end of life; no warning when the version is active or unknown. The date is injected, so the result is a pure function of the policy, the component, the version, and the date.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly PolicySource $source, private readonly DateTimeInterface $now)` |
| `warning()` | `public function warning(string $component, string $version): ?string` |

## Proxy

### `Proxy\ClientIp`

`final class ClientIp` in `src/Proxy/ClientIp.php`. Resolves the client IP. When the peer (`REMOTE_ADDR`) is a trusted proxy, the client IP is read from `X-Forwarded-For`: the header is a comma-separated list in which the rightmost entry is the one the closest proxy recorded, so walk from the right, skipping trusted proxies, and the first remaining entry is the client. An entry that is not a valid IP address fails closed: the peer is returned instead of continuing to an earlier entry. IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) are unmapped to `a.b.c.d` before matching, so a dual-stack proxy still matches an IPv4 trust list. When the peer is not a trusted proxy, the header is ignored entirely and `REMOTE_ADDR` is the client.

| Member | Signature |
| --- | --- |
| `resolve()` | `public static function resolve(array $serverParams, TrustedProxies $proxies, ?LoggerInterface $logger = null): string` |

### `Proxy\HttpsDetector`

`final class HttpsDetector` in `src/Proxy/HttpsDetector.php`. Decides whether the request arrived over HTTPS. If the SAPI reports a TLS connection, the request is HTTPS; this is the only signal trusted unconditionally. Otherwise, only a trusted proxy's `X-Forwarded-Proto: https` counts, and only the last non-empty value of the list is read (the one the closest proxy appended), trimmed and lower-cased before comparison. `X-Forwarded-Port` is deliberately never read: the front proxy sets `X-Forwarded-Proto` and the port is always 443 on the TLS edge, so the port header adds no information and only creates a spoofing surface.

| Member | Signature |
| --- | --- |
| `isHttps()` | `public static function isHttps(array $serverParams, TrustedProxies $proxies): bool` |

### `Proxy\IpAllowList`

`final class IpAllowList` in `src/Proxy/IpAllowList.php`. Matches a client IP address against an allow list of exact IPv4 or IPv6 addresses and CIDR ranges. Every entry is validated at construction time: an entry that is not a valid address or CIDR throws, a /0 prefix (which would allow every address of its family) is refused, and the wildcard "*" is refused, so bad config fails loudly instead of looking like working config while allowing nothing, or the wrong thing. The matching itself is delegated to Symfony's IpUtils.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(array $entries = [])` |
| `allows()` | `public function allows(string $ip): bool` |
| `isEmpty()` | `public function isEmpty(): bool` |

### `Proxy\TrustedProxies`

`final class TrustedProxies` in `src/Proxy/TrustedProxies.php`. Matches a client or peer IP address against the list of trusted proxies. The list may contain exact IPv4 or IPv6 addresses and CIDR ranges, with the same validation rules as `IpAllowList`: an invalid entry throws, a /0 prefix is refused, and the wildcard "*" is refused, so bad config fails loudly at boot. The matching itself is delegated to Symfony's IpUtils.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(array $proxies = [])` |
| `isTrusted()` | `public function isTrusted(string $ip): bool` |
| `unmapIpv4MappedIpv6()` | `public static function unmapIpv4MappedIpv6(string $ip): string` |
| `isEmpty()` | `public function isEmpty(): bool` |
| `count()` | `public function count(): int` |
| `broadEntries()` | `public function broadEntries(): array` |

## Ribbon

### `Ribbon\Ribbon`

`final class Ribbon` in `src/Ribbon/Ribbon.php`. An environment ribbon: a small visual indicator that shows which environment an app is running in (for example "STAGING" or "DEVELOPMENT"), meant to be injected near the top of the page so a developer or operator can see at a glance that they are not looking at production. It is disabled by default in production and enabled in every other environment. The environment name is escaped to prevent XSS. When a policy file is configured, the ribbon also shows a warning line for each component version that is in its security-only phase or end of life, from CREED's policy.json; a missing, unreadable or invalid policy file produces no warning and never breaks the page.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly string $environment, private readonly bool $enabled = true, ?string $policyPath = null, private readonly array $components = [], ?DateTimeInterface $now = null)` |
| `shouldShow()` | `public function shouldShow(): bool` |
| `environment()` | `public function environment(): string` |
| `html()` | `public function html(): string` |

## Session

### `Session\SessionHardening`

`final class SessionHardening` in `src/Session/SessionHardening.php`. Applies hardened session settings before the session starts: the secure cookie mode (`auto` or `always`, so in always mode the cookie is only sent over TLS), `cookie_lifetime` 0 (a browser-session cookie that ends when the browser closes), `cache_limiter` nocache (so pages cannot be cached and replayed with the session attached), `cookie_path` and `cookie_domain` of `/` and empty (so the cookie is host-only), and the name `__Host-WSUSESSID` on HTTPS or `WSUSESSID` on HTTP, but only while the name is still PHP's default `PHPSESSID` (an app-chosen name is left alone, because SESAME reads whatever `session_name()` returns). `session.sid_length` is deliberately not touched: it is deprecated as of PHP 8.4 and the platform image sets it to 26.

| Member | Signature |
| --- | --- |
| `SECURE_AUTO` | `public const SECURE_AUTO = 'auto';` |
| `SECURE_ALWAYS` | `public const SECURE_ALWAYS = 'always';` |
| `apply()` | `public static function apply(bool $https, ?array $settings = null, string $secureMode = self::SECURE_AUTO): void` |

## Support

### `Support\Setting`

`final class Setting` in `src/Support/Setting.php`. Resolves one HELMET setting from outside the package. The real environment wins (getenv, then `$_ENV`, then `$_SERVER`); an empty string counts as not set, so a blank value in the environment falls through to Playbook. When the environment has no value for the key, the private `waynestate/playbook` package supplies it, if it is installed. When neither has it, null comes back and the caller applies its own default. Playbook is optional by design: the package never requires it, it only looks for the class. That is how a WSU value can arrive at an app without this public repo naming a single one of them.

| Member | Signature |
| --- | --- |
| `get()` | `public static function get(string $key): ?string` |

## Related

- [Environment variables](configuration.md)
- [Proof and tests](proof.md)
- [Quick start](../guides/quick-start.md)
- [Raw PHP](../guides/usage-raw-php.md)
- [PSR-15 middleware](../guides/usage-psr15.md)
- [Laravel](../guides/usage-laravel.md)

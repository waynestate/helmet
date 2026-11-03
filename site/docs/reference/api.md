---
title: "API | HELMET docs"
description: "The public entry points of the core, from helmet_boot() to helmet_metrics(), plus the middleware and guard around them."
url: https://helmet.apps.wayne.edu/docs/reference/api.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# API reference[#](https://helmet.apps.wayne.edu/docs/reference/api.html#api-reference)

Every public entry point of the `waynestate/helmet` core, with signatures exactly as they appear in the code under `src/`. For what each part does to a request, see the usage guides; for the environment variables behind the Laravel adapter, see [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html).

## Global functions[#](https://helmet.apps.wayne.edu/docs/reference/api.html#global-functions)

File `src/functions.php`, namespace `Waynestate\Helmet`:

| Function | Signature |
| --- | --- |
| `helmet_boot()` | `function helmet_boot(array $config): array` |
| `helmet_up_response()` | `function helmet_up_response(): array` |
| `helmet_up()` | `function helmet_up(): never` |
| `helmet_forbidden_response()` | `function helmet_forbidden_response(): array` |
| `helmet_forbidden()` | `function helmet_forbidden(): never` |
| `helmet_csp_nonce_meta()` | `function helmet_csp_nonce_meta(string $nonce): string` |
| `helmet_metrics()` | `function helmet_metrics(array $config): array` |

What each one returns, from the docblocks:

- `helmet_boot()` boots the core for raw PHP: it validates the config and returns `array{nonce: string, logger: LoggerInterface|null, request_id: string}`. It throws `UnknownPresetException` for an unknown preset, `\InvalidArgumentException` for a bad config value, `InvalidCspReportUriException` for a bad report URI, and `\LogicException` if a session is already active.
- `helmet_up_response()` and `helmet_forbidden_response()` return the answer as data, `array{status: int, headers: array<string, string>, body: string}`, without sending anything; the caller sends it. `helmet_up()` and `helmet_forbidden()` send the same answers (200, body "ok", and 403, body "Forbidden") and exit, so a small public file can answer a probe that never reaches `helmet_boot()`.
- `helmet_csp_nonce_meta()` builds the `<meta property="csp-nonce">` tag that Vite's dev runtime reads to nonce the CSS and preload tags it adds after page load. Print it in `<head>` of every page, with the same nonce `helmet_boot()` returned; the nonce is escaped so a quote cannot break out of the attribute.
- `helmet_metrics()` builds the `/metrics` answer as data, `array{status: int, content_type: string, body: string}`. It is fail-closed: disabled gives 404, enabled but unauthorized gives 403, and enabled but misconfigured (no token and no allow list) gives 404 plus a warning.

File `src/Ribbon/functions.php`, namespace `Waynestate\Helmet\Ribbon`:

| Function | Signature |
| --- | --- |
| `helmet_ribbon()` | `function helmet_ribbon(string $environment, bool $enabled = true, ?string $policyPath = null): Ribbon` |

A convenience helper for raw PHP apps: it creates a `Ribbon` for the given environment, which you render with its `html()` method and inject near the top of the page. When a policy path is given, the ribbon also shows a version warning for the running PHP version from CREED's policy.json; a missing, unreadable or invalid policy file produces no warning and never breaks the page.

## Csrf[#](https://helmet.apps.wayne.edu/docs/reference/api.html#csrf)

### `Csrf\RequestForgeryGuard` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#csrf-requestforgeryguard)

`final class RequestForgeryGuard implements MiddlewareInterface` in `src/Csrf/RequestForgeryGuard.php`. PSR-15 middleware that rejects cross-site request forgery. Safe methods always pass; for every other method it reads `Sec-Fetch-Site` first (`same-origin` and `none` pass, `same-site` passes only when `allowSameSite` is true, cross-site or an unknown value is rejected), then falls back to the `Origin` header (the request's own origin and configured trusted origins pass, `Origin: null` and an unparseable origin are rejected, an absent origin passes). The rejection is 403, `text/plain`, `Cache-Control: no-store`, body "Forbidden"; it never echoes request headers.

| Member | Signature |
| --- | --- |
| `SAFE_METHODS` | `public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];` |
| `__construct()` | `public function __construct(private readonly TrustedProxies $proxies, private readonly ?ResponseFactoryInterface $responseFactory = null, private readonly ?StreamFactoryInterface $streamFactory = null, private readonly bool $allowSameSite = false, array $trustedOrigins = [], array $except = [], private readonly ?LoggerInterface $logger = null)` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |
| `allows()` | `public function allows(string $method, string $path, ?string $secFetchSite, ?string $origin, ?string $requestOrigin): bool` |
| `originFromServerParams()` | `public static function originFromServerParams(array $serverParams, TrustedProxies $proxies): ?string` |
| `canonicalOrigin()` | `public static function canonicalOrigin(string $origin): ?string` |

## Error[#](https://helmet.apps.wayne.edu/docs/reference/api.html#error)

### `Error\GenericErrorResponse` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#error-genericerrorresponse)

`final class GenericErrorResponse` in `src/Error/GenericErrorResponse.php`. Builds the generic error pages HELMET serves instead of a stack trace. For an uncaught exception the user gets a neutral 500 page that says nothing about what broke; the full detail goes to the log at error level, and a logger that throws never changes the answer. For a client fault the app asks for a fixed 4xx page by status code. The page text comes only from a constant map, so nothing from the request or an exception can ever reach the body.

| Member | Signature |
| --- | --- |
| `build()` | `public function build(\Throwable $e, ?LoggerInterface $logger = null): array` |
| `clientError()` | `public function clientError(int $status): array` |

## Headers[#](https://helmet.apps.wayne.edu/docs/reference/api.html#headers)

### `Headers\SecurityHeaders` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#headers-securityheaders)

`final class SecurityHeaders` in `src/Headers/SecurityHeaders.php`. The header builders: the default CSP policy, per-request nonces, HSTS, cross-origin isolation, the permissions policy, and the full header set for one request.

| Member | Signature |
| --- | --- |
| `defaultCspPolicy()` | `public static function defaultCspPolicy(): array` |
| `defaultPermissionsPolicy()` | `public static function defaultPermissionsPolicy(): array` |
| `generateNonce()` | `public static function generateNonce(): string` |
| `buildCsp()` | `public static function buildCsp(array $policy, string $nonce, ?string $reportUri = null): string` |
| `validateReportUri()` | `public static function validateReportUri(string $uri): string` |
| `mergePolicies()` | `public static function mergePolicies(array $default, array $custom): array` |
| `build()` | `public static function build(array $policy, string $nonce, bool $isHttps, int $hstsMaxAge = 31536000, bool $hstsIncludeSubDomains = true, ?string $cspReportUri = null, bool $cspReportOnly = false, ?string $coop = 'same-origin', ?string $corp = null, ?array $permissionsPolicy = null, bool $hstsPreload = false, bool $trustedTypesEnabled = false, array $trustedTypesPolicies = ['default']): array` |
| `buildHsts()` | `public static function buildHsts(int $maxAge = 31536000, bool $includeSubDomains = true, bool $preload = false): string` |
| `buildTrustedTypes()` | `public static function buildTrustedTypes(array $policies): string` |
| `noStoreApplies()` | `public static function noStoreApplies(bool $enabled, string $path, array $paths): bool` |
| `buildCoop()` | `public static function buildCoop(string $value): string` |
| `buildCorp()` | `public static function buildCorp(string $value): string` |
| `buildPermissionsPolicy()` | `public static function buildPermissionsPolicy(array $policy): ?string` |

### `Headers\SecurityHeadersMiddleware` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#headers-securityheadersmiddleware)

`final class SecurityHeadersMiddleware implements MiddlewareInterface` in `src/Headers/SecurityHeadersMiddleware.php`. PSR-15 middleware that sends HELMET's security headers. It decides whether the request is HTTPS from the trusted-proxy list, builds the headers (including a per-request CSP nonce), exposes the nonce to the application as the `helmet.csp_nonce` request attribute, and removes the `X-Powered-By` header. The headers are sent even when the inner handler throws; by default the middleware rethrows so the framework's error handler still runs, and with `rethrow` false it returns a 500 response carrying the same headers.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly TrustedProxies $proxies, ?array $cspPolicy = null, private readonly bool $rethrow = true, private readonly ?ResponseFactoryInterface $responseFactory = null, private readonly int $hstsMaxAge = 31536000, private readonly bool $hstsIncludeSubDomains = true, private readonly ?string $cspReportUri = null, private readonly bool $cspReportOnly = false, private readonly ?string $coop = 'same-origin', private readonly ?string $corp = null, private readonly ?array $permissionsPolicy = null, private readonly bool $hstsPreload = false, private readonly bool $trustedTypesEnabled = false, private readonly array $trustedTypesPolicies = ['default'], private readonly bool $noStoreEnabled = false, private readonly array $noStorePaths = [])` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |

### `Headers\InvalidCspReportUriException` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#headers-invalidcspreporturiexception)

`final class InvalidCspReportUriException extends InvalidArgumentException` in `src/Headers/InvalidCspReportUriException.php`. Thrown when the configured CSP report URI is not a valid report destination: it must be an absolute `https://` URL with a host, or a path relative to the page's origin that starts with a single slash. A report endpoint the browser cannot reach is worse than none, and a malformed value can break the Reporting-Endpoints header or inject a second header line.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(string $uri)` |

## Health[#](https://helmet.apps.wayne.edu/docs/reference/api.html#health)

### `Health\HealthCheck` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#health-healthcheck)

`final class HealthCheck implements MiddlewareInterface` in `src/Health/HealthCheck.php`. Answers the health check path (default `/up`) with 200 and the body "ok" without touching the application, so a running container reports up even while the app is slow. Any other request is passed through unchanged.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly ResponseFactoryInterface $responseFactory, private readonly StreamFactoryInterface $streamFactory, private readonly string $path = '/up')` |
| `process()` | `public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` |

## Laravel[#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel)

The Laravel adapter. The service provider wires the config into the app; the four middlewares put the core classes into the request pipeline; the two route classes register the optional endpoints, which stay off until an app opts in.

### `Laravel\HelmetServiceProvider` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-helmetserviceprovider)

`final class HelmetServiceProvider extends ServiceProvider` in `src/Laravel/HelmetServiceProvider.php`. Merges and publishes `config/helmet.php`, copies `helmet.proxies` into Laravel's trusted-proxy list when the app has not set `trustedproxy.proxies` itself (an explicit `trustProxies(at: ...)` call still wins), and registers the `cspReport` and `helmetMetrics` route macros. It registers no routes of its own; the `/up` health route is left to Laravel's built-in `health: '/up'` option.

| Member | Signature |
| --- | --- |
| `register()` | `public function register(): void` |
| `boot()` | `public function boot(): void` |

### `Laravel\SecurityHeaders` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-securityheaders)

`final class SecurityHeaders` in `src/Laravel/SecurityHeaders.php`. The Laravel adapter for the security headers. It reads the `helmet` config and merges per-directive CSP overrides over the core default, sends the same header set as the core middleware, resets the Vite nonce to a fresh random one after the response (a nonce that outlives its request is a nonce an attacker may have seen), and injects a `<meta property="csp-nonce">` tag right after the opening `<head>` tag in HTML responses. A nonce page differs per request, so on an HTML response whose `Cache-Control` names `public` or `s-maxage` it replaces `public` with `private` and drops `s-maxage`; every other directive, and every non-HTML response, is left exactly as written.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\RequestForgeryGuard` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-requestforgeryguard)

`final class RequestForgeryGuard` in `src/Laravel/RequestForgeryGuard.php`. The Laravel adapter for the request-forgery guard. When it is enabled it applies the same rule the core PSR-15 middleware and `helmet_boot()` use, through the core `RequestForgeryGuard`, so every consumer path agrees; otherwise the request passes through untouched.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\ForwardedProtoNormalizer` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-forwardedprotonormalizer)

`final class ForwardedProtoNormalizer` in `src/Laravel/ForwardedProtoNormalizer.php`. Makes Laravel agree with HELMET's HTTPS detection. When the request comes from a trusted proxy and `X-Forwarded-Proto` carries more than one value, the header is rewritten to the last value (the one the closest proxy appended) before any code reads it, because Symfony's `Request::isSecure()` reads the first value, which is the client's. Requests from untrusted peers are left alone.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\InjectRibbon` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-injectribbon)

`final class InjectRibbon` in `src/Laravel/InjectRibbon.php`. Injects the environment ribbon into HTML responses so a developer or operator can see at a glance that they are not looking at production. It is injected only when `config('helmet.ribbon')` is true, the response `Content-Type` is HTML, and the environment is one of the non-production ones (local, development, dev, testing, test, staging); it is never injected for production, prod, live, or an empty environment. The ribbon HTML goes right after the opening `<body>` tag, and a ribbon error never breaks the page.

| Member | Signature |
| --- | --- |
| `handle()` | `public function handle(Request $request, Closure $next): Response` |

### `Laravel\CspReportController` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-cspreportcontroller)

`final class CspReportController` in `src/Laravel/CspReportController.php`. Receives browser CSP violation reports. Reports can be numerous and can be large, so the controller caps the body size, accepts only the two report content types, and logs one line per violation (the violated directive, the blocked URI's host, and the document path), never the full body. The legacy `application/csp-report` body is a single object with the report under `csp-report` (kebab-case fields); the Reporting API `application/reports+json` body is a JSON array of report objects, and only the csp-violation entries are logged because browsers batch other report types into the same POST.

| Member | Signature |
| --- | --- |
| `MAX_BODY_BYTES` | `public const int MAX_BODY_BYTES = 16 * 1024;` |
| `MAX_REPORTS` | `public const int MAX_REPORTS = 20;` |
| `__invoke()` | `public function __invoke(Request $request): Response` |

### `Laravel\CspReportRoute` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-cspreportroute)

`final class CspReportRoute` in `src/Laravel/CspReportRoute.php`. Registers the `Route::cspReport()` macro and the POST route it creates. The route carries no authentication of its own (an app that wants to keep the endpoint private should wrap it in its own middleware or route group), excludes the web group's CSRF middleware (browsers POST reports without a CSRF token, and the middleware would reject them with 419), and is throttled by default because the endpoint is public and its input is attacker-influenced; pass `null` as the second argument to register it without a throttle.

| Member | Signature |
| --- | --- |
| `DEFAULT_PATH` | `public const string DEFAULT_PATH = '/csp-report';` |
| `ROUTE_NAME` | `public const string ROUTE_NAME = 'helmet.csp-report';` |
| `DEFAULT_THROTTLE` | `public const string DEFAULT_THROTTLE = 'throttle:60,1';` |
| `registerMacro()` | `public static function registerMacro(): void` |
| `register()` | `public static function register(string $path = self::DEFAULT_PATH, ?string $throttle = self::DEFAULT_THROTTLE): \Illuminate\Routing\Route` |

### `Laravel\MetricsController` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-metricscontroller)

`final class MetricsController` in `src/Laravel/MetricsController.php`. Serves the metrics endpoint, fail-closed. When `helmet.metrics.enabled` is false (the default) it answers 404 so the route is not advertised; when it is enabled, a request is served only if it presents the configured bearer token or comes from an allowed client IP, anything else is 403; when it is enabled but neither a token nor an allow list is configured, it answers 404 and logs a warning, because a misconfiguration must not serve metrics. The client IP is resolved through the `helmet.proxies` trusted-proxy list, so a spoofed `X-Forwarded-For` from an untrusted peer cannot grant access.

| Member | Signature |
| --- | --- |
| `__invoke()` | `public function __invoke(Request $request): Response` |

### `Laravel\MetricsRoute` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#laravel-metricsroute)

`final class MetricsRoute` in `src/Laravel/MetricsRoute.php`. Registers the `Route::helmetMetrics()` macro and the GET route it creates. The route is a plain GET to `MetricsController` and carries no authentication of its own: the controller enforces the bearer token and the client IP allow list from the `helmet.metrics` config. It is throttled by default because, even protected, it is a public URL that a scraper or a curious client can hit; pass `null` as the second argument to register it without a throttle.

| Member | Signature |
| --- | --- |
| `DEFAULT_PATH` | `public const string DEFAULT_PATH = '/metrics';` |
| `ROUTE_NAME` | `public const string ROUTE_NAME = 'helmet.metrics';` |
| `DEFAULT_THROTTLE` | `public const string DEFAULT_THROTTLE = 'throttle:60,1';` |
| `registerMacro()` | `public static function registerMacro(): void` |
| `register()` | `public static function register(string $path = self::DEFAULT_PATH, ?string $throttle = self::DEFAULT_THROTTLE): \Illuminate\Routing\Route` |

## Logging[#](https://helmet.apps.wayne.edu/docs/reference/api.html#logging)

### `Logging\Logging` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#logging-logging)

`final class Logging` in `src/Logging/Logging.php`. Creates a Monolog logger that writes JSON lines at the given minimum level, easy to ship to a log aggregator and to grep on the host. Secrets stay out of the logs: a processor redacts known sensitive keys (passwords, tokens, session IDs, keys, connection strings) from the record's context and extra before anything is written. A logging failure never takes the request down: the configured stream is wrapped in a fallback, so if it cannot be written the record goes to `php://stderr` instead. Every record also carries a request ID in `extra.request_id`, generated by HELMET and never taken from an inbound header, so the lines of one request can be tied together.

| Member | Signature |
| --- | --- |
| `create()` | `public static function create(string $channel, string $streamOrPath, string $level = 'info', ?string $requestId = null): LoggerInterface` |
| `requestId()` | `public static function requestId(): string` |
| `isValidRequestId()` | `public static function isValidRequestId(string $id): bool` |
| `addRequestId()` | `public static function addRequestId(LogRecord $record): LogRecord` |
| `redact()` | `public static function redact(LogRecord $record): LogRecord` |

## Metrics[#](https://helmet.apps.wayne.edu/docs/reference/api.html#metrics)

### `Metrics\Metrics` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#metrics-metrics)

`final class Metrics` in `src/Metrics/Metrics.php`. Framework-free builder of the metrics payload: it knows nothing about Laravel, raw PHP, or any web framework. It builds a CollectorRegistry, registers the HELMET info metric (and, when a policy source is given, the PHP version status metric), and renders the result in the Prometheus text exposition format. The default storage adapter is InMemory, which is correct for a request scope: each request builds its own registry and renders it once. Pass an APCng adapter (`Prometheus\Storage\APCng`) to share counters across requests on a long-running process.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(?Adapter $storage = null, ?PolicySource $policy = null, ?DateTimeInterface $now = null, ?string $version = null)` |
| `render()` | `public function render(): string` |
| `mimeType()` | `public static function mimeType(): string` |

### `Metrics\MetricsGate` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#metrics-metricsgate)

`final class MetricsGate` in `src/Metrics/MetricsGate.php`. Decides whether a request may read the metrics endpoint. The decision is fail-closed: disabled gives 404 (the route is not advertised), enabled but neither a token nor an allow list configured gives 404 plus a warning (a misconfiguration must not serve metrics), a correct bearer token or an allowed client IP gives 200, and anything else gives 403. The token is compared with `hash_equals` (constant time); the client IP is the one resolved by `ClientIp`, which already honors the trusted-proxy list. An empty token is the same as no token.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly bool $enabled, ?string $token, private readonly IpAllowList $allow)` |
| `decide()` | `public function decide(?string $requestToken, string $clientIp): MetricsDecision` |

### `Metrics\MetricsDecision` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#metrics-metricsdecision)

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

### `Metrics\BearerToken` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#metrics-bearertoken)

`final class BearerToken` in `src/Metrics/BearerToken.php`. Extracts the bearer token from an authorization header value, or returns null when there is none.

| Member | Signature |
| --- | --- |
| `from()` | `public static function from(?string $authorizationHeader): ?string` |

## Platform[#](https://helmet.apps.wayne.edu/docs/reference/api.html#platform)

### `Platform\Presets` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#platform-presets)

`final class Presets` in `src/Platform/Presets.php`. The platform presets: each preset names the trusted proxies for a deployment platform, so an app does not hard-code addresses that belong to the platform. `citadel` trusts the Docker bridge gateway to the HA-Proxy front (a published app port that is source-NATed to the gateway address would make a direct client look like the proxy); `docker` trusts nothing, for a plain Docker deployment with no front proxy. The headers a trusted front proxy may set are the same for every preset: `X-Forwarded-For` and `X-Forwarded-Proto`. `X-Forwarded-Host` and `X-Forwarded-Prefix` are deliberately not in the set (a platform that rewrites the host would let a client pre-seed them), and `X-Forwarded-Port` is out too, because Citadel's HA-Proxy never sends it.

| Member | Signature |
| --- | --- |
| `TRUSTED_HEADERS` | `public const TRUSTED_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Proto'];` |
| `proxies()` | `public static function proxies(string $name): array` |
| `exists()` | `public static function exists(string $name): bool` |
| `names()` | `public static function names(): array` |

### `Platform\UnknownPresetException` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#platform-unknownpresetexception)

`final class UnknownPresetException extends \InvalidArgumentException` in `src/Platform/UnknownPresetException.php`. Thrown when a config names a preset the package does not know, so a typo fails loudly at boot instead of silently trusting nothing.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(string $name)` |

## Policy[#](https://helmet.apps.wayne.edu/docs/reference/api.html#policy)

### `Policy\PolicySource` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#policy-policysource)

`interface PolicySource` in `src/Policy/PolicySource.php`. The interface a component-version policy source implements.

| Member | Signature |
| --- | --- |
| `components()` | `public function components(): array;` |

### `Policy\JsonFilePolicySource` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#policy-jsonfilepolicysource)

`final class JsonFilePolicySource implements PolicySource` in `src/Policy/JsonFilePolicySource.php`. Reads a CREED-format policy file: a JSON object with a `schema_version` and a `components` object. It throws `PolicyReadException` on any problem it finds: a missing or unreadable file, invalid JSON, a schema_version it does not know, or a missing `components` object. Callers that must not throw (such as the version warning at request time) catch `PolicyReadException`.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly string $path)` |
| `components()` | `public function components(): array` |

### `Policy\PolicyReadException` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#policy-policyreadexception)

`final class PolicyReadException extends \RuntimeException` in `src/Policy/PolicyReadException.php`. Thrown by `JsonFilePolicySource` when the policy file cannot be read or parsed. It has no public members of its own.

### `Policy\VersionStatus` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#policy-versionstatus)

`final class VersionStatus` in `src/Policy/VersionStatus.php`. Determines the support status of a component version on a given date: `active` on or before its active_until date, `security` after active_until and on or before its security_until date, `eol` after security_until, and `unknown` when the component or version is not in the policy. The date is injected, so the result is a pure function of the policy, the component, the version, and the date, which makes the boundaries testable without waiting for real support windows to close.

| Member | Signature |
| --- | --- |
| `ACTIVE` | `public const ACTIVE = 'active';` |
| `SECURITY` | `public const SECURITY = 'security';` |
| `EOL` | `public const EOL = 'eol';` |
| `UNKNOWN` | `public const UNKNOWN = 'unknown';` |
| `__construct()` | `public function __construct(private readonly DateTimeInterface $now, private readonly array $components)` |
| `status()` | `public function status(string $component, string $version): string` |

### `Policy\VersionWarning` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#policy-versionwarning)

`final class VersionWarning` in `src/Policy/VersionWarning.php`. Produces a human-readable warning when a component version is in its security-only phase or is end of life; no warning when the version is active or unknown. The date is injected, so the result is a pure function of the policy, the component, the version, and the date.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly PolicySource $source, private readonly DateTimeInterface $now)` |
| `warning()` | `public function warning(string $component, string $version): ?string` |

## Proxy[#](https://helmet.apps.wayne.edu/docs/reference/api.html#proxy)

### `Proxy\ClientIp` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#proxy-clientip)

`final class ClientIp` in `src/Proxy/ClientIp.php`. Resolves the client IP. When the peer (`REMOTE_ADDR`) is a trusted proxy, the client IP is read from `X-Forwarded-For`: the header is a comma-separated list in which the rightmost entry is the one the closest proxy recorded, so walk from the right, skipping trusted proxies, and the first remaining entry is the client. An entry that is not a valid IP address fails closed: the peer is returned instead of continuing to an earlier entry. IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) are unmapped to `a.b.c.d` before matching, so a dual-stack proxy still matches an IPv4 trust list. When the peer is not a trusted proxy, the header is ignored entirely and `REMOTE_ADDR` is the client.

| Member | Signature |
| --- | --- |
| `resolve()` | `public static function resolve(array $serverParams, TrustedProxies $proxies, ?LoggerInterface $logger = null): string` |

### `Proxy\HttpsDetector` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#proxy-httpsdetector)

`final class HttpsDetector` in `src/Proxy/HttpsDetector.php`. Decides whether the request arrived over HTTPS. If the SAPI reports a TLS connection, the request is HTTPS; this is the only signal trusted unconditionally. Otherwise, only a trusted proxy's `X-Forwarded-Proto: https` counts, and only the last non-empty value of the list is read (the one the closest proxy appended), trimmed and lower-cased before comparison. `X-Forwarded-Port` is deliberately never read: the front proxy sets `X-Forwarded-Proto` and the port is always 443 on the TLS edge, so the port header adds no information and only creates a spoofing surface.

| Member | Signature |
| --- | --- |
| `isHttps()` | `public static function isHttps(array $serverParams, TrustedProxies $proxies): bool` |

### `Proxy\IpAllowList` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#proxy-ipallowlist)

`final class IpAllowList` in `src/Proxy/IpAllowList.php`. Matches a client IP address against an allow list of exact IPv4 or IPv6 addresses and CIDR ranges. Every entry is validated at construction time: an entry that is not a valid address or CIDR throws, a /0 prefix (which would allow every address of its family) is refused, and the wildcard "\*" is refused, so bad config fails loudly instead of looking like working config while allowing nothing, or the wrong thing. The matching itself is delegated to Symfony's IpUtils.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(array $entries = [])` |
| `allows()` | `public function allows(string $ip): bool` |
| `isEmpty()` | `public function isEmpty(): bool` |

### `Proxy\TrustedProxies` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#proxy-trustedproxies)

`final class TrustedProxies` in `src/Proxy/TrustedProxies.php`. Matches a client or peer IP address against the list of trusted proxies. The list may contain exact IPv4 or IPv6 addresses and CIDR ranges, with the same validation rules as `IpAllowList`: an invalid entry throws, a /0 prefix is refused, and the wildcard "\*" is refused, so bad config fails loudly at boot. The matching itself is delegated to Symfony's IpUtils.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(array $proxies = [])` |
| `isTrusted()` | `public function isTrusted(string $ip): bool` |
| `unmapIpv4MappedIpv6()` | `public static function unmapIpv4MappedIpv6(string $ip): string` |
| `isEmpty()` | `public function isEmpty(): bool` |

## Ribbon[#](https://helmet.apps.wayne.edu/docs/reference/api.html#ribbon)

### `Ribbon\Ribbon` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#ribbon-ribbon)

`final class Ribbon` in `src/Ribbon/Ribbon.php`. An environment ribbon: a small visual indicator that shows which environment an app is running in (for example "STAGING" or "DEVELOPMENT"), meant to be injected near the top of the page so a developer or operator can see at a glance that they are not looking at production. It is disabled by default in production and enabled in every other environment. The environment name is escaped to prevent XSS. When a policy file is configured, the ribbon also shows a warning line for each component version that is in its security-only phase or end of life, from CREED's policy.json; a missing, unreadable or invalid policy file produces no warning and never breaks the page.

| Member | Signature |
| --- | --- |
| `__construct()` | `public function __construct(private readonly string $environment, private readonly bool $enabled = true, ?string $policyPath = null, private readonly array $components = [], ?DateTimeInterface $now = null)` |
| `shouldShow()` | `public function shouldShow(): bool` |
| `environment()` | `public function environment(): string` |
| `html()` | `public function html(): string` |

## Session[#](https://helmet.apps.wayne.edu/docs/reference/api.html#session)

### `Session\SessionHardening` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#session-sessionhardening)

`final class SessionHardening` in `src/Session/SessionHardening.php`. Applies hardened session settings before the session starts: the secure cookie mode (`auto` or `always`, so in always mode the cookie is only sent over TLS), `cookie_lifetime` 0 (a browser-session cookie that ends when the browser closes), `cache_limiter` nocache (so pages cannot be cached and replayed with the session attached), `cookie_path` and `cookie_domain` of `/` and empty (so the cookie is host-only), and the name `__Host-WSUSESSID` on HTTPS or `WSUSESSID` on HTTP, but only while the name is still PHP's default `PHPSESSID` (an app-chosen name is left alone, because SESAME reads whatever `session_name()` returns). `session.sid_length` is deliberately not touched: it is deprecated as of PHP 8.4 and the platform image sets it to 26.

| Member | Signature |
| --- | --- |
| `SECURE_AUTO` | `public const SECURE_AUTO = 'auto';` |
| `SECURE_ALWAYS` | `public const SECURE_ALWAYS = 'always';` |
| `apply()` | `public static function apply(bool $https, ?array $settings = null, string $secureMode = self::SECURE_AUTO): void` |

## Support[#](https://helmet.apps.wayne.edu/docs/reference/api.html#support)

### `Support\Setting` [#](https://helmet.apps.wayne.edu/docs/reference/api.html#support-setting)

`final class Setting` in `src/Support/Setting.php`. Resolves one HELMET setting from outside the package. The real environment wins (getenv, then `$_ENV`, then `$_SERVER`); an empty string counts as not set, so a blank value in the environment falls through to Playbook. When the environment has no value for the key, the private `waynestate/playbook` package supplies it, if it is installed. When neither has it, null comes back and the caller applies its own default. Playbook is optional by design: the package never requires it, it only looks for the class. That is how a WSU value can arrive at an app without this public repo naming a single one of them.

| Member | Signature |
| --- | --- |
| `get()` | `public static function get(string $key): ?string` |

## Related[#](https://helmet.apps.wayne.edu/docs/reference/api.html#related)

- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
- [Proof and tests](https://helmet.apps.wayne.edu/docs/reference/proof.html)
- [Quick start](https://helmet.apps.wayne.edu/docs/guides/quick-start.html)
- [Raw PHP](https://helmet.apps.wayne.edu/docs/guides/usage-raw-php.html)
- [PSR-15 middleware](https://helmet.apps.wayne.edu/docs/guides/usage-psr15.html)
- [Laravel](https://helmet.apps.wayne.edu/docs/guides/usage-laravel.html)

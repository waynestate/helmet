<?php

declare(strict_types=1);

namespace Waynestate\Helmet;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Csrf\RequestForgeryGuard;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Logging\Logging;
use Waynestate\Helmet\Metrics\BearerToken;
use Waynestate\Helmet\Metrics\Metrics;
use Waynestate\Helmet\Metrics\MetricsGate;
use Waynestate\Helmet\Platform\Presets;
use Waynestate\Helmet\Platform\UnknownPresetException;
use Waynestate\Helmet\Policy\PolicySource;
use Waynestate\Helmet\Proxy\ClientIp;
use Waynestate\Helmet\Proxy\HttpsDetector;
use Waynestate\Helmet\Proxy\IpAllowList;
use Waynestate\Helmet\Proxy\TrustedProxies;
use Waynestate\Helmet\Session\SessionHardening;

if (!function_exists('helmet_boot')) {
    /**
     * Boot HELMET in a framework-free PHP app.
     *
     * Call this at the very top of the entry point, before any output. It:
       *  - decides whether the request is HTTPS from the trusted-proxy list,
       *  - hardens the session settings (throws if a session is already active),
       *  - removes the X-Powered-By header,
       *  - answers the health check path with "ok" and stops,
       *  - rejects a state-changing request when the request-forgery guard is
       *    enabled and the request is not same-origin (or from a trusted
       *    origin),
       *  - sends the security headers (including a per-request CSP nonce),
      *  - sets up a JSON logger (if configured).
     *
     * It reads $_SERVER directly rather than building a PSR-7 request, so it
     * works in plain PHP without a server-request factory.
     *
     * The trusted proxies come from, in order of precedence:
     *  - "trusted_proxies": an explicit list of IPs or CIDR ranges.
     *  - "preset": a platform preset name (see
     *    Waynestate\Helmet\Platform\Presets), e.g. "citadel" or "docker".
     *  - neither: an empty list, which trusts nothing.
     *
       * @param array{
       *     trusted_proxies?: list<string>,
       *     preset?: string,
       *     csp?: array<string, list<string>|null>,
       *     csp_report_uri?: string,
       *     csp_report_only?: bool,
       *     csp_trusted_types?: array{enabled?: bool, policies?: list<string>},
       *     coop?: string|null,
       *     corp?: string|null,
       *     permissions_policy?: array<string, list<string>>|null,
       *     health_path?: string,
       *     hsts_max_age?: int,
       *     hsts_include_subdomains?: bool,
       *     hsts_preload?: bool,
       *     no_store?: array{enabled?: bool, paths?: list<string>},
       *     csrf?: array{enabled?: bool, allow_same_site?: bool, trusted_origins?: list<string>, except?: list<string>},
        *     log?: array{channel?: string, stream?: string, level?: 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency', request_id?: string}
        * } $config
      *
      * @return array{nonce: string, logger: LoggerInterface|null, request_id: string}
     *
     * @throws UnknownPresetException If "preset" names a preset the package
     *         does not know.
        * @throws \InvalidArgumentException If "trusted_proxies" or "preset" is
        *         the wrong type, "csrf" is the wrong type, or "coop", "corp",
        *         "permissions_policy", "csp_trusted_types", "no_store" or a
        *         "csrf" value is invalid, or "log.request_id" is not a string.
     * @throws \Waynestate\Helmet\Headers\InvalidCspReportUriException If
     *         "csp_report_uri" is not a valid report destination.
     * @throws \LogicException If a session is already active.
     */
    function helmet_boot(array $config): array
    {
        $proxyList = null;
        if (isset($config['trusted_proxies'])) {
            // @phpstan-ignore booleanNot.alwaysFalse (the docblock says list<string>, but a caller may pass a non-array at runtime)
            if (!is_array($config['trusted_proxies'])) {
                throw new \InvalidArgumentException('helmet_boot() "trusted_proxies" must be a list.');
            }
            $proxyList = $config['trusted_proxies'];
        } elseif (isset($config['preset'])) {
            // @phpstan-ignore booleanNot.alwaysFalse (the docblock says string, but a caller may pass a non-string at runtime)
            if (!is_string($config['preset']) || $config['preset'] === '') {
                throw new \InvalidArgumentException('helmet_boot() "preset" must be a non-empty string.');
            }
            $proxyList = Presets::proxies($config['preset']);
        } else {
            $proxyList = [];
        }

        $proxies = new TrustedProxies($proxyList);
        $isHttps = HttpsDetector::isHttps($_SERVER, $proxies);

        SessionHardening::apply($isHttps);

        // Strip the server signature before any early answer below (the
        // health check and the forgery rejection both exit), so no response
        // leaves the door still wearing X-Powered-By.
        header_remove('X-Powered-By');

        $logger = null;
        $requestId = Logging::requestId();
        $log = $config['log'] ?? null;
        if (is_array($log)) {
            $suppliedRequestId = null;
            if (isset($log['request_id'])) {
                // @phpstan-ignore booleanNot.alwaysFalse (the docblock says string, but a caller may pass a non-string at runtime)
                if (!is_string($log['request_id'])) {
                    throw new \InvalidArgumentException('helmet_boot() "log.request_id" must be a string.');
                }
                $suppliedRequestId = $log['request_id'];
            }
            $logger = Logging::create(
                $log['channel'] ?? 'helmet',
                $log['stream'] ?? 'php://stderr',
                $log['level'] ?? 'info',
                $suppliedRequestId,
            );
            if ($suppliedRequestId !== null && Logging::isValidRequestId($suppliedRequestId)) {
                $requestId = $suppliedRequestId;
            }
        }

        $healthPath = $config['health_path'] ?? '/up';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // REQUEST_URI includes the query string; the path is the part before ?.
        $path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];

        if (($method === 'GET' || $method === 'HEAD') && $path === $healthPath) {
            helmet_up();
        }

        $csrf = $config['csrf'] ?? null;
        if ($csrf !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($csrf)) {
                throw new \InvalidArgumentException('helmet_boot() "csrf" must be an array.');
            }
        }

        if (is_array($csrf) && ($csrf['enabled'] ?? false) === true) {
            $csrfAllowSameSite = $csrf['allow_same_site'] ?? false;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says bool, but a caller may pass a non-bool at runtime)
            if (!is_bool($csrfAllowSameSite)) {
                throw new \InvalidArgumentException('helmet_boot() "csrf.allow_same_site" must be a bool.');
            }
            $csrfTrustedOrigins = $csrf['trusted_origins'] ?? [];
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says list<string>, but a caller may pass a non-array at runtime)
            if (!is_array($csrfTrustedOrigins)) {
                throw new \InvalidArgumentException('helmet_boot() "csrf.trusted_origins" must be a list.');
            }
            $csrfExcept = $csrf['except'] ?? [];
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says list<string>, but a caller may pass a non-array at runtime)
            if (!is_array($csrfExcept)) {
                throw new \InvalidArgumentException('helmet_boot() "csrf.except" must be a list.');
            }

            $guard = new RequestForgeryGuard(
                $proxies,
                null,
                null,
                $csrfAllowSameSite,
                $csrfTrustedOrigins,
                $csrfExcept,
            );
            $secFetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
            $secFetchSite = is_string($secFetchSite) && $secFetchSite !== '' ? $secFetchSite : null;
            $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
            $origin = is_string($origin) && $origin !== '' ? $origin : null;
            $requestOrigin = RequestForgeryGuard::originFromServerParams($_SERVER, $proxies);

            if (!$guard->allows($method, $path, $secFetchSite, $origin, $requestOrigin)) {
                helmet_forbidden();
            }
        }

        $cspPolicy = SecurityHeaders::mergePolicies(
            SecurityHeaders::defaultCspPolicy(),
            $config['csp'] ?? [],
        );
        $nonce = SecurityHeaders::generateNonce();
        // "coop" uses array_key_exists, not ??, so an explicit null means
        // "send no COOP header" (the same as the Laravel config), while an
        // absent key keeps the same-origin default.
        $coop = array_key_exists('coop', $config) ? $config['coop'] : 'same-origin';

        $trustedTypes = $config['csp_trusted_types'] ?? null;
        $trustedTypesEnabled = false;
        $trustedTypesPolicies = ['default'];
        if ($trustedTypes !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($trustedTypes)) {
                throw new \InvalidArgumentException('helmet_boot() "csp_trusted_types" must be an array.');
            }
            $trustedTypesEnabled = $trustedTypes['enabled'] ?? false;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says bool, but a caller may pass a non-bool at runtime)
            if (!is_bool($trustedTypesEnabled)) {
                throw new \InvalidArgumentException('helmet_boot() "csp_trusted_types.enabled" must be a bool.');
            }
            $trustedTypesPolicies = $trustedTypes['policies'] ?? ['default'];
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says list<string>, but a caller may pass a non-array at runtime)
            if (!is_array($trustedTypesPolicies)) {
                throw new \InvalidArgumentException('helmet_boot() "csp_trusted_types.policies" must be a list.');
            }
        }

        $noStore = $config['no_store'] ?? null;
        $noStoreEnabled = false;
        $noStorePaths = [];
        if ($noStore !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($noStore)) {
                throw new \InvalidArgumentException('helmet_boot() "no_store" must be an array.');
            }
            $noStoreEnabled = $noStore['enabled'] ?? false;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says bool, but a caller may pass a non-bool at runtime)
            if (!is_bool($noStoreEnabled)) {
                throw new \InvalidArgumentException('helmet_boot() "no_store.enabled" must be a bool.');
            }
            $noStorePaths = $noStore['paths'] ?? [];
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says list<string>, but a caller may pass a non-array at runtime)
            if (!is_array($noStorePaths)) {
                throw new \InvalidArgumentException('helmet_boot() "no_store.paths" must be a list.');
            }
        }

        $headers = SecurityHeaders::build(
            $cspPolicy,
            $nonce,
            $isHttps,
            $config['hsts_max_age'] ?? 31536000,
            $config['hsts_include_subdomains'] ?? true,
            $config['csp_report_uri'] ?? null,
            $config['csp_report_only'] ?? false,
            $coop,
            $config['corp'] ?? null,
            $config['permissions_policy'] ?? null,
            $config['hsts_preload'] ?? false,
            $trustedTypesEnabled,
            $trustedTypesPolicies,
        );

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        if (SecurityHeaders::noStoreApplies($noStoreEnabled, $path, $noStorePaths)) {
            $hasCacheControl = false;
            foreach (headers_list() as $sentHeader) {
                if (stripos($sentHeader, 'Cache-Control:') === 0) {
                    $hasCacheControl = true;
                    break;
                }
            }
            if (!$hasCacheControl) {
                header('Cache-Control: no-store', true);
            }
        }

        return ['nonce' => $nonce, 'logger' => $logger, 'request_id' => $requestId];
    }
}

if (!function_exists('helmet_up_response')) {
    /**
     * The health check answer, as data.
     *
     * Returns the status code, headers and body that answer the health
     * check path. It does not send anything; the caller does. This keeps
     * the answer testable and lets helmet_up() and helmet_boot() share
     * one definition, so the two paths cannot drift apart.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    function helmet_up_response(): array
    {
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => 'ok',
        ];
    }
}

if (!function_exists('helmet_up')) {
    /**
     * Answer the health check path and stop.
     *
     * A raw PHP app with no front controller (or an Apache setup with no
     * rewrite) never reaches helmet_boot() for /up. Point the probe at a
     * small public/up.php that requires the autoloader and calls this:
     *
     *     <?php
     *     require __DIR__ . '/../vendor/autoload.php';
     *     helmet_up();
     *
     * It sends exactly what helmet_boot() sends for the health path
     * (200, Content-Type: text/plain; charset=utf-8, Cache-Control:
     * no-store, body "ok") and exits. No session, no other headers, no
     * config read.
     */
    function helmet_up(): never
    {
        $response = helmet_up_response();

        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $response['body'];
        exit;
    }
}

if (!function_exists('helmet_forbidden_response')) {
    /**
     * The request-forgery rejection answer, as data.
     *
     * Returns the status code, headers and body that answer a rejected
     * state-changing request. It does not send anything; the caller does.
     * This keeps the answer testable and lets helmet_forbidden() and
     * helmet_boot() share one definition, so the two paths cannot drift
     * apart.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    function helmet_forbidden_response(): array
    {
        return [
            'status' => 403,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => 'Forbidden',
        ];
    }
}

if (!function_exists('helmet_forbidden')) {
    /**
     * Reject a state-changing request and stop.
     *
     * It sends exactly what helmet_forbidden_response() returns (403,
     * Content-Type: text/plain; charset=utf-8, Cache-Control: no-store,
     * body "Forbidden") and exits. It never echoes request headers.
     */
    function helmet_forbidden(): never
    {
        $response = helmet_forbidden_response();

        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $response['body'];
        exit;
    }
}

if (!function_exists('helmet_csp_nonce_meta')) {
    /**
     * Build the csp-nonce meta tag for Vite's runtime.
     *
     * Vite's dev runtime reads <meta property="csp-nonce" nonce="..."> to
     * nonce the CSS and preload tags it adds after page load. Print this in
     * <head> of every page, with the same nonce helmet_boot() returned.
     *
     * The nonce is escaped, so a nonce with a quote cannot break out of the
     * attribute.
     */
    function helmet_csp_nonce_meta(string $nonce): string
    {
        return '<meta property="csp-nonce" nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
    }
}

if (!function_exists('helmet_metrics')) {
    /**
     * Build the /metrics response for a framework-free PHP app.
     *
     * Returns the status code, content type, and body for the metrics
     * endpoint. It does not send headers or echo; the caller does. This keeps
     * it testable and lets the caller decide how to serve it.
     *
     * The endpoint is fail-closed, exactly like the Laravel controller:
     * disabled -> 404, enabled but unauthorized -> 403, enabled but
     * misconfigured (no token and no allow list) -> 404 plus a warning.
     *
     * The trusted proxies come from, in order of precedence (the same rule
     * helmet_boot() uses):
     *  - "trusted_proxies": an explicit list of IPs or CIDR ranges.
     *  - "preset": a platform preset name (see
     *    Waynestate\Helmet\Platform\Presets), e.g. "citadel" or "docker".
     *  - neither: an empty list, which trusts nothing.
     *
     * @param array{
     *     enabled?: bool,
     *     token?: string|null,
     *     allow?: list<string>,
     *     trusted_proxies?: list<string>,
     *     preset?: string,
     *     policy?: PolicySource|null,
     *     version?: string|null,
     *     logger?: LoggerInterface|null,
     * } $config
     *
     * @return array{status: int, content_type: string, body: string}
     *
     * @throws UnknownPresetException If "preset" names a preset the package
     *         does not know.
     * @throws \InvalidArgumentException If "enabled", "trusted_proxies" or
     *         "preset" is the wrong type, or an "allow" entry is invalid.
     */
    function helmet_metrics(array $config): array
    {
        $enabled = $config['enabled'] ?? false;
        // @phpstan-ignore function.alreadyNarrowedType (the docblock says bool, but a caller may pass a non-bool at runtime)
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException('helmet_metrics() "enabled" must be a bool.');
        }

        if (!$enabled) {
            return [
                'status' => 404,
                'content_type' => 'text/plain; charset=utf-8',
                'body' => '',
            ];
        }

        $proxyList = null;
        if (isset($config['trusted_proxies'])) {
            // @phpstan-ignore booleanNot.alwaysFalse (the docblock says list<string>, but a caller may pass a non-array at runtime)
            if (!is_array($config['trusted_proxies'])) {
                throw new \InvalidArgumentException('helmet_metrics() "trusted_proxies" must be a list.');
            }
            $proxyList = $config['trusted_proxies'];
        } elseif (isset($config['preset'])) {
            // @phpstan-ignore booleanNot.alwaysFalse (the docblock says string, but a caller may pass a non-string at runtime)
            if (!is_string($config['preset']) || $config['preset'] === '') {
                throw new \InvalidArgumentException('helmet_metrics() "preset" must be a non-empty string.');
            }
            $proxyList = Presets::proxies($config['preset']);
        } else {
            $proxyList = [];
        }
        $proxies = new TrustedProxies($proxyList);

        $clientIp = ClientIp::resolve($_SERVER, $proxies);
        $requestToken = BearerToken::from($_SERVER['HTTP_AUTHORIZATION'] ?? null);

        $gate = new MetricsGate(
            true,
            $config['token'] ?? null,
            new IpAllowList($config['allow'] ?? []),
        );
        $decision = $gate->decide($requestToken, $clientIp);

        if ($decision->reason() === 'misconfigured') {
            $logger = $config['logger'] ?? null;
            if ($logger !== null) {
                $logger->warning(
                    'HELMET metrics endpoint is enabled but has neither a token nor an allow list; refusing to serve metrics.',
                );
            }
        }

        if (!$decision->isAllowed()) {
            return [
                'status' => $decision->status(),
                'content_type' => 'text/plain; charset=utf-8',
                'body' => '',
            ];
        }

        $metrics = new Metrics(
            null,
            $config['policy'] ?? null,
            null,
            $config['version'] ?? null,
        );

        return [
            'status' => 200,
            'content_type' => Metrics::mimeType(),
            'body' => $metrics->render(),
        ];
    }
}

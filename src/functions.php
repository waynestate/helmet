<?php

declare(strict_types=1);

namespace Waynestate\Helmet;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Csrf\RequestForgeryGuard;
use Waynestate\Helmet\Error\GenericErrorResponse;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Health\Readiness;
use Waynestate\Helmet\Logging\Logging;
use Waynestate\Helmet\Metrics\BearerToken;
use Waynestate\Helmet\Metrics\Metrics;
use Waynestate\Helmet\Metrics\MetricsGate;
use Waynestate\Helmet\Metrics\MetricsStorage;
use Waynestate\Helmet\Metrics\MetricsStorageException;
use Waynestate\Helmet\Metrics\RequestMetrics;
use Waynestate\Helmet\Metrics\RequestTimer;
use Waynestate\Helmet\Platform\Presets;
use Waynestate\Helmet\Platform\UnknownPresetException;
use Waynestate\Helmet\Policy\JsonFilePolicySource;
use Waynestate\Helmet\Policy\PolicyReadException;
use Waynestate\Helmet\Policy\PolicySource;
use Waynestate\Helmet\Proxy\ClientIp;
use Waynestate\Helmet\Proxy\HttpsDetector;
use Waynestate\Helmet\Proxy\IpAllowList;
use Waynestate\Helmet\Proxy\TrustedProxies;
use Waynestate\Helmet\Session\SessionHardening;
use Waynestate\Helmet\Support\Setting;

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
       *  - answers the readiness path (when "readiness" is enabled) with the
       *    JSON readiness report, 200 or 503, and stops,
       *  - sets up a JSON logger (if configured),
       *  - writes one helmet.broad_trusted_proxy warning (through that
       *    logger, when one was configured) if the trusted-proxy list holds
       *    a broad range.
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
         *     warn_broad_proxies?: bool,
         *     session?: array{secure?: 'auto'|'always'},
       *     csp?: array<string, list<string>|null>,
       *     csp_report_uri?: string,
       *     csp_report_only?: bool,
       *     csp_trusted_types?: array{enabled?: bool, policies?: list<string>},
       *     style_src_attr?: 'inline'|'report'|'none',
       *     coop?: string|null,
       *     corp?: string|null,
       *     permissions_policy?: array<string, list<string>>|null,
       *     health_path?: string,
       *     hsts_max_age?: int,
       *     hsts_include_subdomains?: bool,
       *     hsts_preload?: bool,
       *     no_store?: array{enabled?: bool, paths?: list<string>},
       *     csrf?: array{enabled?: bool, allow_same_site?: bool, trusted_origins?: list<string>, except?: list<string>},
        *     log?: array{channel?: string, stream?: string, level?: 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency', request_id?: string},
        *     error_handler?: array{enabled?: bool},
        *     readiness?: array{enabled?: bool, path?: string, checks?: array<string, callable(): (bool|null)>}
        * } $config
      *
      * @return array{nonce: string, logger: LoggerInterface|null, request_id: string}
     *
     * @throws UnknownPresetException If "preset" names a preset the package
     *         does not know.
      * @throws \InvalidArgumentException If "trusted_proxies", "preset" or
      *         "session" is the wrong type, "csrf" is the wrong type, or
      *         "coop", "corp", "permissions_policy", "csp_trusted_types",
      *         "no_store", "session.secure" or a "csrf" value is invalid, or
      *         "log.request_id" is not a string, or "error_handler" is the
      *         wrong type, or "readiness" is the wrong type, its path is not
      *         an absolute path or equals the health path, or a check name
      *         is invalid or a check is not callable.
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
        // @phpstan-ignore nullCoalesce.offset (the docblock does not name a "logger" key, but a caller may pass one)
        $isHttps = HttpsDetector::isHttps($_SERVER, $proxies, $config['logger'] ?? null);

        $secureMode = SessionHardening::SECURE_AUTO;
        $session = $config['session'] ?? null;
        if ($session !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($session)) {
                throw new \InvalidArgumentException('helmet_boot() "session" must be an array.');
            }
            $secureMode = $session['secure'] ?? SessionHardening::SECURE_AUTO;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says 'auto'|'always', but a caller may pass anything at runtime),booleanAnd.alwaysFalse (same reason; the value check stays live code),booleanOr.alwaysFalse (same reason; the value check stays live code),notIdentical.alwaysFalse (same reason; the value check stays live code)
            if (!is_string($secureMode) || $secureMode !== SessionHardening::SECURE_AUTO && $secureMode !== SessionHardening::SECURE_ALWAYS) {
                throw new \InvalidArgumentException('helmet_boot() "session.secure" must be "auto" or "always".');
            }
        }

        SessionHardening::apply($isHttps, null, $secureMode);

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

        // H21: trust the proxy's address, not its whole network. Warn once at
        // boot when the trusted-proxy list holds a broad range (an IPv4 range
        // shorter than /16 or an IPv6 range shorter than /48): such a range
        // trusts other machines on the same network, any of which could then
        // set X-Forwarded-Proto. Only through a configured logger, and only
        // when warn_broad_proxies has not been switched off. A logging failure
        // never breaks the boot.
        if (($config['warn_broad_proxies'] ?? true) === true && $logger !== null) {
            $broad = $proxies->broadEntries();
            if ($broad !== []) {
                try {
                    $logger->warning('helmet.broad_trusted_proxy', [
                        'event' => 'broad_trusted_proxy',
                        'entries' => $broad,
                    ]);
                } catch (\Throwable) {
                    // A logging failure must never break the boot.
                }
            }
        }

        $errorHandler = $config['error_handler'] ?? null;
        if ($errorHandler !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($errorHandler)) {
                throw new \InvalidArgumentException('helmet_boot() "error_handler" must be an array.');
            }
            $errorHandlerEnabled = $errorHandler['enabled'] ?? false;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says bool, but a caller may pass a non-bool at runtime)
            if (!is_bool($errorHandlerEnabled)) {
                throw new \InvalidArgumentException('helmet_boot() "error_handler.enabled" must be a bool.');
            }
            if ($errorHandlerEnabled === true) {
                // The full detail goes to the log; the user gets a neutral page.
                set_exception_handler(static function (\Throwable $e) use ($logger): never {
                    $response = (new GenericErrorResponse())->build($e, $logger);

                    if (!headers_sent()) {
                        http_response_code($response['status']);
                        foreach ($response['headers'] as $name => $value) {
                            header($name . ': ' . $value, true);
                        }
                    }
                    echo $response['body'];
                    exit(1);
                });
            }
        }

        $healthPath = $config['health_path'] ?? '/up';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // REQUEST_URI includes the query string; the path is the part before ?.
        $path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];

        if (($method === 'GET' || $method === 'HEAD') && $path === $healthPath) {
            helmet_up();
        }

        // Readiness is opt-in and validated on every request (after the
        // liveness answer, so a readiness typo never restarts the app), so a
        // bad config fails at boot rather than on the first probe.
        $readiness = $config['readiness'] ?? null;
        $readinessEnabled = false;
        $readinessPath = Readiness::DEFAULT_PATH;
        $readinessChecks = [];
        if ($readiness !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($readiness)) {
                throw new \InvalidArgumentException('helmet_boot() "readiness" must be an array.');
            }
            $readinessEnabled = $readiness['enabled'] ?? false;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says bool, but a caller may pass a non-bool at runtime)
            if (!is_bool($readinessEnabled)) {
                throw new \InvalidArgumentException('helmet_boot() "readiness.enabled" must be a bool.');
            }
            $readinessPath = $readiness['path'] ?? Readiness::DEFAULT_PATH;
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says string, but a caller may pass a non-string at runtime)
            if (!is_string($readinessPath) || !str_starts_with($readinessPath, '/') || $readinessPath === $healthPath) {
                throw new \InvalidArgumentException('helmet_boot() "readiness.path" must be an absolute path that differs from the health path.');
            }
            $readinessChecks = $readiness['checks'] ?? [];
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says array, but a caller may pass a non-array at runtime)
            if (!is_array($readinessChecks)) {
                throw new \InvalidArgumentException('helmet_boot() "readiness.checks" must be an array of name => callable.');
            }
            // A dry registration against the three HELMET check names, so an
            // invalid or clashing name throws now, not on the first probe.
            $dryRun = (new Readiness())
                ->withHeaders(static fn(): array => [])
                ->withTrustedProxies($proxies)
                ->withDebugOutput(false);
            foreach ($readinessChecks as $checkName => $check) {
                // @phpstan-ignore function.alreadyNarrowedType (the docblock says name => callable, but a caller may pass anything at runtime), function.alreadyNarrowedType (same), booleanOr.alwaysFalse (same)
                if (!is_string($checkName) || !is_callable($check)) {
                    throw new \InvalidArgumentException('helmet_boot() "readiness.checks" must be an array of name => callable.');
                }
                $dryRun = $dryRun->withCheck($checkName, $check);
            }
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
                $logger,
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

        // HELMET-17: the inline style attribute mode. "inline" (the default)
        // keeps style-src-attr 'unsafe-inline' for WayneUI's recipes.
        $styleSrcAttr = $config['style_src_attr'] ?? 'inline';
        // @phpstan-ignore function.alreadyNarrowedType (the docblock says string, but a caller may pass a non-string at runtime)
        if (!is_string($styleSrcAttr)) {
            throw new \InvalidArgumentException('helmet_boot() "style_src_attr" must be "inline", "report" or "none".');
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
            $styleSrcAttr,
        );

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        // The readiness answer goes out after the security headers, so it
        // wears them like any other response. The headers check passes
        // here by construction (the set was just built), and it still names
        // the CSP mode so a probe log shows what the instance enforces.
        if ($readinessEnabled && ($method === 'GET' || $method === 'HEAD') && $path === $readinessPath) {
            $report = Readiness::standard(
                $proxies,
                static fn(): array => $headers,
                ($config['csp_report_only'] ?? false) === true,
                $styleSrcAttr,
                $logger,
            );
            foreach ($readinessChecks as $checkName => $check) {
                $report = $report->withCheck($checkName, $check);
            }
            helmet_ready($report);
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

if (!function_exists('Waynestate\Helmet\helmet_ribbon')) {
    /**
     * Create a ribbon for the given environment.
     *
     * This is a convenience helper for raw PHP apps. The returned Ribbon can
     * be rendered with its html() method and injected near the top of the
     * page. It renders only for the non-production environments in
     * Ribbon::ENVIRONMENTS, the same list the Laravel adapter uses; for
     * production, an empty name or a name it does not know, html() returns
     * an empty string even when $enabled is true.
     *
     * When a policy path is given, the ribbon also shows a version warning
     * for the running PHP version (PHP_MAJOR_VERSION.PHP_MINOR_VERSION) from
     * CREED's policy.json. A missing, unreadable or invalid policy file
     * produces no warning and never breaks the page.
     *
     * @param string $environment The environment name (for example "STAGING").
     * @param bool $enabled Whether the ribbon may be shown at all (it still
     *        never shows outside Ribbon::ENVIRONMENTS).
     * @param string|null $policyPath The path to a CREED policy.json, or null
     *        for no version warnings.
     */
    function helmet_ribbon(string $environment, bool $enabled = true, ?string $policyPath = null): \Waynestate\Helmet\Ribbon\Ribbon
    {
        return new \Waynestate\Helmet\Ribbon\Ribbon(
            $environment,
            $enabled,
            $policyPath,
            [['php', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION]],
        );
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

if (!function_exists('helmet_ready')) {
    /**
     * Answer the readiness path and stop.
     *
     * Runs the checks, sends the status (200 ready, 503 not ready), the
     * JSON Content-Type, Cache-Control: no-store and the JSON report, and
     * exits. helmet_boot() calls it when "readiness" is enabled; a raw PHP
     * app with no front controller can call it from a public/ready.php:
     *
     *     <?php
     *     require __DIR__ . '/../vendor/autoload.php';
     *     use Waynestate\Helmet\Health\Readiness;
     *     helmet_ready((new Readiness())->withCheck('database', fn () => db_ping()));
     */
    function helmet_ready(Readiness $readiness): never
    {
        $response = $readiness->response();

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

if (!function_exists('helmet_csp_nonce_swap')) {
    /**
     * Swap a build-time CSP nonce placeholder for the response nonce.
     *
     * A raw PHP app that serves a built Vue (Vite) page sets Vite's
     * html.cspNonce to a placeholder at build time. On every request, pass
     * the built page and the nonce helmet_boot() returned, and every
     * occurrence of the placeholder comes back as the nonce, HTML-escaped
     * exactly like helmet_csp_nonce_meta() escapes it, so the tags Vite
     * wrote carry the same value the CSP header sends.
     *
     * It is a pure function: no output, no headers, no globals. When the
     * placeholder does not occur in the page, the page is returned
     * unchanged, so a page without a bundle still works.
     *
     * @throws \InvalidArgumentException If "placeholder" is shorter than 16
     *         characters (a short one would collide with page text), or if
     *         "nonce" is empty.
     */
    function helmet_csp_nonce_swap(string $html, string $nonce, string $placeholder = 'CSP_NONCE_SLOT_FOR_HELMET'): string
    {
        if (strlen($placeholder) < 16) {
            throw new \InvalidArgumentException('helmet_csp_nonce_swap() "placeholder" must be at least 16 characters.');
        }

        if ($nonce === '') {
            throw new \InvalidArgumentException('helmet_csp_nonce_swap() "nonce" must not be empty.');
        }

        return str_replace($placeholder, htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $html);
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
     *     storage?: string|null,
     *     redis_url?: string|null,
     *     sqlite_path?: string|null,
     * } $config
     *
     * When "storage" names a shared store, the request metrics recorded by
     * helmet_metrics_track() are read back from it and added to the body
     * (see helmet_metrics_requests()).
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

        $clientIp = ClientIp::resolve($_SERVER, $proxies, $config['logger'] ?? null);
        $requestToken = BearerToken::fromServer($_SERVER);

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
            helmet_metrics_requests($config),
        );

        return [
            'status' => 200,
            'content_type' => Metrics::mimeType(),
            'body' => $metrics->render(),
        ];
    }
}

if (!function_exists('helmet_metrics_env')) {
    /**
     * The metrics settings from the environment, for a framework-free app.
     *
     * Reads the same HELMET_METRICS_* variables the Laravel config reads (the
     * environment first, then waynestate/playbook when it is installed), so
     * one set of variables drives both paths. Merge your own keys on top:
     *
     *     helmet_metrics(['preset' => 'citadel'] + helmet_metrics_env());
     *
     * HELMET_POLICY_PATH, when set and readable, becomes the "policy" source
     * for the PHP support metrics; an unreadable policy is left out rather
     * than failing the scrape.
     *
     * @return array{
     *     enabled: bool,
     *     token: string|null,
     *     allow: list<string>,
     *     storage: string|null,
     *     redis_url: string|null,
     *     sqlite_path: string|null,
     *     policy: PolicySource|null,
     * }
     */
    function helmet_metrics_env(): array
    {
        $allow = [];
        foreach (explode(',', Setting::get('HELMET_METRICS_ALLOW') ?? '') as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $allow[] = $entry;
            }
        }

        $policy = null;
        $policyPath = Setting::get('HELMET_POLICY_PATH');
        if ($policyPath !== null) {
            try {
                $policy = new JsonFilePolicySource($policyPath);
            } catch (PolicyReadException) {
                $policy = null;
            }
        }

        return [
            'enabled' => filter_var(Setting::get('HELMET_METRICS_ENABLED') ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'token' => Setting::get('HELMET_METRICS_TOKEN'),
            'allow' => $allow,
            'storage' => Setting::get('HELMET_METRICS_STORAGE'),
            'redis_url' => Setting::get('HELMET_METRICS_REDIS_URL'),
            'sqlite_path' => Setting::get('HELMET_METRICS_SQLITE_PATH'),
            'policy' => $policy,
        ];
    }
}

if (!function_exists('helmet_metrics_requests')) {
    /**
     * The request-metrics recorder for a metrics config, or null when request
     * metrics are off.
     *
     * Request metrics are on when "enabled" is true and "storage" names one
     * of the shared stores ("apcu", "redis", "sqlite"; see MetricsStorage).
     * A store that cannot be used here is logged (through "logger", when
     * given) and treated as off: a metrics problem never breaks a page.
     *
     * @param array{
     *     enabled?: bool,
     *     storage?: string|null,
     *     redis_url?: string|null,
     *     sqlite_path?: string|null,
     *     logger?: LoggerInterface|null,
     * } $config
     */
    function helmet_metrics_requests(array $config): ?RequestMetrics
    {
        if (($config['enabled'] ?? false) !== true || !MetricsStorage::isOn($config['storage'] ?? null)) {
            return null;
        }

        try {
            $storage = MetricsStorage::create(
                $config['storage'] ?? null,
                $config['redis_url'] ?? null,
                $config['sqlite_path'] ?? null,
            );
        } catch (MetricsStorageException $e) {
            ($config['logger'] ?? null)?->warning($e->getMessage(), ['event' => 'metrics_storage_unusable']);

            return null;
        }

        return $storage === null ? null : new RequestMetrics($storage);
    }
}

if (!function_exists('helmet_metrics_track')) {
    /**
     * Count and time this request, for a framework-free app.
     *
     * Call it near the top of the front controller with the same config you
     * give helmet_metrics(). It returns a RequestTimer that records the
     * request once, at shutdown, so a page that ends with exit is counted
     * too. Name the route group when you know it:
     *
     *     $timer = helmet_metrics_track(helmet_metrics_env());
     *     $timer->route('asset');
     *
     * The clock starts at REQUEST_TIME_FLOAT, when the web server handed the
     * request to PHP. When request metrics are off (the default) the timer
     * is inert and nothing is registered.
     *
     * @param array{
     *     enabled?: bool,
     *     storage?: string|null,
     *     redis_url?: string|null,
     *     sqlite_path?: string|null,
     *     logger?: LoggerInterface|null,
     * } $config
     */
    function helmet_metrics_track(array $config): RequestTimer
    {
        $metrics = helmet_metrics_requests($config);
        if ($metrics === null) {
            return RequestTimer::off();
        }

        $startedAt = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $timer = new RequestTimer(
            $metrics,
            is_float($startedAt) || is_int($startedAt) ? (float) $startedAt : microtime(true),
            is_string($method) ? $method : 'GET',
            $config['logger'] ?? null,
        );
        register_shutdown_function(static function () use ($timer): void {
            $timer->finish();
        });

        return $timer;
    }
}

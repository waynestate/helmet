<?php

declare(strict_types=1);

namespace Waynestate\Helmet;

use Psr\Log\LoggerInterface;
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
     *  - answers the health check path with "ok" and stops,
     *  - sends the security headers (including a per-request CSP nonce),
     *  - removes the X-Powered-By header,
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
     *     health_path?: string,
     *     hsts_max_age?: int,
     *     hsts_include_subdomains?: bool,
     *     log?: array{channel?: string, stream?: string, level?: 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'}
     * } $config
     *
     * @return array{nonce: string, logger: LoggerInterface|null}
     *
     * @throws UnknownPresetException If "preset" names a preset the package
     *         does not know.
     * @throws \InvalidArgumentException If "trusted_proxies" or "preset" is
     *         the wrong type.
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

        $logger = null;
        $log = $config['log'] ?? null;
        if (is_array($log)) {
            $logger = Logging::create(
                $log['channel'] ?? 'helmet',
                $log['stream'] ?? 'php://stderr',
                $log['level'] ?? 'info',
            );
        }

        $healthPath = $config['health_path'] ?? '/up';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // REQUEST_URI includes the query string; the path is the part before ?.
        $path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];

        if (($method === 'GET' || $method === 'HEAD') && $path === $healthPath) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'ok';
            exit;
        }

        $cspPolicy = SecurityHeaders::mergePolicies(
            SecurityHeaders::defaultCspPolicy(),
            $config['csp'] ?? [],
        );
        $nonce = SecurityHeaders::generateNonce();
        $headers = SecurityHeaders::build(
            $cspPolicy,
            $nonce,
            $isHttps,
            $config['hsts_max_age'] ?? 31536000,
            $config['hsts_include_subdomains'] ?? true,
            $config['csp_report_uri'] ?? null,
            $config['csp_report_only'] ?? false,
        );

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        header_remove('X-Powered-By');

        return ['nonce' => $nonce, 'logger' => $logger];
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

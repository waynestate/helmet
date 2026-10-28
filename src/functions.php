<?php

declare(strict_types=1);

namespace Waynestate\Helmet;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Logging\Logging;
use Waynestate\Helmet\Platform\Presets;
use Waynestate\Helmet\Platform\UnknownPresetException;
use Waynestate\Helmet\Proxy\HttpsDetector;
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

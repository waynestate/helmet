<?php declare(strict_types=1);

namespace Waynestate\Helmet;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Logging\Logging;
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
     * @param array{
     *     trusted_proxies: list<string>,
     *     csp?: array<string, list<string>|null>,
     *     health_path?: string,
     *     log?: array{channel?: string, stream?: string, level?: string}
     * } $config
     *
     * @return array{nonce: string, logger: LoggerInterface|null}
     *
     * @throws \InvalidArgumentException If trusted_proxies is missing.
     * @throws \LogicException If a session is already active.
     */
    function helmet_boot(array $config): array
    {
        if (!isset($config['trusted_proxies']) || !is_array($config['trusted_proxies'])) {
            throw new \InvalidArgumentException('helmet_boot() requires a "trusted_proxies" list.');
        }

        $proxies = new TrustedProxies($config['trusted_proxies']);
        $isHttps = HttpsDetector::isHttps($_SERVER, $proxies);

        SessionHardening::apply($isHttps);

        $logger = null;
        if (isset($config['log']) && is_array($config['log'])) {
            $log = $config['log'];
            $logger = Logging::create(
                $log['channel'] ?? 'helmet',
                $log['stream'] ?? 'php://stderr',
                $log['level'] ?? 'info'
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
            $config['csp'] ?? []
        );
        $nonce = SecurityHeaders::generateNonce();
        $headers = SecurityHeaders::build($cspPolicy, $nonce, $isHttps);

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        header_remove('X-Powered-By');

        return ['nonce' => $nonce, 'logger' => $logger];
    }
}

<?php declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Helmet\Headers\SecurityHeaders as CoreSecurityHeaders;
use Waynestate\Helmet\Proxy\HttpsDetector;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * Laravel middleware that sends HELMET's security headers.
 *
 * It reads the header list from the core SecurityHeaders class (the same
 * single source of truth the PSR-15 middleware and helmet_boot() use) and
 * sets those headers directly on the Laravel response, so the two consumer
 * paths always agree. It decides whether the request is HTTPS from the
 * trusted-proxy list, builds a per-request CSP nonce, and exposes the nonce
 * to the application as the "helmet.csp_nonce" request attribute, exactly
 * like the core middleware.
 *
 * The CSP policy comes from config('helmet.csp') and the HSTS max-age from
 * config('helmet.hsts'); this middleware never reads env() directly.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $proxies = new TrustedProxies((array) config('helmet.proxies'));
        $isHttps = HttpsDetector::isHttps($request->server->all(), $proxies);

        $nonce = CoreSecurityHeaders::generateNonce();
        $headers = CoreSecurityHeaders::build(
            (array) config('helmet.csp'),
            $nonce,
            $isHttps
        );

        if ($isHttps) {
            $headers['Strict-Transport-Security'] =
                'max-age=' . (int) config('helmet.hsts') . '; includeSubDomains';
        }

        $request->attributes->set('helmet.csp_nonce', $nonce);

        $response = $next($request);

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}

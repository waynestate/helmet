<?php declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
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
 * The CSP overrides come from config('helmet.csp'), the HSTS max-age from
 * config('helmet.hsts'), and the HSTS includeSubDomains directive from
 * config('helmet.hsts_include_subdomains'); this middleware never reads env()
 * directly.
 *
 * It also hands the per-request nonce to Laravel's Vite helper, so the @vite
 * script and style tags carry the same nonce the CSP header sends. The app no
 * longer needs to call Vite::useCspNonce() itself. If a second package or the
 * app changes the Vite nonce after HELMET set it, the middleware logs a
 * warning, because the @vite tags would then carry a different nonce than the
 * CSP header and the browser would block the scripts.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $proxies = new TrustedProxies((array) config('helmet.proxies'));
        $isHttps = HttpsDetector::isHttps($request->server->all(), $proxies);

        $nonce = CoreSecurityHeaders::generateNonce();
        // The config holds per-directive overrides, merged over the default by
        // the core exactly as helmet_boot() and the PSR-15 middleware do, so a
        // partial policy keeps the directives it does not mention and a null
        // directive removes one.
        $policy = CoreSecurityHeaders::mergePolicies(
            CoreSecurityHeaders::defaultCspPolicy(),
            (array) config('helmet.csp')
        );
        $headers = CoreSecurityHeaders::build(
            $policy,
            $nonce,
            $isHttps,
            (int) config('helmet.hsts'),
            (bool) config('helmet.hsts_include_subdomains')
        );

        $request->attributes->set('helmet.csp_nonce', $nonce);

        // Hand the nonce to Vite so the @vite tags carry the same nonce the
        // CSP header sends. The app no longer needs to call
        // Vite::useCspNonce() itself.
        Vite::useCspNonce($nonce);

        $response = $next($request);

        // Catch a second package or the app that changed the Vite nonce after
        // HELMET set it. The @vite tags would then carry a different nonce
        // than the CSP header, so the browser would block the scripts.
        if (Vite::cspNonce() !== $nonce) {
            Log::warning('HELMET set the CSP nonce via Vite::useCspNonce(), but a different nonce is now active. A second package or the app changed it; the @vite tags may not match the CSP header.');
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}

<?php declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
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
 *
 * It also injects a <meta property="csp-nonce" nonce="..."> tag into HTML
 * responses, right after the opening <head> tag. Vite's dev runtime reads
 * that tag to nonce the CSS and preload tags it adds after page load. A meta
 * tag error must never break the page, so any failure is swallowed and the
 * response is returned as-is.
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

        try {
            $this->maybeInjectCspNonceMeta($response, $nonce);
        } catch (Throwable) {
            // A meta tag error must not break the page.
        }

        return $response;
    }

    /**
     * Inject the csp-nonce meta tag into an HTML response, right after the
     * opening <head> tag. Vite's dev runtime reads that tag to nonce the CSS
     * and preload tags it adds after page load.
     *
     * The tag is only injected when the response is HTML (Content-Type starts
     * with text/html) and is not a streamed response. If the content has no
     * <head> tag, it is left unchanged.
     */
    private function maybeInjectCspNonceMeta(Response $response, string $nonce): void
    {
        if ($response instanceof StreamedResponse) {
            return;
        }

        if (!$this->isHtml($response)) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }

        $meta = '<meta property="csp-nonce" nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';

        $modified = preg_replace_callback(
            '/<head[^>]*>/i',
            static function (array $matches) use ($meta): string {
                return $matches[0] . $meta;
            },
            $content,
            1
        );

        if (is_string($modified) && $modified !== $content) {
            $response->setContent($modified);
        }
    }

    /**
     * Whether the response Content-Type is HTML.
     */
    private function isHtml(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');
        if (!is_string($contentType) || $contentType === '') {
            return false;
        }

        $mime = strtolower(trim(explode(';', $contentType)[0]));

        return str_starts_with($mime, 'text/html');
    }
}

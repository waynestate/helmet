<?php

declare(strict_types=1);

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
 * config('helmet.hsts'), the HSTS includeSubDomains directive from
 * config('helmet.hsts_include_subdomains'), the HSTS preload directive from
 * config('helmet.hsts_preload'), the CSP report destination from
 * config('helmet.csp_report_uri'), the report-only switch from
 * config('helmet.csp_report_only'), the Trusted Types report-only switch and
 * policy names from config('helmet.csp_trusted_types'), the
 * Cross-Origin-Opener-Policy value from config('helmet.coop'), the
 * Cross-Origin-Resource-Policy value from config('helmet.corp'), the
 * Permissions-Policy feature map from config('helmet.permissions_policy'), and
 * the no-store switch and path prefixes from config('helmet.no_store'); this
 * middleware never reads env() directly.
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
        /** @var list<string> $proxyList */
        $proxyList = (array) config('helmet.proxies');
        $proxies = new TrustedProxies($proxyList);
        $isHttps = HttpsDetector::isHttps($request->server->all(), $proxies);

        $nonce = CoreSecurityHeaders::generateNonce();
        // The config holds per-directive overrides, merged over the default by
        // the core exactly as helmet_boot() and the PSR-15 middleware do, so a
        // partial policy keeps the directives it does not mention and a null
        // directive removes one.
        $policy = CoreSecurityHeaders::mergePolicies(
            CoreSecurityHeaders::defaultCspPolicy(),
            (array) config('helmet.csp'),
        );
        $policy = $this->maybeAddDevOrigin($policy);
        // An empty string from env means "not set", so normalize it to null.
        $reportUri = config('helmet.csp_report_uri');
        $reportUri = is_string($reportUri) && $reportUri !== '' ? $reportUri : null;
        $coop = config('helmet.coop');
        $coop = is_string($coop) && $coop !== '' ? $coop : null;
        $corp = config('helmet.corp');
        $corp = is_string($corp) && $corp !== '' ? $corp : null;
        // An empty array is meaningful: it sends no Permissions-Policy
        // header. null (or a non-array) falls back to the default deny list.
        $permissionsPolicy = config('helmet.permissions_policy');
        $permissionsPolicy = is_array($permissionsPolicy) ? $permissionsPolicy : null;
        $hstsPreload = (bool) config('helmet.hsts_preload');
        // A non-array csp_trusted_types falls back to the disabled default, so
        // a malformed value never changes the CSP header.
        $trustedTypes = config('helmet.csp_trusted_types');
        $trustedTypes = is_array($trustedTypes) ? $trustedTypes : [];
        $trustedTypesEnabled = (bool) ($trustedTypes['enabled'] ?? false);
        $trustedTypesPolicies = $trustedTypes['policies'] ?? ['default'];
        $trustedTypesPolicies = is_array($trustedTypesPolicies) ? $trustedTypesPolicies : ['default'];
        // A non-array no_store falls back to the disabled default.
        $noStore = config('helmet.no_store');
        $noStore = is_array($noStore) ? $noStore : [];
        $noStoreEnabled = (bool) ($noStore['enabled'] ?? false);
        $noStorePaths = $noStore['paths'] ?? [];
        $noStorePaths = is_array($noStorePaths) ? $noStorePaths : [];
        $headers = CoreSecurityHeaders::build(
            $policy,
            $nonce,
            $isHttps,
            (int) config('helmet.hsts'),
            (bool) config('helmet.hsts_include_subdomains'),
            $reportUri,
            (bool) config('helmet.csp_report_only'),
            $coop,
            $corp,
            $permissionsPolicy,
            $hstsPreload,
            $trustedTypesEnabled,
            $trustedTypesPolicies,
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

        // In Laravel the framework sets a default Cache-Control (no-cache,
        // private), so no-store must override it rather than skip when the
        // header is already present.
        if (CoreSecurityHeaders::noStoreApplies($noStoreEnabled, $request->getPathInfo(), $noStorePaths)) {
            $response->headers->remove('Cache-Control');
            $response->headers->set('Cache-Control', 'no-store');
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
            1,
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

    /**
     * Add the Vite dev server origin to the policy, but only in the local
     * environment when the dev server is running (a hot file exists). The
     * origin is validated: it must be http or https, on localhost,
     * 127.0.0.1 or [::1], with an optional port. An invalid origin is
     * ignored and a warning is logged.
     *
     * @param array<string, list<string>> $policy
     *
     * @return array<string, list<string>>
     */
    private function maybeAddDevOrigin(array $policy): array
    {
        if (!app()->environment('local')) {
            return $policy;
        }

        if (!Vite::isRunningHot()) {
            return $policy;
        }

        $origin = $this->devOrigin();
        if ($origin === null) {
            return $policy;
        }

        // The dev server serves scripts, styles, fonts and images.
        foreach (['script-src', 'style-src', 'style-src-elem', 'font-src', 'img-src'] as $directive) {
            $policy[$directive][] = $origin;
        }

        // The dev server's HMR websocket and the page's fetch calls.
        $policy['connect-src'][] = $origin;
        $policy['connect-src'][] = $this->websocketTwin($origin);

        // Vite's dev server may use web workers loaded from blob: URLs.
        $policy['worker-src'][] = 'blob:';

        return $policy;
    }

    /**
     * The Vite dev server origin: config('helmet.dev_origin') when set,
     * otherwise the hot file contents. Returns null when the origin is
     * missing or invalid.
     */
    private function devOrigin(): ?string
    {
        $origin = config('helmet.dev_origin');
        if (!is_string($origin) || $origin === '') {
            $origin = $this->hotFileContents();
        }

        if (!is_string($origin) || $origin === '') {
            return null;
        }

        $origin = trim($origin);

        if (!$this->isValidDevOrigin($origin)) {
            Log::warning('HELMET ignored an invalid Vite dev origin: ' . $origin . '. The origin must be http or https on localhost, 127.0.0.1 or [::1], with an optional port.');

            return null;
        }

        return $origin;
    }

    /**
     * Read the Vite hot file contents, or null when it cannot be read.
     */
    private function hotFileContents(): ?string
    {
        try {
            $hotFile = Vite::hotFile();
            if (!is_file($hotFile)) {
                return null;
            }

            $contents = file_get_contents($hotFile);

            return is_string($contents) ? trim($contents) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the origin is a valid Vite dev server origin: http or https,
     * on localhost, 127.0.0.1 or [::1], with an optional port.
     */
    private function isValidDevOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'localhost' && $host !== '127.0.0.1' && $host !== '[::1]') {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] < 1) {
            return false;
        }

        return true;
    }

    /**
     * The ws:// or wss:// twin of an http/https origin.
     */
    private function websocketTwin(string $origin): string
    {
        $parts = parse_url($origin);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';

        return $wsScheme . '://' . $host . $port;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Waynestate\Helmet\Csrf\RequestForgeryGuard as CoreRequestForgeryGuard;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * Laravel middleware that rejects cross-site request forgery.
 *
 * It is opt-in: when `helmet.csrf.enabled` is false (the default) it passes
 * the request through untouched. When it is true, it applies the same rule
 * the core PSR-15 middleware and helmet_boot() use, through the core
 * RequestForgeryGuard, so every consumer path agrees.
 *
 * The guard reads the Sec-Fetch-Site header first: same-origin and none
 * pass, same-site passes only when `helmet.csrf.allow_same_site` is true,
 * and cross-site or an unknown value is rejected. When Sec-Fetch-Site is
 * absent it falls back to the Origin header: the request's own origin and
 * any origin in `helmet.csrf.trusted_origins` pass, Origin: null is
 * rejected, an unparseable origin is rejected, and an absent origin passes
 * (a non-browser client that authenticates some other way).
 *
 * The rejection answer is 403, Content-Type: text/plain; charset=utf-8,
 * Cache-Control: no-store, body "Forbidden". It never echoes request
 * headers.
 */
final class RequestForgeryGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('helmet.csrf.enabled') !== true) {
            return $next($request);
        }

        /** @var list<string> $proxyList */
        $proxyList = (array) config('helmet.proxies');
        $proxies = new TrustedProxies($proxyList);
        $allowSameSite = (bool) config('helmet.csrf.allow_same_site');
        /** @var list<string> $trustedOrigins */
        $trustedOrigins = (array) config('helmet.csrf.trusted_origins');
        /** @var list<string> $except */
        $except = (array) config('helmet.csrf.except');

        $guard = new CoreRequestForgeryGuard(
            $proxies,
            null,
            null,
            $allowSameSite,
            $trustedOrigins,
            $except,
        );
        $secFetchSite = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');
        $requestOrigin = CoreRequestForgeryGuard::originFromServerParams($request->server->all(), $proxies);

        if (!$guard->allows(
            $request->method(),
            $request->getPathInfo(),
            $secFetchSite,
            $origin,
            $requestOrigin,
        )) {
            $this->logRefusal($request->method(), $request->getPathInfo(), $secFetchSite, $origin);

            $headers = [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ];
            $response = new Response('Forbidden', 403, $headers);

            // Symfony's ResponseHeaderBag conservatively adds "private" to a
            // Cache-Control header that names no visibility. The guard's
            // answer is exactly "no-store", so keep the header as written.
            $response->headers = new class ($headers) extends ResponseHeaderBag {
                protected function computeCacheControlValue(): string
                {
                    return $this->getCacheControlHeader();
                }
            };

            return $response;
        }

        return $next($request);
    }

    /**
     * Log a refused request as a structured security event.
     *
     * The Laravel path has no injected logger, so it logs through the Log
     * facade with the same message and context the core PSR-15 middleware
     * uses. The context carries only the method, the path without the query
     * string, the Sec-Fetch-Site value normalized to one of the four known
     * values or "other" (null when the header is absent), and whether an
     * Origin header was present. It never carries the Origin value,
     * cookies, Authorization, or any other header value. A logging failure
     * is ignored: it must never change the 403 answer.
     *
     * @param string $method The HTTP method, in any case.
     * @param string $path The request path, without the query string.
     * @param string|null $secFetchSite The Sec-Fetch-Site header, or null
     *        when absent.
     * @param string|null $origin The Origin header, or null when absent.
     */
    private function logRefusal(string $method, string $path, ?string $secFetchSite, ?string $origin): void
    {
        $site = $secFetchSite === null ? '' : strtolower($secFetchSite);
        $normalizedSite = $site === ''
            ? null
            : (in_array($site, ['same-origin', 'same-site', 'cross-site', 'none'], true) ? $site : 'other');

        try {
            Log::warning('helmet.request_refused', [
                'event' => 'request_refused',
                'method' => strtoupper($method),
                'path' => $path,
                'sec_fetch_site' => $normalizedSite,
                'origin_present' => $origin !== null && $origin !== '',
            ]);
        } catch (\Throwable) {
            // A logging failure must never change the 403 answer.
        }
    }
}

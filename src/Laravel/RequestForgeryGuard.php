<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
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
}

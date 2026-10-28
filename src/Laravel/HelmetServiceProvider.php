<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use ReflectionProperty;

/**
 * The Laravel adapter for HELMET.
 *
 * It merges and publishes the `helmet` config, and appends the two HELMET
 * middlewares (SecurityHeaders and InjectRibbon) to the `web` middleware
 * group, through the HTTP kernel, so every web route gets the baseline
 * headers and the environment ribbon.
 *
 * HELMET decides for itself whether a request really arrived over HTTPS,
 * from the `helmet.proxies` list, so the HSTS header is only sent for
 * requests that were really secure.
 *
 * Laravel has its own, separate trusted-proxy setting (it drives url(),
 * secure cookies and $request->isSecure()). This provider follows the same
 * list: when the app has not set `trustedproxy.proxies` itself, the provider
 * copies `helmet.proxies` over, so HELMET_PROXIES drives both. An app that
 * sets `trustedproxy.proxies` keeps its own value, and an explicit
 * `trustProxies(at: ...)` call in `bootstrap/app.php` still wins, because
 * Laravel applies it before the config fallback.
 *
 * The `/up` health route is left to Laravel's built-in `health: '/up'`
 * option in `withRouting()`; this provider does not register a route.
 */
final class HelmetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/helmet.php', 'helmet');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/helmet.php' => config_path('helmet.php'),
        ], 'helmet-config');

        // Make Route::cspReport() available so an app can opt into the CSP
        // report endpoint. The macro is always registered; the route is only
        // added when the app calls it, so the endpoint stays off by default.
        CspReportRoute::registerMacro();

        // Make Route::helmetMetrics() available so an app can opt into the
        // metrics endpoint. The macro is always registered; the route is only
        // added when the app calls it, so the endpoint stays off by default.
        MetricsRoute::registerMacro();

        // Laravel's own trusted-proxy setting drives url(), secure cookies and
        // $request->isSecure(). When the app has not set it, follow the same
        // list HELMET uses, so HELMET_PROXIES drives both. An app value (or an
        // explicit trustProxies(at: ...) call) wins.
        if (config('trustedproxy.proxies') === null) {
            config(['trustedproxy.proxies' => config('helmet.proxies')]);
        }

        // Laravel's default trusted-header set also includes X-Forwarded-Host
        // and X-Forwarded-Prefix. A platform that rewrites the host (OpenShift
        // appends the route host) would let a client pre-seed those headers, so
        // HELMET narrows the set to the three a front proxy actually sets: For,
        // Proto and Port - the same set as Presets::TRUSTED_HEADERS. An app that
        // has already chosen its own headers (TrustProxies::withHeaders(), or
        // trustProxies(..., headers: ...)) keeps them: the static is only null
        // when nobody has set it yet.
        $alwaysTrustHeaders = (new ReflectionProperty(TrustProxies::class, 'alwaysTrustHeaders'))->getValue();
        if ($alwaysTrustHeaders === null) {
            TrustProxies::withHeaders(
                Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
            );
        }

        // Join the middleware through the HTTP kernel, not the router. The
        // kernel owns the group lists and copies them over the router's each
        // time it is built or asked to append a middleware, so a push made on
        // the router alone is wiped by the next sync. That is what happened to
        // the headers under Testbench (the kernel is first resolved after the
        // providers boot) and would happen in production to any app where a
        // later provider appends to a group through the kernel.
        // callAfterResolving runs the callback now when the kernel is already
        // built, or right after it is built, once the framework's own groups
        // are in place.
        $this->callAfterResolving(Kernel::class, static function (Kernel $kernel): void {
            if (!$kernel instanceof FoundationKernel) {
                return;
            }

            // SecurityHeaders runs in the global middleware when scope is
            // "global" (the default), so every response - including 404s and
            // JSON routes - carries the baseline headers. When scope is "web",
            // it stays in the `web` group only. pushMiddleware and
            // appendMiddlewareToGroup are both idempotent, so the middleware
            // is added once even if the callback runs more than once.
            if (config('helmet.scope') === 'global') {
                $kernel->pushMiddleware(SecurityHeaders::class);
            } else {
                $kernel->appendMiddlewareToGroup('web', SecurityHeaders::class);
            }

            // InjectRibbon always stays in the `web` group, because it edits
            // HTML and a JSON or 404 response has no HTML to edit.
            $kernel->appendMiddlewareToGroup('web', InjectRibbon::class);
        });
    }
}

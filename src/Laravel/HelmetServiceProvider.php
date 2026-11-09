<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use ReflectionProperty;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * The Laravel adapter for HELMET.
 *
 * It merges and publishes the `helmet` config, and appends the HELMET
 * middlewares (SecurityHeaders, InjectRibbon, and the opt-in
 * RequestForgeryGuard) to the `web` middleware group, through the HTTP
 * kernel, so every web route gets the baseline headers, the environment
 * ribbon, and the request-forgery check when it is enabled.
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

        // The request-metrics recorder, built on first use and memoized, so
        // the middleware and both report endpoints share one.
        $this->app->singleton(
            RequestMetricsBinding::class,
            static fn($app): RequestMetricsBinding => new RequestMetricsBinding($app->make('log')),
        );
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

        // Make Route::helmetReady() available so an app can opt into the
        // readiness endpoint. The macro is always registered; the route is
        // only added when the app calls it.
        ReadinessRoute::registerMacro();

        // Laravel's own trusted-proxy setting drives url(), secure cookies and
        // $request->isSecure(). When the app has not set it, follow the same
        // list HELMET uses, so HELMET_PROXIES drives both. An app value (or an
        // explicit trustProxies(at: ...) call) wins.
        if (config('trustedproxy.proxies') === null) {
            config(['trustedproxy.proxies' => config('helmet.proxies')]);
        }

        // Laravel's default trusted-header set also includes X-Forwarded-Host,
        // X-Forwarded-Prefix and X-Forwarded-Port. A platform that rewrites
        // the host (OpenShift appends the route host) would let a client
        // pre-seed those headers, so HELMET narrows the set to the two a
        // front proxy actually sets: For and Proto - the same set as
        // Presets::TRUSTED_HEADERS. X-Forwarded-Port is out because Citadel's
        // HA-Proxy never sends it at all, so a client-sent value would pass
        // through untouched and change the port Laravel reports and the URLs
        // it generates. An app that has already chosen its own headers
        // (TrustProxies::withHeaders(), or trustProxies(..., headers: ...))
        // keeps them: the static is only null when nobody has set it yet.
        $alwaysTrustHeaders = (new ReflectionProperty(TrustProxies::class, 'alwaysTrustHeaders'))->getValue();
        if ($alwaysTrustHeaders === null) {
            TrustProxies::withHeaders(
                Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
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

            // X-Forwarded-Proto is normalized at the very start of the global
            // chain, ahead of TrustProxies. Citadel's HA-Proxy appends the
            // header instead of replacing it, so a client can produce
            // "https, http", and Symfony would read the first (client's)
            // value from isSecure(). The normalizer only rewrites the raw
            // header and server bags, and Symfony applies its trusted-proxy
            // rewrites lazily on read, so running before TrustProxies is
            // safe and earliest. Running it twice is a no-op: after the
            // first pass the header carries a single value.
            $kernel->prependMiddleware(ForwardedProtoNormalizer::class);

            // RecordRequestMetrics is opt-in: it joins only when the metrics
            // endpoint is enabled and names a shared storage. Prepended last,
            // so it is the outermost middleware and times the whole request.
            if (RequestMetricsBinding::configured()) {
                $kernel->prependMiddleware(RecordRequestMetrics::class);
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

            // RequestForgeryGuard is opt-in. When enabled, it joins the `web`
            // group after InjectRibbon, so a rejected request never reaches
            // the route.
            if ((bool) config('helmet.csrf.enabled') === true) {
                $kernel->appendMiddlewareToGroup('web', RequestForgeryGuard::class);
            }
        });

        // H42: with helmet.error_pages on, a 4xx HTTP exception gets HELMET's
        // fixed client-error page. The callback is always added and reads
        // the switch on each render, so it costs nothing while it is off.
        // The handler is a singleton: callAfterResolving runs this once,
        // now if the handler is already built or right after it is.
        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            ClientErrorPages::register($handler);
        });

        // H43: keep debug output off in production. A production app that
        // boots with app.debug true is a misconfiguration, so say so once
        // through the Log facade. The check runs at boot, not per request:
        // the value is fixed for the life of the process, and a long-lived
        // worker must not log it on every request.
        $this->warnIfDebugInProduction();
        $this->warnIfBroadTrustedProxies();
    }

    /**
     * Write one warning when the app boots with app.debug true in the
     * production environment (H43: keep debug output off in production).
     *
     * Both sides are checked strictly: app.debug must be the boolean true
     * (a string such as "false" does not trigger the warning), and the
     * environment must be exactly "production", so staging or local never
     * log it. The context carries only the event name: no values, no
     * config, no paths. Opt out with helmet.warn_debug (env
     * HELMET_WARN_DEBUG). A logging failure is swallowed: it must never
     * break the boot.
     */
    private function warnIfDebugInProduction(): void
    {
        if ((bool) config('helmet.warn_debug') !== true) {
            return;
        }

        if (config('app.debug') !== true) {
            return;
        }

        if (!$this->app->environment('production')) {
            return;
        }

        try {
            Log::warning('helmet.debug_enabled_in_production', [
                'event' => 'debug_enabled_in_production',
            ]);
        } catch (\Throwable) {
            // A logging failure must never break the boot.
        }
    }

    /**
     * Write one warning when the resolved trusted-proxy list contains a broad
     * entry (H21: trust the proxy's address, not its whole network).
     *
     * A broad entry is an IPv4 range shorter than /16 or an IPv6 range shorter
     * than /48; such a range trusts other machines on the same network, any
     * of which could then set X-Forwarded-Proto. The warning fires once at
     * boot, not per request. The context carries the event name and the broad
     * entries, and nothing else. Opt out with helmet.warn_broad_proxies (env
     * HELMET_WARN_BROAD_PROXIES). A logging failure is swallowed: it must
     * never break the boot.
     */
    private function warnIfBroadTrustedProxies(): void
    {
        if ((bool) config('helmet.warn_broad_proxies') !== true) {
            return;
        }

        /** @var list<string> $proxyList */
        $proxyList = (array) config('helmet.proxies');
        $broad = (new TrustedProxies($proxyList))->broadEntries();

        if ($broad === []) {
            return;
        }

        try {
            Log::warning('helmet.broad_trusted_proxy', [
                'event' => 'broad_trusted_proxy',
                'entries' => $broad,
            ]);
        } catch (\Throwable) {
            // A logging failure must never break the boot.
        }
    }
}

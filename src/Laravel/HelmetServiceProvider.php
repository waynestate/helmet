<?php declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Support\ServiceProvider;

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
 * requests that were really secure. Laravel has its own, separate
 * trusted-proxy setting (it drives url(), secure cookies and
 * $request->isSecure()), and trusting a proxy is a one-time app step, not
 * something this provider can set. On Laravel 11+ it is configured in
 * `bootstrap/app.php`, not in a service provider. Add this to the app's
 * `bootstrap/app.php` (once, when the package is installed), listing the
 * same address as HELMET_PROXIES:
 *
 *     return Application::configure(basePath: dirname(__DIR__))
 *         ->withRouting(
 *             web: __DIR__.'/../routes/web.php',
 *             commands: __DIR__.'/../routes/console.php',
 *             health: '/up',
 *         )
 *         ->withMiddleware(function (Middleware $middleware) {
 *             $middleware->trustProxies(at: ['172.18.0.1']);
 *         })
 *         ->withExceptions(function (Exceptions $exceptions) {
 *             //
 *         })->create();
 *
 * Use a literal list there. That closure runs when the HTTP kernel is built,
 * before the configuration is loaded, so config('helmet.proxies') does not
 * exist yet and calling it throws.
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

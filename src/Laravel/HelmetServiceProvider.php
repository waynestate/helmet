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
 * Trusted proxies are a one-time app step, not something this provider can
 * set. On Laravel 11+ the trusted-proxy list is configured in
 * `bootstrap/app.php`, not in a service provider. Add this to the app's
 * `bootstrap/app.php` (once, when the package is installed):
 *
 *     return Application::configure(basePath: dirname(__DIR__))
 *         ->withRouting(
 *             web: __DIR__.'/../routes/web.php',
 *             commands: __DIR__.'/../routes/console.php',
 *             health: '/up',
 *         )
 *         ->withMiddleware(function (Middleware $middleware) {
 *             $middleware->trustProxies(at: config('helmet.proxies'));
 *         })
 *         ->withExceptions(function (Exceptions $exceptions) {
 *             //
 *         })->create();
 *
 * The `helmet.proxies` config is the same list the core uses to decide
 * whether a request really arrived over HTTPS, so the HSTS header is only
 * sent for requests that were really secure.
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

        // Join the `web` group through the HTTP kernel, not the router. The
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

            $kernel->appendMiddlewareToGroup('web', SecurityHeaders::class);
            $kernel->appendMiddlewareToGroup('web', InjectRibbon::class);
        });
    }
}

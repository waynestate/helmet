<?php declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * The Laravel adapter for HELMET.
 *
 * It merges and publishes the `helmet` config, and appends the two HELMET
 * middlewares (SecurityHeaders and InjectRibbon) to the `web` middleware
 * group so every web route gets the baseline headers and the environment
 * ribbon.
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

        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('web', SecurityHeaders::class);
        $router->pushMiddlewareToGroup('web', InjectRibbon::class);
    }
}

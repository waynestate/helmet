<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Support\Facades\Route;
use Waynestate\Helmet\Health\Readiness;

/**
 * Registers the readiness endpoint.
 *
 * The service provider registers the Route::helmetReady() macro in boot(),
 * but the route itself is only added when an app calls it, so the endpoint is
 * off unless the app opts in:
 *
 *     Route::helmetReady();                                     // GET /ready
 *     Route::helmetReady('/ready', ['database' => DatabaseReady::class]);
 *     Route::helmetReady('/ready', [], 'throttle:120,1');       // with a throttle
 *
 * App checks are named invokable classes (resolved from the container and
 * called with no arguments; true means usable), not closures, so the route
 * survives `php artisan route:cache`.
 *
 * No throttle by default: the probe polls on a timer, and a throttled probe
 * would read as "not ready". Laravel's own `health: '/up'` stays the liveness
 * route; never point the restart probe here.
 */
final class ReadinessRoute
{
    /**
     * The route name the readiness endpoint is registered under.
     */
    public const string ROUTE_NAME = 'helmet.ready';

    /**
     * The route default that carries the app checks to the controller.
     */
    public const string CHECKS_DEFAULT = 'helmet_ready_checks';

    /**
     * Register the Route::helmetReady() macro. Called by the service
     * provider in boot(); safe to call more than once.
     */
    public static function registerMacro(): void
    {
        if (Route::hasMacro('helmetReady')) {
            return;
        }

        Route::macro('helmetReady', static function (
            string $path = Readiness::DEFAULT_PATH,
            array $checks = [],
            ?string $throttle = null,
        ): \Illuminate\Routing\Route {
            return ReadinessRoute::register($path, $checks, $throttle);
        });
    }

    /**
     * Register the readiness endpoint at the given path.
     *
     * @param array<mixed> $checks Check name => invokable class name.
     * @param string|null $throttle A throttle middleware (for example
     *        "throttle:120,1"), or null for none (the default).
     *
     * @throws \InvalidArgumentException If a check is not a name => class
     *         string pair.
     */
    public static function register(
        string $path = Readiness::DEFAULT_PATH,
        array $checks = [],
        ?string $throttle = null,
    ): \Illuminate\Routing\Route {
        foreach ($checks as $name => $class) {
            if (!is_string($name) || !is_string($class) || $class === '') {
                throw new \InvalidArgumentException('Route::helmetReady() checks must be an array of name => invokable class name.');
            }
        }

        $route = Route::get($path, ReadinessController::class)
            ->name(self::ROUTE_NAME)
            ->defaults(self::CHECKS_DEFAULT, $checks);

        if ($throttle !== null) {
            $route->middleware($throttle);
        }

        return $route;
    }
}

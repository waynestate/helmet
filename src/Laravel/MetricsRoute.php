<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Support\Facades\Route;

/**
 * Registers the metrics endpoint.
 *
 * The service provider registers the Route::helmetMetrics() macro in boot(),
 * but the route itself is only added when an app calls it, so the endpoint is
 * off unless the app opts in:
 *
 *     Route::helmetMetrics();                 // GET /metrics
 *     Route::helmetMetrics('/prometheus');    // GET /prometheus
 *     Route::helmetMetrics('/metrics', null); // no throttle
 *
 * The route is a plain GET to MetricsController. It carries no
 * authentication of its own: the controller enforces the bearer token and the
 * client IP allow list from the helmet.metrics config. An app that wants an
 * extra layer (for example, a network-level allow list) should wrap the route
 * in its own middleware or route group.
 *
 * The route is throttled by default (throttle:60,1) because, even protected,
 * it is a public URL that a scraper or a curious client can hit; pass null as
 * the second argument to register it without a throttle.
 */
final class MetricsRoute
{
    /**
     * The default path the metrics endpoint is registered at.
     */
    public const string DEFAULT_PATH = '/metrics';

    /**
     * The route name the metrics endpoint is registered under.
     */
    public const string ROUTE_NAME = 'helmet.metrics';

    /**
     * The throttle applied to the metrics endpoint by default: 60 scrapes a
     * minute per IP.
     */
    public const string DEFAULT_THROTTLE = 'throttle:60,1';

    /**
     * Register the Route::helmetMetrics() macro. Called by the service
     * provider in boot(); safe to call more than once.
     */
    public static function registerMacro(): void
    {
        if (Route::hasMacro('helmetMetrics')) {
            return;
        }

        Route::macro('helmetMetrics', static function (
            string $path = MetricsRoute::DEFAULT_PATH,
            ?string $throttle = MetricsRoute::DEFAULT_THROTTLE,
        ): \Illuminate\Routing\Route {
            return MetricsRoute::register($path, $throttle);
        });
    }

    /**
     * Register the metrics endpoint at the given path.
     *
     * @param string $path The path the endpoint is registered at.
     * @param string|null $throttle The throttle middleware to apply (for
     *        example "throttle:120,1"), or null to register the route without
     *        a throttle.
     */
    public static function register(
        string $path = self::DEFAULT_PATH,
        ?string $throttle = self::DEFAULT_THROTTLE,
    ): \Illuminate\Routing\Route {
        $route = Route::get($path, MetricsController::class)->name(self::ROUTE_NAME);

        if ($throttle !== null) {
            $route->middleware($throttle);
        }

        return $route;
    }
}

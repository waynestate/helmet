<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/**
 * Registers the CSP report endpoint.
 *
 * The service provider registers the Route::cspReport() macro in boot(), but
 * the route itself is only added when an app calls it, so the endpoint is off
 * unless the app opts in:
 *
 *     Route::cspReport();                 // POST /csp-report
 *     Route::cspReport('/reports/csp');   // POST /reports/csp
 *     Route::cspReport('/reports/csp', 'throttle:120,1');
 *     Route::cspReport('/reports/csp', null);   // no throttle
 *
 * The route is a plain POST to CspReportController; it carries no
 * authentication of its own. An app that wants to keep the endpoint private
 * should wrap it in its own middleware or route group.
 *
 * The route excludes the web group's CSRF middleware: browsers POST reports
 * without a CSRF token, and the middleware would reject them with 419. It is
 * throttled by default (throttle:60,1) because the endpoint is public and its
 * input is attacker-influenced; pass null as the second argument to register
 * it without a throttle.
 */
final class CspReportRoute
{
    /**
     * The default path the report endpoint is registered at.
     */
    public const string DEFAULT_PATH = '/csp-report';

    /**
     * The route name the report endpoint is registered under.
     */
    public const string ROUTE_NAME = 'helmet.csp-report';

    /**
     * The throttle applied to the report endpoint by default: 60 reports a
     * minute per IP.
     */
    public const string DEFAULT_THROTTLE = 'throttle:60,1';

    /**
     * Register the Route::cspReport() macro. Called by the service provider in
     * boot(); safe to call more than once.
     */
    public static function registerMacro(): void
    {
        if (Route::hasMacro('cspReport')) {
            return;
        }

        Route::macro('cspReport', static function (
            string $path = CspReportRoute::DEFAULT_PATH,
            ?string $throttle = CspReportRoute::DEFAULT_THROTTLE,
        ): \Illuminate\Routing\Route {
            return CspReportRoute::register($path, $throttle);
        });
    }

    /**
     * Register the report endpoint at the given path.
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
        $route = Route::post($path, CspReportController::class)->name(self::ROUTE_NAME);

        // Browsers POST reports without a CSRF token, so the web group's CSRF
        // middleware would reject them with 419. Exclude it under every name
        // the framework has used for it: ValidateCsrfToken (Laravel 11+),
        // VerifyCsrfToken (Laravel 10 and earlier) and PreventRequestForgery
        // (Laravel 13, where the web group names the base class).
        $csrf = [ValidateCsrfToken::class];
        if (class_exists(VerifyCsrfToken::class)) {
            $csrf[] = VerifyCsrfToken::class;
        }
        if (class_exists(PreventRequestForgery::class)) {
            $csrf[] = PreventRequestForgery::class;
        }
        $route->withoutMiddleware($csrf);

        if ($throttle !== null) {
            $route->middleware($throttle);
        }

        return $route;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

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
 *
 * The route is a plain POST to CspReportController; it carries no
 * authentication of its own. An app that wants to keep the endpoint private
 * should wrap it in its own middleware or route group.
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
     * Register the Route::cspReport() macro. Called by the service provider in
     * boot(); safe to call more than once.
     */
    public static function registerMacro(): void
    {
        if (Route::hasMacro('cspReport')) {
            return;
        }

        Route::macro('cspReport', static function (string $path = CspReportRoute::DEFAULT_PATH): \Illuminate\Routing\Route {
            return CspReportRoute::register($path);
        });
    }

    /**
     * Register the report endpoint at the given path.
     */
    public static function register(string $path = self::DEFAULT_PATH): \Illuminate\Routing\Route
    {
        return Route::post($path, CspReportController::class)->name(self::ROUTE_NAME);
    }
}

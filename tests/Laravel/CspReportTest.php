<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Waynestate\Helmet\Laravel\CspReportController;
use Waynestate\Helmet\Laravel\CspReportRoute;

/**
 * The opt-in CSP report endpoint.
 *
 * The route is not registered by the service provider; each test opts in by
 * calling Route::cspReport(), the same way an application would.
 */
final class CspReportTest extends TestCase
{
    public function testReportEndpointAcceptsCspReport(): void
    {
        Route::cspReport();

        Log::shouldReceive('warning')
            ->once()
            ->with('CSP violation: directive=script-src blocked_host=evil.example document_path=/page');

        $body = json_encode([
            'csp-report' => [
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/script.js',
                'document-uri' => 'https://app.example/page',
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointAcceptsReportsJson(): void
    {
        Route::cspReport();

        Log::shouldReceive('warning')
            ->once()
            ->with('CSP violation: directive=script-src-elem blocked_host=evil.example document_path=/page');

        // The Reporting API batches: a JSON array of report objects, each
        // with a "type" and a camelCase "body". The document URL carries a
        // query, which must not reach the log line.
        $body = json_encode([
            [
                'type' => 'csp-violation',
                'age' => 0,
                'url' => 'https://app.example/page?next=1',
                'user_agent' => 'Mozilla/5.0',
                'body' => [
                    'documentURL' => 'https://app.example/page?next=1',
                    'blockedURL' => 'https://evil.example/x.js',
                    'effectiveDirective' => 'script-src-elem',
                    'disposition' => 'enforce',
                ],
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/reports+json',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointIgnoresOtherReportTypes(): void
    {
        Route::cspReport();

        // Browsers batch other report types into the same POST; they are
        // accepted (204) but not logged.
        Log::shouldReceive('warning')->never();

        $body = json_encode([
            [
                'type' => 'deprecation',
                'age' => 0,
                'url' => 'https://app.example/page',
                'body' => [
                    'message' => 'Something is deprecated',
                    'sourceFile' => 'https://app.example/app.js',
                ],
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/reports+json',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointCapsReportsAtTwenty(): void
    {
        Route::cspReport();

        // A batch of 25 csp-violation entries: at most 20 are logged.
        Log::shouldReceive('warning')->times(20);

        $entries = [];
        for ($i = 0; $i < 25; $i++) {
            $entries[] = [
                'type' => 'csp-violation',
                'age' => 0,
                'url' => 'https://app.example/page',
                'body' => [
                    'documentURL' => 'https://app.example/page',
                    'blockedURL' => 'https://evil.example/x.js',
                    'effectiveDirective' => 'script-src-elem',
                ],
            ];
        }

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/reports+json',
        ], json_encode($entries));

        $response->assertStatus(204);
    }

    public function testReportEndpointRejectsNonArrayReportsJson(): void
    {
        Route::cspReport();

        // A bare object is not a Reporting API batch.
        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/reports+json',
        ], json_encode(['type' => 'csp-violation']));

        $response->assertStatus(400);
    }

    public function testReportEndpointFallsBackToEffectiveDirectiveAndDashes(): void
    {
        Route::cspReport();

        Log::shouldReceive('warning')
            ->once()
            ->with('CSP violation: directive=img-src blocked_host=- document_path=-');

        $body = json_encode([
            'csp-report' => [
                'effective-directive' => 'img-src',
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointRejectsWrongContentType(): void
    {
        Route::cspReport();

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $response->assertStatus(415);
    }

    public function testReportEndpointRejectsOversizedBody(): void
    {
        Route::cspReport();

        // A body just over the 16 KiB cap.
        $body = str_repeat('a', CspReportController::MAX_BODY_BYTES + 1);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], $body);

        $response->assertStatus(413);
    }

    public function testReportEndpointRejectsOversizedContentLength(): void
    {
        Route::cspReport();

        // The declared length is over the cap, so the endpoint exits before
        // reading the body.
        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
            'CONTENT_LENGTH' => (string) (CspReportController::MAX_BODY_BYTES + 1),
        ], '{}');

        $response->assertStatus(413);
    }

    public function testReportEndpointRejectsInvalidJson(): void
    {
        Route::cspReport();

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], '{not valid json');

        $response->assertStatus(400);
    }

    public function testReportEndpointRejectsMissingCspReport(): void
    {
        Route::cspReport();

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], json_encode(['foo' => 'bar']));

        $response->assertStatus(400);
    }

    public function testRouteExcludesTheCsrfMiddleware(): void
    {
        Route::cspReport();

        $excluded = $this->reportRoute()->excludedMiddleware();

        // Browsers POST reports without a CSRF token, so the route must
        // exclude the web group's CSRF middleware. The framework has renamed
        // it over the years, so assert against whichever class the installed
        // framework actually puts in the web group.
        $this->app->make(Kernel::class);
        $web = $this->app['router']->getMiddlewareGroups()['web'];
        $csrf = array_values(array_intersect($web, $this->csrfMiddlewareClasses()));

        self::assertNotSame([], $csrf, 'the web group carries no known CSRF middleware');
        foreach ($csrf as $class) {
            self::assertContains($class, $excluded);
        }
    }

    public function testRouteCarriesTheDefaultThrottle(): void
    {
        Route::cspReport();

        self::assertContains(CspReportRoute::DEFAULT_THROTTLE, $this->reportRoute()->gatherMiddleware());
    }

    public function testRouteCanOverrideTheThrottle(): void
    {
        Route::cspReport('/csp-report', 'throttle:120,1');

        $middleware = $this->reportRoute()->gatherMiddleware();

        self::assertContains('throttle:120,1', $middleware);
        self::assertNotContains(CspReportRoute::DEFAULT_THROTTLE, $middleware);
    }

    public function testRouteCanDisableTheThrottle(): void
    {
        Route::cspReport('/csp-report', null);

        self::assertNotContains(CspReportRoute::DEFAULT_THROTTLE, $this->reportRoute()->gatherMiddleware());
    }

    /**
     * The registered report route, looked up by name.
     *
     * The name is set fluently after the route is added, so the name look-up
     * table is refreshed first. Laravel does this at app boot in a real
     * application (RouteServiceProvider), but in a test the route is added
     * after boot, so the look-up would otherwise be stale.
     */
    private function reportRoute(): \Illuminate\Routing\Route
    {
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName(CspReportRoute::ROUTE_NAME);
        self::assertNotNull($route);

        return $route;
    }

    /**
     * Every name the framework has used for the web group's CSRF middleware.
     *
     * @return list<string>
     */
    private function csrfMiddlewareClasses(): array
    {
        $classes = [
            ValidateCsrfToken::class,
            PreventRequestForgery::class,
        ];

        if (class_exists(VerifyCsrfToken::class)) {
            $classes[] = VerifyCsrfToken::class;
        }

        return $classes;
    }
}

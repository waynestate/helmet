<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Route;
use Waynestate\Helmet\Laravel\ReadinessRoute;

/**
 * The opt-in readiness endpoint for Laravel.
 *
 * Like the metrics route, the service provider only registers the macro; each
 * test opts in by calling Route::helmetReady(), the same way an app would.
 */
final class ReadinessTest extends TestCase
{
    public function testRouteMacroRegistersTheRoute(): void
    {
        Route::helmetReady();

        $route = $this->readyRoute();

        self::assertContains('GET', $route->methods());
        self::assertSame('ready', $route->uri());
    }

    public function testNoThrottleByDefault(): void
    {
        Route::helmetReady();

        self::assertSame([], array_values(array_filter(
            $this->readyRoute()->gatherMiddleware(),
            static fn(mixed $m): bool => is_string($m) && str_starts_with($m, 'throttle'),
        )));
    }

    public function testThrottleCanBeAdded(): void
    {
        Route::helmetReady('/ready', [], 'throttle:120,1');

        self::assertContains('throttle:120,1', $this->readyRoute()->gatherMiddleware());
    }

    public function testReadyWithTheDefaultConfig(): void
    {
        Route::helmetReady();

        $response = $this->get('/ready');

        $response->assertStatus(200);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('checks.headers.status', 'pass');
        $response->assertJsonPath('checks.headers.csp', 'enforce');
        $response->assertJsonPath('checks.headers.style_src_attr', 'inline');
        $response->assertJsonPath('checks.debug_output.status', 'pass');
    }

    public function testTheResponseWearsTheSecurityHeaders(): void
    {
        Route::helmetReady();

        $this->get('/ready')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testReportOnlyIsNamed(): void
    {
        config(['helmet.csp_report_only' => true]);
        Route::helmetReady();

        $this->get('/ready')->assertJsonPath('checks.headers.csp', 'report-only');
    }

    public function testAFailingAppCheckIs503(): void
    {
        Route::helmetReady('/ready', ['database' => ReadinessTestDown::class, 'cache' => ReadinessTestUp::class]);

        $response = $this->get('/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'not ready');
        $response->assertJsonPath('checks.database.status', 'fail');
        $response->assertJsonPath('checks.cache.status', 'pass');
    }

    public function testAThrowingAppCheckKeepsItsMessageOutOfTheBody(): void
    {
        Route::helmetReady('/ready', ['database' => ReadinessTestThrows::class]);

        $response = $this->get('/ready');

        $response->assertStatus(503);
        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function testANonInvokableCheckFails(): void
    {
        Route::helmetReady('/ready', ['database' => \stdClass::class]);

        $this->get('/ready')->assertStatus(503)->assertJsonPath('checks.database.status', 'fail');
    }

    public function testDebugInProductionIsAWarningNotAFailure(): void
    {
        config(['app.debug' => true]);
        $this->app->detectEnvironment(static fn(): string => 'production');
        Route::helmetReady();

        $response = $this->get('/ready');

        $response->assertStatus(200);
        $response->assertJsonPath('checks.debug_output.status', 'warn');
    }

    public function testTheBodyCarriesNoProxyAddresses(): void
    {
        config(['helmet.proxies' => ['172.18.0.1']]);
        Route::helmetReady();

        $response = $this->get('/ready');

        $response->assertJsonPath('checks.trusted_proxies.count', 1);
        self::assertStringNotContainsString('172.18.0.1', (string) $response->getContent());
    }

    public function testChecksMustBeNamedClassStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadinessRoute::register('/ready', [0 => ReadinessTestUp::class]); // @phpstan-ignore argument.type
    }

    private function readyRoute(): \Illuminate\Routing\Route
    {
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName(ReadinessRoute::ROUTE_NAME);
        self::assertNotNull($route);

        return $route;
    }
}

final class ReadinessTestUp
{
    public function __invoke(): bool
    {
        return true;
    }
}

final class ReadinessTestDown
{
    public function __invoke(): bool
    {
        return false;
    }
}

final class ReadinessTestThrows
{
    public function __invoke(): bool
    {
        throw new \RuntimeException('password=hunter2');
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Waynestate\Helmet\Laravel\MetricsRoute;

/**
 * The opt-in metrics endpoint.
 *
 * The route is not registered by the service provider; each test opts in by
 * calling Route::helmetMetrics(), the same way an application would. The
 * endpoint is fail-closed: off by default, and when on it serves metrics only
 * to a request that presents the bearer token or comes from an allowed IP.
 */
final class MetricsTest extends TestCase
{
    public function testRouteMacroRegistersTheRoute(): void
    {
        Route::helmetMetrics();

        $route = $this->metricsRoute();

        self::assertContains('GET', $route->methods());
        self::assertSame('metrics', $route->uri());
    }

    public function testRouteCarriesTheDefaultThrottle(): void
    {
        Route::helmetMetrics();

        self::assertContains(MetricsRoute::DEFAULT_THROTTLE, $this->metricsRoute()->gatherMiddleware());
    }

    public function testRouteCanOverrideTheThrottle(): void
    {
        Route::helmetMetrics('/metrics', 'throttle:120,1');

        $middleware = $this->metricsRoute()->gatherMiddleware();

        self::assertContains('throttle:120,1', $middleware);
        self::assertNotContains(MetricsRoute::DEFAULT_THROTTLE, $middleware);
    }

    public function testRouteCanDisableTheThrottle(): void
    {
        Route::helmetMetrics('/metrics', null);

        self::assertNotContains(MetricsRoute::DEFAULT_THROTTLE, $this->metricsRoute()->gatherMiddleware());
    }

    public function testDisabledEndpointIs404(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => false, 'token' => 'secret', 'allow' => []]);

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
        ]);

        $response->assertStatus(404);
    }

    public function testEnabledWithoutTokenOrAllowIs404AndLogsAWarning(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => null, 'allow' => []]);

        Log::shouldReceive('warning')
            ->once()
            ->with('HELMET metrics endpoint is enabled but has neither a token nor an allow list; refusing to serve metrics.');

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        $response->assertStatus(404);
    }

    public function testCorrectTokenIs200WithHelmetInfo(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => 'secret', 'allow' => []]);

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
        ]);

        $response->assertStatus(200);
        self::assertStringStartsWith('text/plain; version=0.0.4', (string) $response->headers->get('Content-Type'));
        $response->assertSee('helmet_info', false);
    }

    public function testWrongTokenIs403(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => 'secret', 'allow' => []]);

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer wrong',
        ]);

        $response->assertStatus(403);
    }

    public function testAllowedIpIs200(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => null, 'allow' => ['10.0.0.1']]);

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        $response->assertStatus(200);
    }

    public function testDisallowedIpIs403(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => null, 'allow' => ['10.0.0.1']]);

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.2',
        ]);

        $response->assertStatus(403);
    }

    public function testTrustedProxyUnwrapsForwardedFor(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => null, 'allow' => ['9.9.9.9']]);
        $this->app['config']->set('helmet.proxies', ['10.0.0.1']);

        // The peer 10.0.0.1 is a trusted proxy, so the X-Forwarded-For header
        // is honored: the client is 9.9.9.9, which is allowed.
        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1',
        ]);

        $response->assertStatus(200);
    }

    public function testUntrustedProxyForwardedForIsIgnored(): void
    {
        Route::helmetMetrics();
        $this->setMetricsConfig(['enabled' => true, 'token' => null, 'allow' => ['9.9.9.9']]);

        // No trusted proxies, so the X-Forwarded-For header is ignored: the
        // client is 10.0.0.1, which is not allowed.
        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Set the helmet.metrics config for the test app.
     *
     * @param array{enabled: bool, token: string|null, allow: list<string>} $config
     */
    private function setMetricsConfig(array $config): void
    {
        $this->app['config']->set('helmet.metrics', $config);
    }

    /**
     * The registered metrics route, looked up by name.
     *
     * The name is set fluently after the route is added, so the name look-up
     * table is refreshed first (the same reason as CspReportTest).
     */
    private function metricsRoute(): \Illuminate\Routing\Route
    {
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName(MetricsRoute::ROUTE_NAME);
        self::assertNotNull($route);

        return $route;
    }
}

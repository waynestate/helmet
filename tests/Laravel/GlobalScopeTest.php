<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Waynestate\Helmet\Laravel\SecurityHeaders;

/**
 * The default scope. SecurityHeaders runs in the kernel's global middleware,
 * so every response - including 404s and JSON routes - carries the baseline
 * headers.
 */
final class GlobalScopeTest extends TestCase
{
    public function testScopeDefaultsToGlobal(): void
    {
        self::assertSame('global', config('helmet.scope'));
    }

    public function testNotFoundResponseCarriesHstsAndCspOverHttps(): void
    {
        config(['helmet.proxies' => ['10.0.0.1']]);

        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/missing');

        $response->assertStatus(404);
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('Content-Security-Policy');
    }

    public function testJsonRouteCarriesHstsAndCspOverHttps(): void
    {
        $this->app['router']->get('/json', static fn() => response()->json(['ok' => true]));
        config(['helmet.proxies' => ['10.0.0.1']]);

        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/json');

        $response->assertStatus(200);
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('Content-Security-Policy');
    }

    public function testGlobalMiddlewareIsAddedOnceWhenTheKernelIsResolvedAgain(): void
    {
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);
        $this->app->make(Kernel::class);

        $counts = array_count_values($this->globalMiddlewareList($kernel));

        self::assertSame(1, $counts[SecurityHeaders::class] ?? 0);
    }
}

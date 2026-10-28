<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Helmet\Laravel\RequestForgeryGuard;

final class RequestForgeryGuardTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function enableCsrf(array $overrides = []): void
    {
        $this->app['config']->set('helmet.proxies', []);
        $this->app['config']->set('helmet.csrf', array_merge([
            'enabled' => true,
            'allow_same_site' => false,
            'trusted_origins' => [],
            'except' => [],
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function makeRequest(string $method, string $uri, array $server = []): Request
    {
        $server += [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'app.example',
            'HTTPS' => 'on',
        ];

        return Request::create($uri, $method, server: $server);
    }

    private function handle(Request $request): Response
    {
        $middleware = new RequestForgeryGuard();

        return $middleware->handle($request, static fn(): Response => response('ok', 200));
    }

    public function testRequestForgeryGuardIsNotRegisteredByDefault(): void
    {
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);

        $web = $this->app['router']->getMiddlewareGroups()['web'];

        self::assertNotContains(RequestForgeryGuard::class, $web);
    }

    public function testRequestForgeryGuardIsRegisteredWhenEnabled(): void
    {
        $this->app['config']->set('helmet.csrf.enabled', true);

        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);

        $web = $this->app['router']->getMiddlewareGroups()['web'];

        self::assertContains(RequestForgeryGuard::class, $web);
    }

    public function testRequestForgeryGuardIsNotRegisteredWhenExplicitlyDisabled(): void
    {
        $this->app['config']->set('helmet.csrf.enabled', false);

        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);

        $web = $this->app['router']->getMiddlewareGroups()['web'];

        self::assertNotContains(RequestForgeryGuard::class, $web);
    }

    public function testMiddlewarePassesThroughWhenDisabled(): void
    {
        $this->app['config']->set('helmet.csrf.enabled', false);
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());
    }

    public function testMiddlewareAllowsSameOriginSecFetchSite(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());
    }

    public function testMiddlewareRejectsCrossSiteSecFetchSite(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $response->getContent());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('no-store', $response->headers->get('Cache-Control'));
    }

    public function testMiddlewareRejectsSameSiteByDefault(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_SEC_FETCH_SITE' => 'same-site',
        ]);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMiddlewareAllowsSameSiteWhenAllowed(): void
    {
        $this->enableCsrf(['allow_same_site' => true]);
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_SEC_FETCH_SITE' => 'same-site',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareAllowsOriginFallbackSameOrigin(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_ORIGIN' => 'https://app.example',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareAllowsTrustedOrigin(): void
    {
        $this->enableCsrf(['trusted_origins' => ['https://trusted.example']]);
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_ORIGIN' => 'https://trusted.example',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareRejectsCrossOrigin(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $response->getContent());
    }

    public function testMiddlewareRejectsNullOrigin(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit', [
            'HTTP_ORIGIN' => 'null',
        ]);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMiddlewareAllowsAbsentOrigin(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('POST', 'https://app.example/submit');

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareAllowsExceptedPath(): void
    {
        $this->enableCsrf(['except' => ['/webhook']]);
        $request = $this->makeRequest('POST', 'https://app.example/webhook', [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareAllowsSafeMethods(): void
    {
        $this->enableCsrf();
        $request = $this->makeRequest('GET', 'https://app.example/submit', [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }
}

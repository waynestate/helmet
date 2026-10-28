<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Csrf;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Csrf\RequestForgeryGuard;
use Waynestate\Helmet\Proxy\TrustedProxies;

final class RequestForgeryGuardTest extends TestCase
{
    /**
     * @param list<string> $trustedOrigins
     * @param list<string> $except
     */
    private function guard(bool $allowSameSite = false, array $trustedOrigins = [], array $except = []): RequestForgeryGuard
    {
        $factory = new Psr17Factory();

        return new RequestForgeryGuard(
            new TrustedProxies([]),
            $factory,
            $factory,
            $allowSameSite,
            $trustedOrigins,
            $except,
        );
    }

    /**
     * @param list<string> $trustedOrigins
     * @param list<string> $except
     */
    private function guardWithoutFactories(bool $allowSameSite = false, array $trustedOrigins = [], array $except = []): RequestForgeryGuard
    {
        return new RequestForgeryGuard(
            new TrustedProxies([]),
            null,
            null,
            $allowSameSite,
            $trustedOrigins,
            $except,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $serverParams
     */
    private function makeRequest(string $method, string $uri, array $headers = [], array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri, $headers, null, '1.1', $serverParams);
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('X-Handler', 'yes');
            }
        };
    }

    public function testSafeMethodsAlwaysPass(): void
    {
        $guard = $this->guard();

        foreach (['GET', 'HEAD', 'OPTIONS', 'get', 'head', 'options'] as $method) {
            self::assertTrue(
                $guard->allows($method, '/anything', 'cross-site', 'https://evil.example', 'https://app.example'),
                "$method should pass",
            );
        }
    }

    public function testExceptedExactPathPasses(): void
    {
        $guard = $this->guard(except: ['/webhook']);

        self::assertTrue($guard->allows('POST', '/webhook', 'cross-site', 'https://evil.example', 'https://app.example'));
        self::assertFalse($guard->allows('POST', '/webhook/other', 'cross-site', 'https://evil.example', 'https://app.example'));
    }

    public function testExceptedPrefixPathPasses(): void
    {
        $guard = $this->guard(except: ['/api/*']);

        self::assertTrue($guard->allows('POST', '/api/users', 'cross-site', 'https://evil.example', 'https://app.example'));
        self::assertFalse($guard->allows('POST', '/apiary', 'cross-site', 'https://evil.example', 'https://app.example'));
    }

    public function testExceptedStarMatchesEveryPath(): void
    {
        $guard = $this->guard(except: ['*']);

        self::assertTrue($guard->allows('POST', '/anything', 'cross-site', 'https://evil.example', 'https://app.example'));
    }

    public function testSecFetchSiteSameOriginPasses(): void
    {
        $guard = $this->guard();

        self::assertTrue($guard->allows('POST', '/submit', 'same-origin', 'https://evil.example', 'https://app.example'));
    }

    public function testSecFetchSiteNonePasses(): void
    {
        $guard = $this->guard();

        self::assertTrue($guard->allows('POST', '/submit', 'none', 'https://evil.example', 'https://app.example'));
    }

    public function testSecFetchSiteSameSiteIsRejectedByDefault(): void
    {
        $guard = $this->guard();

        self::assertFalse($guard->allows('POST', '/submit', 'same-site', 'https://evil.example', 'https://app.example'));
    }

    public function testSecFetchSiteSameSitePassesWhenAllowed(): void
    {
        $guard = $this->guard(allowSameSite: true);

        self::assertTrue($guard->allows('POST', '/submit', 'same-site', 'https://evil.example', 'https://app.example'));
    }

    public function testSecFetchSiteCrossSiteIsRejected(): void
    {
        $guard = $this->guard();

        self::assertFalse($guard->allows('POST', '/submit', 'cross-site', 'https://app.example', 'https://app.example'));
    }

    public function testSecFetchSiteUnknownValueIsRejected(): void
    {
        $guard = $this->guard();

        self::assertFalse($guard->allows('POST', '/submit', 'from-the-deep', 'https://app.example', 'https://app.example'));
    }

    public function testSecFetchSiteIsCaseInsensitive(): void
    {
        $guard = $this->guard(allowSameSite: true);

        self::assertTrue($guard->allows('POST', '/submit', 'Same-Origin', null, 'https://app.example'));
        self::assertTrue($guard->allows('POST', '/submit', 'NONE', null, 'https://app.example'));
        self::assertTrue($guard->allows('POST', '/submit', 'SAME-SITE', null, 'https://app.example'));
        self::assertFalse($guard->allows('POST', '/submit', 'CROSS-SITE', null, 'https://app.example'));
    }

    public function testSecFetchSiteWinsOverOrigin(): void
    {
        $guard = $this->guard();

        // A cross-site Sec-Fetch-Site is rejected even when the Origin header
        // matches the request's own origin.
        self::assertFalse($guard->allows('POST', '/submit', 'cross-site', 'https://app.example', 'https://app.example'));

        // A same-origin Sec-Fetch-Site passes even when the Origin header is
        // from another site.
        self::assertTrue($guard->allows('POST', '/submit', 'same-origin', 'https://evil.example', 'https://app.example'));
    }

    public function testOriginFallbackSameOriginPasses(): void
    {
        $guard = $this->guard();

        self::assertTrue($guard->allows('POST', '/submit', null, 'https://app.example', 'https://app.example'));
        // Default ports are dropped, so the two spellings compare equal.
        self::assertTrue($guard->allows('POST', '/submit', null, 'https://app.example:443', 'https://app.example'));
    }

    public function testOriginFallbackTrustedOriginPasses(): void
    {
        $guard = $this->guard(trustedOrigins: ['https://trusted.example']);

        self::assertTrue($guard->allows('POST', '/submit', null, 'https://trusted.example', 'https://app.example'));
        // The configured origin is normalized, so case and default port do
        // not matter.
        $normalized = $this->guard(trustedOrigins: ['HTTPS://Trusted.Example:443']);
        self::assertTrue($normalized->allows('POST', '/submit', null, 'https://trusted.example', 'https://app.example'));
    }

    public function testOriginFallbackCrossOriginIsRejected(): void
    {
        $guard = $this->guard();

        self::assertFalse($guard->allows('POST', '/submit', null, 'https://evil.example', 'https://app.example'));
    }

    public function testOriginNullIsRejected(): void
    {
        $guard = $this->guard();

        self::assertFalse($guard->allows('POST', '/submit', null, 'null', 'https://app.example'));
    }

    public function testOriginAbsentPasses(): void
    {
        $guard = $this->guard();

        self::assertTrue($guard->allows('POST', '/submit', null, null, 'https://app.example'));
    }

    public function testOriginUnparseableIsRejected(): void
    {
        $guard = $this->guard();

        foreach (['not-an-origin', 'ftp://example.com', 'https://', 'https://example.com/path'] as $origin) {
            self::assertFalse(
                $guard->allows('POST', '/submit', null, $origin, 'https://app.example'),
                "$origin should be rejected",
            );
        }
    }

    public function testTrustedOriginInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid http or https origin');

        $this->guard(trustedOrigins: ['not-an-origin']);
    }

    public function testTrustedOriginNonStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trusted origins must be non-empty strings');

        $this->guard(trustedOrigins: [123]);
    }

    public function testExceptInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('except patterns must be non-empty strings');

        $this->guard(except: ['']);
    }

    public function testProcessAllowsSameOrigin(): void
    {
        $guard = $this->guard();
        $request = $this->makeRequest(
            'POST',
            'https://app.example/submit',
            ['Sec-Fetch-Site' => 'same-origin'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $response = $guard->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Handler'));
    }

    public function testProcessAllowsOriginFallback(): void
    {
        $guard = $this->guard();
        $request = $this->makeRequest(
            'POST',
            'https://app.example/submit',
            ['Origin' => 'https://app.example'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $response = $guard->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Handler'));
    }

    public function testProcessRejectsCrossSite(): void
    {
        $guard = $this->guard();
        $request = $this->makeRequest(
            'POST',
            'https://app.example/submit',
            ['Sec-Fetch-Site' => 'cross-site'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $response = $guard->process($request, $this->passthroughHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Forbidden', (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-Handler'));
    }

    public function testProcessRejectsCrossOrigin(): void
    {
        $guard = $this->guard();
        $request = $this->makeRequest(
            'POST',
            'https://app.example/submit',
            ['Origin' => 'https://evil.example'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $response = $guard->process($request, $this->passthroughHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', (string) $response->getBody());
    }

    public function testProcessExceptedPathPasses(): void
    {
        $guard = $this->guard(except: ['/webhook']);
        $request = $this->makeRequest(
            'POST',
            'https://app.example/webhook',
            ['Sec-Fetch-Site' => 'cross-site'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $response = $guard->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Handler'));
    }

    public function testProcessMissingFactoriesThrowsOnReject(): void
    {
        $guard = $this->guardWithoutFactories();
        $request = $this->makeRequest(
            'POST',
            'https://app.example/submit',
            ['Sec-Fetch-Site' => 'cross-site'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSR-17 ResponseFactory and StreamFactory');

        $guard->process($request, $this->passthroughHandler());
    }

    public function testProcessMissingFactoriesDoesNotThrowWhenAllowed(): void
    {
        $guard = $this->guardWithoutFactories();
        $request = $this->makeRequest(
            'POST',
            'https://app.example/submit',
            ['Sec-Fetch-Site' => 'same-origin'],
            ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'],
        );

        $response = $guard->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOriginFromServerParamsUsesHttps(): void
    {
        $proxies = new TrustedProxies([]);

        self::assertSame(
            'https://app.example',
            RequestForgeryGuard::originFromServerParams(['HTTP_HOST' => 'app.example', 'HTTPS' => 'on'], $proxies),
        );
    }

    public function testOriginFromServerParamsUsesHttpWhenThereIsNoHttps(): void
    {
        $proxies = new TrustedProxies([]);

        self::assertSame(
            'http://app.example',
            RequestForgeryGuard::originFromServerParams(['HTTP_HOST' => 'app.example'], $proxies),
        );
    }

    public function testOriginFromServerParamsHonorsTrustedProxyForwardedProto(): void
    {
        $proxies = new TrustedProxies(['10.0.0.1']);

        self::assertSame(
            'https://app.example',
            RequestForgeryGuard::originFromServerParams([
                'HTTP_HOST' => 'app.example',
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ], $proxies),
        );
    }

    public function testOriginFromServerParamsIgnoresUntrustedProxyForwardedProto(): void
    {
        $proxies = new TrustedProxies(['10.0.0.1']);

        self::assertSame(
            'http://app.example',
            RequestForgeryGuard::originFromServerParams([
                'HTTP_HOST' => 'app.example',
                'REMOTE_ADDR' => '192.168.1.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ], $proxies),
        );
    }

    public function testOriginFromServerParamsMissingHostReturnsNull(): void
    {
        $proxies = new TrustedProxies([]);

        self::assertNull(RequestForgeryGuard::originFromServerParams(['HTTPS' => 'on'], $proxies));
    }

    public function testOriginFromServerParamsKeepsANonDefaultPort(): void
    {
        $proxies = new TrustedProxies([]);

        self::assertSame(
            'https://app.example:8443',
            RequestForgeryGuard::originFromServerParams(['HTTP_HOST' => 'app.example:8443', 'HTTPS' => 'on'], $proxies),
        );
    }

    public function testCanonicalOriginNormalizesSchemeHostAndPort(): void
    {
        self::assertSame('https://example.com', RequestForgeryGuard::canonicalOrigin('https://example.com'));
        self::assertSame('https://example.com', RequestForgeryGuard::canonicalOrigin('HTTPS://Example.COM:443'));
        self::assertSame('http://example.com', RequestForgeryGuard::canonicalOrigin('http://example.com:80'));
        self::assertSame('https://example.com:8443', RequestForgeryGuard::canonicalOrigin('https://example.com:8443'));
        self::assertSame('http://[::1]:8080', RequestForgeryGuard::canonicalOrigin('http://[::1]:8080'));
    }

    public function testCanonicalOriginRejectsInvalidOrigins(): void
    {
        foreach ([
            'not-an-origin',
            'ftp://example.com',
            'https://',
            'https://example.com/path',
            'https://user:pass@example.com',
            'https://example.com?query=1',
            'https://example.com#fragment',
        ] as $origin) {
            self::assertNull(
                RequestForgeryGuard::canonicalOrigin($origin),
                "$origin should be rejected",
            );
        }
    }
}

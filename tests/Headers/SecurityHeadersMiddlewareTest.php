<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Proxy\TrustedProxies;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function makeRequest(array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://app.example/', [], null, '1.1', $serverParams);
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('X-Powered-By', 'PHP/8.2');
            }
        };
    }

    public function testSetsAllSecurityHeaders(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('camera=(), geolocation=(), microphone=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testCspContainsDefaultPolicyAndNonce(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertTrue($response->hasHeader('Content-Security-Policy'));
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringContainsString('https://wayneui.apps.wayne.edu', $csp);
        self::assertMatchesRegularExpression("#'nonce-[A-Za-z0-9+/=]+'#", $csp);
    }

    public function testHstsSentWhenHttps(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $response->getHeaderLine('Strict-Transport-Security')
        );
    }

    public function testNoHstsWhenNotHttps(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testXPoweredByIsRemoved(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertFalse($response->hasHeader('X-Powered-By'));
    }

    public function testNonceMatchesRequestAttribute(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $handler = new class implements RequestHandlerInterface {
            public mixed $capturedNonce = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturedNonce = $request->getAttribute('helmet.csp_nonce');

                return new Response(200);
            }
        };

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $handler);

        self::assertIsString($handler->capturedNonce);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("'nonce-{$handler->capturedNonce}'", $csp);
    }

    public function testCustomCspPolicyIsUsed(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $policy = ['default-src' => ["'none'"]];
        $middleware = new SecurityHeadersMiddleware($proxies, $policy);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
    }

    public function testDefaultPolicyMatchesSharedDefault(): void
    {
        $middleware = new SecurityHeadersMiddleware(new TrustedProxies([]));

        $request = $this->makeRequest();
        $response = $middleware->process($request, $this->passthroughHandler());

        $nonce = SecurityHeaders::generateNonce();
        $expected = SecurityHeaders::build(SecurityHeaders::defaultCspPolicy(), $nonce, false);

        self::assertSame(
            $expected['X-Content-Type-Options'],
            $response->getHeaderLine('X-Content-Type-Options')
        );
    }
}

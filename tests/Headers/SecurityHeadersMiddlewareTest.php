<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use Nyholm\Psr7\Factory\Psr17Factory;
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

    private function throwingHandler(\Throwable $e): RequestHandlerInterface
    {
        return new class ($e) implements RequestHandlerInterface {
            public function __construct(private readonly \Throwable $e) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->e;
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
        self::assertSame(
            'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            $response->getHeaderLine('Permissions-Policy'),
        );
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        // CORP is opt-in: the default sends no CORP header.
        self::assertFalse($response->hasHeader('Cross-Origin-Resource-Policy'));
    }

    public function testSendsConfiguredCoopCorpAndPermissionsPolicy(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware(
            $proxies,
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'unsafe-none',
            'same-site',
            ['camera' => ['self']],
        );

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame('unsafe-none', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('same-site', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('camera=(self)', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testOmitsCoopWhenNull(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware(
            $proxies,
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            null,
        );

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertFalse($response->hasHeader('Cross-Origin-Opener-Policy'));
    }

    public function testOmitsPermissionsPolicyWhenEmpty(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware(
            $proxies,
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            [],
        );

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertFalse($response->hasHeader('Permissions-Policy'));
    }

    public function testConstructorRejectsInvalidCoop(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COOP must be one of');

        new SecurityHeadersMiddleware(
            $proxies,
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'bogus',
        );
    }

    public function testConstructorRejectsInvalidCorp(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CORP must be one of');

        new SecurityHeadersMiddleware(
            $proxies,
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'same-origin',
            'bogus',
        );
    }

    public function testConstructorRejectsInvalidPermissionsPolicy(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature name "Camera" is invalid');

        new SecurityHeadersMiddleware(
            $proxies,
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            ['Camera' => []],
        );
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
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringContainsString('https://wayneui.apps.wayne.edu', $csp);
        self::assertMatchesRegularExpression("#'nonce-[A-Za-z0-9+/=]+'#", $csp);
        self::assertStringContainsString("'strict-dynamic'", $csp);
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
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    public function testHstsHonorsCustomMaxAgeAndSubDomainSetting(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        // A short max-age with no subdomains is the staged-rollout starting point.
        $middleware = new SecurityHeadersMiddleware($proxies, null, true, null, 600, false);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        self::assertSame('max-age=600', $response->getHeaderLine('Strict-Transport-Security'));
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

    public function testCustomCspPolicyMergesOverDefault(): void
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
        // The custom directive replaces the default's value for that directive...
        self::assertStringContainsString("default-src 'none'", $csp);
        // ...while directives the app did not set are kept from the default.
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
    }

    public function testCustomCspPolicyCanRemoveADirective(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $policy = ['form-action' => null];
        $middleware = new SecurityHeadersMiddleware($proxies, $policy);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->passthroughHandler());

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringNotContainsString('form-action', $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
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
            $response->getHeaderLine('X-Content-Type-Options'),
        );
    }

    public function testRethrowsByDefaultWhenHandlerThrows(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $middleware->process($request, $this->throwingHandler(new \RuntimeException('boom')));
    }

    public function testReturns500WithHeadersWhenRethrowIsFalse(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $factory = new Psr17Factory();
        $middleware = new SecurityHeadersMiddleware($proxies, null, false, $factory);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response = $middleware->process($request, $this->throwingHandler(new \RuntimeException('boom')));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertTrue($response->hasHeader('Content-Security-Policy'));
        self::assertMatchesRegularExpression(
            "#'nonce-[A-Za-z0-9+/=]+'#",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testRethrowFalseWithoutFactoryThrows(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $middleware = new SecurityHeadersMiddleware($proxies, null, false);

        $request = $this->makeRequest([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ResponseFactory');

        $middleware->process($request, $this->throwingHandler(new \RuntimeException('boom')));
    }
}

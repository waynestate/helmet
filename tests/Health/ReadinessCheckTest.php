<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Health;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Health\HealthCheck;
use Waynestate\Helmet\Health\Readiness;
use Waynestate\Helmet\Health\ReadinessCheck;

final class ReadinessCheckTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('X-App', 'yes');
            }
        };
    }

    private function middleware(Readiness $readiness, string $path = '/ready'): ReadinessCheck
    {
        $factory = new Psr17Factory();

        return new ReadinessCheck($factory, $factory, $readiness, $path);
    }

    public function testGetReadyRunsTheChecks(): void
    {
        $response = $this->middleware((new Readiness())->withCheck('database', static fn(): bool => true))
            ->process(new ServerRequest('GET', 'http://app.example/ready'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('{"status":"ready","checks":{"database":{"status":"pass"}}}', (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-App'));
    }

    public function testAFailedCheckIs503(): void
    {
        $response = $this->middleware((new Readiness())->withCheck('database', static fn(): bool => false))
            ->process(new ServerRequest('HEAD', 'http://app.example/ready'), $this->handler());

        self::assertSame(503, $response->getStatusCode());
    }

    public function testOtherPathsAndMethodsPassThrough(): void
    {
        $middleware = $this->middleware((new Readiness())->withCheck('database', static fn(): bool => false));

        self::assertTrue($middleware->process(new ServerRequest('GET', 'http://app.example/'), $this->handler())->hasHeader('X-App'));
        self::assertTrue($middleware->process(new ServerRequest('POST', 'http://app.example/ready'), $this->handler())->hasHeader('X-App'));
    }

    public function testCustomPath(): void
    {
        $response = $this->middleware(new Readiness(), '/readyz')
            ->process(new ServerRequest('GET', 'http://app.example/readyz'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-App'));
    }

    public function testLivenessStaysIndependentOfAFailingReadiness(): void
    {
        // The two answers live side by side: a failing dependency takes the
        // instance out of rotation (503 on /ready) and never fails /up.
        $factory = new Psr17Factory();
        $ready = $this->middleware((new Readiness())->withCheck('database', static fn(): bool => false));
        $live = new HealthCheck($factory, $factory);
        $stack = new class ($ready, $this->handler()) implements RequestHandlerInterface {
            public function __construct(private ReadinessCheck $ready, private RequestHandlerInterface $app) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->ready->process($request, $this->app);
            }
        };

        self::assertSame(200, $live->process(new ServerRequest('GET', 'http://app.example/up'), $stack)->getStatusCode());
        self::assertSame(503, $live->process(new ServerRequest('GET', 'http://app.example/ready'), $stack)->getStatusCode());
    }
}

<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Health;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Health\HealthCheck;

final class HealthCheckTest extends TestCase
{
    private HealthCheck $middleware;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->middleware = new HealthCheck($factory, $factory, '/up');
    }

    private function makeRequest(string $method, string $uri): ServerRequestInterface
    {
        return new ServerRequest($method, $uri);
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('X-App', 'yes');
            }
        };
    }

    public function testGetHealthPathReturnsOk(): void
    {
        $request = $this->makeRequest('GET', 'https://app.example/up');
        $response = $this->middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        // The application handler is not called for the health path.
        self::assertFalse($response->hasHeader('X-App'));
    }

    public function testHeadHealthPathReturnsOk(): void
    {
        $request = $this->makeRequest('HEAD', 'https://app.example/up');
        $response = $this->middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('X-App'));
    }

    public function testHealthPathIsHostAgnostic(): void
    {
        // The orchestrator may probe by IP:port (no DNS name); the path is
        // what matters, not the host.
        $request = $this->makeRequest('GET', 'http://10.1.2.3:8080/up');
        $response = $this->middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('X-App'));
    }

    public function testOtherPathPassesThrough(): void
    {
        $request = $this->makeRequest('GET', 'https://app.example/other');
        $response = $this->middleware->process($request, $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('X-App'));
    }

    public function testPostHealthPathPassesThrough(): void
    {
        $request = $this->makeRequest('POST', 'https://app.example/up');
        $response = $this->middleware->process($request, $this->passthroughHandler());

        self::assertTrue($response->hasHeader('X-App'));
    }
}

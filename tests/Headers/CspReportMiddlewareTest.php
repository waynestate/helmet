<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Headers\CspReportMiddleware;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * H7: the PSR-15 path receives CSP reports too, so all three consumer paths
 * (raw PHP, PSR-15, Laravel) have a report endpoint and log the same line.
 *
 * CspReportMiddleware answers the report path the way ReadinessCheck answers
 * /ready: on its path it answers and the app never sees the request; on any
 * other path it passes through untouched.
 */
final class CspReportMiddlewareTest extends TestCase
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

    private function middleware(?Logger $logger = null, ?RequestMetrics $metrics = null, string $path = '/csp-report'): CspReportMiddleware
    {
        $factory = new Psr17Factory();

        return new CspReportMiddleware($factory, $factory, $path, $logger, $metrics);
    }

    private function legacy(): string
    {
        return (string) json_encode(['csp-report' => [
            'document-uri' => 'https://app.example.edu/courses/12?tab=grades',
            'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example.com/x.js',
            'original-policy' => "script-src 'self' secret-policy-text",
        ]]);
    }

    private function post(string $path, string $contentType, string $body): ServerRequest
    {
        return (new ServerRequest('POST', 'https://app.example.edu' . $path, ['Content-Type' => $contentType]))
            ->withBody((new Psr17Factory())->createStream($body));
    }

    public function testAReportOnThePathIsLoggedAndAnsweredWithNoContent(): void
    {
        $handler = new TestHandler();

        $response = $this->middleware(new Logger('test', [$handler]))
            ->process($this->post('/csp-report', 'application/csp-report', $this->legacy()), $this->handler());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('X-App'));

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(
            'CSP violation: directive=script-src-elem blocked_host=evil.example.com document_path=/courses/12',
            $records[0]->message,
        );
        self::assertSame([
            'directive' => 'script-src-elem',
            'blocked_host' => 'evil.example.com',
            'document_path' => '/courses/12',
        ], $records[0]->context);
    }

    public function testOtherPathsPassThroughUntouched(): void
    {
        $handler = new TestHandler();

        $response = $this->middleware(new Logger('test', [$handler]))
            ->process($this->post('/courses', 'application/csp-report', $this->legacy()), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-App'));
        self::assertSame([], $handler->getRecords());
    }

    public function testAGetOnThePathIsRefusedWithAllowPost(): void
    {
        $response = $this->middleware()
            ->process(new ServerRequest('GET', 'https://app.example.edu/csp-report'), $this->handler());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame('Method not allowed.', (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-App'));
    }

    public function testACustomPathIsHonored(): void
    {
        $middleware = $this->middleware(null, null, '/reports/csp');

        self::assertSame(204, $middleware->process($this->post('/reports/csp', 'application/csp-report', $this->legacy()), $this->handler())->getStatusCode());
        self::assertSame(200, $middleware->process($this->post('/csp-report', 'application/csp-report', $this->legacy()), $this->handler())->getStatusCode());
    }

    public function testAWrongContentTypeIs415(): void
    {
        $response = $this->middleware()
            ->process($this->post('/csp-report', 'application/json', $this->legacy()), $this->handler());

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('Unsupported media type.', (string) $response->getBody());
    }

    public function testAnOversizedBodyIs413(): void
    {
        $response = $this->middleware()
            ->process($this->post('/csp-report', 'application/csp-report', str_repeat('a', 16 * 1024 + 1)), $this->handler());

        self::assertSame(413, $response->getStatusCode());
    }

    public function testADeclaredLengthOverTheCapIs413(): void
    {
        $request = $this->post('/csp-report', 'application/csp-report', $this->legacy())
            ->withHeader('Content-Length', (string) (16 * 1024 + 1));

        self::assertSame(413, $this->middleware()->process($request, $this->handler())->getStatusCode());
    }

    public function testAReportingApiBatchIsCountedByDirective(): void
    {
        $metrics = new RequestMetrics(new InMemory());
        $body = (string) json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src']],
            ['type' => 'deprecation', 'body' => []],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src']],
        ]);

        $response = $this->middleware(null, $metrics)
            ->process($this->post('/csp-report', 'application/reports+json', $body), $this->handler());

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString(
            'helmet_csp_violations_total{directive="img-src"} 2',
            (new RenderTextFormat())->render($metrics->samples()),
        );
    }

    public function testTheRawAndPsr15AnswersAgree(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_TYPE'] = 'application/csp-report';
            unset($_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_CONTENT_LENGTH']);
            $raw = \Waynestate\Helmet\helmet_csp_report_response(null, null, $this->legacy());
        } finally {
            $_SERVER = $server;
        }

        $psr = $this->middleware()
            ->process($this->post('/csp-report', 'application/csp-report', $this->legacy()), $this->handler());

        self::assertSame($raw['status'], $psr->getStatusCode());
        self::assertSame($raw['body'], (string) $psr->getBody());
        foreach ($raw['headers'] as $name => $value) {
            self::assertSame($value, $psr->getHeaderLine($name), $name);
        }
    }
}

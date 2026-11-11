<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Log\AbstractLogger;
use Waynestate\Helmet\Metrics\RequestMetrics;

use function Waynestate\Helmet\helmet_csp_report_response;

/**
 * H7, HELMET-10: a raw PHP app receives its own CSP reports.
 *
 * Until now only the Laravel adapter had a report endpoint, and a raw PHP
 * app pointed csp_report_uri at a route it wrote itself. The data twin,
 * helmet_csp_report_response(), reads the method and content headers from
 * $_SERVER, takes the body as an argument here (php://input in production),
 * logs one line per violation and answers. helmet_csp_report() sends that
 * answer and exits, the way helmet_ready() does.
 */
final class FunctionsCspReportTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function post(string $contentType, ?string $contentLength = null): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = $contentType;
        unset($_SERVER['HTTP_CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_CONTENT_LENGTH']);
        if ($contentLength !== null) {
            $_SERVER['CONTENT_LENGTH'] = $contentLength;
        }
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

    public function testAReportIsLoggedAndAnsweredWithNoContent(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $this->post('application/csp-report');

        $response = helmet_csp_report_response($logger, null, $this->legacy());

        self::assertSame(204, $response['status']);
        self::assertSame('', $response['body']);
        self::assertSame('text/plain; charset=utf-8', $response['headers']['Content-Type']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame(
            'CSP violation: directive=script-src-elem blocked_host=evil.example.com document_path=/courses/12',
            $records[0]->message,
        );
        self::assertSame([
            'directive' => 'script-src-elem',
            'blocked_host' => 'evil.example.com',
            'document_path' => '/courses/12',
        ], $records[0]->context);
        self::assertStringNotContainsString('secret-policy-text', json_encode($records[0]->toArray()) ?: '');
    }

    public function testAReportingApiBatchLogsEachViolation(): void
    {
        $handler = new TestHandler();
        $this->post('application/reports+json');
        $body = (string) json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'https://a.example.net/p.png']],
            ['type' => 'deprecation', 'body' => []],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'font-src']],
        ]);

        $response = helmet_csp_report_response(new Logger('test', [$handler]), null, $body);

        self::assertSame(204, $response['status']);
        self::assertCount(2, $handler->getRecords());
    }

    public function testTheContentTypeHeaderIsReadFromEitherServerKey(): void
    {
        $handler = new TestHandler();
        $this->post('');
        unset($_SERVER['CONTENT_TYPE']);
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/csp-report';

        $response = helmet_csp_report_response(new Logger('test', [$handler]), null, $this->legacy());

        self::assertSame(204, $response['status']);
        self::assertCount(1, $handler->getRecords());
    }

    public function testAGetIsRefusedWithAllowPost(): void
    {
        $handler = new TestHandler();
        $this->post('application/csp-report');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = helmet_csp_report_response(new Logger('test', [$handler]), null, $this->legacy());

        self::assertSame(405, $response['status']);
        self::assertSame('Method not allowed.', $response['body']);
        self::assertSame('POST', $response['headers']['Allow']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);
        self::assertSame([], $handler->getRecords());
    }

    public function testAWrongContentTypeIsRefusedAndNothingIsLogged(): void
    {
        $handler = new TestHandler();
        $this->post('application/json');

        $response = helmet_csp_report_response(new Logger('test', [$handler]), null, $this->legacy());

        self::assertSame(415, $response['status']);
        self::assertSame('Unsupported media type.', $response['body']);
        self::assertSame([], $handler->getRecords());
    }

    public function testADeclaredLengthOverTheCapIsRefused(): void
    {
        $handler = new TestHandler();
        $this->post('application/csp-report', (string) (16 * 1024 + 1));

        $response = helmet_csp_report_response(new Logger('test', [$handler]), null, $this->legacy());

        self::assertSame(413, $response['status']);
        self::assertSame([], $handler->getRecords());
    }

    public function testAnOversizedBodyIsRefused(): void
    {
        $this->post('application/csp-report');

        $response = helmet_csp_report_response(null, null, str_repeat('a', 16 * 1024 + 1));

        self::assertSame(413, $response['status']);
        self::assertSame('Report too large.', $response['body']);
    }

    public function testInvalidJsonIsRefused(): void
    {
        $this->post('application/csp-report');

        $response = helmet_csp_report_response(null, null, '{nope');

        self::assertSame(400, $response['status']);
        self::assertSame('Invalid report.', $response['body']);
    }

    public function testNoLoggerStillAnswers(): void
    {
        $this->post('application/csp-report');

        self::assertSame(204, helmet_csp_report_response(null, null, $this->legacy())['status']);
    }

    public function testALoggerThatThrowsNeverBreaksTheAnswer(): void
    {
        $this->post('application/csp-report');
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log down');
            }
        };

        self::assertSame(204, helmet_csp_report_response($logger, null, $this->legacy())['status']);
    }

    public function testRequestMetricsCountEachViolationByDirective(): void
    {
        $this->post('application/reports+json');
        $metrics = new RequestMetrics(new InMemory());
        $body = (string) json_encode([
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src-elem']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src-elem']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'made-up']],
        ]);

        helmet_csp_report_response(null, $metrics, $body);

        $text = (new RenderTextFormat())->render($metrics->samples());
        self::assertStringContainsString('helmet_csp_violations_total{directive="script-src-elem"} 2', $text);
        self::assertStringContainsString('helmet_csp_violations_total{directive="other"} 1', $text);
    }

    public function testARefusedReportCountsNothing(): void
    {
        $this->post('application/json');
        $metrics = new RequestMetrics(new InMemory());

        helmet_csp_report_response(null, $metrics, $this->legacy());

        self::assertStringNotContainsString(
            'helmet_csp_violations_total{',
            (new RenderTextFormat())->render($metrics->samples()),
        );
    }
}

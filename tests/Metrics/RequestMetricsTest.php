<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * The request metrics, against promphp's InMemory storage (ADR-0011: the
 * tests never need a live store). The labels are the point: whatever a client
 * sends, the set of time series stays small and HELMET-shaped.
 */
final class RequestMetricsTest extends TestCase
{
    public function testObserveRecordsAHistogramByRouteMethodAndStatusClass(): void
    {
        $metrics = new RequestMetrics(new InMemory());
        $metrics->observe('docs.show', 'get', 200, 0.03);
        $metrics->observe('docs.show', 'GET', 204, 0.2);
        $metrics->observe('docs.show', 'GET', 503, 2.0);

        $body = $this->render($metrics);

        self::assertStringContainsString('# TYPE helmet_http_request_duration_seconds histogram', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_count{route="docs.show",method="GET",status="2xx"} 2', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_count{route="docs.show",method="GET",status="5xx"} 1', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_bucket{route="docs.show",method="GET",status="2xx",le="0.05"} 1', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_bucket{route="docs.show",method="GET",status="2xx",le="0.25"} 2', $body);
    }

    public function testANegativeDurationIsRecordedAsZero(): void
    {
        $metrics = new RequestMetrics(new InMemory());
        $metrics->observe('page', 'GET', 200, -1.0);

        self::assertStringContainsString(
            'helmet_http_request_duration_seconds_sum{route="page",method="GET",status="2xx"} 0',
            $this->render($metrics),
        );
    }

    public function testCspViolationCountsByDirective(): void
    {
        $metrics = new RequestMetrics(new InMemory());
        $metrics->cspViolation('script-src-elem');
        $metrics->cspViolation("script-src-elem 'self'");
        $metrics->cspViolation('made-up-directive');

        $body = $this->render($metrics);

        self::assertStringContainsString('# TYPE helmet_csp_violations_total counter', $body);
        self::assertStringContainsString('helmet_csp_violations_total{directive="script-src-elem"} 2', $body);
        self::assertStringContainsString('helmet_csp_violations_total{directive="other"} 1', $body);
    }

    public function testNothingIsRenderedBeforeAnythingIsRecorded(): void
    {
        self::assertSame([], (new RequestMetrics(new InMemory()))->samples());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'a route name' => ['docs.show', 'docs.show'];
        yield 'a URI template' => ['docs/{page}', 'docs/{page}'];
        yield 'slashes trimmed' => ['/api/users/', 'api/users'];
        yield 'lower-cased' => ['Docs.Show', 'docs.show'];
        yield 'the site root' => ['/', 'root'];
        yield 'free text' => ['hello world', 'other'];
        yield 'a query string' => ['search?q=wayne', 'other'];
        yield 'too long' => [str_repeat('a', 65), 'other'];
        yield 'a quote' => ['page"}', 'other'];
    }

    #[DataProvider('routes')]
    public function testRouteLabel(string $route, string $label): void
    {
        self::assertSame($label, RequestMetrics::routeLabel($route));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', 'GET'];
        yield 'lower case' => ['patch', 'PATCH'];
        yield 'WebDAV' => ['PROPFIND', 'OTHER'];
        yield 'made up' => ['BREW', 'OTHER'];
    }

    #[DataProvider('methods')]
    public function testMethodLabel(string $method, string $label): void
    {
        self::assertSame($label, RequestMetrics::methodLabel($method));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, '2xx'];
        yield '301' => [301, '3xx'];
        yield '404' => [404, '4xx'];
        yield '599' => [599, '5xx'];
        yield '0' => [0, 'other'];
        yield '600' => [600, 'other'];
    }

    #[DataProvider('statuses')]
    public function testStatusLabel(int $status, string $label): void
    {
        self::assertSame($label, RequestMetrics::statusLabel($status));
    }

    private function render(RequestMetrics $metrics): string
    {
        return (new RenderTextFormat())->render($metrics->samples());
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Psr\Log\AbstractLogger;
use Waynestate\Helmet\Metrics\RequestMetrics;
use Waynestate\Helmet\Metrics\RequestTimer;

final class RequestTimerTest extends TestCase
{
    public function testAnInertTimerRecordsNothing(): void
    {
        $timer = RequestTimer::off();

        self::assertFalse($timer->isOn());
        $timer->route('page')->finish(200);
    }

    public function testFinishRecordsTheRouteStatusAndDurationOnce(): void
    {
        $metrics = new RequestMetrics(new InMemory());
        $timer = new RequestTimer($metrics, 100.0, 'HEAD');

        self::assertTrue($timer->isOn());
        $timer->route('asset')->finish(304, 100.25);
        $timer->finish(500, 200.0);

        $body = (new RenderTextFormat())->render($metrics->samples());
        self::assertStringContainsString('helmet_http_request_duration_seconds_count{route="asset",method="HEAD",status="3xx"} 1', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_sum{route="asset",method="HEAD",status="3xx"} 0.25', $body);
        self::assertStringNotContainsString('5xx', $body);
    }

    public function testTheRouteGroupDefaultsToApp(): void
    {
        $metrics = new RequestMetrics(new InMemory());
        (new RequestTimer($metrics, 0.0, 'GET'))->finish(200, 0.1);

        self::assertStringContainsString(
            'route="app"',
            (new RenderTextFormat())->render($metrics->samples()),
        );
    }

    public function testAStorageFailureIsLoggedAndSwallowed(): void
    {
        $broken = $this->createMock(Adapter::class);
        $broken->method('updateHistogram')->willThrowException(new StorageException('locked'));

        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        (new RequestTimer(new RequestMetrics($broken), 0.0, 'GET', $logger))->finish(200, 0.1);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('metrics_record_failed', $logger->records[0][2]['event']);
    }
}

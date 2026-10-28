<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Metrics\Metrics;
use Waynestate\Helmet\Policy\PolicySource;

final class MetricsTest extends TestCase
{
    public function testMimeTypeIsThePrometheusTextFormat(): void
    {
        self::assertSame('text/plain; version=0.0.4', Metrics::mimeType());
    }

    public function testRenderIncludesTheInfoMetricWithVersions(): void
    {
        $metrics = new Metrics(null, null, null, '9.9.9');
        $body = $metrics->render();

        self::assertStringContainsString('# HELP helmet_info HELMET is running.', $body);
        self::assertStringContainsString('# TYPE helmet_info gauge', $body);
        self::assertStringContainsString(
            'helmet_info{php_version="' . PHP_VERSION . '",helmet_version="9.9.9"} 1',
            $body,
        );
    }

    public function testRenderDoesNotIncludeTheDefaultPhpInfoMetric(): void
    {
        // registerDefaultMetrics is false, so promphp's php_info gauge is not
        // present; the PHP version lives in helmet_info instead.
        $metrics = new Metrics(null, null, null, '9.9.9');
        $body = $metrics->render();

        self::assertStringNotContainsString('php_info', $body);
    }

    public function testRenderOmitsThePhpVersionStatusWithoutAPolicy(): void
    {
        $metrics = new Metrics(null, null, null, '9.9.9');
        $body = $metrics->render();

        self::assertStringNotContainsString('helmet_php_version_status', $body);
    }

    public function testRenderIncludesThePhpVersionStatusWhenAPolicyIsGiven(): void
    {
        $policy = new class implements PolicySource {
            public function components(): array
            {
                return [
                    'php' => [
                        PHP_VERSION => [
                            'active_until' => '2099-01-01',
                            'security_until' => '2099-01-01',
                        ],
                    ],
                ];
            }
        };

        $metrics = new Metrics(null, $policy, new DateTimeImmutable('2026-01-01'), '9.9.9');
        $body = $metrics->render();

        self::assertStringContainsString('# TYPE helmet_php_version_status gauge', $body);
        self::assertStringContainsString('helmet_php_version_status{status="active"} 1', $body);
    }

    public function testRenderReportsEolWhenThePolicySaysSo(): void
    {
        $policy = new class implements PolicySource {
            public function components(): array
            {
                return [
                    'php' => [
                        PHP_VERSION => [
                            'active_until' => '2020-01-01',
                            'security_until' => '2020-01-01',
                        ],
                    ],
                ];
            }
        };

        $metrics = new Metrics(null, $policy, new DateTimeImmutable('2026-01-01'), '9.9.9');
        $body = $metrics->render();

        self::assertStringContainsString('helmet_php_version_status{status="eol"} 1', $body);
    }
}

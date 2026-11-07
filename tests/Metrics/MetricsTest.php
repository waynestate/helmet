<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Waynestate\Helmet\Metrics\Metrics;
use Waynestate\Helmet\Metrics\RequestMetrics;
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
                        Metrics::phpMinor() => [
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
                        Metrics::phpMinor() => [
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

    /**
     * CREED's policy names PHP by major.minor ("8.3"), never by the patch
     * release PHP_VERSION carries ("8.3.12"). Looking up the full version
     * reported "unknown" on every real app.
     */
    public function testThePolicyIsReadByMajorMinorNotThePatchRelease(): void
    {
        $policy = $this->policyFor([
            PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION => ['active_until' => '2099-01-01', 'security_until' => '2099-06-30'],
        ]);

        $body = (new Metrics(null, $policy, new DateTimeImmutable('2026-01-01'), '9.9.9', null, []))->render();

        self::assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, Metrics::phpMinor());
        self::assertStringContainsString('helmet_php_version_status{status="active"} 1', $body);
    }

    public function testRenderIncludesTheEndOfSupportAsAUnixTimestamp(): void
    {
        $policy = $this->policyFor([
            Metrics::phpMinor() => ['active_until' => '2027-11-30', 'security_until' => '2028-11-30'],
        ]);

        $body = (new Metrics(null, $policy, new DateTimeImmutable('2026-01-01'), '9.9.9', null, []))->render();

        $expected = (new DateTimeImmutable('2028-11-30'))->getTimestamp();
        self::assertStringContainsString('# TYPE helmet_php_eol_timestamp_seconds gauge', $body);
        self::assertMatchesRegularExpression(
            '/^helmet_php_eol_timestamp_seconds\{php_minor="' . preg_quote(Metrics::phpMinor(), '/') . '"\} ' . $expected . '(\.0+)?$/m',
            $body,
        );
    }

    public function testTheEndOfSupportIsOmittedWhenThePolicyDoesNotKnowThisPhp(): void
    {
        $policy = $this->policyFor([
            '5.6' => ['active_until' => '2017-01-19', 'security_until' => '2018-12-31'],
        ]);

        $body = (new Metrics(null, $policy, new DateTimeImmutable('2026-01-01'), '9.9.9', null, []))->render();

        self::assertStringContainsString('helmet_php_version_status{status="unknown"} 1', $body);
        self::assertStringNotContainsString('helmet_php_eol_timestamp_seconds', $body);
    }

    public function testRenderListsEveryStackPackageInWsuStackInfo(): void
    {
        $body = (new Metrics(null, null, null, '9.9.9', null, [
            'waynestate/proctor' => '0.4.1',
            'waynestate/creed' => '0.1.3',
        ]))->render();

        self::assertStringContainsString('# TYPE wsu_stack_info gauge', $body);
        self::assertStringContainsString('wsu_stack_info{package="waynestate/creed",version="0.1.3"} 1', $body);
        self::assertStringContainsString('wsu_stack_info{package="waynestate/proctor",version="0.4.1"} 1', $body);
    }

    public function testWsuStackInfoIsOmittedWhenNoStackPackageIsInstalled(): void
    {
        $body = (new Metrics(null, null, null, '9.9.9', null, []))->render();

        self::assertStringNotContainsString('wsu_stack_info', $body);
    }

    public function testByDefaultWsuStackInfoReadsTheInstalledWaynestatePackages(): void
    {
        // HELMET itself is installed here (the root package), so the default
        // discovery finds at least that one.
        $body = (new Metrics(null, null, null, '9.9.9'))->render();

        self::assertStringContainsString('wsu_stack_info{package="waynestate/helmet"', $body);
    }

    public function testWithoutRequestMetricsThereIsNoStorageGaugeOrHistogram(): void
    {
        $body = (new Metrics(null, null, null, '9.9.9', null, []))->render();

        self::assertStringNotContainsString('helmet_metrics_storage_up', $body);
        self::assertStringNotContainsString('helmet_http_request_duration_seconds', $body);
    }

    public function testRequestMetricsAreAddedFromTheirStorage(): void
    {
        $requests = new RequestMetrics(new InMemory());
        $requests->observe('page', 'GET', 200, 0.02);

        $body = (new Metrics(null, null, null, '9.9.9', $requests, []))->render();

        self::assertStringContainsString('helmet_metrics_storage_up 1', $body);
        self::assertStringContainsString('helmet_info{', $body);
        self::assertStringContainsString(
            'helmet_http_request_duration_seconds_count{route="page",method="GET",status="2xx"} 1',
            $body,
        );
    }

    public function testAStorageOutageKeepsTheInfoMetricsAndReportsStorageDown(): void
    {
        $broken = $this->createMock(Adapter::class);
        $broken->method('collect')->willThrowException(new StorageException('Redis went for a walk.'));

        $body = (new Metrics(null, null, null, '9.9.9', new RequestMetrics($broken), []))->render();

        self::assertStringContainsString('helmet_info{', $body);
        self::assertStringContainsString('helmet_metrics_storage_up 0', $body);
    }

    /**
     * @param array<string, array{active_until: string, security_until: string}> $php
     */
    private function policyFor(array $php): PolicySource
    {
        return new class ($php) implements PolicySource {
            /**
             * @param array<string, array{active_until: string, security_until: string}> $php
             */
            public function __construct(private readonly array $php) {}

            public function components(): array
            {
                return ['php' => $this->php];
            }
        };
    }
}

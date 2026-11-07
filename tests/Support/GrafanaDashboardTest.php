<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * The fleet dashboard ships in docs/grafana/helmet.json so a Grafana with a
 * Prometheus data source can graph the HELMET metrics as soon as one exists.
 */
final class GrafanaDashboardTest extends TestCase
{
    private const DASHBOARD = __DIR__ . '/../../docs/grafana/helmet.json';

    /**
     * Every metric name HELMET exposes, and Prometheus' own up.
     */
    private const METRICS = [
        'helmet_info',
        'helmet_php_version_status',
        'helmet_php_eol_timestamp_seconds',
        'helmet_metrics_storage_up',
        'helmet_http_request_duration_seconds_count',
        'helmet_http_request_duration_seconds_bucket',
        'helmet_csp_violations_total',
        'wsu_stack_info',
        'up',
    ];

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        self::assertFileExists(self::DASHBOARD);

        $json = file_get_contents(self::DASHBOARD);
        self::assertNotFalse($json, 'the dashboard must be readable');

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'the dashboard must be valid JSON');

        return $decoded;
    }

    /**
     * The panels that query Prometheus (rows and text panels do not).
     *
     * @return list<array<string, mixed>>
     */
    private function queryPanels(): array
    {
        return array_values(array_filter(
            $this->dashboard()['panels'],
            static fn(array $panel): bool => !in_array($panel['type'], ['row', 'text'], true),
        ));
    }

    public function testDashboardIsValidJson(): void
    {
        $dashboard = $this->dashboard();

        self::assertSame(39, $dashboard['schemaVersion']);
        self::assertSame('HELMET fleet', $dashboard['title']);
        self::assertSame('helmet-fleet', $dashboard['uid']);
        self::assertSame(['helmet', 'wsu'], $dashboard['tags']);
        self::assertNull($dashboard['id'], 'an exported dashboard carries no instance id');
    }

    /**
     * Grafana's import screen only asks for a data source when the file
     * declares it under __inputs (the export format). Under any other key the
     * ${DS_PROMETHEUS} placeholders stay unresolved and every panel fails.
     */
    public function testTheDataSourceIsAnImportInput(): void
    {
        $dashboard = $this->dashboard();

        self::assertArrayNotHasKey('inputs', $dashboard);
        self::assertSame(
            [['name' => 'DS_PROMETHEUS', 'type' => 'datasource', 'pluginId' => 'prometheus']],
            array_map(
                static fn(array $input): array => [
                    'name' => $input['name'],
                    'type' => $input['type'],
                    'pluginId' => $input['pluginId'],
                ],
                $dashboard['__inputs'],
            ),
        );

        foreach ($this->queryPanels() as $panel) {
            self::assertSame('${DS_PROMETHEUS}', $panel['datasource']['uid'], sprintf('panel "%s"', $panel['title']));
            foreach ($panel['targets'] as $target) {
                self::assertSame('${DS_PROMETHEUS}', $target['datasource']['uid'], sprintf('panel "%s"', $panel['title']));
            }
        }
    }

    public function testDashboardHasTheExpectedPanelTitles(): void
    {
        $titles = array_map(static fn(array $panel): string => $panel['title'], $this->queryPanels());
        sort($titles);

        self::assertSame(
            [
                'Apps by instance',
                'Apps reporting',
                'CSP violations by directive',
                'Days to PHP end of support',
                'HELMET versions in use',
                'Latency p50 and p95',
                'Metrics storage',
                'PHP support status',
                'PHP versions in use',
                'Requests by route group',
                'Requests by status class',
                'Scrape health',
                'Server errors',
                'Stack packages',
                'Targets down',
                'p95 latency by route group',
            ],
            $titles,
        );
    }

    public function testEveryPanelQueriesOnlyHelmetMetricsOrUp(): void
    {
        foreach ($this->queryPanels() as $panel) {
            self::assertNotEmpty($panel['targets'], sprintf('panel "%s" has no targets', $panel['title']));

            foreach ($panel['targets'] as $target) {
                $expr = (string) $target['expr'];
                preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\{/', $expr, $matches);

                self::assertNotEmpty($matches[1], sprintf('panel "%s" selects no metric: %s', $panel['title'], $expr));
                foreach ($matches[1] as $metric) {
                    self::assertContains($metric, self::METRICS, sprintf('panel "%s" queries an unknown metric: %s', $panel['title'], $expr));
                }
            }
        }
    }

    /**
     * Every query honors the job and instance variables, so one dashboard
     * serves the whole fleet or one app.
     */
    public function testEveryQueryIsFilteredByJobAndInstance(): void
    {
        $names = array_map(static fn(array $var): string => $var['name'], $this->dashboard()['templating']['list']);
        self::assertSame(['job', 'instance'], $names);

        foreach ($this->queryPanels() as $panel) {
            foreach ($panel['targets'] as $target) {
                self::assertStringContainsString('job=~"$job", instance=~"$instance"', (string) $target['expr'], $panel['title']);
            }
        }
    }

    /**
     * A target that is down has no helmet_info, so joining up against it
     * hides exactly the targets the panel exists to show.
     */
    public function testScrapeHealthShowsDownTargets(): void
    {
        foreach ($this->queryPanels() as $panel) {
            foreach ($panel['targets'] as $target) {
                $expr = (string) $target['expr'];
                if (preg_match('/\bup\{/', $expr) === 1) {
                    self::assertStringNotContainsString('helmet_info', $expr, $panel['title']);
                }
            }
        }
    }

    public function testTheLatencyBucketsMatchTheCode(): void
    {
        // The dashboard's quantiles are only as good as the buckets; this
        // pins the pair so a bucket change is a deliberate, visible one.
        self::assertSame([0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0], RequestMetrics::BUCKETS);
    }

    public function testDashboardCarriesNoHostnamesOrAddresses(): void
    {
        $json = (string) file_get_contents(self::DASHBOARD);

        self::assertStringNotContainsString('://', $json, 'the dashboard must not carry URLs');
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            $json,
            'the dashboard must not carry IPv4 addresses',
        );
    }
}

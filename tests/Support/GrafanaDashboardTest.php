<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The fleet dashboard ships in docs/grafana/helmet.json so a Grafana with a
 * Prometheus data source can graph the HELMET metrics as soon as one exists.
 */
final class GrafanaDashboardTest extends TestCase
{
    private const DASHBOARD = __DIR__ . '/../../docs/grafana/helmet.json';

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

    public function testDashboardIsValidJson(): void
    {
        $dashboard = $this->dashboard();

        self::assertSame(39, $dashboard['schemaVersion']);
        self::assertSame('HELMET fleet', $dashboard['title']);
        self::assertSame('helmet-fleet', $dashboard['uid']);
        self::assertSame(['helmet', 'wsu'], $dashboard['tags']);

        $inputs = $dashboard['inputs'];
        self::assertIsArray($inputs);
        self::assertContainsEquals(
            ['name' => 'DS_PROMETHEUS', 'type' => 'datasource', 'pluginId' => 'prometheus'],
            array_map(
                static function (array $input): array {
                    return [
                        'name' => $input['name'],
                        'type' => $input['type'],
                        'pluginId' => $input['pluginId'],
                    ];
                },
                $inputs,
            ),
        );
    }

    public function testDashboardHasTheExpectedPanelTitles(): void
    {
        $titles = [];

        foreach ($this->dashboard()['panels'] as $panel) {
            $titles[] = $panel['title'];
        }

        sort($titles);

        self::assertSame(
            [
                'Apps by instance',
                'Apps reporting',
                'HELMET versions in use',
                'PHP support status',
                'PHP versions in use',
                'Scrape health',
            ],
            $titles,
        );
    }

    public function testEveryPanelQueriesOnlyHelmetMetricsOrUp(): void
    {
        foreach ($this->dashboard()['panels'] as $panel) {
            self::assertNotEmpty($panel['targets'], sprintf('panel "%s" has no targets', $panel['title']));

            foreach ($panel['targets'] as $target) {
                $expr = (string) $target['expr'];

                self::assertMatchesRegularExpression(
                    '/helmet_info|helmet_php_version_status|\bup\b/',
                    $expr,
                    sprintf('panel "%s" query does not use a HELMET metric or up: %s', $panel['title'], $expr),
                );
            }
        }
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

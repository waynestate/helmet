<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * HELMET 1.0 ships from a pipeline that runs one Preflight release, not a mix:
 * every component include names the same released version. From Preflight
 * 0.3.0 each Docker Hub component adds the Dependency Proxy prefix itself
 * (image_prefix), so no component input may carry the prefix again.
 */
final class PreflightPinTest extends TestCase
{
    private function ci(): string
    {
        $ci = file_get_contents(dirname(__DIR__, 2) . '/.gitlab-ci.yml');
        self::assertIsString($ci);

        return $ci;
    }

    public function testEveryPreflightIncludeNamesOneRelease(): void
    {
        preg_match_all(
            '#^  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/([a-z0-9-]+)@(\S+)#m',
            $this->ci(),
            $m,
        );

        self::assertNotEmpty($m[1], 'the pipeline includes Preflight components');
        foreach ($m[2] as $i => $ref) {
            self::assertMatchesRegularExpression('#^\d+\.\d+\.\d+$#', $ref, $m[1][$i] . ' is pinned to a release');
        }
        self::assertCount(1, array_unique($m[2]), 'one Preflight version: ' . implode(', ', array_unique($m[2])));
        self::assertSame('0.3.0', $m[2][0]);
    }

    public function testNoComponentInputCarriesTheProxyPrefix(): void
    {
        $found = preg_match('#^include:\n(?:  [^\n]*\n|\n)*#m', $this->ci(), $m);
        self::assertSame(1, $found, 'the pipeline has an include block');

        self::assertStringNotContainsString('CI_DEPENDENCY_PROXY', $m[0]);
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The pipeline runs PROCTOR on the deploy layer through Preflight's proctor
 * component, pinned to a release and blocking: a failing check fails the
 * pipeline.
 */
final class ProctorJobTest extends TestCase
{
    private function ci(): string
    {
        $ci = file_get_contents(dirname(__DIR__, 2) . '/.gitlab-ci.yml');
        self::assertIsString($ci);

        return $ci;
    }

    /**
     * The include block for the proctor component, up to the next top-level
     * key or include entry.
     */
    private function block(): string
    {
        $found = preg_match(
            '#^  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/proctor@[^\n]*\n(?:    [^\n]*\n|\n)*#m',
            $this->ci(),
            $m,
        );
        self::assertSame(1, $found, 'the Preflight proctor component is included');

        return $m[0];
    }

    public function testTheComponentIsPinnedToARelease(): void
    {
        self::assertMatchesRegularExpression('#proctor@\d+\.\d+\.\d+\n#', $this->block());
        self::assertStringNotContainsString('@~latest', $this->ci());
    }

    public function testItChecksTheDeployLayer(): void
    {
        self::assertStringContainsString('working_dir: deploy', $this->block());
    }

    public function testAFailingCheckFailsThePipeline(): void
    {
        self::assertStringNotContainsString('allow_failure', $this->block());
    }
}

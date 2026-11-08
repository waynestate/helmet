<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * HELMET-5: the pipeline runs a maintained secret scanner (Gitleaks, through
 * Preflight's secret-detection component), pinned, through the Dependency
 * Proxy, and blocking. HELMET does not write a scanner of its own.
 */
final class SecretScanJobTest extends TestCase
{
    private function ci(): string
    {
        $ci = file_get_contents(dirname(__DIR__, 2) . '/.gitlab-ci.yml');
        self::assertIsString($ci);

        return $ci;
    }

    /**
     * The include block for the secret-detection component, up to the next
     * top-level key or include entry.
     */
    private function block(): string
    {
        $found = preg_match(
            '#^  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/secret-detection@[^\n]*\n(?:    [^\n]*\n|\n)*#m',
            $this->ci(),
            $m,
        );
        self::assertSame(1, $found, 'the Preflight secret-detection component is included');

        return $m[0];
    }

    public function testTheComponentIsPinnedToARelease(): void
    {
        self::assertMatchesRegularExpression('#secret-detection@\d+\.\d+\.\d+\n#', $this->block());
        self::assertStringNotContainsString('@~latest', $this->ci());
    }

    public function testTheImageIsPinnedByDigestThroughTheProxy(): void
    {
        self::assertMatchesRegularExpression(
            '#image: "\$\{CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX\}/zricethezav/gitleaks:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}"#',
            $this->block(),
        );
    }

    public function testAFindingFailsThePipeline(): void
    {
        self::assertStringNotContainsString('allow_failure: true', $this->block());
    }

    public function testNoScannerOfOurOwn(): void
    {
        self::assertStringNotContainsString('gitleaks dir', $this->ci(), 'the component runs Gitleaks; the pipeline does not script it again');
    }
}

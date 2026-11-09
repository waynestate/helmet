<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Secret scanning: the pipeline runs a maintained secret scanner (Gitleaks, through
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

    /**
     * From Preflight 0.3.0 the component pulls image_prefix/image, and
     * image_prefix defaults to the Dependency Proxy, so the image input is the
     * plain name. Naming the proxy in it as well would put the prefix in twice.
     */
    public function testTheImageIsPinnedByDigestThroughTheProxy(): void
    {
        $block = $this->block();

        self::assertMatchesRegularExpression(
            '#image: "zricethezav/gitleaks:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}"#',
            $block,
        );
        self::assertStringNotContainsString('CI_DEPENDENCY_PROXY', $block);
        self::assertStringNotContainsString('image_prefix', $block, 'the default prefix is the proxy');
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

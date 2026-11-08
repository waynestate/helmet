<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * HELMET-23: the pipeline checks the front page and every docs page for
 * accessibility with Preflight's a11y component, at WCAG 2.2 AA, blocking.
 */
final class A11yJobTest extends TestCase
{
    private function block(): string
    {
        $ci = file_get_contents(dirname(__DIR__, 2) . '/.gitlab-ci.yml');
        self::assertIsString($ci);
        $found = preg_match(
            '#^  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/a11y@[^\n]*\n(?:    [^\n]*\n|\n)*#m',
            $ci,
            $m,
        );
        self::assertSame(1, $found, 'the Preflight a11y component is included');

        return $m[0];
    }

    public function testTheComponentIsPinnedToARelease(): void
    {
        self::assertMatchesRegularExpression('#/a11y@\d+\.\d+\.\d+\n#', $this->block());
    }

    public function testTheFrontPageAndEveryDocsPageAreChecked(): void
    {
        $block = $this->block();

        self::assertStringContainsString('site_dir: site', $block);
        self::assertStringContainsString('pages: ["index.html", "docs/**/*.html"]', $block);
    }

    public function testTheLevelIsWcag22Aa(): void
    {
        self::assertStringContainsString('"wcag22aa"', $this->block());
    }

    public function testAViolationFailsThePipeline(): void
    {
        self::assertStringNotContainsString('allow_failure: true', $this->block());
    }

    /**
     * The local gate covers the same ground as the pipeline: every HTML file
     * under site/ is the front page plus the docs pages the component checks,
     * at WCAG 2.2 AA, in both color schemes, at 320 and 1280 px.
     */
    public function testTheLocalGateCoversTheSamePages(): void
    {
        foreach (['scripts/a11y.sh', 'scripts/a11y.ps1'] as $script) {
            $text = file_get_contents(dirname(__DIR__, 2) . '/' . $script);
            self::assertIsString($text);
            self::assertStringContainsString('"siteDir": "site"', $text, $script);
            self::assertStringContainsString('"pages": ["**/*.html"]', $text, $script);
            self::assertStringContainsString('"wcag22aa"', $text, $script);
            self::assertStringContainsString('"colorSchemes": ["light", "dark"]', $text, $script);
            self::assertStringContainsString('"widths": [320, 1280]', $text, $script);
        }
    }
}

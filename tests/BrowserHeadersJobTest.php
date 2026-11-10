<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The pipeline runs the CORP opt-in (H13) and the COEP readiness checks in
 * a real Chromium browser: browser-headers-json prints HELMET's headers to
 * build/helmet-headers.json and browser-headers feeds them to the two
 * checks. This test fails when either job is unwired or when its Playwright
 * image drifts from the one scripts/a11y.sh runs, so a page that passes
 * locally passes the pipeline. No surprises: if the wiring moves, this says
 * so first.
 */
final class BrowserHeadersJobTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The job that prints the headers JSON the browser checks read. */
    private const HEADERS_JSON_JOB = 'browser-headers-json:';

    /** The job that runs both browser checks against the printed headers. */
    private const BROWSER_CHECKS_JOB = 'browser-headers:';

    /** The command that prints HELMET's headers as JSON for the checks. */
    private const PRINT_HEADERS = 'php tools/a11y/corp-headers.php > build/helmet-headers.json';

    /** The CORP opt-in check (H13), reading the printed headers. */
    private const CORP_CHECK = 'node tools/a11y/corp-check.mjs --headers=build/helmet-headers.json';

    /** The COEP readiness check, reading the printed headers. */
    private const COEP_CHECK = 'node tools/a11y/coep-check.mjs --headers=build/helmet-headers.json';

    /** How to bring a drifted wire back, in one commit. */
    private const UPDATE = 'To update the browser-headers wiring, in one commit: move the jobs in .gitlab-ci.yml, '
        . 'move the image string in scripts/a11y.sh and scripts/a11y.ps1 and the image in the browser-headers '
        . 'job to the same pinned Playwright image, and update the constants in this test.';

    public function test_the_pipeline_wires_both_browser_header_jobs(): void
    {
        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');

        $this->assertStringContainsString(self::HEADERS_JSON_JOB, $ci, '.gitlab-ci.yml has no browser-headers-json job. ' . self::UPDATE);
        $this->assertStringContainsString(self::BROWSER_CHECKS_JOB, $ci, '.gitlab-ci.yml has no browser-headers job. ' . self::UPDATE);
        $this->assertStringContainsString(self::PRINT_HEADERS, $ci, 'browser-headers-json does not print the headers JSON. ' . self::UPDATE);
        $this->assertStringContainsString(self::CORP_CHECK, $ci, 'browser-headers does not run the CORP check on the printed headers. ' . self::UPDATE);
        $this->assertStringContainsString(self::COEP_CHECK, $ci, 'browser-headers does not run the COEP check on the printed headers. ' . self::UPDATE);
        $this->assertStringContainsString('needs: [browser-headers-json]', $ci, 'browser-headers does not need browser-headers-json. ' . self::UPDATE);
    }

    public function test_the_browser_job_uses_the_same_image_as_the_local_a11y_script(): void
    {
        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');
        if (!preg_match('/^browser-headers:\n((?:^[ \t].*$\n?)+)/m', $ci, $job)) {
            $this->fail('.gitlab-ci.yml has no browser-headers job body. ' . self::UPDATE);
        }
        if (!preg_match('/^  image: (mcr\.microsoft\.com\/playwright:\S+)$/m', $job[1], $matches)) {
            $this->fail('the browser-headers job names no Playwright image. ' . self::UPDATE);
        }

        $script = (string) file_get_contents(self::ROOT . '/scripts/a11y.sh');
        if (!preg_match('/^IMAGE="(mcr\.microsoft\.com\/playwright:[^"]+)"$/m', $script, $scriptMatches)) {
            $this->fail('scripts/a11y.sh names no IMAGE. ' . self::UPDATE);
        }

        $this->assertSame(
            $scriptMatches[1],
            $matches[1],
            'the browser-headers job uses ' . $matches[1] . ', not the image scripts/a11y.sh runs. ' . self::UPDATE,
        );
    }
}

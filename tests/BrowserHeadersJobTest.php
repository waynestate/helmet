<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The pipeline runs the CORP opt-in (H13), the COEP readiness and the Vue
 * (H51) checks in a real Chromium browser: vue-fixture-build builds the Vue
 * fixture, browser-headers-json prints HELMET's headers to
 * build/helmet-headers.json and renders the fixture through Laravel, and
 * browser-headers feeds both to the three checks. This test fails when a job
 * is unwired or when the Playwright
 * image drifts from the one scripts/a11y.sh runs, so a page that passes
 * locally passes the pipeline. No surprises: if the wiring moves, this says
 * so first.
 */
final class BrowserHeadersJobTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The job that prints the headers JSON the browser checks read. */
    private const HEADERS_JSON_JOB = 'browser-headers-json:';

    /** The job that runs the three browser checks against the printed headers. */
    private const BROWSER_CHECKS_JOB = 'browser-headers:';

    /** The command that prints HELMET's headers as JSON for the checks. */
    private const PRINT_HEADERS = 'php tools/a11y/corp-headers.php > build/helmet-headers.json';

    /** The CORP opt-in check (H13), reading the printed headers. */
    private const CORP_CHECK = 'node tools/a11y/corp-check.mjs --headers=build/helmet-headers.json';

    /** The COEP readiness check, reading the printed headers. */
    private const COEP_CHECK = 'node tools/a11y/coep-check.mjs --headers=build/helmet-headers.json';

    /** The job that builds the Vue fixture (H51) for the Vue check. */
    private const VUE_BUILD_JOB = 'vue-fixture-build';

    /** Its steps, in order: install without install scripts, then the raw PHP and the Laravel builds. */
    private const VUE_STEPS = [
        'npm --prefix tools/vue-vite ci --no-audit --no-fund --ignore-scripts',
        'npm --prefix tools/vue-vite run build',
        'npm --prefix tools/vue-vite run build:laravel',
    ];

    /** What it hands on: both builds, and Vue's full build for the template compiler probe. */
    private const VUE_ARTIFACTS = [
        'tools/vue-vite/dist/',
        'tools/vue-vite/dist-laravel/',
        'tools/vue-vite/node_modules/vue/dist/vue.esm-browser.prod.js',
    ];

    /** The command that renders the fixture page through Laravel and HELMET. */
    private const LARAVEL_PAGE = 'php tools/a11y/laravel-page.php > build/laravel-page.json';

    /** The Vue check: both recipes under HELMET's default CSP. */
    private const VUE_CHECK = 'node tools/a11y/vue-check.mjs --headers=build/helmet-headers.json --laravel=build/laravel-page.json';

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
        $this->assertStringContainsString('needs: [browser-headers-json, vue-fixture-build]', $ci, 'browser-headers does not need browser-headers-json and vue-fixture-build. ' . self::UPDATE);
    }

    public function test_the_vue_fixture_is_built_rendered_through_laravel_and_checked(): void
    {
        $build = $this->jobBody(self::VUE_BUILD_JOB);
        $at = -1;
        foreach (self::VUE_STEPS as $step) {
            $found = strpos($build, $step);
            $this->assertNotFalse($found, self::VUE_BUILD_JOB . ' does not run: ' . $step . '. ' . self::UPDATE);
            $this->assertGreaterThan($at, $found, self::VUE_BUILD_JOB . ' runs its steps out of order. ' . self::UPDATE);
            $at = $found;
        }
        foreach (self::VUE_ARTIFACTS as $artifact) {
            $this->assertStringContainsString('- ' . $artifact, $build, self::VUE_BUILD_JOB . ' does not keep ' . $artifact . '. ' . self::UPDATE);
        }

        $json = $this->jobBody('browser-headers-json');
        $this->assertStringContainsString('needs: [vue-fixture-build]', $json, 'browser-headers-json does not need the Laravel build. ' . self::UPDATE);
        $this->assertStringContainsString(self::LARAVEL_PAGE, $json, 'browser-headers-json does not render the Laravel page. ' . self::UPDATE);
        $this->assertStringContainsString('build/laravel-page.json', (string) strstr($json, 'artifacts:'), 'browser-headers-json does not keep the Laravel page. ' . self::UPDATE);

        $this->assertStringContainsString(self::VUE_CHECK, $this->jobBody('browser-headers'), 'browser-headers does not run the Vue check on both recipes. ' . self::UPDATE);
    }

    public function test_the_vue_fixture_job_uses_the_same_image_as_the_browser_job(): void
    {
        $image = '/^  image: (\S+)$/m';
        $this->assertSame(1, preg_match($image, $this->jobBody('browser-headers'), $browser), 'browser-headers names no image. ' . self::UPDATE);
        $this->assertSame(1, preg_match($image, $this->jobBody(self::VUE_BUILD_JOB), $build), self::VUE_BUILD_JOB . ' names no image. ' . self::UPDATE);
        $this->assertSame($browser[1], $build[1], self::VUE_BUILD_JOB . ' must build with the Node of the image the check runs in. ' . self::UPDATE);
    }

    private function jobBody(string $job): string
    {
        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');
        if (!preg_match('/^' . preg_quote($job, '/') . ':\n((?:^[ \t].*$\n?)+)/m', $ci, $matches)) {
            $this->fail('.gitlab-ci.yml has no ' . $job . ' job body. ' . self::UPDATE);
        }

        return $matches[1];
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

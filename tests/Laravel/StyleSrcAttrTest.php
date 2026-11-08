<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Route;

/**
 * HELMET-17 in Laravel: helmet.csp_style_src_attr (HELMET_CSP_STYLE_SRC_ATTR).
 */
final class StyleSrcAttrTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_CSP_STYLE_SRC_ATTR');

        parent::tearDown();
    }

    private function page(): void
    {
        $this->app['router']->get('/page', static fn() => response('<html><head></head><body>hi</body></html>', 200, ['Content-Type' => 'text/html']))
            ->middleware('web');
    }

    public function testTheDefaultIsInline(): void
    {
        self::assertSame('inline', config('helmet.csp_style_src_attr'));
        $this->page();

        $response = $this->get('/page');

        self::assertStringContainsString("style-src-attr 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function testTheEnvVariableIsRead(): void
    {
        $this->putEnv('HELMET_CSP_STYLE_SRC_ATTR', ' Report ');
        $this->reloadPackageConfig();

        self::assertSame('report', config('helmet.csp_style_src_attr'));
    }

    public function testAnEmptyEnvVariableMeansInline(): void
    {
        $this->putEnv('HELMET_CSP_STYLE_SRC_ATTR', '');
        $this->reloadPackageConfig();

        self::assertSame('inline', config('helmet.csp_style_src_attr'));
    }

    public function testNoneIsSent(): void
    {
        config(['helmet.csp_style_src_attr' => 'none']);
        $this->page();

        self::assertStringContainsString("style-src-attr 'none'", (string) $this->get('/page')->headers->get('Content-Security-Policy'));
    }

    public function testReportAddsTheProbe(): void
    {
        config(['helmet.csp_style_src_attr' => 'report']);
        $this->page();

        $response = $this->get('/page');

        self::assertStringContainsString("style-src-attr 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('Content-Security-Policy-Report-Only', "style-src-attr 'none'");
    }

    public function testReadinessNamesTheMode(): void
    {
        config(['helmet.csp_style_src_attr' => 'none']);
        Route::helmetReady();

        $this->get('/ready')->assertStatus(200)->assertJsonPath('checks.headers.style_src_attr', 'none');
    }

    public function testAnUnknownModeIsNeverReady(): void
    {
        // A bad mode breaks every page, the probe included: with the default
        // global scope the header middleware throws before the route runs,
        // so the probe sees a server error, never a 200.
        config(['helmet.csp_style_src_attr' => 'strict']);
        Route::helmetReady();

        $this->get('/ready')->assertServerError();
    }

    public function testAnUnknownModeFailsTheHeadersCheckInWebScope(): void
    {
        // In web scope the probe route (outside the web group) still runs,
        // and the headers check names the broken config.
        config(['helmet.scope' => 'web', 'helmet.csp_style_src_attr' => 'strict']);
        Route::helmetReady();

        $this->get('/ready')->assertStatus(503)->assertJsonPath('checks.headers.status', 'fail');
    }
}

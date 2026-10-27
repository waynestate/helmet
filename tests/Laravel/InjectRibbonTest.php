<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

final class InjectRibbonTest extends TestCase
{
    private const HTML = '<html><head><title>t</title></head><body><p>hello</p></body></html>';

    public function testRibbonIsInjectedAfterBodyInLocal(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');
        $content = $response->getContent();

        $expected = '<div class="helmet-ribbon" data-environment="local">local</div>';
        self::assertStringContainsString($expected, $content);
        // The ribbon sits right after the opening <body> tag.
        self::assertStringContainsString('<body>' . $expected, $content);
    }

    public function testRibbonIsInjectedAfterABodyTagWithAttributes(): void
    {
        $this->app->detectEnvironment(static fn () => 'staging');
        $this->defineHtmlRoute('/test', '<html><body class="home" data-page="1"><p>hello</p></body></html>');

        $response = $this->get('/test');

        self::assertStringContainsString(
            '<body class="home" data-page="1"><div class="helmet-ribbon" data-environment="staging">staging</div>',
            (string) $response->getContent()
        );
    }

    public function testRibbonIsAbsentInProduction(): void
    {
        $this->app->detectEnvironment(static fn () => 'production');
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');

        self::assertSame(self::HTML, $response->getContent());
    }

    public function testRibbonIsAbsentWhenConfigIsDisabled(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');
        config(['helmet.ribbon' => false]);
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');

        self::assertSame(self::HTML, $response->getContent());
    }

    public function testJsonResponseIsUnchanged(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');
        $json = json_encode(['ok' => true]);
        $this->app['router']->get('/test', static fn () => response($json, 200, ['Content-Type' => 'application/json']))
            ->middleware('web');

        $response = $this->get('/test');

        self::assertSame($json, $response->getContent());
    }

    public function testHtmlWithoutBodyIsUnchanged(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');
        $html = '<html><head><title>t</title></head></html>';
        $this->defineHtmlRoute('/test', $html);

        $response = $this->get('/test');

        self::assertSame($html, $response->getContent());
    }

    /**
     * Register a GET route in the "web" group that returns the given HTML.
     */
    private function defineHtmlRoute(string $uri, string $html): void
    {
        $this->app['router']->get($uri, static fn () => response($html, 200, ['Content-Type' => 'text/html']))
            ->middleware('web');
    }
}

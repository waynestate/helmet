<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\View;

/**
 * The HTML rewrites (the CSP nonce meta tag and the environment ribbon) call
 * $response->setContent(), which on a Laravel response also overwrites the
 * public $original property. Test helpers such as assertInertia() and
 * assertViewHas() read that property to find the view or Inertia page, so
 * HELMET must keep it intact across the rewrite: the body changes, the view
 * object stays exactly where it was.
 */
final class KeepsOriginalTest extends TestCase
{
    private const HTML = '<html><head><title>t</title></head><body><p>hi</p></body></html>';

    public function testLocalRibbonRewriteKeepsTheOriginalObject(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        $stub = new StubRenderable(self::HTML);
        $this->defineViewRoute('/test', $stub);

        $response = $this->get('/test');
        $content = (string) $response->baseResponse->getContent();

        // Both rewrites happened: the ribbon after <body>, the nonce meta in <head>.
        self::assertStringContainsString('<div class="helmet-ribbon" data-environment="local">local</div>', $content);
        self::assertStringContainsString('<meta property="csp-nonce" nonce="', $content);

        // ...and the original is still the very same object, not a string.
        self::assertSame($stub, $response->baseResponse->original);
    }

    public function testProductionNonceRewriteKeepsTheOriginalObject(): void
    {
        $this->app->detectEnvironment(static fn() => 'production');
        $stub = new StubRenderable(self::HTML);
        $this->defineViewRoute('/test', $stub);

        $response = $this->get('/test');
        $content = (string) $response->baseResponse->getContent();

        // Production gets no ribbon, but the nonce meta is still injected.
        self::assertStringNotContainsString('helmet-ribbon', $content);
        self::assertStringContainsString('<meta property="csp-nonce" nonce="', $content);

        self::assertSame($stub, $response->baseResponse->original);
    }

    /**
     * A real Blade view: the framework's own test helpers read
     * baseResponse->original, so if the rewrite had clobbered it these would
     * fail with "Not a valid Inertia response" style errors.
     */
    public function testRealBladeViewStillPassesAssertViewIsAndAssertViewHas(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $dir = sys_get_temp_dir() . '/helmet-keeps-original-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/greeting.blade.php', self::HTML);
        View::addLocation($dir);

        try {
            $this->app['router']->get('/test', static fn() => response()->view('greeting', ['name' => 'world']))
                ->middleware('web');

            $response = $this->get('/test');
            $content = (string) $response->baseResponse->getContent();

            self::assertStringContainsString('<meta property="csp-nonce" nonce="', $content);
            self::assertStringContainsString('<div class="helmet-ribbon" data-environment="local">local</div>', $content);

            $response->assertViewIs('greeting');
            $response->assertViewHas('name', 'world');
        } finally {
            unlink($dir . '/greeting.blade.php');
            rmdir($dir);
        }
    }

    /**
     * Register a GET route in the "web" group that returns an HTTP response
     * whose content is the given object and whose original is that same
     * object, with an explicit HTML Content-Type.
     */
    private function defineViewRoute(string $uri, Renderable $renderable): void
    {
        $this->app['router']->get($uri, static fn() => new HttpResponse($renderable, 200, ['Content-Type' => 'text/html']))
            ->middleware('web');
    }
}

/**
 * A renderable stub that always renders the same HTML.
 */
final class StubRenderable implements Renderable
{
    public function __construct(private readonly string $html) {}

    public function render(): string
    {
        return $this->html;
    }
}

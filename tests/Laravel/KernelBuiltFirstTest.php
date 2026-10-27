<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Waynestate\Helmet\Laravel\InjectRibbon;
use Waynestate\Helmet\Laravel\SecurityHeaders;

/**
 * The other boot order. A real app builds the HTTP kernel before the
 * providers boot (public/index.php resolves the kernel, then the kernel
 * bootstraps), whereas Testbench boots the providers first. The provider has
 * to join the `web` group in both orders, so this class forces the kernel to
 * be built before HELMET's provider boots.
 */
final class KernelBuiltFirstTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // defineEnvironment() runs after the providers register and before
        // they boot, so building the kernel here puts it first.
        $app->make(Kernel::class);
    }

    public function testMiddlewaresAreInTheWebGroup(): void
    {
        $web = $this->app['router']->getMiddlewareGroups()['web'];

        self::assertContains(SecurityHeaders::class, $web);
        self::assertContains(InjectRibbon::class, $web);
    }

    public function testWebResponseCarriesTheHeaders(): void
    {
        $this->app['router']->get('/test', static fn () => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html']))
            ->middleware('web');

        $response = $this->get('/test');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}

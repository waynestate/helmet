<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Waynestate\Helmet\Laravel\InjectRibbon;
use Waynestate\Helmet\Laravel\SecurityHeaders;

/**
 * The opt-in scope. SecurityHeaders stays in the `web` middleware group only,
 * so a 404 or a JSON route (neither of which uses the `web` group) goes out
 * without the baseline headers.
 */
final class WebScopeTest extends TestCase
{
    protected function setUp(): void
    {
        // The config reads HELMET_SCOPE while the application is being created,
        // so the env var has to be in place before parent::setUp().
        $this->putEnv('HELMET_SCOPE', 'web');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_SCOPE');

        parent::tearDown();
    }

    public function testScopeIsWeb(): void
    {
        self::assertSame('web', config('helmet.scope'));
    }

    public function testSecurityHeadersIsInWebGroupNotGlobal(): void
    {
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);

        $global = $this->globalMiddlewareList($kernel);
        $web = $this->app['router']->getMiddlewareGroups()['web'];

        self::assertNotContains(SecurityHeaders::class, $global);
        self::assertContains(SecurityHeaders::class, $web);
        self::assertContains(InjectRibbon::class, $web);
    }

    public function testNotFoundResponseCarriesNoHelmetHeaders(): void
    {
        config(['helmet.proxies' => ['10.0.0.1']]);

        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/missing');

        $response->assertStatus(404);
        $response->assertHeaderMissing('Strict-Transport-Security');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function testJsonRouteCarriesNoHelmetHeaders(): void
    {
        $this->app['router']->get('/json', static fn () => response()->json(['ok' => true]));
        config(['helmet.proxies' => ['10.0.0.1']]);

        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/json');

        $response->assertStatus(200);
        $response->assertHeaderMissing('Strict-Transport-Security');
        $response->assertHeaderMissing('Content-Security-Policy');
    }
}

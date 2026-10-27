<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

/**
 * An app that sets `trustedproxy.proxies` itself keeps its own value. HELMET
 * never overrides it, so Laravel's $request->isSecure() follows the app's
 * list, not helmet.proxies.
 */
final class TrustedProxyAppValueWinsTest extends TestCase
{
    protected function setUp(): void
    {
        $this->putEnv('HELMET_PROXIES', '10.0.0.1');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PROXIES');

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // defineEnvironment() runs after the providers register and before
        // they boot, so the provider's fallback sees this value.
        $app['config']->set('trustedproxy.proxies', ['192.0.2.1']);
    }

    public function testAppValueIsNotOverridden(): void
    {
        self::assertSame(['192.0.2.1'], config('trustedproxy.proxies'));
    }

    public function testLaravelTrustsTheAppValueNotHelmetProxies(): void
    {
        $this->defineSecureStatusRoute('/secure');

        $fromHelmetProxy = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/secure');
        $fromHelmetProxy->assertStatus(200);
        $fromHelmetProxy->assertJson(['secure' => false]);

        $fromAppProxy = $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/secure');
        $fromAppProxy->assertStatus(200);
        $fromAppProxy->assertJson(['secure' => true]);
    }

    private function defineSecureStatusRoute(string $uri): void
    {
        $this->app['router']->get($uri, static fn () => response()->json([
            'secure' => request()->isSecure(),
        ]));
    }
}

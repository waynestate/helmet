<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

/**
 * When the app has not set `trustedproxy.proxies` itself, the provider copies
 * `helmet.proxies` over, so HELMET_PROXIES drives both HELMET's HSTS decision
 * and Laravel's own $request->isSecure().
 */
final class TrustedProxyFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        // The config reads HELMET_PROXIES while the application is being
        // created, so the env var has to be in place before parent::setUp().
        $this->putEnv('HELMET_PROXIES', '10.0.0.1');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PROXIES');

        parent::tearDown();
    }

    public function testTrustedProxyProxiesFollowHelmetProxiesWhenTheAppHasNotSetThem(): void
    {
        self::assertSame(['10.0.0.1'], config('trustedproxy.proxies'));
    }

    public function testRequestIsSecureBehindTheProxyHelmetTrusted(): void
    {
        $this->defineSecureStatusRoute('/secure');

        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/secure');

        $response->assertStatus(200);
        $response->assertJson(['secure' => true]);
    }

    private function defineSecureStatusRoute(string $uri): void
    {
        $this->app['router']->get($uri, static fn () => response()->json([
            'secure' => request()->isSecure(),
        ]));
    }
}

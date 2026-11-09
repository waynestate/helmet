<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * helmet.session.secure (env HELMET_SESSION_SECURE) reaches the Laravel
 * session cookie. "always" sets the Secure flag on every response; "auto"
 * (the default) follows the trusted-proxy HTTPS decision; an app that set
 * session.secure itself keeps its own value.
 *
 * The provider maps the key at boot, during parent::setUp(), so each test
 * puts its environment in place first, keyed by the test name, the way
 * ForwardedProtoTest sets HELMET_PROXIES. "never" is not a mode: the config
 * refuses it, so a typo cannot quietly drop the strap.
 */
final class SessionSecureTest extends TestCase
{
    /** The env HELMET_SESSION_SECURE per test, or null to leave it unset. */
    private const SECURE_ENV = [
        'testAlwaysMarksThePlainHttpCookieSecure' => 'always',
        'testAlwaysLeavesAnAppValueAlone' => 'always',
        'testExplicitAutoMatchesTheDefault' => 'auto',
    ];

    protected function setUp(): void
    {
        $this->putEnv('HELMET_PROXIES', '10.0.0.1');
        $mode = self::SECURE_ENV[$this->name()] ?? null;
        if ($mode !== null) {
            $this->putEnv('HELMET_SESSION_SECURE', $mode);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PROXIES');
        $this->clearEnv('HELMET_SESSION_SECURE');

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // An in-memory session, so the web group writes a session cookie
        // without a database.
        $app['config']->set('session.driver', 'array');

        if ($this->name() === 'testAlwaysLeavesAnAppValueAlone') {
            // The app chose its own value (SESSION_SECURE_COOKIE=false).
            $app['config']->set('session.secure', false);
        }
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/session', static fn() => 'ok');
    }

    public function testAutoIsTheDefaultAndLeavesSessionSecureUnset(): void
    {
        self::assertSame('auto', config('helmet.session.secure'));
        self::assertNull(config('session.secure'));
    }

    public function testAutoPlainHttpCookieIsNotSecure(): void
    {
        $cookie = $this->sessionCookie($this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/session'));

        self::assertFalse($cookie->isSecure());
    }

    public function testAutoHttpsViaTrustedProxyCookieIsSecure(): void
    {
        $cookie = $this->sessionCookie($this->withServerVariables($this->httpsViaTrustedProxy())->get('/session'));

        self::assertTrue($cookie->isSecure());
    }

    public function testAutoForwardedHttpsFromUntrustedPeerCookieIsNotSecure(): void
    {
        // The peer is not in helmet.proxies, so its X-Forwarded-Proto is
        // ignored and the request stays plain HTTP.
        $cookie = $this->sessionCookie($this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/session'));

        self::assertFalse($cookie->isSecure());
    }

    public function testAutoFollowsTheProxyValueOfAnAppendedList(): void
    {
        // The client sent https, the trusted proxy appended http: the
        // request was not secure, so neither is the cookie.
        $cookie = $this->sessionCookie($this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ])->get('/session'));

        self::assertFalse($cookie->isSecure());
    }

    public function testExplicitAutoMatchesTheDefault(): void
    {
        self::assertSame('auto', config('helmet.session.secure'));
        self::assertNull(config('session.secure'));

        $plain = $this->sessionCookie($this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/session'));
        self::assertFalse($plain->isSecure());

        $proxied = $this->sessionCookie($this->withServerVariables($this->httpsViaTrustedProxy())->get('/session'));
        self::assertTrue($proxied->isSecure());
    }

    public function testAlwaysMarksThePlainHttpCookieSecure(): void
    {
        self::assertSame('always', config('helmet.session.secure'));
        self::assertTrue(config('session.secure'));

        $cookie = $this->sessionCookie($this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/session'));

        self::assertTrue($cookie->isSecure());
    }

    public function testAlwaysLeavesAnAppValueAlone(): void
    {
        self::assertSame('always', config('helmet.session.secure'));
        self::assertFalse(config('session.secure'));

        $cookie = $this->sessionCookie($this->withServerVariables($this->httpsViaTrustedProxy())->get('/session'));

        // An explicit false beats Symfony's per-request default as well.
        self::assertFalse($cookie->isSecure());
    }

    public function testNeverIsRefusedAtConfigLoad(): void
    {
        $this->putEnv('HELMET_SESSION_SECURE', 'never');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HELMET_SESSION_SECURE must be "auto" or "always".');
        $this->reloadPackageConfig();
    }

    private function sessionCookie(TestResponse $response): Cookie
    {
        $response->assertOk();
        $cookie = $response->getCookie((string) config('session.cookie'), false);
        self::assertInstanceOf(Cookie::class, $cookie, 'The web group should write a session cookie.');

        return $cookie;
    }
}

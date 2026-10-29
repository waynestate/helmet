<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use ReflectionProperty;

/**
 * Laravel's default trusted-header set includes X-Forwarded-Host,
 * X-Forwarded-Prefix and X-Forwarded-Port, which a platform that rewrites
 * the host (OpenShift appends the route host) or never sets the port
 * (Citadel's HA-Proxy) would let a client pre-seed. The provider narrows
 * the set to the two headers a front proxy actually sets: For and Proto.
 */
final class TrustedHeadersTest extends TestCase
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

    public function testProviderNarrowsTheTrustedHeaderSet(): void
    {
        $headers = (new ReflectionProperty(TrustProxies::class, 'alwaysTrustHeaders'))->getValue();

        self::assertSame(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO,
            $headers,
        );
    }

    public function testForwardedPortIsNotTrusted(): void
    {
        // A client-sent X-Forwarded-Port must not change the port Laravel
        // reports or the URLs it generates, even when the request comes from
        // the trusted proxy: Citadel's HA-Proxy never sets the header, so any
        // value is the client's own. (A full URL is used because
        // SymfonyRequest::create() takes the host from the URL, not from a
        // server variable.)
        $this->app['router']->get('/port', static fn() => response()->json([
            'port' => request()->getPort(),
            'url' => url('/port'),
        ]));

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PORT' => '1337',
        ])->get('http://app.example/port');

        $response->assertStatus(200);
        $response->assertJson(['port' => 80, 'url' => 'http://app.example/port']);
    }

    public function testForwardedHostIsNotTrusted(): void
    {
        // A client-sent X-Forwarded-Host must not change the host, even when
        // the request comes from the trusted proxy. The URL carries the real
        // host; the forwarded header is ignored because HELMET does not trust
        // it. (A full URL is used because SymfonyRequest::create() takes the
        // host from the URL, not from a server variable.)
        $this->app['router']->get('/host', static fn() => response()->json([
            'host' => request()->getHost(),
        ]));

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
        ])->get('http://app.example/host');

        $response->assertStatus(200);
        $response->assertJson(['host' => 'app.example']);
    }

    public function testForwardedProtoIsTrusted(): void
    {
        // X-Forwarded-Proto from the trusted proxy still decides HTTPS.
        $this->app['router']->get('/secure', static fn() => response()->json([
            'secure' => request()->isSecure(),
        ]));

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/secure');

        $response->assertStatus(200);
        $response->assertJson(['secure' => true]);
    }
}

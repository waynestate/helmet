<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Helmet\Laravel\ForwardedProtoNormalizer;

/**
 * Citadel's HA-Proxy appends X-Forwarded-Proto instead of replacing it, so a
 * client can produce "https, http". Symfony reads the first value of the
 * list (the client's); HELMET's detector reads the last (the proxy's). The
 * normalizer middleware makes Laravel agree with HELMET: when the request
 * comes from a trusted proxy, the last value wins.
 */
final class ForwardedProtoTest extends TestCase
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

    public function testClientFirstValueDoesNotWinOverTheProxyValue(): void
    {
        $this->app['router']->get('/secure', static fn() => response()->json([
            'secure' => request()->isSecure(),
        ]));

        // The client sent https first, the trusted proxy appended http. The
        // proxy's value is the truth: the request was not secure. Without
        // the normalizer, Symfony would read the first value and call this
        // secure.
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ])->get('/secure');

        $response->assertStatus(200);
        $response->assertJson(['secure' => false]);
    }

    public function testProxyHttpsWinsOverAnEarlierHttp(): void
    {
        $this->app['router']->get('/secure', static fn() => response()->json([
            'secure' => request()->isSecure(),
        ]));

        // The client sent http first, the trusted proxy appended https.
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ])->get('/secure');

        $response->assertStatus(200);
        $response->assertJson(['secure' => true]);
    }

    public function testForgedProtoListFromAnUntrustedPeerIsIgnored(): void
    {
        $this->app['router']->get('/secure', static fn() => response()->json([
            'secure' => request()->isSecure(),
        ]));

        // The peer is not in helmet.proxies, so the whole header is ignored,
        // exactly as before the normalizer existed.
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ])->get('/secure');

        $response->assertStatus(200);
        $response->assertJson(['secure' => false]);
    }

    public function testUntrustedPeerCannotClaimHttpsThroughAProtoList(): void
    {
        $this->app['router']->get('/secure', static fn() => response()->json([
            'secure' => request()->isSecure(),
        ]));

        // The peer is not in helmet.proxies, so a proxy-looking list ending
        // in https must not flip the request to secure.
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ])->get('/secure');

        $response->assertStatus(200);
        $response->assertJson(['secure' => false]);

        // Directly: the normalizer rewrites nothing for an untrusted peer,
        // so the header and server values stay exactly as sent.
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ]);

        config(['helmet.proxies' => ['10.0.0.1']]);
        (new ForwardedProtoNormalizer())->handle($request, static fn() => new Response('ok'));

        self::assertSame('http, https', $request->headers->get('X-Forwarded-Proto'));
        self::assertSame('http, https', $request->server->get('HTTP_X_FORWARDED_PROTO'));
    }

    public function testMultiLineHeaderFromTrustedProxyKeepsTheLastLine(): void
    {
        // PHP merges repeated header lines into a comma list in $_SERVER,
        // but a request built with separate lines must normalize the same
        // way, so the middleware is called directly with both lines present.
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        // Repeated header lines are stored as an array of values.
        $request->headers->add(['X-Forwarded-Proto' => ['https', 'http']]);
        $request->server->set('HTTP_X_FORWARDED_PROTO', 'https, http');

        config(['helmet.proxies' => ['10.0.0.1']]);
        (new ForwardedProtoNormalizer())->handle($request, static fn() => new Response('ok'));

        self::assertSame('http', $request->headers->get('X-Forwarded-Proto'));
        self::assertSame('http', $request->server->get('HTTP_X_FORWARDED_PROTO'));
    }

    public function testSingleValueFromTrustedProxyIsLeftAlone(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        config(['helmet.proxies' => ['10.0.0.1']]);
        (new ForwardedProtoNormalizer())->handle($request, static fn() => new Response('ok'));

        self::assertSame('https', $request->headers->get('X-Forwarded-Proto'));
        self::assertSame('https', $request->server->get('HTTP_X_FORWARDED_PROTO'));
    }
}

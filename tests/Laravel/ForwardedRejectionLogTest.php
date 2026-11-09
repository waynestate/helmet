<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The Laravel adapter logs the two forwarded-header rejections through the
 * app's logger, with the same event names and context keys as raw PHP:
 *
 *  - helmet.forwarded_proto_rejected from SecurityHeaders, when a peer that
 *    is not a trusted proxy sends X-Forwarded-Proto (helmet_boot() logs it
 *    the same way);
 *  - helmet.forwarded_for_rejected from the metrics endpoint, when the
 *    client-IP resolver fails closed on an unparseable X-Forwarded-For entry
 *    (helmet_metrics() logs it the same way).
 *
 * The header values themselves never reach the log.
 */
final class ForwardedRejectionLogTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $loggedWarnings = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', false);
        $app['config']->set('helmet.proxies', ['10.0.0.1']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/page', static fn() => 'ok');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Capture every warning so a missing or a stray one both show up.
        Log::shouldReceive('warning')
            ->zeroOrMoreTimes()
            ->withArgs(function (mixed $message, mixed $context = []): bool {
                $this->loggedWarnings[] = [$message, $context];

                return true;
            });
    }

    public function testUntrustedForwardedProtoIsLoggedThroughTheAppLogger(): void
    {
        $response = $this->call('GET', '/page', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https-spoof-1',
        ]);

        $response->assertOk();
        // The rejected header changes nothing: no HSTS on a plain request.
        $response->assertHeaderMissing('Strict-Transport-Security');

        self::assertSame([[
            'helmet.forwarded_proto_rejected',
            ['event' => 'forwarded_proto_rejected', 'reason' => 'untrusted_peer', 'peer' => '203.0.113.9'],
        ]], $this->loggedWarnings);
        self::assertStringNotContainsString('https-spoof-1', (string) json_encode($this->loggedWarnings));
    }

    public function testTrustedForwardedProtoLogsNothing(): void
    {
        $response = $this->call('GET', '/page', [], [], [], $this->httpsViaTrustedProxy());

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security');
        self::assertSame([], $this->loggedWarnings);
    }

    public function testNoForwardedProtoLogsNothing(): void
    {
        $this->call('GET', '/page', [], [], [], ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();

        self::assertSame([], $this->loggedWarnings);
    }

    public function testUnparseableForwardedForOnTheMetricsEndpointIsLogged(): void
    {
        Route::helmetMetrics('/metrics', null);
        config(['helmet.metrics' => [
            'enabled' => true,
            'token' => null,
            'allow' => ['198.51.100.7'],
            'storage' => null,
        ]]);

        // The trusted proxy forwarded a garbage entry. The resolver fails
        // closed on the peer, which is not on the allow list, so the scrape
        // is refused and the event is logged.
        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, not-an-ip-1',
        ]);

        $response->assertStatus(403);
        self::assertSame([[
            'helmet.forwarded_for_rejected',
            ['event' => 'forwarded_for_rejected', 'reason' => 'unparseable_entry', 'peer' => '10.0.0.1'],
        ]], $this->loggedWarnings);
        self::assertStringNotContainsString('not-an-ip-1', (string) json_encode($this->loggedWarnings));
    }

    public function testParseableForwardedForOnTheMetricsEndpointLogsNothing(): void
    {
        Route::helmetMetrics('/metrics', null);
        config(['helmet.metrics' => [
            'enabled' => true,
            'token' => null,
            'allow' => ['198.51.100.7'],
            'storage' => null,
        ]]);

        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        $response->assertOk();
        self::assertSame([], $this->loggedWarnings);
    }
}

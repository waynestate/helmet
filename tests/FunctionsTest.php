<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Platform\UnknownPresetException;

final class FunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Make the HTTPS-detection assertions deterministic: start from a
        // known cookie_secure and no forwarded-proto / TLS signal.
        ini_set('session.cookie_secure', '0');
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootThrowsWhenSessionAlreadyActive(): void
    {
        // In a separate process no output has been sent, so a session can start.
        session_start();
        self::assertSame(PHP_SESSION_ACTIVE, session_status());

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(\LogicException::class);
        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
        ]);
    }

    public function testBootReturnsNonceWhenNoSessionActive(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertArrayHasKey('logger', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    public function testPresetCitadelResolvesTrustedProxyAndDetectsHttps(): void
    {
        // The citadel preset trusts the Docker bridge gateway 172.18.0.1, so
        // a forwarded-proto from that peer is believed.
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'preset' => 'citadel',
        ]);

        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    public function testPresetDockerTrustsNothing(): void
    {
        // The docker preset trusts nothing, so the same forwarded-proto from
        // the same peer is ignored.
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'preset' => 'docker',
        ]);

        self::assertNotSame('1', ini_get('session.cookie_secure'));
    }

    public function testNoPresetAndNoProxiesTrustsNothing(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([]);

        self::assertNotSame('1', ini_get('session.cookie_secure'));
    }

    public function testExplicitProxiesWinOverPreset(): void
    {
        // The explicit list trusts 127.0.0.1; the citadel preset would trust
        // 172.18.0.1. The peer is 127.0.0.1, so only the explicit list can
        // make this request HTTPS.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'preset' => 'citadel',
        ]);

        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    public function testUnknownPresetThrows(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $this->expectException(UnknownPresetException::class);

        \Waynestate\Helmet\helmet_boot([
            'preset' => 'openshift',
        ]);
    }
}

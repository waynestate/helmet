<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Platform\UnknownPresetException;

/**
 * The framework-free helmet_metrics() helper.
 *
 * It reads the request from $_SERVER (the same source helmet_boot() uses) and
 * returns the status, content type, and body for the metrics endpoint. The
 * caller sends the response; the helper does not.
 */
final class FunctionsMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testDisabledIs404(): void
    {
        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => false,
            'token' => 'secret',
        ]);

        self::assertSame(404, $result['status']);
        self::assertSame('', $result['body']);
    }

    public function testEnabledWithoutTokenOrAllowIs404(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
        ]);

        self::assertSame(404, $result['status']);
        self::assertSame('', $result['body']);
    }

    public function testCorrectTokenIs200WithHelmetInfo(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'token' => 'secret',
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame('text/plain; version=0.0.4', $result['content_type']);
        self::assertStringContainsString('helmet_info', $result['body']);
    }

    public function testWrongTokenIs403(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'token' => 'secret',
        ]);

        self::assertSame(403, $result['status']);
        self::assertSame('', $result['body']);
    }

    public function testAllowedIpIs200(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'allow' => ['10.0.0.1'],
        ]);

        self::assertSame(200, $result['status']);
    }

    public function testDisallowedIpIs403(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'allow' => ['10.0.0.1'],
        ]);

        self::assertSame(403, $result['status']);
    }

    public function testTrustedProxyUnwrapsForwardedFor(): void
    {
        // The peer is a trusted proxy, so the X-Forwarded-For header is
        // honored: the client is 9.9.9.9, which is allowed.
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 10.0.0.1';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'allow' => ['9.9.9.9'],
            'trusted_proxies' => ['10.0.0.1'],
        ]);

        self::assertSame(200, $result['status']);
    }

    public function testUntrustedProxyForwardedForIsIgnored(): void
    {
        // The peer is not a trusted proxy, so the X-Forwarded-For header is
        // ignored: the client is 10.0.0.1, which is not allowed.
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';

        $result = \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'allow' => ['9.9.9.9'],
        ]);

        self::assertSame(403, $result['status']);
    }

    public function testUnknownPresetThrows(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $this->expectException(UnknownPresetException::class);

        \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'token' => 'secret',
            'preset' => 'openshift',
        ]);
    }

    public function testInvalidAllowEntryThrows(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $this->expectException(\InvalidArgumentException::class);

        \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'allow' => ['not-an-ip'],
        ]);
    }

    public function testWildcardAllowEntryThrows(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $this->expectException(\InvalidArgumentException::class);

        \Waynestate\Helmet\helmet_metrics([
            'enabled' => true,
            'allow' => ['*'],
        ]);
    }
}

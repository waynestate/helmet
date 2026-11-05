<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * H21: trust the proxy's address, not its whole network. When a raw PHP app
 * boots through helmet_boot() with a broad range in its trusted-proxy list
 * (an IPv4 range shorter than /16 or an IPv6 range shorter than /48), it
 * writes one helmet.broad_trusted_proxy warning through the configured
 * logger. Without a logger the warning is a no-op, and warn_broad_proxies
 * false silences it.
 */
final class FunctionsBroadProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        // Keep the request signals from leaking into later test files.
        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
        );

        parent::tearDown();
    }

    public function testBootLogsABroadProxyWarning(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet-broad-');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['10.0.0.0/8'],
            'log' => ['stream' => $file],
        ]);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('helmet.broad_trusted_proxy', $content);
        self::assertStringContainsString('10.0.0.0/8', $content);
    }

    public function testBootWithoutLoggerDoesNothing(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        // No logger is configured, so the broad-range warning has nowhere to
        // go: the boot still succeeds and returns its usual shape.
        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['10.0.0.0/8'],
        ]);

        self::assertArrayHasKey('request_id', $result);
        self::assertNull($result['logger']);
    }

    public function testBootOptOutSilencesTheWarning(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet-broad-off-');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['10.0.0.0/8'],
            'warn_broad_proxies' => false,
            'log' => ['stream' => $file],
        ]);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('broad_trusted_proxy', $content);
    }
}

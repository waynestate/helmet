<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * When a raw PHP app boots through helmet_boot() and the peer is not a
 * trusted proxy but sends X-Forwarded-Proto anyway, the boot writes one
 * helmet.forwarded_proto_rejected warning through the configured logger,
 * with the reason and the peer, never the header value. A trusted proxy's
 * X-Forwarded-Proto is believed and nothing is logged. Without a logger the
 * event has nowhere to go and the boot still succeeds.
 */
final class FunctionsForwardedProtoTest extends TestCase
{
    protected function tearDown(): void
    {
        // Keep the request signals from leaking into later test files.
        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
        );

        parent::tearDown();
    }

    public function testBootLogsForwardedProtoRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet-proto-');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https-spoof-1';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['10.0.0.1'],
            'log' => ['stream' => $file],
        ]);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('helmet.forwarded_proto_rejected', $content);
        self::assertStringContainsString('203.0.113.9', $content);
        self::assertStringNotContainsString('https-spoof-1', $content);
    }

    public function testBootTrustedProxyForwardedProtoLogsNothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet-proto-trusted-');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['10.0.0.1'],
            'log' => ['stream' => $file],
        ]);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('forwarded_proto_rejected', $content);
    }

    public function testBootWithoutLoggerStillBoots(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https-spoof-1';

        // No logger is configured, so the rejected-header event has nowhere
        // to go: the boot still succeeds and returns its usual shape.
        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['10.0.0.1'],
        ]);

        self::assertArrayHasKey('request_id', $result);
        self::assertNull($result['logger']);
    }
}

<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
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
}

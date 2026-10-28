<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Proxy\ClientIp;
use Waynestate\Helmet\Proxy\TrustedProxies;

final class ClientIpTest extends TestCase
{
    public function testNoProxiesUsesRemoteAddr(): void
    {
        $server = ['REMOTE_ADDR' => '1.2.3.4'];

        self::assertSame('1.2.3.4', ClientIp::resolve($server, new TrustedProxies([])));
    }

    public function testNoForwardedHeaderUsesRemoteAddr(): void
    {
        $server = ['REMOTE_ADDR' => '1.2.3.4'];

        self::assertSame('1.2.3.4', ClientIp::resolve($server, new TrustedProxies(['1.2.3.4'])));
    }

    public function testTrustedProxyUnwrapsForwardedHeader(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1',
        ];

        self::assertSame('9.9.9.9', ClientIp::resolve($server, new TrustedProxies(['10.0.0.1'])));
    }

    public function testUntrustedProxyForwardedHeaderIsIgnored(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ];

        // 10.0.0.1 is not trusted, so the header is ignored entirely.
        self::assertSame('10.0.0.1', ClientIp::resolve($server, new TrustedProxies([])));
    }

    public function testSkipsTrustedHopsUntilAnUntrustedOne(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1, 10.0.0.2',
        ];

        // Walk right to left: 10.0.0.2 (trusted, skip), 10.0.0.1 (trusted,
        // skip), 9.9.9.9 (untrusted, return).
        self::assertSame('9.9.9.9', ClientIp::resolve($server, new TrustedProxies(['10.0.0.1', '10.0.0.2'])));
    }

    public function testAllTrustedFallsBackToRemoteAddr(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ];

        self::assertSame('10.0.0.1', ClientIp::resolve($server, new TrustedProxies(['10.0.0.1'])));
    }

    public function testSkipsInvalidEntries(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'garbage, 9.9.9.9, 10.0.0.1',
        ];

        // Walk right to left: 10.0.0.1 (trusted, skip), 9.9.9.9 (untrusted,
        // return); "garbage" is never reached.
        self::assertSame('9.9.9.9', ClientIp::resolve($server, new TrustedProxies(['10.0.0.1'])));
    }

    public function testCidrTrustedProxy(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1',
        ];

        self::assertSame('9.9.9.9', ClientIp::resolve($server, new TrustedProxies(['10.0.0.0/8'])));
    }

    public function testMissingRemoteAddrIsAnEmptyString(): void
    {
        self::assertSame('', ClientIp::resolve([], new TrustedProxies([])));
    }
}

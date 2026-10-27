<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Proxy\TrustedProxies;

final class TrustedProxiesTest extends TestCase
{
    public function testExactIpIsTrusted(): void
    {
        $proxies = new TrustedProxies(['10.1.2.3']);

        self::assertTrue($proxies->isTrusted('10.1.2.3'));
        self::assertFalse($proxies->isTrusted('10.1.2.4'));
    }

    public function testCidrRangeIsTrusted(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);

        self::assertTrue($proxies->isTrusted('10.0.0.1'));
        self::assertTrue($proxies->isTrusted('10.1.2.3'));
        self::assertTrue($proxies->isTrusted('10.255.255.255'));
        self::assertFalse($proxies->isTrusted('11.0.0.1'));
        self::assertFalse($proxies->isTrusted('203.0.113.9'));
    }

    public function testCidrBoundaryIsInclusive(): void
    {
        $proxies = new TrustedProxies(['192.168.1.0/24']);

        self::assertTrue($proxies->isTrusted('192.168.1.0'));
        self::assertTrue($proxies->isTrusted('192.168.1.255'));
        self::assertFalse($proxies->isTrusted('192.168.0.255'));
        self::assertFalse($proxies->isTrusted('192.168.2.0'));
    }

    public function testEmptyListTrustsNothing(): void
    {
        $proxies = new TrustedProxies([]);

        self::assertTrue($proxies->isEmpty());
        self::assertFalse($proxies->isTrusted('10.1.2.3'));
        self::assertFalse($proxies->isTrusted('127.0.0.1'));
    }

    public function testInvalidIpIsNotTrusted(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);

        self::assertFalse($proxies->isTrusted('not-an-ip'));
    }

    public function testInvalidCidrThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedProxies(['10.0.0.0/33']);
    }
}

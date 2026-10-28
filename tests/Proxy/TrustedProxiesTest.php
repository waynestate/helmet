<?php

declare(strict_types=1);

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

    public function testIpv6ExactIsTrusted(): void
    {
        $proxies = new TrustedProxies(['2001:db8::1']);

        self::assertTrue($proxies->isTrusted('2001:db8::1'));
        self::assertTrue($proxies->isTrusted('2001:0db8:0000:0000:0000:0000:0000:0001'));
        self::assertFalse($proxies->isTrusted('2001:db8::2'));
        self::assertFalse($proxies->isTrusted('10.1.2.3'));
    }

    public function testIpv6CidrRangeIsTrusted(): void
    {
        $proxies = new TrustedProxies(['2001:db8::/32']);

        self::assertTrue($proxies->isTrusted('2001:db8::1'));
        self::assertTrue($proxies->isTrusted('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'));
        self::assertFalse($proxies->isTrusted('2001:db9::1'));
        self::assertFalse($proxies->isTrusted('10.1.2.3'));
    }

    public function testIpv4AndIpv6DoNotCrossMatch(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8', '2001:db8::/32']);

        self::assertTrue($proxies->isTrusted('10.1.2.3'));
        self::assertTrue($proxies->isTrusted('2001:db8::1'));
        self::assertFalse($proxies->isTrusted('11.0.0.1'));
        self::assertFalse($proxies->isTrusted('2001:db9::1'));
    }

    public function testInvalidExactEntryThrowsNamingTheEntry(): void
    {
        try {
            new TrustedProxies(['not-an-ip']);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not-an-ip', $e->getMessage());
        }
    }

    public function testWildcardEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('*');

        new TrustedProxies(['*']);
    }

    public function testIpv4ZeroPrefixThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedProxies(['0.0.0.0/0']);
    }

    public function testIpv6ZeroPrefixThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedProxies(['::/0']);
    }

    public function testNonStringEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedProxies([10]);
    }

    public function testEmptyStringEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedProxies(['']);
    }

    public function testInvalidCidrSubnetThrowsNamingTheEntry(): void
    {
        try {
            new TrustedProxies(['10.0.0.256/8']);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('10.0.0.256/8', $e->getMessage());
        }
    }
}

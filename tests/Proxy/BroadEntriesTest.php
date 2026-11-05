<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * H21: trust the proxy's address, not its whole network. broadEntries()
 * reports the configured entries that are too broad to be safe: an IPv4 range
 * shorter than /16 or an IPv6 range shorter than /48. Exact addresses and
 * narrower ranges are not broad, and the result keeps the configured order.
 */
final class BroadEntriesTest extends TestCase
{
    public function testBroadEntriesFlagsIpv4SlashEightAndSlashFifteen(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8', '192.168.0.0/15']);

        self::assertSame(['10.0.0.0/8', '192.168.0.0/15'], $proxies->broadEntries());
    }

    public function testBroadEntriesIgnoresSlashSixteenAndExactAddresses(): void
    {
        // /16 is the narrowest IPv4 range that is still allowed to pass
        // unflagged; exact addresses and /32 are never broad.
        $proxies = new TrustedProxies(['192.168.0.0/16', '203.0.113.5', '10.0.0.0/32']);

        self::assertSame([], $proxies->broadEntries());
    }

    public function testBroadEntriesFlagsIpv6ShorterThanSlashFortyEight(): void
    {
        // /48 is the narrowest IPv6 range that stays unflagged; anything
        // shorter than that trusts far more than one proxy.
        $proxies = new TrustedProxies(['2001:db8::/32', '2001:db8::/47', '2001:db8::/48']);

        self::assertSame(['2001:db8::/32', '2001:db8::/47'], $proxies->broadEntries());
    }

    public function testBroadEntriesKeepsConfiguredOrder(): void
    {
        // Narrow entries are skipped, but the broad ones come back in the same
        // order they were configured in.
        $proxies = new TrustedProxies(['203.0.113.0/24', '10.0.0.0/8', '192.0.2.0/15', '172.16.0.0/16']);

        self::assertSame(['10.0.0.0/8', '192.0.2.0/15'], $proxies->broadEntries());
    }
}

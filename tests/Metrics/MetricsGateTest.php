<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Metrics\MetricsGate;
use Waynestate\Helmet\Proxy\IpAllowList;

final class MetricsGateTest extends TestCase
{
    public function testDisabledIs404RegardlessOfCredentials(): void
    {
        $gate = new MetricsGate(false, 'secret', new IpAllowList(['10.0.0.1']));
        $decision = $gate->decide('secret', '10.0.0.1');

        self::assertFalse($decision->isAllowed());
        self::assertSame(404, $decision->status());
        self::assertSame('disabled', $decision->reason());
    }

    public function testEnabledWithoutTokenOrAllowIsMisconfigured404(): void
    {
        $gate = new MetricsGate(true, null, new IpAllowList([]));
        $decision = $gate->decide('anything', '10.0.0.1');

        self::assertFalse($decision->isAllowed());
        self::assertSame(404, $decision->status());
        self::assertSame('misconfigured', $decision->reason());
    }

    public function testEmptyTokenIsTreatedAsNoToken(): void
    {
        $gate = new MetricsGate(true, '', new IpAllowList([]));
        $decision = $gate->decide(null, '10.0.0.1');

        self::assertSame('misconfigured', $decision->reason());
    }

    public function testCorrectTokenIsAllowed(): void
    {
        $gate = new MetricsGate(true, 'secret', new IpAllowList([]));
        $decision = $gate->decide('secret', '10.0.0.1');

        self::assertTrue($decision->isAllowed());
        self::assertSame(200, $decision->status());
        self::assertSame('allowed', $decision->reason());
    }

    public function testWrongTokenIsDenied(): void
    {
        $gate = new MetricsGate(true, 'secret', new IpAllowList([]));
        $decision = $gate->decide('wrong', '10.0.0.1');

        self::assertFalse($decision->isAllowed());
        self::assertSame(403, $decision->status());
        self::assertSame('denied', $decision->reason());
    }

    public function testMissingTokenIsDeniedWhenATokenIsRequired(): void
    {
        $gate = new MetricsGate(true, 'secret', new IpAllowList([]));
        $decision = $gate->decide(null, '10.0.0.1');

        self::assertFalse($decision->isAllowed());
        self::assertSame(403, $decision->status());
    }

    public function testAllowedIpIsAllowed(): void
    {
        $gate = new MetricsGate(true, null, new IpAllowList(['10.0.0.1']));
        $decision = $gate->decide(null, '10.0.0.1');

        self::assertTrue($decision->isAllowed());
        self::assertSame(200, $decision->status());
    }

    public function testDisallowedIpIsDenied(): void
    {
        $gate = new MetricsGate(true, null, new IpAllowList(['10.0.0.1']));
        $decision = $gate->decide(null, '10.0.0.2');

        self::assertFalse($decision->isAllowed());
        self::assertSame(403, $decision->status());
        self::assertSame('denied', $decision->reason());
    }

    public function testTokenWinsWhenTheIpIsNotAllowed(): void
    {
        $gate = new MetricsGate(true, 'secret', new IpAllowList(['10.0.0.1']));
        $decision = $gate->decide('secret', '10.0.0.2');

        self::assertTrue($decision->isAllowed());
    }

    public function testIpWinsWhenTheTokenIsWrong(): void
    {
        $gate = new MetricsGate(true, 'secret', new IpAllowList(['10.0.0.1']));
        $decision = $gate->decide('wrong', '10.0.0.1');

        self::assertTrue($decision->isAllowed());
    }

    public function testCidrAllowListMatches(): void
    {
        $gate = new MetricsGate(true, null, new IpAllowList(['10.0.0.0/8']));
        $decision = $gate->decide(null, '10.1.2.3');

        self::assertTrue($decision->isAllowed());
    }

    public function testEmptyClientIpIsNeverAllowed(): void
    {
        $gate = new MetricsGate(true, null, new IpAllowList(['10.0.0.1']));
        $decision = $gate->decide(null, '');

        self::assertFalse($decision->isAllowed());
        self::assertSame(403, $decision->status());
    }
}

<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Proxy\HttpsDetector;
use Waynestate\Helmet\Proxy\TrustedProxies;

final class HttpsDetectorTest extends TestCase
{
    public function testSpoofedForwardedProtoFromUntrustedPeerIsIgnored(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }

    public function testForwardedProtoFromTrustedPeerIsHonored(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testEmptyTrustedListTrustsNothing(): void
    {
        $proxies = new TrustedProxies([]);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }

    public function testSapiHttpsIsTrustedUnconditionally(): void
    {
        $proxies = new TrustedProxies([]);
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTPS' => 'on',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testSapiHttpsOffFallsThroughToProxy(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testForwardedProtoHttpIsNotHttps(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }

    public function testForwardedPortIsNeverRead(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PORT' => '443',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Proxy;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

    public function testMultiValueForwardedProtoUsesLastValueHttps(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testMultiValueForwardedProtoUsesLastValueHttp(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }

    public function testMultiValueForwardedProtoTrimsAndLowercasesLastValue(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'http,  HTTPS ',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testMultiValueForwardedProtoFromUntrustedPeerIsIgnored(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }

    public function testTrailingEmptyTokenAfterHttpsIsStillSecure(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https,',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testTrailingEmptyTokenAfterLastValueIsRead(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'http, https,',
        ];

        self::assertTrue(HttpsDetector::isHttps($server, $proxies));
    }

    public function testTrailingEmptyTokenAfterHttpIsNotSecure(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8']);
        $server = [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https, http,',
        ];

        self::assertFalse(HttpsDetector::isHttps($server, $proxies));
    }

    public function testUntrustedPeerForwardedProtoIsLogged(): void
    {
        $proxies = new TrustedProxies(['10.0.0.1']);
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https-spoof-1',
        ];
        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        self::assertFalse(HttpsDetector::isHttps($server, $proxies, $logger));
        self::assertCount(1, $handler->getRecords());

        $record = $handler->getRecords()[0];
        self::assertSame('helmet.forwarded_proto_rejected', $record->message);
        self::assertSame('forwarded_proto_rejected', $record->context['event']);
        self::assertSame('untrusted_peer', $record->context['reason']);
        self::assertSame('203.0.113.9', $record->context['peer']);

        // The header value never reaches the record.
        $serialized = json_encode(['message' => $record->message, 'context' => $record->context]);
        self::assertStringNotContainsString('https-spoof-1', (string) $serialized);
    }

    public function testTrustedPeerForwardedProtoLogsNothing(): void
    {
        $proxies = new TrustedProxies(['10.0.0.1']);
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        self::assertTrue(HttpsDetector::isHttps($server, $proxies, $logger));
        self::assertCount(0, $handler->getRecords());
    }

    public function testNoForwardedProtoLogsNothing(): void
    {
        $proxies = new TrustedProxies(['10.0.0.1']);
        $server = ['REMOTE_ADDR' => '203.0.113.9'];
        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        self::assertFalse(HttpsDetector::isHttps($server, $proxies, $logger));
        self::assertCount(0, $handler->getRecords());
    }

    public function testNoTrustedProxiesLogsNothing(): void
    {
        $proxies = new TrustedProxies([]);
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        self::assertFalse(HttpsDetector::isHttps($server, $proxies, $logger));
        self::assertCount(0, $handler->getRecords());
    }
}

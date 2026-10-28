<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Logging\Logging;

final class LoggingTest extends TestCase
{
    public function testRedactsSensitiveKeys(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet_log');
        $logger = Logging::create('test', $file);

        $logger->info('login', ['password' => 'hunter2', 'user' => 'alice']);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('hunter2', $content);
        self::assertStringContainsString('***', $content);
        self::assertStringContainsString('alice', $content);
    }

    public function testKeepsNonSensitiveKeys(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet_log');
        $logger = Logging::create('test', $file);

        $logger->info('request', ['user' => 'alice', 'path' => '/home']);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('alice', $content);
        self::assertStringContainsString('/home', $content);
        self::assertStringNotContainsString('***', $content);
    }

    public function testRedactsNestedSensitiveKeys(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet_log');
        $logger = Logging::create('test', $file);

        $logger->info('db', ['db' => ['password' => 'hunter2', 'host' => 'localhost']]);

        $content = (string) file_get_contents($file);
        unlink($file);

        self::assertStringNotContainsString('hunter2', $content);
        self::assertStringContainsString('localhost', $content);
    }

    public function testLoggingFailureDoesNotThrow(): void
    {
        // /tmp is a directory, so the primary stream cannot be opened. The
        // fallback (php://stderr) must handle the record without throwing.
        $logger = Logging::create('test', '/tmp');
        self::assertInstanceOf(LoggerInterface::class, $logger);

        $logger->info('test message', ['user' => 'alice']);
    }
}

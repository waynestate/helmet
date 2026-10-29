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

    public function testRequestIdIsStableWithinTheProcess(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', Logging::requestId());
        self::assertSame(Logging::requestId(), Logging::requestId());
    }

    public function testEveryRecordCarriesTheSameRequestId(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet_log');
        $logger = Logging::create('test', $file);

        $logger->info('first', ['user' => 'alice']);
        $logger->info('second', ['user' => 'bob']);

        $content = (string) file_get_contents($file);
        unlink($file);
        $lines = array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
        self::assertCount(2, $lines);

        $ids = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            self::assertIsArray($record);
            self::assertArrayHasKey('request_id', $record['extra']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $record['extra']['request_id']);
            $ids[] = $record['extra']['request_id'];
        }

        self::assertSame($ids[0], $ids[1]);
    }

    public function testSuppliedValidRequestIdIsUsed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'helmet_log');
        $logger = Logging::create('test', $file, 'info', 'req-20261029-abcd');

        $logger->info('login', ['user' => 'alice']);

        $content = (string) file_get_contents($file);
        unlink($file);

        $record = json_decode($content, true);
        self::assertIsArray($record);
        self::assertSame('req-20261029-abcd', $record['extra']['request_id']);
    }

    public function testSuppliedInvalidRequestIdIsIgnored(): void
    {
        // An ID with a space and an ID that is too short both fail the shape
        // check, so the generated per-process ID is stamped instead.
        foreach (['not an id', 'short'] as $invalid) {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file, 'info', $invalid);

            $logger->info('login', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertNotSame($invalid, $record['extra']['request_id']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $record['extra']['request_id']);
        }
    }

    public function testRedactionStillMasksPasswordWithRequestId(): void
    {
        // Redaction must still run on the same record that carries the
        // request ID: the password comes out masked, the ID stays.
        $file = tempnam(sys_get_temp_dir(), 'helmet_log');
        $logger = Logging::create('test', $file);

        $logger->info('login', ['password' => 'hunter2', 'user' => 'alice']);

        $content = (string) file_get_contents($file);
        unlink($file);

        $record = json_decode($content, true);
        self::assertIsArray($record);
        self::assertStringNotContainsString('hunter2', $content);
        self::assertSame('***', $record['context']['password']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $record['extra']['request_id']);
    }
}

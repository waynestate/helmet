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
        $lines = array_values(array_filter(explode("\n", $content), static fn(string $line): bool => $line !== ''));
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

    public function testWebRequestRecordCarriesHttpContext(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/admin/users?token=abc';
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('login', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            self::assertStringNotContainsString('token=abc', $content);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame(['method' => 'POST', 'path' => '/admin/users', 'client_ip' => '10.1.2.3'], $record['extra']['http']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testRecordWithoutRequestMethodGetsNoHttpContext(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        unset($_SERVER['REQUEST_METHOD']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('job', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertArrayNotHasKey('http', $record['extra']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testInvalidClientAddressIsNullAndForwardedHeaderIgnored(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/home';
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            self::assertStringNotContainsString('1.2.3.4', $content);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertNull($record['extra']['http']['client_ip']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testUnusualMethodBecomesOtherAndLongPathIsCutTo512Bytes(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'G E T';
        $_SERVER['REQUEST_URI'] = '/' . str_repeat('a', 1999);
        unset($_SERVER['REMOTE_ADDR']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame('OTHER', $record['extra']['http']['method']);
            self::assertSame(512, strlen($record['extra']['http']['path']));
            self::assertStringStartsWith('/', $record['extra']['http']['path']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testResetPasswordTokenInPathIsMasked(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/reset-password/' . str_repeat('0123456789abcdef', 4);
        unset($_SERVER['REMOTE_ADDR']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame('/reset-password/{redacted}', $record['extra']['http']['path']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testInviteTokenInPathIsMasked(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/invite/AbC123xyz789AbC123xyz789Q';
        unset($_SERVER['REMOTE_ADDR']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame('/invite/{redacted}', $record['extra']['http']['path']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testLongSegmentWithoutDigitsIsKept(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/docs/getting-started-with-helmet-and-friends';
        unset($_SERVER['REMOTE_ADDR']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame('/docs/getting-started-with-helmet-and-friends', $record['extra']['http']['path']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testShortNumericSegmentIsKept(): void
    {
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users/42/edit';
        unset($_SERVER['REMOTE_ADDR']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame('/users/42/edit', $record['extra']['http']['path']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    public function testUuidSegmentIsMaskedAcceptedTradeOff(): void
    {
        // A UUID is not a secret, but it matches the token shape, so it is
        // masked anyway; that is an accepted trade-off.
        $saved = $this->saveServerKeys(['REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/orders/3f2b1c9e-8d7a-4b6c-9e5f-1a2b3c4d5e6f';
        unset($_SERVER['REMOTE_ADDR']);

        try {
            $file = tempnam(sys_get_temp_dir(), 'helmet_log');
            $logger = Logging::create('test', $file);

            $logger->info('visit', ['user' => 'alice']);

            $content = (string) file_get_contents($file);
            unlink($file);

            $record = json_decode($content, true);
            self::assertIsArray($record);
            self::assertSame('/orders/{redacted}', $record['extra']['http']['path']);
        } finally {
            $this->restoreServerKeys($saved);
        }
    }

    /**
     * Save the given $_SERVER keys so a test can restore them afterwards.
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed> key to saved value, null when the key was unset
     */
    private function saveServerKeys(array $keys): array
    {
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = $_SERVER[$key] ?? null;
        }

        return $saved;
    }

    /**
     * Restore the $_SERVER keys saved by saveServerKeys().
     *
     * @param array<string, mixed> $saved
     */
    private function restoreServerKeys(array $saved): void
    {
        foreach ($saved as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }
}

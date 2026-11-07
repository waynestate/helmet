<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Waynestate\Helmet\Policy\JsonFilePolicySource;

use function Waynestate\Helmet\helmet_metrics;
use function Waynestate\Helmet\helmet_metrics_env;
use function Waynestate\Helmet\helmet_metrics_requests;
use function Waynestate\Helmet\helmet_metrics_track;

/**
 * The framework-free request metrics: helmet_metrics_env() reads the same
 * variables as the Laravel config, helmet_metrics_track() times a request,
 * and helmet_metrics() reads the result back from shared storage.
 */
final class FunctionsMetricsRequestsTest extends TestCase
{
    private const VARIABLES = [
        'HELMET_METRICS_ENABLED',
        'HELMET_METRICS_TOKEN',
        'HELMET_METRICS_ALLOW',
        'HELMET_METRICS_STORAGE',
        'HELMET_METRICS_REDIS_URL',
        'HELMET_METRICS_SQLITE_PATH',
        'HELMET_POLICY_PATH',
    ];

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . '/helmet-metrics-fn-' . bin2hex(random_bytes(6)) . '.sqlite';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach (self::VARIABLES as $name) {
            $this->clearEnv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->clearEnv($name);
        }
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testEnvDefaultsAreAllOff(): void
    {
        $env = helmet_metrics_env();

        self::assertFalse($env['enabled']);
        self::assertNull($env['token']);
        self::assertSame([], $env['allow']);
        self::assertNull($env['storage']);
        self::assertNull($env['redis_url']);
        self::assertNull($env['sqlite_path']);
        self::assertNull($env['policy']);
    }

    public function testEnvReadsEveryMetricsVariable(): void
    {
        $this->putEnv('HELMET_METRICS_ENABLED', 'true');
        $this->putEnv('HELMET_METRICS_TOKEN', 'scrape-me');
        $this->putEnv('HELMET_METRICS_ALLOW', ' 10.0.0.0/8 , ,192.0.2.7');
        $this->putEnv('HELMET_METRICS_STORAGE', 'sqlite');
        $this->putEnv('HELMET_METRICS_REDIS_URL', 'redis://cache:6379');
        $this->putEnv('HELMET_METRICS_SQLITE_PATH', '/tmp/m.sqlite');
        $this->putEnv('HELMET_POLICY_PATH', __DIR__ . '/fixtures/policy.json');

        $env = helmet_metrics_env();

        self::assertTrue($env['enabled']);
        self::assertSame('scrape-me', $env['token']);
        self::assertSame(['10.0.0.0/8', '192.0.2.7'], $env['allow']);
        self::assertSame('sqlite', $env['storage']);
        self::assertSame('redis://cache:6379', $env['redis_url']);
        self::assertSame('/tmp/m.sqlite', $env['sqlite_path']);
        self::assertInstanceOf(JsonFilePolicySource::class, $env['policy']);
    }

    public function testAnUnreadablePolicyIsLeftOut(): void
    {
        $this->putEnv('HELMET_POLICY_PATH', __DIR__ . '/fixtures/no-such-policy.json');

        self::assertNull(helmet_metrics_env()['policy']);
    }

    public function testTrackIsInertByDefault(): void
    {
        self::assertFalse(helmet_metrics_track([])->isOn());
        self::assertFalse(helmet_metrics_track(['enabled' => true])->isOn());
        self::assertFalse(helmet_metrics_track(['enabled' => false, 'storage' => 'sqlite'])->isOn());
    }

    public function testAnUnusableStoreIsLoggedAndLeavesRequestMetricsOff(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $requests = helmet_metrics_requests([
            'enabled' => true,
            'storage' => 'sqlite',
            'sqlite_path' => sys_get_temp_dir() . '/no-such-folder-' . bin2hex(random_bytes(4)) . '/m.sqlite',
            'logger' => $logger,
        ]);

        self::assertNull($requests);
        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('HELMET metrics storage "sqlite"', $logger->messages[0]);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testATrackedRequestShowsUpInTheScrape(): void
    {
        $config = [
            'enabled' => true,
            'token' => 'secret',
            'storage' => 'sqlite',
            'sqlite_path' => $this->file,
        ];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.05;
        $timer = helmet_metrics_track($config);
        self::assertTrue($timer->isOn());
        $timer->route('page')->finish(200);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';
        $result = helmet_metrics($config);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('helmet_metrics_storage_up 1', $result['body']);
        self::assertStringContainsString(
            'helmet_http_request_duration_seconds_count{route="page",method="GET",status="2xx"} 1',
            $result['body'],
        );
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testWithoutStorageTheScrapeHasNoRequestMetrics(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $result = helmet_metrics(['enabled' => true, 'token' => 'secret']);

        self::assertSame(200, $result['status']);
        self::assertStringNotContainsString('helmet_metrics_storage_up', $result['body']);
        self::assertStringNotContainsString('helmet_http_request_duration_seconds', $result['body']);
    }

    private function putEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

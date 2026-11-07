<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APCng;
use Waynestate\Helmet\Metrics\MetricsStorage;
use Waynestate\Helmet\Metrics\MetricsStorageException;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * The storage switch. HELMET only chooses between promphp's adapters, so the
 * tests prove the choice and the one property that matters: two workers that
 * share nothing but the store see one count.
 */
final class MetricsStorageTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir() . '/helmet-metrics-test-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testNoStorageNameMeansNoRequestMetrics(): void
    {
        self::assertFalse(MetricsStorage::isOn(null));
        self::assertFalse(MetricsStorage::isOn(''));
        self::assertFalse(MetricsStorage::isOn('none'));
        self::assertFalse(MetricsStorage::isOn('memcached'));
        self::assertNull(MetricsStorage::create(null));
        self::assertNull(MetricsStorage::create('none'));
    }

    public function testTheThreeStoresTurnRequestMetricsOn(): void
    {
        self::assertTrue(MetricsStorage::isOn('apcu'));
        self::assertTrue(MetricsStorage::isOn('Redis'));
        self::assertTrue(MetricsStorage::isOn('sqlite'));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testTwoSqliteConnectionsShareOneCount(): void
    {
        // Two connections stand in for two PHP-FPM workers or two Apache
        // children: separate processes, one file.
        $first = new RequestMetrics(self::adapter('sqlite', $this->file));
        $second = new RequestMetrics(self::adapter('sqlite', $this->file));

        $first->observe('page', 'GET', 200, 0.01);
        $second->observe('page', 'GET', 200, 0.01);

        $reader = new RequestMetrics(self::adapter('sqlite', $this->file));
        self::assertStringContainsString(
            'helmet_http_request_duration_seconds_count{route="page",method="GET",status="2xx"} 2',
            (new RenderTextFormat())->render($reader->samples()),
        );
    }

    public function testTheDefaultSqliteFileLivesInTheTempFolder(): void
    {
        self::assertSame(
            rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'helmet-metrics.sqlite',
            MetricsStorage::defaultSqlitePath(),
        );
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnSqliteFileThatCannotBeOpenedIsAStorageException(): void
    {
        $this->expectException(MetricsStorageException::class);

        MetricsStorage::create('sqlite', null, sys_get_temp_dir() . '/no-such-folder-' . bin2hex(random_bytes(4)) . '/metrics.sqlite');
    }

    public function testApcuWithoutTheExtensionIsAStorageException(): void
    {
        if (extension_loaded('apcu')) {
            self::markTestSkipped('APCu is loaded here.');
        }

        $this->expectException(MetricsStorageException::class);
        $this->expectExceptionMessage('APCu extension');

        MetricsStorage::create('apcu');
    }

    #[RequiresPhpExtension('apcu')]
    public function testApcuUsesPromphpsApcngAdapter(): void
    {
        if (!apcu_enabled()) {
            self::markTestSkipped('APCu is loaded but not enabled for this SAPI (apc.enable_cli).');
        }

        self::assertInstanceOf(APCng::class, MetricsStorage::create('apcu'));
    }

    public function testRedisWithoutTheExtensionIsAStorageException(): void
    {
        if (extension_loaded('redis')) {
            self::markTestSkipped('The redis extension is loaded here.');
        }

        $this->expectException(MetricsStorageException::class);
        $this->expectExceptionMessage('redis extension');

        MetricsStorage::create('redis', 'redis://127.0.0.1:6379');
    }

    public function testRedisUrlParsing(): void
    {
        self::assertSame(
            ['host' => 'cache', 'port' => 6380, 'password' => 'p@ss', 'user' => null, 'database' => 2],
            MetricsStorage::redisOptions('redis://:p%40ss@cache:6380/2'),
        );
        self::assertSame(
            ['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'user' => null],
            MetricsStorage::redisOptions('redis://127.0.0.1'),
        );
    }

    public function testABadRedisUrlIsAStorageException(): void
    {
        $this->expectException(MetricsStorageException::class);

        MetricsStorage::redisOptions('http://cache:6379');
    }

    public function testANonNumericRedisDatabaseIsAStorageException(): void
    {
        $this->expectException(MetricsStorageException::class);

        MetricsStorage::redisOptions('redis://cache/metrics');
    }

    private static function adapter(string $driver, string $file): \Prometheus\Storage\Adapter
    {
        $adapter = MetricsStorage::create($driver, null, $file);
        self::assertNotNull($adapter);

        return $adapter;
    }
}

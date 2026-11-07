<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

use PDO;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APCng;
use Prometheus\Storage\PDO as PdoStorage;
use Prometheus\Storage\Redis;

/**
 * Picks the shared storage for the request metrics.
 *
 * PHP-FPM workers and Apache prefork children share no memory, so a counter
 * kept in a PHP variable dies with the request. The request metrics need a
 * store every worker reaches, and promphp ships three that fit; HELMET only
 * chooses between them:
 *
 *  - "apcu":   APCu shared memory (promphp's APCng adapter). The fastest, no
 *              server. Shared by the workers of one PHP-FPM pool or one
 *              Apache, not across containers; cleared on restart.
 *  - "redis":  a Redis server (ext-redis). Shared across containers and
 *              restarts. Needs a Redis the app can reach.
 *  - "sqlite": a SQLite file through PDO (pdo_sqlite, in every official PHP
 *              image). No server and no extension to add; a write per
 *              request, so it suits a low-traffic site. One file per
 *              container; cleared when the file goes.
 *
 * Anything else (including null or "none") means "no request metrics".
 */
final class MetricsStorage
{
    /**
     * The storage names HELMET accepts.
     *
     * @var list<string>
     */
    public const array DRIVERS = ['apcu', 'redis', 'sqlite'];

    /**
     * The key prefix in APCu and the table prefix in SQLite, so HELMET's
     * counters cannot collide with an app's own promphp counters.
     */
    public const string PREFIX = 'helmet_metrics';

    /**
     * Whether a storage name turns the request metrics on.
     */
    public static function isOn(?string $driver): bool
    {
        return $driver !== null && in_array(strtolower($driver), self::DRIVERS, true);
    }

    /**
     * Build the storage adapter, or null when the name turns the request
     * metrics off.
     *
     * @param string|null $driver  "apcu", "redis", "sqlite", or null/"none".
     * @param string|null $redisUrl  For "redis": redis://[:password@]host[:port][/database].
     *        Defaults to redis://127.0.0.1:6379.
     * @param string|null $sqlitePath  For "sqlite": the database file.
     *        Defaults to helmet-metrics.sqlite in the system temp folder.
     *
     * @throws MetricsStorageException When the storage is named but cannot
     *         be used here (the extension is missing, the URL is bad, the
     *         file cannot be opened). The caller logs it and carries on
     *         without request metrics: a metrics problem never breaks a page.
     */
    public static function create(?string $driver, ?string $redisUrl = null, ?string $sqlitePath = null): ?Adapter
    {
        if (!self::isOn($driver)) {
            return null;
        }

        try {
            return match (strtolower((string) $driver)) {
                'apcu' => self::apcu(),
                'redis' => self::redis($redisUrl),
                default => self::sqlite($sqlitePath),
            };
        } catch (MetricsStorageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MetricsStorageException(
                sprintf('HELMET metrics storage "%s" is not usable: %s', $driver, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * The default SQLite file: one per machine (or container), in the temp
     * folder every platform lets PHP write to.
     */
    public static function defaultSqlitePath(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'helmet-metrics.sqlite';
    }

    private static function apcu(): Adapter
    {
        if (!extension_loaded('apcu')) {
            throw new MetricsStorageException('HELMET metrics storage "apcu" needs the APCu extension, which is not loaded.');
        }

        return new APCng(self::PREFIX);
    }

    private static function redis(?string $url): Adapter
    {
        if (!extension_loaded('redis')) {
            throw new MetricsStorageException('HELMET metrics storage "redis" needs the redis extension, which is not loaded.');
        }

        // promphp's key prefix is a global static (Redis::setPrefix), so
        // HELMET leaves it alone rather than move an app's own counters; the
        // helmet_ metric names already keep the keys apart.
        return new Redis(self::redisOptions($url ?? 'redis://127.0.0.1:6379'));
    }

    /**
     * The promphp Redis options for a redis:// URL.
     *
     * @return array{host: string, port: int, password: string|null, user: string|null, database?: int}
     *
     * @throws MetricsStorageException When the URL is not a redis:// URL with a host.
     */
    public static function redisOptions(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'redis' || ($parts['host'] ?? '') === '') {
            throw new MetricsStorageException('HELMET metrics redis_url must look like redis://[:password@]host[:port][/database].');
        }

        $options = [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 6379,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'user' => isset($parts['user']) && $parts['user'] !== '' ? rawurldecode($parts['user']) : null,
        ];

        $database = trim($parts['path'] ?? '', '/');
        if ($database !== '') {
            if (!ctype_digit($database)) {
                throw new MetricsStorageException('HELMET metrics redis_url database must be a number.');
            }
            $options['database'] = (int) $database;
        }

        return $options;
    }

    private static function sqlite(?string $path): Adapter
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new MetricsStorageException('HELMET metrics storage "sqlite" needs the pdo_sqlite extension, which is not loaded.');
        }

        $path = ($path === null || $path === '') ? self::defaultSqlitePath() : $path;
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Concurrent workers wait for the write lock instead of failing.
            PDO::ATTR_TIMEOUT => 2,
        ]);
        // WAL lets the scrape read while a worker writes.
        $pdo->exec('PRAGMA journal_mode=WAL');

        return new PdoStorage($pdo, self::PREFIX . '_');
    }
}

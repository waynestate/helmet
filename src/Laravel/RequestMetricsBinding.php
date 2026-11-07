<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Metrics\MetricsStorage;
use Waynestate\Helmet\Metrics\RequestMetrics;

use function Waynestate\Helmet\helmet_metrics_requests;

/**
 * The app's request-metrics recorder, built once from helmet.metrics.
 *
 * A container singleton that resolves to null is built again on every
 * make(), so the recorder (or the fact that there is none) is memoized here
 * instead. The middleware, the metrics controller and the CSP report
 * controller all ask this one object.
 */
final class RequestMetricsBinding
{
    private bool $resolved = false;

    private ?RequestMetrics $metrics = null;

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * Whether helmet.metrics turns the request metrics on: the endpoint is
     * enabled and a shared storage is named. Read at boot, so an app with
     * metrics off never gains the middleware.
     */
    public static function configured(): bool
    {
        $config = (array) config('helmet.metrics', []);

        return (bool) ($config['enabled'] ?? false)
            && MetricsStorage::isOn(is_string($config['storage'] ?? null) ? $config['storage'] : null);
    }

    /**
     * The recorder, or null when request metrics are off or the storage
     * cannot be used here (logged once).
     */
    public function get(): ?RequestMetrics
    {
        if ($this->resolved) {
            return $this->metrics;
        }
        $this->resolved = true;

        $config = (array) config('helmet.metrics', []);
        $this->metrics = helmet_metrics_requests([
            'enabled' => (bool) ($config['enabled'] ?? false),
            'storage' => is_string($config['storage'] ?? null) ? $config['storage'] : null,
            'redis_url' => is_string($config['redis_url'] ?? null) ? $config['redis_url'] : null,
            'sqlite_path' => is_string($config['sqlite_path'] ?? null) ? $config['sqlite_path'] : null,
            'logger' => $this->logger,
        ]);

        return $this->metrics;
    }
}

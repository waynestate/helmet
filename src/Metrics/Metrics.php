<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use OutOfBoundsException;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Waynestate\Helmet\Policy\PolicySource;
use Waynestate\Helmet\Policy\VersionStatus;

/**
 * Builds the HELMET /metrics payload.
 *
 * Framework-free: this class knows nothing about Laravel, raw PHP, or any web
 * framework. It builds a CollectorRegistry, registers the HELMET info metric
 * (and, when a policy source is given, the PHP version status metric), and
 * renders the result in the Prometheus text exposition format.
 *
 * The default storage adapter is InMemory, which is correct for a request
 * scope: each request builds its own registry and renders it once. Pass an
 * APCng adapter (see Prometheus\Storage\APCng) to share counters across
 * requests on a long-running process.
 */
final class Metrics
{
    /**
     * The Prometheus namespace for every HELMET metric.
     */
    private const NAMESPACE = 'helmet';

    private CollectorRegistry $registry;

    private RenderTextFormat $renderer;

    /**
     * @param Adapter|null $storage  The storage adapter. Defaults to InMemory.
     * @param PolicySource|null $policy  When given, the PHP version status
     *        metric is registered from it.
     * @param DateTimeInterface|null $now  The clock for the version status
     *        metric. Defaults to now.
     * @param string|null $version  The HELMET version for the info metric.
     *        Defaults to the installed package version.
     */
    public function __construct(
        ?Adapter $storage = null,
        ?PolicySource $policy = null,
        ?DateTimeInterface $now = null,
        ?string $version = null,
    ) {
        // registerDefaultMetrics is false: promphp's default php_info gauge
        // would duplicate the PHP version that helmet_info already carries.
        $this->registry = new CollectorRegistry($storage ?? new InMemory(), false);
        $this->renderer = new RenderTextFormat();

        $this->registerInfo($version ?? $this->installedVersion());

        if ($policy !== null) {
            $this->registerPhpVersionStatus($policy, $now ?? new DateTimeImmutable('now'));
        }
    }

    /**
     * The metrics in the Prometheus text exposition format.
     */
    public function render(): string
    {
        return $this->renderer->render($this->registry->getMetricFamilySamples());
    }

    /**
     * The Content-Type of the rendered metrics.
     */
    public static function mimeType(): string
    {
        return RenderTextFormat::MIME_TYPE;
    }

    /**
     * helmet_info: a gauge of 1, labelled with the PHP and HELMET versions.
     * Its presence (and labels) is what a scraper or operator wants to see.
     */
    private function registerInfo(string $version): void
    {
        $gauge = $this->registry->registerGauge(
            self::NAMESPACE,
            'info',
            'HELMET is running.',
            ['php_version', 'helmet_version'],
        );
        $gauge->set(1.0, [PHP_VERSION, $version]);
    }

    /**
     * helmet_php_version_status: a gauge of 1, labelled with the support
     * status of the running PHP version against the policy.
     */
    private function registerPhpVersionStatus(PolicySource $policy, DateTimeInterface $now): void
    {
        $status = (new VersionStatus($now, $policy->components()))->status('php', PHP_VERSION);
        $gauge = $this->registry->registerGauge(
            self::NAMESPACE,
            'php_version_status',
            'Support status of the running PHP version against the policy.',
            ['status'],
        );
        $gauge->set(1.0, [$status]);
    }

    /**
     * The installed package version, or "unknown" when it cannot be read.
     */
    private function installedVersion(): string
    {
        try {
            $version = InstalledVersions::getVersion('waynestate/helmet');
        } catch (OutOfBoundsException) {
            return 'unknown';
        }

        return $version ?? 'unknown';
    }
}

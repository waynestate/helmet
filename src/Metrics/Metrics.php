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
 * framework. It builds a CollectorRegistry of request-free gauges (the
 * HELMET info metric, the stack package versions and, when a policy source is
 * given, the PHP support status and end date), adds the request metrics from
 * shared storage when a RequestMetrics is given, and renders the result in
 * the Prometheus text exposition format.
 *
 * The request-free gauges always live in an InMemory registry built for this
 * scrape: they are facts about the running code, so they need no storage, and
 * a stale label (last week's PHP version) can never outlive a restart. Only
 * the counters that must add up across requests (RequestMetrics) live in
 * shared storage.
 */
final class Metrics
{
    /**
     * The Prometheus namespace for every HELMET metric.
     */
    private const NAMESPACE = 'helmet';

    /**
     * The package prefix wsu_stack_info reports on.
     */
    private const STACK_PREFIX = 'waynestate/';

    private CollectorRegistry $registry;

    private RenderTextFormat $renderer;

    /**
     * @param Adapter|null $storage  The storage for the request-free gauges.
     *        Defaults to InMemory, which is right for them.
     * @param PolicySource|null $policy  When given, the PHP version status and
     *        end-of-support metrics are registered from it.
     * @param DateTimeInterface|null $now  The clock for the version status
     *        metric. Defaults to now.
     * @param string|null $version  The HELMET version for the info metric.
     *        Defaults to the installed package version.
     * @param RequestMetrics|null $requests  The request metrics to add to the
     *        payload, read from their shared storage. null when request
     *        metrics are off.
     * @param array<string, string>|null $stackPackages  The stack packages
     *        and versions for wsu_stack_info. Defaults to every installed
     *        waynestate/* package.
     */
    public function __construct(
        ?Adapter $storage = null,
        ?PolicySource $policy = null,
        ?DateTimeInterface $now = null,
        ?string $version = null,
        private readonly ?RequestMetrics $requests = null,
        ?array $stackPackages = null,
    ) {
        // registerDefaultMetrics is false: promphp's default php_info gauge
        // would duplicate the PHP version that helmet_info already carries.
        $this->registry = new CollectorRegistry($storage ?? new InMemory(), false);
        $this->renderer = new RenderTextFormat();

        $this->registerInfo($version ?? $this->installedVersion());
        $this->registerStackInfo($stackPackages ?? $this->installedStackPackages());

        if ($policy !== null) {
            $this->registerPhpSupport($policy, $now ?? new DateTimeImmutable('now'));
        }
    }

    /**
     * The metrics in the Prometheus text exposition format.
     */
    public function render(): string
    {
        $requestSamples = [];
        if ($this->requests !== null) {
            // A storage outage must not take the info metrics down with it:
            // the scrape still answers, and helmet_metrics_storage_up says 0.
            $storageUp = 1.0;
            try {
                $requestSamples = $this->requests->samples();
            } catch (\Throwable) {
                $storageUp = 0.0;
            }
            $this->registry->getOrRegisterGauge(
                self::NAMESPACE,
                'metrics_storage_up',
                'Whether the request-metrics storage answered this scrape (1) or not (0).',
            )->set($storageUp);
        }

        return $this->renderer->render([...$this->registry->getMetricFamilySamples(), ...$requestSamples]);
    }

    /**
     * The Content-Type of the rendered metrics.
     */
    public static function mimeType(): string
    {
        return RenderTextFormat::MIME_TYPE;
    }

    /**
     * The policy key for the running PHP: its major.minor ("8.3"), which is
     * how CREED's policy names PHP versions.
     */
    public static function phpMinor(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
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
     * wsu_stack_info: a gauge of 1 per installed stack package, labelled with
     * the package and its version (ADR-0011), so one dashboard can map which
     * app runs which version of every stack part. Omitted when none are
     * installed.
     *
     * @param array<string, string> $packages
     */
    private function registerStackInfo(array $packages): void
    {
        if ($packages === []) {
            return;
        }

        ksort($packages);
        $gauge = $this->registry->registerGauge(
            'wsu',
            'stack_info',
            'A Wayne State stack package is installed.',
            ['package', 'version'],
        );
        foreach ($packages as $package => $version) {
            $gauge->set(1.0, [$package, $version]);
        }
    }

    /**
     * helmet_php_version_status: a gauge of 1, labelled with the support
     * status of the running PHP version against the policy.
     *
     * helmet_php_eol_timestamp_seconds: the end of security support for the
     * running PHP version, as a Unix timestamp. Prometheus convention is to
     * expose the date and let the query do the arithmetic, so "days left" is
     * (helmet_php_eol_timestamp_seconds - time()) / 86400. Omitted when the
     * policy does not know this PHP version.
     */
    private function registerPhpSupport(PolicySource $policy, DateTimeInterface $now): void
    {
        $versions = new VersionStatus($now, $policy->components());
        $minor = self::phpMinor();

        $status = $this->registry->registerGauge(
            self::NAMESPACE,
            'php_version_status',
            'Support status of the running PHP version against the policy.',
            ['status'],
        );
        $status->set(1.0, [$versions->status('php', $minor)]);

        $securityUntil = $versions->securityUntil('php', $minor);
        if ($securityUntil === null) {
            return;
        }

        $eol = $this->registry->registerGauge(
            self::NAMESPACE,
            'php_eol_timestamp_seconds',
            'End of security support for the running PHP version, from the policy (Unix time).',
            ['php_minor'],
        );
        $eol->set((float) $securityUntil->getTimestamp(), [$minor]);
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

    /**
     * Every installed waynestate/* package with its pretty version.
     *
     * @return array<string, string>
     */
    private function installedStackPackages(): array
    {
        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (!str_starts_with($package, self::STACK_PREFIX)) {
                continue;
            }
            $packages[$package] = InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        }

        return $packages;
    }
}

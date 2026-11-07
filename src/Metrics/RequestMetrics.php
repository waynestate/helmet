<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;

/**
 * The request metrics: counts and latency per route group, and CSP
 * violations per directive. Opt-in, and only ever on shared storage.
 *
 * PHP shares nothing between requests, so these counters live in a storage
 * adapter every worker can reach (see MetricsStorage): APCu, Redis or SQLite.
 * The /metrics scrape reads them back from the same storage.
 *
 * Every label is bounded by HELMET, never by the client. A route group is the
 * app's own name for a set of pages (a Laravel route name or URI template,
 * or what a raw PHP app passes), checked against a strict pattern; the
 * method is one of the standard verbs or OTHER; the status is its class
 * (2xx to 5xx); the CSP directive is one of the known directive names or
 * "other". A browser's report, a scanner's made-up method or a flood of
 * random paths can therefore never mint a new time series. A hat with a chin
 * strap: it stays on in a crowd.
 */
final class RequestMetrics
{
    /**
     * The Prometheus namespace for every HELMET metric.
     */
    private const NAMESPACE = 'helmet';

    /**
     * The latency buckets, in seconds: Prometheus' own defaults, which suit
     * a web request from a cached asset (5 ms) to a slow report (10 s).
     *
     * @var list<float>
     */
    public const array BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /**
     * The methods that keep their own label value; anything else is OTHER.
     *
     * @var list<string>
     */
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * The CSP directives a violation report can name (CSP Level 3, plus the
     * Trusted Types pair). Anything else counts as "other".
     *
     * @var list<string>
     */
    private const array DIRECTIVES = [
        'base-uri', 'child-src', 'connect-src', 'default-src', 'font-src', 'form-action', 'frame-ancestors',
        'frame-src', 'img-src', 'manifest-src', 'media-src', 'object-src', 'prefetch-src', 'require-trusted-types-for',
        'script-src', 'script-src-attr', 'script-src-elem', 'style-src', 'style-src-attr', 'style-src-elem',
        'trusted-types', 'worker-src',
    ];

    /**
     * A route group: lowercase letters, digits and . _ - / { } :, 1 to 64
     * characters. Laravel route names ("docs.show") and URI templates
     * ("docs/{page}") fit; free text does not.
     */
    private const string ROUTE_PATTERN = '/^[a-z0-9._\/{}:-]{1,64}$/';

    /**
     * The route group for a request that matched no route (a 404).
     */
    public const string UNMATCHED = 'unmatched';

    private readonly CollectorRegistry $registry;

    public function __construct(Adapter $storage)
    {
        $this->registry = new CollectorRegistry($storage, false);
    }

    /**
     * Record one finished request.
     *
     * @param string $route  The route group (see routeLabel()).
     * @param string $method  The HTTP method.
     * @param int $status  The response status code.
     * @param float $seconds  How long the request took, in seconds.
     */
    public function observe(string $route, string $method, int $status, float $seconds): void
    {
        $this->registry->getOrRegisterHistogram(
            self::NAMESPACE,
            'http_request_duration_seconds',
            'Time spent answering HTTP requests, by route group, method and status class. Its _count is the request count.',
            ['route', 'method', 'status'],
            self::BUCKETS,
        )->observe(max(0.0, $seconds), [
            self::routeLabel($route),
            self::methodLabel($method),
            self::statusLabel($status),
        ]);
    }

    /**
     * Count one CSP violation report.
     */
    public function cspViolation(string $directive): void
    {
        $this->registry->getOrRegisterCounter(
            self::NAMESPACE,
            'csp_violations_total',
            'Content-Security-Policy violation reports received, by directive.',
            ['directive'],
        )->inc([self::directiveLabel($directive)]);
    }

    /**
     * The request metrics, read back from storage, ready to render.
     *
     * @return list<MetricFamilySamples>
     */
    public function samples(): array
    {
        return array_values($this->registry->getMetricFamilySamples());
    }

    /**
     * The route label: the group when it fits the pattern, "other" when it
     * does not, so a bad value never becomes a time series.
     */
    public static function routeLabel(string $route): string
    {
        $route = strtolower(trim($route, " \t/"));
        if ($route === '') {
            return 'root';
        }

        return preg_match(self::ROUTE_PATTERN, $route) === 1 ? $route : 'other';
    }

    /**
     * The method label: a standard verb, or OTHER.
     */
    public static function methodLabel(string $method): string
    {
        $method = strtoupper($method);

        return in_array($method, self::METHODS, true) ? $method : 'OTHER';
    }

    /**
     * The status label: the status class, 1xx to 5xx, or "other" for a code
     * outside 100 to 599.
     */
    public static function statusLabel(int $status): string
    {
        return ($status >= 100 && $status <= 599) ? intdiv($status, 100) . 'xx' : 'other';
    }

    /**
     * The directive label: a known CSP directive, or "other". A report can
     * name the directive with its value ("script-src 'self'"), so only the
     * first word counts.
     */
    public static function directiveLabel(string $directive): string
    {
        $name = strtolower(explode(' ', trim($directive), 2)[0]);

        return in_array($name, self::DIRECTIVES, true) ? $name : 'other';
    }
}

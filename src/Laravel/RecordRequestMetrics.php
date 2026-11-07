<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * Counts and times every request for the metrics endpoint.
 *
 * The provider puts it at the front of the global middleware only when
 * helmet.metrics is enabled and names a shared storage, so an app with
 * metrics off runs exactly the middleware it ran before.
 *
 * It records in terminate(), after the response has gone to the client, so
 * the visitor never waits on a counter. The route group is the route's name,
 * or its URI template ("docs/{page}") when it has none, or "unmatched" for a
 * request no route took (a 404). The path itself is never a label: a scanner
 * walking a thousand made-up URLs adds no time series.
 */
final class RecordRequestMetrics
{
    /**
     * The request attribute that holds the start time when the server did
     * not provide REQUEST_TIME_FLOAT.
     */
    private const string STARTED_AT = 'helmet.metrics.started_at';

    public function __construct(private readonly RequestMetricsBinding $binding) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $metrics = $this->binding->get();
        if ($metrics === null) {
            return;
        }

        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        if (!is_float($startedAt) && !is_int($startedAt)) {
            $startedAt = $request->attributes->get(self::STARTED_AT, microtime(true));
        }

        try {
            $metrics->observe(
                self::routeGroup($request),
                $request->getMethod(),
                $response->getStatusCode(),
                microtime(true) - (float) $startedAt,
            );
        } catch (\Throwable $e) {
            Log::warning('HELMET could not record request metrics; the request was served.', [
                'event' => 'metrics_record_failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The route group for a request: the route's name, its URI template when
     * it has no name (or only the generated::... name route caching gives
     * it), or "unmatched".
     */
    public static function routeGroup(Request $request): string
    {
        $route = $request->route();
        if (!$route instanceof \Illuminate\Routing\Route) {
            return RequestMetrics::UNMATCHED;
        }

        $name = $route->getName();
        if (is_string($name) && $name !== '' && !str_starts_with($name, 'generated::')) {
            return $name;
        }

        return $route->uri();
    }
}

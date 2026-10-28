<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Waynestate\Helmet\Metrics\BearerToken;
use Waynestate\Helmet\Metrics\Metrics;
use Waynestate\Helmet\Metrics\MetricsGate;
use Waynestate\Helmet\Policy\PolicySource;
use Waynestate\Helmet\Proxy\ClientIp;
use Waynestate\Helmet\Proxy\IpAllowList;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * The endpoint that serves the HELMET metrics in the Prometheus text
 * exposition format.
 *
 * The route is not registered by the service provider. An app opts in by
 * calling Route::helmetMetrics() (see MetricsRoute), so the endpoint is off
 * unless the app wants it.
 *
 * The endpoint is fail-closed. When helmet.metrics.enabled is false (the
 * default), it answers 404 so the route is not advertised. When it is
 * enabled, a request is served only if it presents the configured bearer
 * token or comes from an allowed client IP; anything else is 403. When it is
 * enabled but neither a token nor an allow list is configured, it answers 404
 * and logs a warning: a misconfiguration must not serve metrics.
 *
 * The client IP is resolved through the helmet.proxies trusted-proxy list, so
 * a spoofed X-Forwarded-For from an untrusted peer cannot grant access. When
 * the app binds a PolicySource, the PHP version status metric is included.
 */
final class MetricsController
{
    public function __invoke(Request $request): Response
    {
        $config = config('helmet.metrics', []);
        $enabled = (bool) ($config['enabled'] ?? false);

        if (!$enabled) {
            return new Response('', 404);
        }

        /** @var list<string> $proxyList */
        $proxyList = (array) config('helmet.proxies', []);
        $proxies = new TrustedProxies($proxyList);
        $clientIp = ClientIp::resolve($request->server->all(), $proxies);
        $requestToken = BearerToken::from($request->headers->get('Authorization'));

        /** @var list<string> $allowList */
        $allowList = (array) ($config['allow'] ?? []);
        $gate = new MetricsGate(
            true,
            $config['token'] ?? null,
            new IpAllowList($allowList),
        );
        $decision = $gate->decide($requestToken, $clientIp);

        if ($decision->reason() === 'misconfigured') {
            Log::warning(
                'HELMET metrics endpoint is enabled but has neither a token nor an allow list; refusing to serve metrics.',
            );
        }

        if (!$decision->isAllowed()) {
            return new Response('', $decision->status());
        }

        $policy = app()->bound(PolicySource::class) ? app(PolicySource::class) : null;
        $metrics = new Metrics(null, $policy, null, null);

        return new Response($metrics->render(), 200, [
            'Content-Type' => Metrics::mimeType(),
        ]);
    }
}

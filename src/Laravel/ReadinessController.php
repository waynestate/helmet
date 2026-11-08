<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Headers\SecurityHeaders as CoreSecurityHeaders;
use Waynestate\Helmet\Health\Readiness;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * The readiness endpoint for a Laravel app (see ReadinessRoute).
 *
 * It runs HELMET's three checks against the app's own config (the header
 * middleware is registered and the header set builds, the trusted-proxy
 * count, debug mode in production) plus the app's named checks, and answers
 * 200 or 503 with the JSON report. A failing check's detail goes to the log,
 * never the body.
 */
final class ReadinessController
{
    public function __invoke(Request $request): Response
    {
        /** @var list<string> $proxyList */
        $proxyList = (array) config('helmet.proxies', []);
        $reportOnly = (bool) config('helmet.csp_report_only');

        $readiness = (new Readiness(app(LoggerInterface::class)))
            ->withHeaders(
                static fn(): array => SecurityHeaders::configuredHeaders(
                    CoreSecurityHeaders::mergePolicies(CoreSecurityHeaders::defaultCspPolicy(), (array) config('helmet.csp')),
                    CoreSecurityHeaders::generateNonce(),
                    false,
                ),
                $reportOnly,
                SecurityHeaders::styleSrcAttr(),
                $this->headersRegistered(),
            )
            ->withTrustedProxies(new TrustedProxies($proxyList))
            ->withDebugOutput(config('app.debug') === true && app()->environment('production'));

        $checks = $request->route()?->defaults[ReadinessRoute::CHECKS_DEFAULT] ?? [];
        foreach ((array) $checks as $name => $class) {
            $readiness = $readiness->withCheck((string) $name, static function () use ($class): ?bool {
                $check = app((string) $class);
                if (!is_callable($check)) {
                    throw new \LogicException('Readiness check ' . $class . ' is not invokable.');
                }
                $result = $check();

                return is_bool($result) ? $result : null;
            });
        }

        $answer = $readiness->response();

        return new Response($answer['body'], $answer['status'], $answer['headers']);
    }

    /**
     * Whether HELMET's header middleware is in the kernel: globally (scope
     * "global") or in the web group (scope "web").
     */
    private function headersRegistered(): bool
    {
        $kernel = app(Kernel::class);
        if (!$kernel instanceof FoundationKernel) {
            return true;
        }

        if ($kernel->hasMiddleware(SecurityHeaders::class)) {
            return true;
        }

        return in_array(SecurityHeaders::class, $kernel->getMiddlewareGroups()['web'] ?? [], true);
    }
}

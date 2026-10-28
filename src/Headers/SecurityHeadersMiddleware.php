<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Proxy\HttpsDetector;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * PSR-15 middleware that sends HELMET's security headers.
 *
 * It decides whether the request is HTTPS from the trusted-proxy list, builds
 * the headers (including a per-request CSP nonce), exposes the nonce to the
 * application as the "helmet.csp_nonce" request attribute, and removes the
 * X-Powered-By header.
 *
 * The headers are sent even when the inner handler throws. By default the
 * middleware rethrows so the framework's error handler still runs; when
 * rethrow is false it returns a 500 response carrying the same headers.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string, list<string>>
     */
    private readonly array $cspPolicy;

    /**
     * @param TrustedProxies $proxies
     * @param array<string, list<string>|null>|null $cspPolicy Merged per
     *        directive over the default policy. A directive set to null is
     *        removed. null (the default) uses the default policy unchanged.
     * @param bool $rethrow When the inner handler throws, rethrow so the
     *        framework's error handler runs (the default). When false, the
     *        middleware returns a 500 response with the security headers
     *        instead of propagating the exception.
     * @param ResponseFactoryInterface|null $responseFactory Used to build the
     *        500 response when rethrow is false. Only needed on that path.
     * @param int $hstsMaxAge The HSTS max-age in seconds, sent only on HTTPS.
     * @param bool $hstsIncludeSubDomains Whether HSTS covers subdomains, sent
     *        only on HTTPS.
     * @param string|null $cspReportUri The CSP report destination, or null to
     *        send no reporting directives.
     * @param bool $cspReportOnly When true, the policy is sent as
     *        Content-Security-Policy-Report-Only instead of
     *        Content-Security-Policy.
     * @param string|null $coop The Cross-Origin-Opener-Policy value, or null
     *        to send no COOP header.
     * @param string|null $corp The Cross-Origin-Resource-Policy value, or
     *        null to send no CORP header.
     * @param array<string, list<string>>|null $permissionsPolicy The
     *        Permissions-Policy feature map, or null to use the default deny
     *        list. An empty array sends no Permissions-Policy header.
     * @param bool $hstsPreload Whether to append the preload directive to
     *        HSTS, sent only on HTTPS.
     * @param bool $trustedTypesEnabled Whether to append the Trusted Types
     *        directives to the report-only CSP header.
     * @param list<string> $trustedTypesPolicies The Trusted Types policy
     *        names, used when $trustedTypesEnabled is true.
     * @param bool $noStoreEnabled Whether to send Cache-Control: no-store on
     *        matching responses.
     * @param list<string> $noStorePaths Path prefixes that carry no-store, or
     *        empty for every response.
     *
     * @throws \InvalidArgumentException If $coop, $corp, $permissionsPolicy or
     *         $trustedTypesPolicies is invalid.
     */
    public function __construct(
        private readonly TrustedProxies $proxies,
        ?array $cspPolicy = null,
        private readonly bool $rethrow = true,
        private readonly ?ResponseFactoryInterface $responseFactory = null,
        private readonly int $hstsMaxAge = 31536000,
        private readonly bool $hstsIncludeSubDomains = true,
        private readonly ?string $cspReportUri = null,
        private readonly bool $cspReportOnly = false,
        private readonly ?string $coop = 'same-origin',
        private readonly ?string $corp = null,
        private readonly ?array $permissionsPolicy = null,
        private readonly bool $hstsPreload = false,
        private readonly bool $trustedTypesEnabled = false,
        private readonly array $trustedTypesPolicies = ['default'],
        private readonly bool $noStoreEnabled = false,
        private readonly array $noStorePaths = [],
    ) {
        $this->cspPolicy = SecurityHeaders::mergePolicies(
            SecurityHeaders::defaultCspPolicy(),
            $cspPolicy ?? [],
        );

        // Validate the cross-origin isolation settings at boot, not per
        // request: a bad value is a config error, and it should fail where
        // the app starts, not on the first request.
        if ($coop !== null) {
            SecurityHeaders::buildCoop($coop);
        }
        if ($corp !== null) {
            SecurityHeaders::buildCorp($corp);
        }
        if ($permissionsPolicy !== null) {
            SecurityHeaders::buildPermissionsPolicy($permissionsPolicy);
        }

        if ($trustedTypesEnabled) {
            SecurityHeaders::buildTrustedTypes($trustedTypesPolicies);
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isHttps = HttpsDetector::isHttps($request->getServerParams(), $this->proxies);
        $nonce = SecurityHeaders::generateNonce();
        $headers = SecurityHeaders::build(
            $this->cspPolicy,
            $nonce,
            $isHttps,
            $this->hstsMaxAge,
            $this->hstsIncludeSubDomains,
            $this->cspReportUri,
            $this->cspReportOnly,
            $this->coop,
            $this->corp,
            $this->permissionsPolicy,
            $this->hstsPreload,
            $this->trustedTypesEnabled,
            $this->trustedTypesPolicies,
        );

        $request = $request->withAttribute('helmet.csp_nonce', $nonce);

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            if ($this->rethrow) {
                throw $e;
            }
            $response = $this->errorResponse();
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if (SecurityHeaders::noStoreApplies($this->noStoreEnabled, $request->getUri()->getPath(), $this->noStorePaths)
            && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response->withoutHeader('X-Powered-By');
    }

    /**
     * Build the 500 response returned when the inner handler throws and
     * rethrow is false.
     */
    private function errorResponse(): ResponseInterface
    {
        if ($this->responseFactory === null) {
            throw new \RuntimeException(
                'SecurityHeadersMiddleware needs a PSR-17 ResponseFactory to '
                . 'build the 500 response when rethrow is false.',
            );
        }

        return $this->responseFactory
            ->createResponse(500)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}

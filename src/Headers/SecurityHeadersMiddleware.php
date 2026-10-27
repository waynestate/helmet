<?php declare(strict_types=1);

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
     */
    public function __construct(
        private readonly TrustedProxies $proxies,
        ?array $cspPolicy = null,
        private readonly bool $rethrow = true,
        private readonly ?ResponseFactoryInterface $responseFactory = null,
        private readonly int $hstsMaxAge = 31536000,
        private readonly bool $hstsIncludeSubDomains = true
    ) {
        $this->cspPolicy = SecurityHeaders::mergePolicies(
            SecurityHeaders::defaultCspPolicy(),
            $cspPolicy ?? []
        );
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
            $this->hstsIncludeSubDomains
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
                . 'build the 500 response when rethrow is false.'
            );
        }

        return $this->responseFactory
            ->createResponse(500)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}

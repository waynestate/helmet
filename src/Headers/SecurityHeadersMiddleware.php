<?php declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

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
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private readonly array $cspPolicy;

    /**
     * @param TrustedProxies $proxies
     * @param array<string, list<string>|null>|null $cspPolicy Merged per
     *        directive over the default policy. A directive set to null is
     *        removed. null (the default) uses the default policy unchanged.
     */
    public function __construct(
        private readonly TrustedProxies $proxies,
        ?array $cspPolicy = null
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
        $headers = SecurityHeaders::build($this->cspPolicy, $nonce, $isHttps);

        $request = $request->withAttribute('helmet.csp_nonce', $nonce);

        $response = $handler->handle($request);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withoutHeader('X-Powered-By');
    }
}

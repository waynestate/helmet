<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Csrf;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Proxy\HttpsDetector;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * PSR-15 middleware that rejects cross-site request forgery.
 *
 * The guard is opt-in. Safe methods (GET, HEAD and OPTIONS) always pass. For
 * every other method it reads Sec-Fetch-Site first: same-origin and none pass,
 * same-site passes only when allowSameSite is true, and cross-site or an
 * unknown value is rejected. When Sec-Fetch-Site is absent it falls back to
 * the Origin header: the request's own origin and any configured trusted
 * origin pass, Origin: null is rejected, an unparseable origin is rejected,
 * and an absent origin passes (a non-browser client that authenticates some
 * other way).
 *
 * The rejection answer is 403, Content-Type: text/plain; charset=utf-8,
 * Cache-Control: no-store, body "Forbidden". It never echoes request headers.
 */
final class RequestForgeryGuard implements MiddlewareInterface
{
    /**
     * Methods that do not change state and therefore never need the check.
     *
     * @var list<string>
     */
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * The trusted origins, normalized to lowercase scheme://host:port form.
     *
     * @var list<string>
     */
    private readonly array $trustedOrigins;

    /**
     * The except patterns, exactly as configured.
     *
     * @var list<string>
     */
    private readonly array $except;

    /**
     * @param TrustedProxies $proxies The trusted proxies used to decide the
     *        request's own scheme (and therefore its own origin).
     * @param ResponseFactoryInterface|null $responseFactory Used to build the
     *        403 response when a request is rejected.
     * @param StreamFactoryInterface|null $streamFactory Used to build the
     *        403 response body when a request is rejected.
     * @param bool $allowSameSite When true, Sec-Fetch-Site: same-site passes.
     *        Default false, because same-site still includes sibling
     *        subdomains.
     * @param list<string> $trustedOrigins Origins that may make state-changing
     *        requests to this app. Each entry must be a valid http or https
     *        origin.
     * @param list<string> $except Paths that skip the guard. A pattern is
     *        either an exact path or a prefix ending in "*"; "*" matches
     *        every path.
     *
     * @throws \InvalidArgumentException If a trusted origin or except pattern
     *         is invalid.
     */
    public function __construct(
        private readonly TrustedProxies $proxies,
        private readonly ?ResponseFactoryInterface $responseFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
        private readonly bool $allowSameSite = false,
        array $trustedOrigins = [],
        array $except = [],
    ) {
        $this->trustedOrigins = $this->normalizeOrigins($trustedOrigins);
        $this->except = $this->normalizeExcept($except);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $secFetchSite = $request->getHeaderLine('Sec-Fetch-Site');
        $origin = $request->getHeaderLine('Origin');
        $requestOrigin = self::originFromServerParams($request->getServerParams(), $this->proxies);

        if ($this->allows(
            $method,
            $path,
            $secFetchSite !== '' ? $secFetchSite : null,
            $origin !== '' ? $origin : null,
            $requestOrigin,
        )) {
            return $handler->handle($request);
        }

        return $this->forbiddenResponse();
    }

    /**
     * Whether the request is allowed.
     *
     * This is the decision the raw PHP path and the Laravel adapter call, so
     * every consumer path applies the same rule.
     *
     * @param string $method The HTTP method, in any case.
     * @param string $path The request path, without the query string.
     * @param string|null $secFetchSite The Sec-Fetch-Site header, or null when
     *        absent.
     * @param string|null $origin The Origin header, or null when absent.
     * @param string|null $requestOrigin The request's own origin, or null when
     *        it cannot be determined.
     */
    public function allows(
        string $method,
        string $path,
        ?string $secFetchSite,
        ?string $origin,
        ?string $requestOrigin,
    ): bool {
        if (in_array(strtoupper($method), self::SAFE_METHODS, true)) {
            return true;
        }

        if ($this->isExcepted($path)) {
            return true;
        }

        if ($secFetchSite !== null && $secFetchSite !== '') {
            return match (strtolower($secFetchSite)) {
                'same-origin', 'none' => true,
                'same-site' => $this->allowSameSite,
                default => false,
            };
        }

        // No Sec-Fetch-Site: fall back to Origin. An absent Origin is a
        // non-browser client (curl, an API consumer, an old browser) that
        // authenticates some other way, so it passes.
        if ($origin === null || $origin === '') {
            return true;
        }

        // A literal "null" origin is a cross-site opaque request.
        if ($origin === 'null') {
            return false;
        }

        $canonicalOrigin = self::canonicalOrigin($origin);
        if ($canonicalOrigin === null) {
            return false;
        }

        if ($requestOrigin !== null && $canonicalOrigin === self::canonicalOrigin($requestOrigin)) {
            return true;
        }

        foreach ($this->trustedOrigins as $trustedOrigin) {
            if ($canonicalOrigin === $trustedOrigin) {
                return true;
            }
        }

        return false;
    }

    /**
     * The request's own origin from raw $_SERVER parameters.
     *
     * The host comes from HTTP_HOST and the scheme from the same trusted-proxy
     * HTTPS decision the security headers use, so a spoofed
     * X-Forwarded-Proto from an untrusted peer cannot change the origin.
     *
     * @param array<string, mixed> $serverParams The raw $_SERVER array.
     */
    public static function originFromServerParams(array $serverParams, TrustedProxies $proxies): ?string
    {
        $host = $serverParams['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '') {
            return null;
        }

        $scheme = HttpsDetector::isHttps($serverParams, $proxies) ? 'https' : 'http';

        return self::canonicalOrigin($scheme . '://' . $host);
    }

    /**
     * Normalize an origin to lowercase scheme://host:port form.
     *
     * Default ports are dropped (443 for https, 80 for http), so
     * "https://example.com" and "https://example.com:443" compare equal.
     * Returns null when the value is not a valid http or https origin.
     */
    public static function canonicalOrigin(string $origin): ?string
    {
        $parts = parse_url(trim($origin));
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        // An origin is scheme://host:port. Reject user info, a query, a
        // fragment, and anything but an empty or trailing-slash path, so a
        // URL with a path cannot silently become an origin.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $path = $parts['path'] ?? null;
        if ($path !== null && $path !== '' && $path !== '/') {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port < 1) {
            return null;
        }

        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * Whether the path matches one of the except patterns.
     */
    private function isExcepted(string $path): bool
    {
        foreach ($this->except as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);
                if ($prefix === '' || str_starts_with($path, $prefix)) {
                    return true;
                }

                continue;
            }

            if ($path === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize trusted origins to canonical form, throwing on invalid config.
     *
     * @param list<mixed> $origins
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeOrigins(array $origins): array
    {
        $normalized = [];
        foreach ($origins as $origin) {
            if (!is_string($origin) || $origin === '') {
                throw new \InvalidArgumentException(
                    'RequestForgeryGuard trusted origins must be non-empty strings.',
                );
            }

            $canonical = self::canonicalOrigin($origin);
            if ($canonical === null) {
                throw new \InvalidArgumentException(
                    "RequestForgeryGuard trusted origin \"$origin\" is not a valid http or https origin.",
                );
            }

            $normalized[] = $canonical;
        }

        return $normalized;
    }

    /**
     * Validate except patterns, throwing on invalid config.
     *
     * @param list<mixed> $patterns
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeExcept(array $patterns): array
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new \InvalidArgumentException(
                    'RequestForgeryGuard except patterns must be non-empty strings.',
                );
            }

            $normalized[] = $pattern;
        }

        return $normalized;
    }

    /**
     * Build the 403 response returned when a request is rejected.
     */
    private function forbiddenResponse(): ResponseInterface
    {
        if ($this->responseFactory === null || $this->streamFactory === null) {
            throw new \RuntimeException(
                'RequestForgeryGuard needs a PSR-17 ResponseFactory and StreamFactory '
                . 'to build the 403 response.',
            );
        }

        return $this->responseFactory
            ->createResponse(403)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream('Forbidden'));
    }
}

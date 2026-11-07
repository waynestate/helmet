<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

/**
 * Extracts a bearer token from an Authorization header value.
 *
 * The metrics endpoint accepts "Authorization: Bearer <token>". Anything that
 * is not a well-formed bearer header yields null, which the gate treats as
 * "no token presented" (and therefore not authorized by token).
 */
final class BearerToken
{
    /**
     * The bearer token, or null when the header is absent or not a bearer
     * header.
     */
    public static function from(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorizationHeader, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The bearer token of the current request in a framework-free app.
     *
     * Where the Authorization header lands depends on the server. PHP-FPM
     * and the built-in server put it in $_SERVER['HTTP_AUTHORIZATION']; a
     * rewrite that copies it (the classic .htaccess rule) leaves it in
     * REDIRECT_HTTP_AUTHORIZATION; Apache with mod_php (Citadel's images)
     * puts it in neither, and only getallheaders() has it. All three are
     * tried, in that order, so a scraper's token works on every one of them.
     *
     * @param array<array-key, mixed> $server  The server variables ($_SERVER).
     * @param array<array-key, mixed>|null $headers  The request headers.
     *        Defaults to getallheaders() when the server offers it.
     */
    public static function fromServer(array $server, ?array $headers = null): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (is_string($server[$key] ?? null) && $server[$key] !== '') {
                return self::from($server[$key]);
            }
        }

        if ($headers === null && function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        foreach ($headers ?? [] as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                return self::from($value);
            }
        }

        return null;
    }
}

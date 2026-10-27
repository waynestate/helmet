<?php declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

/**
 * Decides whether the current request arrived over HTTPS.
 *
 * The decision is made from the raw $_SERVER array so it works identically in
 * the framework-free boot path and in any adapter. The logic is:
 *
 *  1. If the SAPI reports a TLS connection (HTTPS set and not "off"), the
 *     request is HTTPS. This is the only signal that is trusted unconditionally.
 *  2. Otherwise, if the peer (REMOTE_ADDR) is a trusted proxy and that proxy
 *     forwarded X-Forwarded-Proto: https, the request is HTTPS.
 *
 * X-Forwarded-Port is deliberately never read: the Citadel HA-Proxy front sets
 * X-Forwarded-Proto and the port is always 443 on the TLS edge, so the port
 * header adds no information and only creates a spoofing surface.
 */
final class HttpsDetector
{
    /**
     * Whether the request arrived over HTTPS.
     *
     * @param array<string, mixed> $serverParams The raw $_SERVER array.
     */
    public static function isHttps(array $serverParams, TrustedProxies $proxies): bool
    {
        $https = $serverParams['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        if ($proxies->isEmpty()) {
            return false;
        }

        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddr) || $remoteAddr === '') {
            return false;
        }

        if (!$proxies->isTrusted($remoteAddr)) {
            return false;
        }

        $forwardedProto = $serverParams['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($forwardedProto) && strtolower($forwardedProto) === 'https') {
            return true;
        }

        return false;
    }
}

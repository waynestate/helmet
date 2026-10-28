<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

/**
 * Resolves the real client IP from the server params.
 *
 * When the peer (REMOTE_ADDR) is a trusted proxy, the client IP is read from
 * the X-Forwarded-For header. The header is a comma-separated list in which
 * the rightmost entry is the one the closest proxy recorded; walk from the
 * right, skipping trusted proxies and unparseable entries, and the first
 * remaining entry is the client. When the peer is not a trusted proxy, the
 * header is ignored entirely and REMOTE_ADDR is the client.
 *
 * This mirrors the trust-first posture of HttpsDetector: a forwarded header
 * from an untrusted peer is never believed.
 */
final class ClientIp
{
    /**
     * The client IP, or an empty string when REMOTE_ADDR is absent.
     *
     * @param array<string, mixed> $serverParams The $_SERVER array.
     */
    public static function resolve(array $serverParams, TrustedProxies $proxies): string
    {
        $remote = $serverParams['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || $remote === '') {
            return '';
        }

        if (!$proxies->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = $serverParams['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || $forwarded === '') {
            return $remote;
        }

        $entries = array_map('trim', explode(',', $forwarded));
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $entry = $entries[$i];
            if ($entry === '') {
                continue;
            }
            if (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if ($proxies->isTrusted($entry)) {
                continue;
            }

            return $entry;
        }

        return $remote;
    }
}

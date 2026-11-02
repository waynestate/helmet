<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

use Psr\Log\LoggerInterface;

/**
 * Resolves the real client IP from the server params.
 *
 * When the peer (REMOTE_ADDR) is a trusted proxy, the client IP is read from
 * the X-Forwarded-For header. The header is a comma-separated list in which
 * the rightmost entry is the one the closest proxy recorded; walk from the
 * right, skipping trusted proxies, and the first remaining entry is the
 * client. An entry that is not a valid IP address fails closed: the peer is
 * returned instead of continuing to an earlier entry, because an
 * unparseable entry may hide a real client behind it. IPv4-mapped IPv6
 * addresses (::ffff:a.b.c.d) are unmapped to a.b.c.d before matching, so a
 * dual-stack proxy still matches an IPv4 trust list. When the peer is not a
 * trusted proxy, the header is ignored entirely and REMOTE_ADDR is the
 * client.
 *
 * This mirrors the trust-first posture of HttpsDetector: a forwarded header
 * from an untrusted peer is never believed.
 */
final class ClientIp
{
    /**
     * The client IP, or an empty string when REMOTE_ADDR is absent.
     *
     * When a forwarded entry fails closed, a warning is logged (when a
     * logger is given) with the reason and the peer that already passed
     * isTrusted(). The header and the bad entry are never logged. A logger
     * that throws is ignored: it must never change the resolved IP.
     *
     * @param array<string, mixed> $serverParams The $_SERVER array.
     * @param LoggerInterface|null $logger Optional logger for the fail-closed
     *        event.
     */
    public static function resolve(array $serverParams, TrustedProxies $proxies, ?LoggerInterface $logger = null): string
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
                // Fail closed: an unparseable entry may hide a real client
                // behind it, so stop and use the peer instead of walking on
                // to an earlier entry.
                if ($logger !== null) {
                    try {
                        $logger->warning('helmet.forwarded_for_rejected', [
                            'event' => 'forwarded_for_rejected',
                            'reason' => 'unparseable_entry',
                            'peer' => $remote,
                        ]);
                    } catch (\Throwable) {
                        // A logging failure must never change the resolved IP.
                    }
                }

                return $remote;
            }
            $entry = TrustedProxies::unmapIpv4MappedIpv6($entry);
            if ($proxies->isTrusted($entry)) {
                continue;
            }

            return $entry;
        }

        return $remote;
    }
}

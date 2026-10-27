<?php declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

/**
 * Matches a client or peer IP address against a list of trusted proxies.
 *
 * The list may contain exact IPv4 addresses and IPv4 CIDR ranges. Only IPv4
 * is supported because the Citadel HA-Proxy front and the app containers
 * exchange IPv4 addresses.
 */
final class TrustedProxies
{
    /**
     * Exact IPv4 addresses that are trusted.
     *
     * @var list<string>
     */
    private array $exact = [];

    /**
     * CIDR ranges as inclusive [min, max] pairs of unsigned 32-bit integers.
     *
     * @var list<array{0: int, 1: int}>
     */
    private array $ranges = [];

    /**
     * @param list<string> $proxies Exact IPv4 addresses and/or IPv4 CIDR ranges.
     */
    public function __construct(array $proxies = [])
    {
        foreach ($proxies as $proxy) {
            if (!is_string($proxy) || $proxy === '') {
                continue;
            }

            if (str_contains($proxy, '/')) {
                $this->ranges[] = $this->parseCidr($proxy);
            } else {
                $this->exact[] = $proxy;
            }
        }
    }

    /**
     * Whether the given IP address is one of the trusted proxies.
     */
    public function isTrusted(string $ip): bool
    {
        if (in_array($ip, $this->exact, true)) {
            return true;
        }

        $ipAsLong = ip2long($ip);
        if ($ipAsLong === false) {
            return false;
        }

        foreach ($this->ranges as [$min, $max]) {
            if ($ipAsLong >= $min && $ipAsLong <= $max) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether no proxy is trusted. An empty list trusts nothing.
     */
    public function isEmpty(): bool
    {
        return $this->exact === [] && $this->ranges === [];
    }

    /**
     * Parse an IPv4 CIDR range into an inclusive [min, max] pair.
     *
     * @return array{0: int, 1: int}
     */
    private function parseCidr(string $cidr): array
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }

        // A /0 range covers every IPv4 address.
        if ($bits === 0) {
            return [0, 0xFFFFFFFF];
        }

        $subnetAsLong = ip2long($subnet);
        if ($subnetAsLong === false) {
            throw new \InvalidArgumentException("Invalid subnet address in \"$cidr\".");
        }

        // In 64-bit PHP a leading run of ones is the portable way to build a
        // 32-bit mask: the subnet is only 32 bits wide, so the upper bits of
        // the mask never affect the result.
        $mask = -1 << (32 - $bits);
        $min = $subnetAsLong & $mask;
        $max = $subnetAsLong | ~$mask;

        return [$min, $max];
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

/**
 * Matches a client or peer IP address against a list of trusted proxies.
 *
 * The list may contain exact IPv4 or IPv6 addresses and IPv4 or IPv6 CIDR
 * ranges. Every entry is validated at construction time: an entry that is not
 * a valid address or CIDR throws, a /0 prefix (which would trust every
 * address of its family) is refused, and the wildcard "*" is refused. A
 * silently skipped entry would look like working config while trusting
 * nothing, or the wrong thing.
 */
final class TrustedProxies
{
    /**
     * Exact trusted addresses, packed with inet_pton() (4 bytes for IPv4,
     * 16 for IPv6).
     *
     * @var list<string>
     */
    private array $exact = [];

    /**
     * Trusted CIDR ranges as [packed subnet, prefix length] pairs.
     *
     * @var list<array{0: string, 1: int}>
     */
    private array $ranges = [];

    /**
     * @param list<mixed> $proxies Exact IPv4/IPv6 addresses and/or IPv4/IPv6
     *                              CIDR ranges. Every entry must be a
     *                              non-empty string; anything else throws.
     *
     * @throws \InvalidArgumentException If an entry is not a valid address or
     *                                   CIDR, is a /0 range, or is "*".
     */
    public function __construct(array $proxies = [])
    {
        foreach ($proxies as $proxy) {
            if (!is_string($proxy) || $proxy === '') {
                throw new \InvalidArgumentException(
                    'Trusted proxy entries must be non-empty strings; got '
                    . get_debug_type($proxy) . '.',
                );
            }

            if ($proxy === '*') {
                throw new \InvalidArgumentException(
                    'The "*" entry trusts every peer and is not allowed.',
                );
            }

            if (str_contains($proxy, '/')) {
                $this->ranges[] = $this->parseCidr($proxy);
            } else {
                $packed = @inet_pton($proxy);
                if ($packed === false) {
                    throw new \InvalidArgumentException(
                        "Not a valid IPv4 or IPv6 address: \"$proxy\".",
                    );
                }
                $this->exact[] = $packed;
            }
        }
    }

    /**
     * Whether the given IP address is one of the trusted proxies.
     */
    public function isTrusted(string $ip): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        if (in_array($packedIp, $this->exact, true)) {
            return true;
        }

        foreach ($this->ranges as [$packedSubnet, $prefixLen]) {
            // An IPv4 address can never fall in an IPv6 range, or vice versa.
            if (strlen($packedIp) !== strlen($packedSubnet)) {
                continue;
            }

            $mask = self::buildMask($prefixLen, strlen($packedIp) * 8);
            if (($packedIp & $mask) === ($packedSubnet & $mask)) {
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
     * Parse an IPv4 or IPv6 CIDR range into a [packed subnet, prefix length]
     * pair.
     *
     * @return array{0: string, 1: int}
     *
     * @throws \InvalidArgumentException If the subnet is not a valid address
     *                                   or the prefix length is out of range
     *                                   for the address family. A /0 prefix
     *                                   is refused: it trusts every address.
     */
    private function parseCidr(string $cidr): array
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        if (!ctype_digit($bits)) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }

        $packedSubnet = @inet_pton($subnet);
        if ($packedSubnet === false) {
            throw new \InvalidArgumentException("Invalid subnet address in \"$cidr\".");
        }

        $prefixLen = (int) $bits;
        $familyBits = strlen($packedSubnet) * 8;

        if ($prefixLen > $familyBits) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }

        // A /0 range covers every address of the family.
        if ($prefixLen === 0) {
            throw new \InvalidArgumentException(
                "A /0 prefix trusts every address and is not allowed in \"$cidr\".",
            );
        }

        return [$packedSubnet, $prefixLen];
    }

    /**
     * Build a binary netmask: $prefixLen one bits, then zero bits, for a
     * family of $familyBits bits.
     */
    private static function buildMask(int $prefixLen, int $familyBits): string
    {
        $totalBytes = intdiv($familyBits, 8);
        $fullBytes = intdiv($prefixLen, 8);
        $remainingBits = $prefixLen % 8;

        $mask = str_repeat("\xff", $fullBytes);
        if ($remainingBits > 0) {
            $mask .= chr(0xff << (8 - $remainingBits));
        }

        return $mask . str_repeat("\x00", $totalBytes - strlen($mask));
    }
}

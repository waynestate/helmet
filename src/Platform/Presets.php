<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Platform;

/**
 * Platform presets: the one place in the package that knows what a
 * particular platform looks like.
 *
 * A preset is a named list of trusted proxies. The package itself never
 * hard-codes a platform: an app names a preset (or lists its own proxies),
 * and with neither, nothing is trusted. That is what keeps the same package
 * working on the WSU container platform, on plain Docker, and on OpenShift or
 * Coolify without a code change.
 *
 *  - "citadel": the WSU container platform. The app container talks to the
 *    HA-Proxy front through the Docker bridge gateway 172.18.0.1, the only
 *    address allowed to set X-Forwarded-Proto.
 *  - "docker": a plain Docker deployment with no front proxy in front of the
 *    app container. Nothing is trusted; HTTPS is detected from the direct
 *    connection only.
 *
 * The headers a trusted front proxy may set are the same for every preset:
 * X-Forwarded-For, X-Forwarded-Proto and X-Forwarded-Port. X-Forwarded-Host
 * and X-Forwarded-Prefix are deliberately not in the set: a platform that
 * rewrites the host (for example OpenShift, which appends the route host)
 * would let a client pre-seed those headers.
 */
final class Presets
{
    /**
     * The headers a trusted front proxy may set, for every preset.
     *
     * @var list<string>
     */
    public const TRUSTED_HEADERS = [
        'X-Forwarded-For',
        'X-Forwarded-Proto',
        'X-Forwarded-Port',
    ];

    /**
     * The known presets, by name.
     *
     * @var array<string, list<string>>
     */
    private const PROXIES = [
        'citadel' => ['172.18.0.1'],
        'docker' => [],
    ];

    /**
     * The trusted proxies for a preset.
     *
     * @throws UnknownPresetException If the preset is not known.
     */
    public static function proxies(string $name): array
    {
        if (!array_key_exists($name, self::PROXIES)) {
            throw new UnknownPresetException($name);
        }

        return self::PROXIES[$name];
    }

    /**
     * Whether a preset is known.
     */
    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::PROXIES);
    }

    /**
     * The known preset names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::PROXIES);
    }
}

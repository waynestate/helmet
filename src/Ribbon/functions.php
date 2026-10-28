<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Ribbon;

if (!function_exists('helmet_ribbon')) {
    /**
     * Create a ribbon for the given environment.
     *
     * This is a convenience helper for raw PHP apps. The returned Ribbon can
     * be rendered with its html() method and injected near the top of the
     * page.
     *
     * When a policy path is given, the ribbon also shows a version warning
     * for the running PHP version (PHP_MAJOR_VERSION.PHP_MINOR_VERSION) from
     * CREED's policy.json. A missing, unreadable or invalid policy file
     * produces no warning and never breaks the page.
     *
     * @param string $environment The environment name (for example "STAGING").
     * @param bool $enabled Whether the ribbon should be shown.
     * @param string|null $policyPath The path to a CREED policy.json, or null
     *        for no version warnings.
     */
    function helmet_ribbon(string $environment, bool $enabled = true, ?string $policyPath = null): Ribbon
    {
        return new Ribbon(
            $environment,
            $enabled,
            $policyPath,
            [['php', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION]],
        );
    }
}

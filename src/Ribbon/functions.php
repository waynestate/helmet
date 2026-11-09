<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Ribbon;

if (!function_exists('Waynestate\Helmet\Ribbon\helmet_ribbon')) {
    /**
     * Create a ribbon for the given environment.
     *
     * This is a convenience helper for raw PHP apps. The returned Ribbon can
     * be rendered with its html() method and injected near the top of the
     * page. It renders only for the non-production environments in
     * Ribbon::ENVIRONMENTS, the same list the Laravel adapter uses; for
     * production, an empty name or a name it does not know, html() returns
     * an empty string even when $enabled is true.
     *
     * When a policy path is given, the ribbon also shows a version warning
     * for the running PHP version (PHP_MAJOR_VERSION.PHP_MINOR_VERSION) from
     * CREED's policy.json. A missing, unreadable or invalid policy file
     * produces no warning and never breaks the page.
     *
     * @param string $environment The environment name (for example "STAGING").
     * @param bool $enabled Whether the ribbon may be shown at all (it still
     *        never shows outside Ribbon::ENVIRONMENTS).
     * @param string|null $policyPath The path to a CREED policy.json, or null
     *        for no version warnings.
     *
     * @deprecated Use Waynestate\Helmet\helmet_ribbon() instead.
     */
    function helmet_ribbon(string $environment, bool $enabled = true, ?string $policyPath = null): Ribbon
    {
        @trigger_error('Waynestate\Helmet\Ribbon\helmet_ribbon() is deprecated; use Waynestate\Helmet\helmet_ribbon().', E_USER_DEPRECATED);

        return \Waynestate\Helmet\helmet_ribbon($environment, $enabled, $policyPath);
    }
}

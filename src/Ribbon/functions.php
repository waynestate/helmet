<?php declare(strict_types=1);

namespace Waynestate\Helmet\Ribbon;

if (!function_exists('helmet_ribbon')) {
    /**
     * Create a ribbon for the given environment.
     *
     * This is a convenience helper for raw PHP apps. The returned Ribbon can
     * be rendered with its html() method and injected near the top of the
     * page.
     *
     * @param string $environment The environment name (for example "STAGING").
     * @param bool $enabled Whether the ribbon should be shown.
     */
    function helmet_ribbon(string $environment, bool $enabled = true): Ribbon
    {
        return new Ribbon($environment, $enabled);
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Session;

/**
 * Hardens PHP session settings before the session starts.
 *
 * Must be called before session_start(). It sets the settings that the
 * platform's shared php.ini leaves at insecure defaults:
 *
 *  - use_strict_mode: reject uninitialized session IDs (the platform default
 *    is 0, which accepts any ID a client sends).
 *  - use_only_cookies: only send the session ID in a cookie.
 *  - cookie_httponly: never expose the session cookie to JavaScript.
 *  - cookie_samesite: Lax, to reduce cross-site session reuse.
 *  - cookie_secure: only when the request is HTTPS, so the cookie is only
 *    sent over TLS.
 *
 * session.sid_length is deliberately not touched: it is deprecated as of
 * PHP 8.4 and the platform image sets it to 26. Changing it here would fight
 * the platform and is left to the Upgrade project.
 */
final class SessionHardening
{
    /**
     * Apply the hardened session settings.
     *
     * @param bool $https Whether the request is HTTPS; controls cookie_secure.
     * @param array<string, string>|null $settings Settings to apply. null
     *        (the default) applies the standard session settings for $https.
     *        An explicit list is accepted so the failure path can be tested.
     *
     * @throws \LogicException If a session is already active.
     * @throws \RuntimeException If a setting could not be changed.
     */
    public static function apply(bool $https, ?array $settings = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new \LogicException('Session hardening must be applied before the session starts.');
        }

        self::applySettings($settings ?? self::defaultSettings($https));
    }

    /**
     * The standard session settings, with cookie_secure only for HTTPS.
     *
     * @return array<string, string>
     */
    private static function defaultSettings(bool $https): array
    {
        $settings = [
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'Lax',
        ];

        if ($https) {
            $settings['session.cookie_secure'] = '1';
        }

        return $settings;
    }

    /**
     * Set each ini value, throwing if any of them cannot be changed.
     *
     * @param array<string, string> $settings
     *
     * @throws \RuntimeException Naming the first setting that could not be set.
     */
    private static function applySettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (ini_set($key, $value) === false) {
                throw new \RuntimeException("Session hardening could not set \"{$key}\".");
            }
        }
    }
}

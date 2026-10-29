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
 *  - cookie_secure: when the request is HTTPS (auto mode) or always
 *    (always mode), so the cookie is only sent over TLS.
 *  - cookie_lifetime: 0, a browser-session cookie that ends when the
 *    browser closes.
 *  - cache_limiter: nocache, so pages cannot be cached and replayed with
 *    the session attached.
 *  - cookie_path and cookie_domain: / and empty, so the cookie is host-only
 *    and the __Host- prefix below can be used.
 *  - name: __Host-WSUSESSID on HTTPS or WSUSESSID on HTTP, but only while
 *    the name is still PHP's default PHPSESSID. An app-chosen name is left
 *    alone; SESAME reads whatever session_name() returns, so a changed
 *    name is safe for it.
 *
 * session.sid_length is deliberately not touched: it is deprecated as of
 * PHP 8.4 and the platform image sets it to 26. Changing it here would fight
 * the platform and is left to the Upgrade project.
 */
final class SessionHardening
{
    /** cookie_secure follows the request: set only when it is HTTPS. */
    public const SECURE_AUTO = 'auto';

    /** cookie_secure is set even when the request is not HTTPS. */
    public const SECURE_ALWAYS = 'always';

    /**
     * Apply the hardened session settings.
     *
     * @param bool $https Whether the request is HTTPS; in auto mode it
     *        controls cookie_secure, and it always controls the cookie name.
     * @param array<string, string>|null $settings Settings to apply. null
     *        (the default) applies the standard session settings for $https
     *        and $secureMode. An explicit list is accepted so the failure
     *        path can be tested.
     * @param string $secureMode SECURE_AUTO (the default): cookie_secure
     *        follows $https. SECURE_ALWAYS: cookie_secure is set whether or
     *        not the request is HTTPS, for apps whose TLS always terminates
     *        in front of the app. The cookie name still follows $https, so
     *        a __Host- name never appears on a plain HTTP request.
     *
     * @throws \LogicException If a session is already active.
     * @throws \RuntimeException If a setting could not be changed.
     */
    public static function apply(bool $https, ?array $settings = null, string $secureMode = self::SECURE_AUTO): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new \LogicException('Session hardening must be applied before the session starts.');
        }

        self::applySettings($settings ?? self::defaultSettings($https, $secureMode));
    }

    /**
     * The standard session settings. In auto mode cookie_secure applies only
     * for HTTPS; in always mode it applies either way. The name is renamed
     * only from PHP's default, and only follows $https.
     *
     * @return array<string, string>
     */
    private static function defaultSettings(bool $https, string $secureMode): array
    {
        $settings = [
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'Lax',
            'session.cookie_lifetime' => '0',
            'session.cache_limiter' => 'nocache',
            'session.cookie_path' => '/',
            'session.cookie_domain' => '',
        ];

        if ($https || $secureMode === self::SECURE_ALWAYS) {
            $settings['session.cookie_secure'] = '1';
        }

        // Rename the cookie only while it still has PHP's default name, so
        // an app that chose its own name keeps it. The __Host- prefix needs
        // Secure, Path=/ and no Domain, all of which this method sets.
        if (ini_get('session.name') === 'PHPSESSID') {
            $settings['session.name'] = $https ? '__Host-WSUSESSID' : 'WSUSESSID';
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

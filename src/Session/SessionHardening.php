<?php declare(strict_types=1);

namespace Waynestate\Helmet\Session;

/**
 * Hardens PHP session settings before the session starts.
 *
 * Must be called before session_start(). It sets the settings that the
 * Citadel shared php.ini leaves at insecure defaults:
 *
 *  - use_strict_mode: reject uninitialized session IDs (the Citadel default
 *    is 0, which accepts any ID a client sends).
 *  - use_only_cookies: only send the session ID in a cookie.
 *  - cookie_httponly: never expose the session cookie to JavaScript.
 *  - cookie_samesite: Lax, to reduce cross-site session reuse.
 *  - cookie_secure: only when the request is HTTPS, so the cookie is only
 *    sent over TLS.
 *
 * session.sid_length is deliberately not touched: it is deprecated as of
 * PHP 8.4 and the Citadel image sets it to 26. Changing it here would fight
 * the platform and is left to the Upgrade project.
 */
final class SessionHardening
{
    /**
     * Apply the hardened session settings.
     *
     * @throws \LogicException If a session is already active.
     */
    public static function apply(bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new \LogicException('Session hardening must be applied before the session starts.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if ($https) {
            ini_set('session.cookie_secure', '1');
        }
    }
}

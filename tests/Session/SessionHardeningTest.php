<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Session;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Session\SessionHardening;

final class SessionHardeningTest extends TestCase
{
    /**
     * Reset the settings apply() touches so tests do not leak into each
     * other. use_only_cookies is left alone: turning it back off is
     * deprecated as of PHP 8.4, and no test needs it off.
     */
    protected function tearDown(): void
    {
        // A test may have started a session; end it so the session ini
        // values can be changed again.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ini_set('session.name', 'PHPSESSID');
        ini_set('session.use_strict_mode', '0');
        ini_set('session.cookie_httponly', '');
        ini_set('session.cookie_samesite', '');
        ini_set('session.cookie_secure', '0');
        ini_set('session.cookie_lifetime', '0');
        ini_set('session.cache_limiter', 'nocache');
        ini_set('session.cookie_path', '/');
        ini_set('session.cookie_domain', '');

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrowsWhenSessionActive(): void
    {
        // In a separate process no output has been sent, so a session can start.
        session_start();
        self::assertSame(PHP_SESSION_ACTIVE, session_status());

        $this->expectException(\LogicException::class);
        SessionHardening::apply(true);
    }

    public function testSetsHardenedIniValuesWhenHttps(): void
    {
        SessionHardening::apply(true);

        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('1', ini_get('session.cookie_httponly'));
        self::assertSame('Lax', ini_get('session.cookie_samesite'));
        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    public function testCookieSecureNotSetWhenNotHttps(): void
    {
        ini_set('session.cookie_secure', '0');
        SessionHardening::apply(false);

        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('1', ini_get('session.cookie_httponly'));
        self::assertSame('Lax', ini_get('session.cookie_samesite'));
        self::assertNotSame('1', ini_get('session.cookie_secure'));
    }

    public function testAlwaysModeSetsCookieSecureWhenNotHttps(): void
    {
        ini_set('session.cookie_secure', '0');
        SessionHardening::apply(false, null, SessionHardening::SECURE_ALWAYS);

        self::assertSame('1', ini_get('session.cookie_secure'));
        // The name still follows the request, so a plain HTTP request never
        // gets a __Host- name the browser would reject.
        self::assertNotSame('__Host-WSUSESSID', ini_get('session.name'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSetsNameLifetimePathAndDomainWhenHttps(): void
    {
        // A fresh process starts with PHP's default session name.
        self::assertSame('PHPSESSID', ini_get('session.name'));
        SessionHardening::apply(true);

        self::assertSame('__Host-WSUSESSID', ini_get('session.name'));
        self::assertSame('0', ini_get('session.cookie_lifetime'));
        self::assertSame('nocache', ini_get('session.cache_limiter'));
        self::assertSame('/', ini_get('session.cookie_path'));
        self::assertSame('', ini_get('session.cookie_domain'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSetsPlainNameWhenNotHttps(): void
    {
        SessionHardening::apply(false);

        self::assertSame('WSUSESSID', ini_get('session.name'));
        self::assertSame('0', ini_get('session.cookie_lifetime'));
        self::assertSame('nocache', ini_get('session.cache_limiter'));
        self::assertSame('/', ini_get('session.cookie_path'));
        self::assertSame('', ini_get('session.cookie_domain'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLeavesAnAppChosenNameAlone(): void
    {
        ini_set('session.name', 'appsid');
        SessionHardening::apply(true);

        self::assertSame('appsid', ini_get('session.name'));
        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    public function testSidLengthIsNotTouched(): void
    {
        $before = ini_get('session.sid_length');
        SessionHardening::apply(true);
        $after = ini_get('session.sid_length');

        self::assertSame($before, $after);
    }

    public function testThrowsNamingTheFailedSetting(): void
    {
        // disable_functions is PHP_INI_SYSTEM, so ini_set() cannot change it
        // at runtime and returns false.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disable_functions');
        SessionHardening::apply(false, ['disable_functions' => 'x']);
    }
}

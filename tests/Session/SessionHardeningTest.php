<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Session;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Session\SessionHardening;

final class SessionHardeningTest extends TestCase
{
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

    public function testSidLengthIsNotTouched(): void
    {
        $before = ini_get('session.sid_length');
        SessionHardening::apply(true);
        $after = ini_get('session.sid_length');

        self::assertSame($before, $after);
    }
}

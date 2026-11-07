<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Metrics\BearerToken;

final class BearerTokenTest extends TestCase
{
    public function testExtractsTheToken(): void
    {
        self::assertSame('secret', BearerToken::from('Bearer secret'));
    }

    public function testIsCaseInsensitiveOnTheScheme(): void
    {
        self::assertSame('secret', BearerToken::from('bearer secret'));
        self::assertSame('secret', BearerToken::from('BEARER secret'));
    }

    public function testAllowsMultipleSpacesAfterTheScheme(): void
    {
        self::assertSame('secret', BearerToken::from('Bearer    secret'));
    }

    public function testReturnsNullWhenTheHeaderIsAbsent(): void
    {
        self::assertNull(BearerToken::from(null));
    }

    public function testReturnsNullForAnEmptyHeader(): void
    {
        self::assertNull(BearerToken::from(''));
    }

    public function testReturnsNullForANonBearerScheme(): void
    {
        self::assertNull(BearerToken::from('Basic dXNlcjpwYXNz'));
    }

    public function testReturnsNullWhenThereIsNoToken(): void
    {
        self::assertNull(BearerToken::from('Bearer'));
        self::assertNull(BearerToken::from('Bearer '));
    }

    public function testReturnsNullWhenThereIsTrailingJunk(): void
    {
        self::assertNull(BearerToken::from('Bearer secret extra'));
    }

    public function testFromServerReadsHttpAuthorization(): void
    {
        self::assertSame('fpm', BearerToken::fromServer(['HTTP_AUTHORIZATION' => 'Bearer fpm'], []));
    }

    public function testFromServerReadsTheRedirectCopy(): void
    {
        self::assertSame('rewritten', BearerToken::fromServer(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer rewritten'], []));
    }

    /**
     * Apache with mod_php (Citadel's images) keeps the header out of
     * $_SERVER entirely; only getallheaders() has it. Seen in a php:8.5
     * Apache container: the scrape got 403 with the right token.
     */
    public function testFromServerFallsBackToTheRequestHeadersAnyCase(): void
    {
        self::assertSame('modphp', BearerToken::fromServer([], ['Host' => 'app', 'authorization' => 'Bearer modphp']));
    }

    public function testFromServerPrefersTheServerVariable(): void
    {
        self::assertSame('server', BearerToken::fromServer(
            ['HTTP_AUTHORIZATION' => 'Bearer server'],
            ['Authorization' => 'Bearer header'],
        ));
    }

    public function testFromServerIsNullWithNoHeaderAnywhere(): void
    {
        self::assertNull(BearerToken::fromServer(['HTTP_AUTHORIZATION' => ''], ['Host' => 'app']));
    }
}

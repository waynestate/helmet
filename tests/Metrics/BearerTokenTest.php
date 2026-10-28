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
}

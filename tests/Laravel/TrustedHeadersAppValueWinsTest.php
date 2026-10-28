<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use ReflectionProperty;

/**
 * An app that has already chosen its own trusted-header set (via
 * TrustProxies::withHeaders(), or trustProxies(..., headers: ...) in
 * bootstrap/app.php) keeps that choice: the provider only narrows the set
 * when nobody has set it yet.
 */
final class TrustedHeadersAppValueWinsTest extends TestCase
{
    protected function setUp(): void
    {
        // The app chose its own headers before HELMET booted, so the provider
        // must leave the static alone. It has to be set before parent::setUp()
        // because the provider runs while the application is being created.
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_HOST);

        parent::setUp();
    }

    public function testProviderDoesNotOverrideAppChosenHeaders(): void
    {
        $headers = (new ReflectionProperty(TrustProxies::class, 'alwaysTrustHeaders'))->getValue();

        self::assertSame(Request::HEADER_X_FORWARDED_HOST, $headers);
    }
}

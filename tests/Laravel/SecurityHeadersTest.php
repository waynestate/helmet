<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Helmet\Headers\SecurityHeaders as CoreSecurityHeaders;
use Waynestate\Helmet\Laravel\SecurityHeaders as LaravelSecurityHeaders;

final class SecurityHeadersTest extends TestCase
{
    /**
     * The CSP nonce captured from the request attribute by the route handler.
     */
    private ?string $capturedNonce = null;

    public function testWebResponseCarriesEveryCoreHeader(): void
    {
        $this->defineHtmlRoute('/test');

        $response = $this->get('/test');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');

        // HSTS is only sent on HTTPS, so it must be absent on plain HTTP.
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function testCspContainsANonceThatChangesBetweenRequests(): void
    {
        $this->defineHtmlRoute('/test');

        $first = $this->get('/test');
        $firstCsp = (string) $first->headers->get('Content-Security-Policy');
        $firstNonce = $this->nonceFromCsp($firstCsp);

        $second = $this->get('/test');
        $secondCsp = (string) $second->headers->get('Content-Security-Policy');
        $secondNonce = $this->nonceFromCsp($secondCsp);

        self::assertNotNull($firstNonce, 'The CSP header should contain a nonce.');
        self::assertNotNull($secondNonce, 'The CSP header should contain a nonce.');
        self::assertNotSame($firstNonce, $secondNonce, 'The CSP nonce should change between requests.');
        self::assertStringContainsString("nonce-{$firstNonce}", $firstCsp);
    }

    public function testHstsIsPresentOnHttpsAndAbsentOnHttp(): void
    {
        $this->defineHtmlRoute('/test');

        // Plain HTTP: no HSTS.
        $http = $this->get('/test');
        $http->assertHeaderMissing('Strict-Transport-Security');

        // HTTPS via a trusted proxy: HSTS present, with the configured max-age.
        config(['helmet.proxies' => ['10.0.0.1']]);
        $https = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $https->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function testHstsMaxAgeFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.proxies' => ['10.0.0.1'], 'helmet.hsts' => 600]);
        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $response->assertHeader('Strict-Transport-Security', 'max-age=600; includeSubDomains');
    }

    public function testHstsIsIgnoredFromAnUntrustedProxy(): void
    {
        $this->defineHtmlRoute('/test');

        // No trusted proxies: a forged X-Forwarded-Proto must not earn HSTS.
        config(['helmet.proxies' => []]);
        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function testCustomCspConfigShowsUpInTheHeader(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.csp' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", 'https://custom-csp-test.example'],
        ]]);

        $response = $this->get('/test');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        self::assertStringContainsString('https://custom-csp-test.example', $csp);
    }

    public function testCspConfigIsMergedOverTheDefaultPerDirective(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.csp' => [
            'img-src' => ["'self'", 'https://img-merge-test.example'],
            'form-action' => null,
        ]]);

        $response = $this->get('/test');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        // The directive that was set replaces the default's.
        self::assertStringContainsString('https://img-merge-test.example', $csp);
        // A directive left out keeps the default, so a partial policy cannot
        // quietly drop frame-ancestors or object-src.
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        // A directive set to null is removed, the same as in helmet_boot().
        self::assertStringNotContainsString('form-action', $csp);
    }

    /**
     * With csp_report_only on, the policy goes out as
     * Content-Security-Policy-Report-Only and the enforced header is absent.
     */
    public function testReportOnlyModeSendsTheReportOnlyHeader(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.csp_report_only' => true]);

        $response = $this->get('/test');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    /**
     * With a csp_report_uri set, the Reporting-Endpoints header names the
     * endpoint and the policy carries report-uri and report-to.
     */
    public function testReportUriSendsReportingEndpointsAndDirectives(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.csp_report_uri' => 'https://example.com/csp-report']);

        $response = $this->get('/test');

        $response->assertHeader('Reporting-Endpoints', 'csp-endpoint="https://example.com/csp-report"');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString('report-uri https://example.com/csp-report', $csp);
        self::assertStringContainsString('report-to csp-endpoint', $csp);
    }

    /**
     * With no csp_report_uri, no reporting headers or directives are sent.
     */
    public function testNoReportUriSendsNoReportingHeaders(): void
    {
        $this->defineHtmlRoute('/test');

        $response = $this->get('/test');

        $response->assertHeaderMissing('Reporting-Endpoints');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringNotContainsString('report-uri', $csp);
        self::assertStringNotContainsString('report-to', $csp);
    }

    public function testXPoweredByIsRemoved(): void
    {
        $this->app['router']->get('/test', static fn() => response('<html><body>hi</body></html>', 200, [
            'Content-Type' => 'text/html',
            'X-Powered-By' => 'PHP/8.3',
        ]))->middleware('web');

        $this->get('/test')->assertHeaderMissing('X-Powered-By');
    }

    public function testHeadersAreSentOnAnErrorPage(): void
    {
        $this->app['router']->get('/boom', static function (): never {
            throw new \RuntimeException('boom');
        })->middleware('web');

        $response = $this->get('/boom');

        $response->assertStatus(500);
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testRouteCanReadTheNonceThatTheHeaderCarries(): void
    {
        $this->defineNonceCapturingRoute('/test');

        $response = $this->get('/test');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($this->capturedNonce, 'The route should see the helmet.csp_nonce request attribute.');
        self::assertSame($nonceInHeader, $this->capturedNonce);
    }

    /**
     * The README tells Blade users to print the nonce from the request
     * attribute. This is that snippet, rendered, checked against the header.
     */
    public function testBladeTemplateCanPrintTheNonce(): void
    {
        $this->app['router']->get('/blade', static fn() => response(
            Blade::render(
                '<html><body><script nonce="{{ request()->attributes->get(\'helmet.csp_nonce\') }}">1;</script></body></html>',
            ),
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        $response = $this->get('/blade');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($nonceInHeader);
        self::assertStringContainsString('<script nonce="' . $nonceInHeader . '">', (string) $response->getContent());
    }

    /**
     * The middleware hands the nonce to Vite, so the @vite script tags carry
     * the same nonce the CSP header sends. The app does not call
     * Vite::useCspNonce() itself.
     */
    public function testViteTagsCarryTheNonce(): void
    {
        // A hot file makes @vite print dev-server tags without a build manifest.
        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->app['router']->get('/vite', static fn() => response(
                Blade::render(
                    "@vite(['resources/js/app.js'])",
                ),
                200,
                ['Content-Type' => 'text/html'],
            ))->middleware('web');

            $response = $this->get('/vite');
        } finally {
            unlink($hotFile);
        }

        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));
        self::assertNotNull($nonceInHeader);
        self::assertStringContainsString('src="http://localhost:5173/resources/js/app.js" nonce="' . $nonceInHeader . '"', (string) $response->getContent());
    }

    /**
     * The middleware hands the nonce to Vite, so Vite::cspNonce() inside a
     * route returns the same nonce the CSP header carries.
     */
    public function testViteCspNonceMatchesTheCspHeader(): void
    {
        $this->app['router']->get('/vite-nonce', static fn() => response(
            (string) Vite::cspNonce(),
            200,
            ['Content-Type' => 'text/plain'],
        ))->middleware('web');

        $response = $this->get('/vite-nonce');
        $body = (string) $response->getContent();

        self::assertNotSame('', $body, 'The body should be the Vite nonce.');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("'nonce-{$body}'", $csp);
    }

    /**
     * If a second package or the app changes the Vite nonce after HELMET set
     * it, the middleware logs a warning.
     */
    public function testWarningIsLoggedWhenTheViteNonceChanges(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message): bool {
                return str_contains($message, 'HELMET') && str_contains($message, 'Vite::useCspNonce');
            });

        $this->app['router']->get('/vite-mismatch', static function (): string {
            // A second package or the app changes the Vite nonce after HELMET
            // set it.
            Vite::useCspNonce('a-different-nonce');

            return 'ok';
        })->middleware('web');

        $this->get('/vite-mismatch');
    }

    /**
     * In the local environment with a hot file, the Vite dev server origin
     * is added to the CSP so the dev server's scripts, styles, fonts,
     * images and HMR websocket are allowed.
     */
    public function testDevOriginIsAddedInLocalWhenHot(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString('http://localhost:5173', $csp);
            self::assertStringContainsString('ws://localhost:5173', $csp);
            self::assertStringContainsString('worker-src blob:', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * In the production environment, the Vite dev server origin is never
     * added, even when a hot file exists.
     */
    public function testDevOriginIsNotAddedInProduction(): void
    {
        $this->app->detectEnvironment(static fn() => 'production');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('http://localhost:5173', $csp);
            self::assertStringNotContainsString('ws://localhost:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * An invalid dev origin (a non-local host) is never added, even in the
     * local environment.
     */
    public function testInvalidDevOriginIsNotAdded(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://evil.example:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('http://evil.example:5173', $csp);
            self::assertStringNotContainsString('ws://evil.example:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * config('helmet.dev_origin') takes precedence over the hot file
     * contents.
     */
    public function testDevOriginConfigOverridesHotFile(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.dev_origin' => 'http://127.0.0.1:3000']);

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString('http://127.0.0.1:3000', $csp);
            self::assertStringNotContainsString('http://localhost:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * An invalid dev origin logs a warning.
     */
    public function testInvalidDevOriginLogsAWarning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message): bool {
                return str_contains($message, 'HELMET') && str_contains($message, 'invalid Vite dev origin');
            });

        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://evil.example:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $this->get('/test');
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * A dev origin with port 0 is rejected: parse_url keeps the port and the
     * port check (port < 1) fails.
     */
    public function testDevOriginWithPortZeroIsRejected(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:0');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('http://localhost:0', $csp);
            self::assertStringNotContainsString('ws://localhost:0', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * A dev origin with port 65536 is rejected: parse_url returns false for
     * an out-of-range port, so the origin is never added.
     */
    public function testDevOriginWithPort65536IsRejected(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:65536');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('http://localhost:65536', $csp);
            self::assertStringNotContainsString('ws://localhost:65536', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * A dev origin with port 99999 is rejected: parse_url returns false for
     * an out-of-range port, so the origin is never added.
     */
    public function testDevOriginWithPort99999IsRejected(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:99999');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('http://localhost:99999', $csp);
            self::assertStringNotContainsString('ws://localhost:99999', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * A dev origin with a valid port (5173, Vite's default) is accepted and
     * added to the CSP.
     */
    public function testDevOriginWithPort5173IsAccepted(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString('http://localhost:5173', $csp);
            self::assertStringContainsString('ws://localhost:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * The middleware injects the csp-nonce meta tag into HTML responses,
     * right after the opening <head> tag. Vite's dev runtime reads that tag
     * to nonce the CSS and preload tags it adds after page load.
     */
    public function testHtmlResponseGetsTheCspNonceMetaTag(): void
    {
        $this->app['router']->get('/meta', static fn() => response(
            '<html><head><title>hi</title></head><body>hi</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        $response = $this->get('/meta');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($nonceInHeader);
        $body = (string) $response->getContent();
        self::assertStringContainsString(
            '<head><meta property="csp-nonce" nonce="' . $nonceInHeader . '"><title>hi</title></head>',
            $body,
        );
    }

    /**
     * The meta tag is injected after a <head> tag that carries attributes,
     * and the match is case-insensitive.
     */
    public function testCspNonceMetaTagHandlesHeadWithAttributesAndCase(): void
    {
        $this->app['router']->get('/meta-attrs', static fn() => response(
            '<HTML><HEAD lang="en"><title>hi</title></HEAD><BODY>hi</BODY></HTML>',
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        $response = $this->get('/meta-attrs');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($nonceInHeader);
        $body = (string) $response->getContent();
        self::assertStringContainsString(
            '<HEAD lang="en"><meta property="csp-nonce" nonce="' . $nonceInHeader . '"><title>hi</title></HEAD>',
            $body,
        );
    }

    /**
     * A JSON response is not HTML, so the middleware leaves its body
     * unchanged.
     */
    public function testJsonResponseIsNotModified(): void
    {
        $this->app['router']->get('/json', static fn() => response(
            '{"ok":true}',
            200,
            ['Content-Type' => 'application/json'],
        ))->middleware('web');

        $response = $this->get('/json');

        self::assertSame('{"ok":true}', (string) $response->getContent());
    }

    /**
     * An HTML body without a <head> tag is left unchanged. The ribbon is
     * disabled so the only middleware that could touch the body is the meta
     * tag injection.
     */
    public function testHtmlWithoutHeadIsNotModified(): void
    {
        config(['helmet.ribbon' => false]);
        $this->app['router']->get('/no-head', static fn() => response(
            '<html><body>hi</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        $response = $this->get('/no-head');

        self::assertSame('<html><body>hi</body></html>', (string) $response->getContent());
    }

    /**
     * @param array<string, list<string>|null>|null $cspConfig
     */
    #[DataProvider('headerConfigs')]
    public function testLaravelHeadersMatchCoreBuild(?array $cspConfig, bool $hstsIncludeSubDomains): void
    {
        if ($cspConfig !== null) {
            config(['helmet.csp' => $cspConfig]);
        }
        config([
            'helmet.proxies' => ['10.0.0.1'],
            'helmet.hsts_include_subdomains' => $hstsIncludeSubDomains,
        ]);

        // Call the middleware directly so the only headers on the response are
        // the ones HELMET sends (plus the two Symfony adds to every Response).
        $request = Request::create('/', 'GET', [], [], [], $this->httpsViaTrustedProxy());
        $response = (new LaravelSecurityHeaders())->handle($request, static fn() => new Response('ok'));

        $nonce = $request->attributes->get('helmet.csp_nonce');
        self::assertIsString($nonce, 'The nonce should be set as a request attribute.');

        $expected = CoreSecurityHeaders::build(
            CoreSecurityHeaders::mergePolicies(
                CoreSecurityHeaders::defaultCspPolicy(),
                (array) config('helmet.csp'),
            ),
            $nonce,
            true,
            (int) config('helmet.hsts'),
            $hstsIncludeSubDomains,
        );

        // The set of header names the middleware sends equals build()'s.
        $expectedNames = array_map('strtolower', array_keys($expected));
        $actualNames = array_diff($response->headers->keys(), ['cache-control', 'date']);
        sort($expectedNames);
        sort($actualNames);
        self::assertSame($expectedNames, array_values($actualNames));

        // The HSTS header and every other value equal build()'s.
        self::assertSame(
            $expected['Strict-Transport-Security'],
            $response->headers->get('Strict-Transport-Security'),
        );
        foreach ($expected as $name => $value) {
            self::assertSame($value, $response->headers->get($name), "Header mismatch: {$name}");
        }
    }

    public function testHstsIncludeSubDomainsFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config([
            'helmet.proxies' => ['10.0.0.1'],
            'helmet.hsts_include_subdomains' => false,
        ]);
        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function testHstsIncludeSubDomainsDefaultsToTrue(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.proxies' => ['10.0.0.1']]);
        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * @return array<string, array{array<string, list<string>|null>|null, bool}>
     */
    public static function headerConfigs(): array
    {
        return [
            'the default policy, includeSubDomains on' => [null, true],
            'the default policy, includeSubDomains off' => [null, false],
            'a partial policy with a removed directive, includeSubDomains on' => [[
                'img-src' => ["'self'", 'https://parity-test.example'],
                'form-action' => null,
            ], true],
        ];
    }

    /**
     * Register a GET route in the "web" group that returns simple HTML and
     * records the CSP nonce the middleware put on the request.
     */
    private function defineNonceCapturingRoute(string $uri): void
    {
        $this->app['router']->get($uri, function (Request $request) {
            $this->capturedNonce = $request->attributes->get('helmet.csp_nonce');

            return response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html']);
        })->middleware('web');
    }

    /**
     * Register a GET route in the "web" group that returns simple HTML.
     */
    private function defineHtmlRoute(string $uri): void
    {
        $this->app['router']->get($uri, static fn() => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html']))
            ->middleware('web');
    }

    /**
     * Extract the nonce from a Content-Security-Policy header value, or null.
     */
    private function nonceFromCsp(string $csp): ?string
    {
        // '#' as the delimiter: a base64 nonce can contain '/'.
        if (preg_match("#'nonce-([A-Za-z0-9+/=]+)'#", $csp, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}

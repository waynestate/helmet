<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
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

    public function testXPoweredByIsRemoved(): void
    {
        $this->app['router']->get('/test', static fn () => response('<html><body>hi</body></html>', 200, [
            'Content-Type' => 'text/html',
            'X-Powered-By' => 'PHP/8.3',
        ]))->middleware('web');

        $this->get('/test')->assertHeaderMissing('X-Powered-By');
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
        $this->app['router']->get('/blade', static fn () => response(
            Blade::render(
                '<html><body><script nonce="{{ request()->attributes->get(\'helmet.csp_nonce\') }}">1;</script></body></html>'
            ),
            200,
            ['Content-Type' => 'text/html']
        ))->middleware('web');

        $response = $this->get('/blade');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($nonceInHeader);
        self::assertStringContainsString('<script nonce="' . $nonceInHeader . '">', (string) $response->getContent());
    }

    /**
     * @param array<string, list<string>|null>|null $cspConfig
     */
    #[DataProvider('cspConfigs')]
    public function testLaravelHeadersMatchCoreBuild(?array $cspConfig): void
    {
        if ($cspConfig !== null) {
            config(['helmet.csp' => $cspConfig]);
        }
        config(['helmet.proxies' => ['10.0.0.1']]);

        // Call the middleware directly so the only headers on the response are
        // the ones HELMET sends (plus the two Symfony adds to every Response).
        $request = Request::create('/', 'GET', [], [], [], $this->httpsViaTrustedProxy());
        $response = (new LaravelSecurityHeaders())->handle($request, static fn () => new Response('ok'));

        $nonce = $request->attributes->get('helmet.csp_nonce');
        self::assertIsString($nonce, 'The nonce should be set as a request attribute.');

        $expected = CoreSecurityHeaders::build(
            CoreSecurityHeaders::mergePolicies(
                CoreSecurityHeaders::defaultCspPolicy(),
                (array) config('helmet.csp')
            ),
            $nonce,
            true,
            (int) config('helmet.hsts'),
            true
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
            $response->headers->get('Strict-Transport-Security')
        );
        foreach ($expected as $name => $value) {
            self::assertSame($value, $response->headers->get($name), "Header mismatch: {$name}");
        }
    }

    /**
     * @return array<string, array{array<string, list<string>|null>|null}>
     */
    public static function cspConfigs(): array
    {
        return [
            'the default policy' => [null],
            'a partial policy with a removed directive' => [[
                'img-src' => ["'self'", 'https://parity-test.example'],
                'form-action' => null,
            ]],
        ];
    }

    /**
     * Server variables for an HTTPS request that arrived through the trusted
     * proxy 10.0.0.1 (tests add that address to helmet.proxies).
     *
     * @return array<string, string>
     */
    private function httpsViaTrustedProxy(): array
    {
        return [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
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
        $this->app['router']->get($uri, static fn () => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html']))
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

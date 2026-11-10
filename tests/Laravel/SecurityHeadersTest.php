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
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

        // CORP is opt-in: the default sends no CORP header.
        $response->assertHeaderMissing('Cross-Origin-Resource-Policy');

        // HSTS is only sent on HTTPS, so it must be absent on plain HTTP.
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function testCoopFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.coop' => 'unsafe-none']);

        $response = $this->get('/test');

        $response->assertHeader('Cross-Origin-Opener-Policy', 'unsafe-none');
    }

    public function testCoopNullSendsNoCoopHeader(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.coop' => null]);

        $response = $this->get('/test');

        $response->assertHeaderMissing('Cross-Origin-Opener-Policy');
    }

    public function testCorpFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.corp' => 'same-site']);

        $response = $this->get('/test');

        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
    }

    public function testCorpDefaultSendsNoCorpHeader(): void
    {
        $this->defineHtmlRoute('/test');

        $response = $this->get('/test');

        $response->assertHeaderMissing('Cross-Origin-Resource-Policy');
    }

    public function testCoepFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.coep' => 'require-corp']);

        $response = $this->get('/test');

        $response->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
    }

    public function testPermissionsPolicyFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.permissions_policy' => ['camera' => ['self']]]);

        $response = $this->get('/test');

        $response->assertHeader('Permissions-Policy', 'camera=(self)');
    }

    public function testPermissionsPolicyEmptySendsNoHeader(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.permissions_policy' => []]);

        $response = $this->get('/test');

        $response->assertHeaderMissing('Permissions-Policy');
    }

    /**
     * An invalid COOP value is a configuration error. The middleware is called
     * directly (not through an HTTP request) so the exception propagates to
     * the test instead of being converted to a 500 response by the exception
     * handler.
     */
    public function testInvalidCoopConfigThrows(): void
    {
        config(['helmet.coop' => 'bogus']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COOP must be one of');

        $request = Request::create('/', 'GET');
        (new LaravelSecurityHeaders())->handle($request, static fn() => new Response('ok'));
    }

    public function testInvalidCorpConfigThrows(): void
    {
        config(['helmet.corp' => 'bogus']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CORP must be one of');

        $request = Request::create('/', 'GET');
        (new LaravelSecurityHeaders())->handle($request, static fn() => new Response('ok'));
    }

    public function testInvalidCoepConfigThrows(): void
    {
        config(['helmet.coep' => 'bogus']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COEP must be one of');

        $request = Request::create('/', 'GET');
        (new LaravelSecurityHeaders())->handle($request, static fn() => new Response('ok'));
    }

    public function testInvalidPermissionsPolicyConfigThrows(): void
    {
        config(['helmet.permissions_policy' => ['Camera' => []]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature name "Camera" is invalid');

        $request = Request::create('/', 'GET');
        (new LaravelSecurityHeaders())->handle($request, static fn() => new Response('ok'));
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

    /**
     * H14: an aborted request (403) must carry the baseline security headers,
     * not just a bare error page. The middleware sits outside the router, so
     * the exception handler renders the 403 body and the middleware still
     * stamps the headers on the way out.
     */
    public function testAbortedRequestCarriesTheBaselineHeaders(): void
    {
        $this->app['router']->get('/forbidden', static function (): never {
            abort(403);
        })->middleware('web');

        $response = $this->get('/forbidden');

        $response->assertStatus(403);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
    }

    /**
     * H14: under the default global scope the middleware runs before routing,
     * so even a 404 for a path with no matching route carries the baseline
     * headers. This is the documented reason to prefer the global scope over
     * the web group.
     */
    public function testUnmatchedRouteCarriesTheBaselineHeadersUnderGlobalScope(): void
    {
        $response = $this->get('/no-such-route');

        $response->assertStatus(404);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
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
     * A nonce that outlives its request is a nonce an attacker may have
     * seen. After a handled request, the Vite nonce is a fresh random one,
     * not the nonce the CSP header carried.
     */
    public function testTheViteNonceIsNotLeftBehindAfterARequest(): void
    {
        $this->defineHtmlRoute('/test');

        $response = $this->get('/test');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($nonceInHeader, 'The CSP header should contain a nonce.');

        $leftBehind = Vite::cspNonce();
        self::assertIsString($leftBehind, 'The reset Vite nonce should be a string.');
        self::assertNotSame('', $leftBehind, 'The reset Vite nonce should be a non-empty string.');
        self::assertNotSame($nonceInHeader, $leftBehind, 'The request nonce must not survive the request.');
    }

    /**
     * An exception from the inner handler propagates unchanged, and the
     * Vite nonce is still reset on the way out, so a failed request leaves
     * no stale nonce behind either.
     */
    public function testTheViteNonceIsResetWhenTheInnerHandlerThrows(): void
    {
        $capturedNonce = null;
        $exception = null;

        try {
            (new LaravelSecurityHeaders())->handle(Request::create('/', 'GET'), static function () use (&$capturedNonce): never {
                // Capture the nonce HELMET set for this request.
                $capturedNonce = Vite::cspNonce();
                throw new \RuntimeException('boom');
            });
            self::fail('The exception from the inner handler should propagate.');
        } catch (\Throwable $e) {
            $exception = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $exception, 'The exception from the inner handler should propagate unchanged.');
        self::assertSame('boom', $exception->getMessage());

        self::assertIsString($capturedNonce, 'The handler should see the nonce HELMET set for the request.');
        self::assertNotSame('', $capturedNonce, 'The captured nonce should be non-empty.');

        $leftBehind = Vite::cspNonce();
        self::assertIsString($leftBehind, 'The reset Vite nonce should be a string.');
        self::assertNotSame('', $leftBehind, 'The reset Vite nonce should be a non-empty string.');
        self::assertNotSame($capturedNonce, $leftBehind, 'The request nonce must not survive a failed request.');
    }

    /**
     * Two requests in a row carry different nonces in their CSP headers,
     * and the @vite tags each handler renders carry the nonce of that
     * request's header, not the other request's.
     */
    public function testEachRequestGetsItsOwnViteNonce(): void
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

            $first = $this->get('/vite');
            $second = $this->get('/vite');
        } finally {
            unlink($hotFile);
        }

        $firstNonce = $this->nonceFromCsp((string) $first->headers->get('Content-Security-Policy'));
        $secondNonce = $this->nonceFromCsp((string) $second->headers->get('Content-Security-Policy'));

        self::assertNotNull($firstNonce, 'The first CSP header should contain a nonce.');
        self::assertNotNull($secondNonce, 'The second CSP header should contain a nonce.');
        self::assertNotSame($firstNonce, $secondNonce, 'The two requests should carry different nonces.');

        // The @vite nonce rendered inside each handler equals that request's
        // header nonce.
        self::assertStringContainsString('src="http://localhost:5173/resources/js/app.js" nonce="' . $firstNonce . '"', (string) $first->getContent());
        self::assertStringContainsString('src="http://localhost:5173/resources/js/app.js" nonce="' . $secondNonce . '"', (string) $second->getContent());
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
            // worker-src already exists in the default policy, so the dev
            // origin appends to it instead of creating a new directive.
            self::assertStringContainsString("worker-src 'self' blob:", $csp);
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
     * A dev origin with a trailing slash is accepted and normalized to the
     * bare origin, so the CSP carries scheme://host[:port] without a
     * trailing slash.
     */
    public function testDevOriginBareTrailingSlashIsNormalized(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173/');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString('http://localhost:5173', $csp);
            self::assertStringNotContainsString('http://localhost:5173/', $csp);
            self::assertStringContainsString('ws://localhost:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bareOriginRejections(): array
    {
        return [
            'a path' => ['http://localhost:5173/app'],
            'a query' => ['http://localhost:5173?x=1'],
            'a fragment' => ['http://localhost:5173#a'],
            'userinfo' => ['http://user:pw@localhost:5173'],
        ];
    }

    /**
     * A dev origin that is not a bare origin (it carries a path, query,
     * fragment or userinfo) is ignored with the same warning as any other
     * invalid origin, because the raw string would land in the CSP header.
     */
    #[DataProvider('bareOriginRejections')]
    public function testDevOriginBareNonBareOriginsAreIgnoredWithAWarning(string $origin): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message): bool {
                return str_contains($message, 'HELMET') && str_contains($message, 'invalid Vite dev origin');
            });

        $this->app->detectEnvironment(static fn() => 'local');
        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, $origin);
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('localhost:5173', $csp);
            self::assertStringNotContainsString('ws://localhost:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * A directive the app removed from the policy is recreated only by
     * copying the default-src values first, so the default-src fallback for
     * it survives instead of being dropped.
     */
    public function testDevOriginDoesNotRecreateARemovedDirectiveWithoutDefaultSrcFallback(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.csp' => [
            'default-src' => ["'self'"],
            'font-src' => null,
        ]]);

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString("font-src 'self' http://localhost:5173", $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * When both font-src and default-src are absent from the policy, no
     * font-src directive is created: a bare origin-only directive would
     * drop the default-src fallback the browser applies.
     */
    public function testDevOriginDoesNotRecreateADirectiveWhenDefaultSrcIsAlsoAbsent(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.csp' => [
            'default-src' => null,
            'font-src' => null,
        ]]);

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringNotContainsString('font-src', $csp);
            // The origin still reaches the directives the policy keeps.
            self::assertStringContainsString("script-src 'self'", $csp);
            self::assertStringContainsString('http://localhost:5173', $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * A directive the app kept is appended to as before: the dev origin is
     * added to its existing sources.
     */
    public function testDevOriginDoesNotRecreateADirectiveThatIsPresent(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.csp' => [
            'font-src' => ["'self'", 'https://fonts.example'],
        ]]);

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString("font-src 'self' https://fonts.example http://localhost:5173", $csp);
        } finally {
            unlink($hotFile);
        }
    }

    /**
     * worker-src follows the same rule as every other directive: when the
     * app removed it but kept default-src, blob: is added to a copy of the
     * default-src values, not to a bare blob: directive.
     */
    public function testDevOriginDoesNotRecreateWorkerSrcWithoutDefaultSrcFallback(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.csp' => [
            'worker-src' => null,
        ]]);

        $hotFile = sys_get_temp_dir() . '/helmet-vite-hot-' . bin2hex(random_bytes(4));
        file_put_contents($hotFile, 'http://localhost:5173');
        Vite::useHotFile($hotFile);

        try {
            $this->defineHtmlRoute('/test');
            $response = $this->get('/test');
            $csp = (string) $response->headers->get('Content-Security-Policy');

            self::assertStringContainsString("worker-src 'self' blob:", $csp);
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
     * <header> and <headline> are not <head>. The old pattern /<head[^>]*>/i
     * matched them and would have injected the meta tag after the wrong tag.
     * An HTML document whose first tag is <header> and that has no <head>
     * must come back byte for byte unchanged. The ribbon is disabled so the
     * only middleware that could touch the body is the meta tag injection.
     */
    public function testHeaderAndHeadlineTagsAreNotMistakenForHead(): void
    {
        config(['helmet.ribbon' => false]);

        $headerBody = '<html><body><header class="top">nav</header><p>hi</p></body></html>';
        $this->app['router']->get('/header', static fn() => response(
            $headerBody,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        $headlineBody = '<html><body><headline>hi</headline></body></html>';
        $this->app['router']->get('/headline', static fn() => response(
            $headlineBody,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        self::assertSame($headerBody, (string) $this->get('/header')->getContent());
        self::assertSame($headlineBody, (string) $this->get('/headline')->getContent());
    }

    /**
     * Injecting the meta tag grows the body. A response that carries a
     * Content-Length header must have it refreshed to the new length, or the
     * client would cut the page off mid-tag.
     */
    public function testContentLengthIsRefreshedAfterMetaInjection(): void
    {
        config(['helmet.ribbon' => false]);

        $html = '<html><head><title>hi</title></head><body>hi</body></html>';
        $this->app['router']->get('/cl', static fn() => response($html, 200, [
            'Content-Type' => 'text/html',
            'Content-Length' => (string) strlen($html),
        ]))->middleware('web');

        $response = $this->get('/cl');
        $body = (string) $response->getContent();

        self::assertStringContainsString('<meta property="csp-nonce"', $body);
        self::assertSame(strlen($body), (int) $response->headers->get('Content-Length'));
    }

    /**
     * A literal "<head>" inside a script attribute is not a head tag. The
     * first <head> match sits inside an unclosed start tag, so the body is
     * left byte for byte unchanged. The ribbon is disabled so the only
     * middleware that could touch the body is the meta tag injection.
     */
    public function testNonceMetaSkipsAHeadInsideAScriptAttribute(): void
    {
        config(['helmet.ribbon' => false]);

        $body = '<html><body><script data-x="<head>">1;</script></body></html>';
        $this->app['router']->get('/meta-script', static fn() => response(
            $body,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        self::assertSame($body, (string) $this->get('/meta-script')->getContent());
    }

    /**
     * A literal "<head>" inside a comment is not a head tag, even when a
     * real <head> follows later in the document. The first match wins the
     * decision, it fails the markup check, and the body is left untouched
     * rather than hunted for a second match. The ribbon is disabled so the
     * only middleware that could touch the body is the meta tag injection.
     */
    public function testNonceMetaSkipsAHeadInsideAComment(): void
    {
        config(['helmet.ribbon' => false]);

        $body = '<html><!-- <head> --><head><title>hi</title></head><body>hi</body></html>';
        $this->app['router']->get('/meta-comment', static fn() => response(
            $body,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        self::assertSame($body, (string) $this->get('/meta-comment')->getContent());
    }

    /**
     * A literal "<head>" inside a textarea is not a head tag: textarea takes
     * its inner text verbatim until the matching close tag, so the first
     * match is skipped and the body is left unchanged. The ribbon is
     * disabled so the only middleware that could touch the body is the meta
     * tag injection.
     */
    public function testNonceMetaSkipsAHeadInsideATextarea(): void
    {
        config(['helmet.ribbon' => false]);

        $body = '<html><body><textarea><head></textarea></body></html>';
        $this->app['router']->get('/meta-textarea', static fn() => response(
            $body,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        self::assertSame($body, (string) $this->get('/meta-textarea')->getContent());
    }

    /**
     * A literal "<head>" inside a style block is not a head tag: style takes
     * its inner text verbatim until the matching close tag, so the first
     * match is skipped and the body is left unchanged. The ribbon is
     * disabled so the only middleware that could touch the body is the meta
     * tag injection.
     */
    public function testNonceMetaSkipsAHeadInsideAStyleBlock(): void
    {
        config(['helmet.ribbon' => false]);

        $body = '<html><body><style>.a { content: "<head>"; }</style></body></html>';
        $this->app['router']->get('/meta-style', static fn() => response(
            $body,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        self::assertSame($body, (string) $this->get('/meta-style')->getContent());
    }

    /**
     * A literal "<head>" inside a title element is not a head tag: title
     * takes its inner text verbatim until the matching close tag, so the
     * first match is skipped and the body is left unchanged. The ribbon is
     * disabled so the only middleware that could touch the body is the meta
     * tag injection.
     */
    public function testNonceMetaSkipsAHeadInsideATitle(): void
    {
        config(['helmet.ribbon' => false]);

        $body = '<html><body><title>foo <head> bar</title></body></html>';
        $this->app['router']->get('/meta-title', static fn() => response(
            $body,
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        self::assertSame($body, (string) $this->get('/meta-title')->getContent());
    }

    /**
     * Real markup before the <head> tag still gets the meta: a doctype, a
     * closed comment, and the <html> opening tag are all outside markup, so
     * the injection proceeds and the preceding text survives intact.
     */
    public function testNonceMetaStillInjectsWhenRealMarkupPrecedesTheHead(): void
    {
        $this->app['router']->get('/meta-doctype', static fn() => response(
            '<!DOCTYPE html><!-- generated --><html lang="en"><head><title>hi</title></head><body>hi</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ))->middleware('web');

        $response = $this->get('/meta-doctype');
        $nonceInHeader = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        self::assertNotNull($nonceInHeader);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('<!DOCTYPE html><!-- generated --><html lang="en">', $body);
        self::assertStringContainsString(
            '<head><meta property="csp-nonce" nonce="' . $nonceInHeader . '"><title>hi</title></head>',
            $body,
        );
    }

    /**
     * @param array<string, list<string>|null>|null $cspConfig
     * @param array<string, list<string>>|null $permissionsPolicy
     */
    #[DataProvider('headerConfigs')]
    public function testLaravelHeadersMatchCoreBuild(
        ?array $cspConfig,
        bool $hstsIncludeSubDomains,
        ?string $coop,
        ?string $corp,
        ?array $permissionsPolicy,
    ): void {
        if ($cspConfig !== null) {
            config(['helmet.csp' => $cspConfig]);
        }
        config([
            'helmet.proxies' => ['10.0.0.1'],
            'helmet.hsts_include_subdomains' => $hstsIncludeSubDomains,
            'helmet.coop' => $coop,
            'helmet.corp' => $corp,
            'helmet.permissions_policy' => $permissionsPolicy,
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
            null,
            false,
            $coop,
            $corp,
            $permissionsPolicy,
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
     * @return array<string, array{
     *     array<string, list<string>|null>|null,
     *     bool,
     *     string|null,
     *     string|null,
     *     array<string, list<string>>|null,
     * }>
     */
    public static function headerConfigs(): array
    {
        return [
            'the default policy, includeSubDomains on' => [null, true, 'same-origin', null, null],
            'the default policy, includeSubDomains off' => [null, false, 'same-origin', null, null],
            'a partial policy with a removed directive, includeSubDomains on' => [[
                'img-src' => ["'self'", 'https://parity-test.example'],
                'form-action' => null,
            ], true, 'same-origin', null, null],
            'a custom coop, corp and permissions policy' => [null, true, 'unsafe-none', 'same-site', ['camera' => ['self']]],
            'no coop, no corp, empty permissions policy' => [null, true, null, null, []],
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

    // ── HSTS preload ──────────────────────────────────────────────────────

    public function testHstsPreloadFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config([
            'helmet.proxies' => ['10.0.0.1'],
            'helmet.hsts_preload' => true,
        ]);
        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    public function testHstsPreloadDefaultsToFalse(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.proxies' => ['10.0.0.1']]);
        $response = $this->withServerVariables($this->httpsViaTrustedProxy())->get('/test');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function testHstsPreloadNotSentOnHttp(): void
    {
        $this->defineHtmlRoute('/test');

        config([
            'helmet.proxies' => ['10.0.0.1'],
            'helmet.hsts_preload' => true,
        ]);
        $response = $this->get('/test');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    // ── Trusted Types ─────────────────────────────────────────────────────

    public function testTrustedTypesFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config([
            'helmet.csp_report_only' => true,
            'helmet.csp_trusted_types' => [
                'enabled' => true,
                'policies' => ['default'],
            ],
        ]);
        $response = $this->get('/test');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        self::assertNotNull($csp);
        self::assertStringContainsString("require-trusted-types-for 'script'", $csp);
        self::assertStringContainsString("trusted-types 'default'", $csp);
    }

    public function testTrustedTypesNotAppendedToEnforcingCsp(): void
    {
        $this->defineHtmlRoute('/test');

        config([
            'helmet.csp_report_only' => false,
            'helmet.csp_trusted_types' => [
                'enabled' => true,
                'policies' => ['default'],
            ],
        ]);
        $response = $this->get('/test');

        $csp = $response->headers->get('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringNotContainsString('require-trusted-types-for', $csp);
    }

    public function testTrustedTypesDisabledByDefault(): void
    {
        $this->defineHtmlRoute('/test');

        config(['helmet.csp_report_only' => true]);
        $response = $this->get('/test');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        self::assertNotNull($csp);
        self::assertStringNotContainsString('require-trusted-types-for', $csp);
    }

    // ── no-store ──────────────────────────────────────────────────────────

    public function testNoStoreFollowsConfig(): void
    {
        $this->defineHtmlRoute('/test');

        config([
            'helmet.no_store' => [
                'enabled' => true,
                'paths' => [],
            ],
        ]);
        $response = $this->get('/test');

        // Laravel appends its own "private" directive to the Cache-Control
        // header during response preparation.
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function testNoStoreDisabledByDefault(): void
    {
        $this->defineHtmlRoute('/test');

        $response = $this->get('/test');

        // Laravel's default response carries no-cache, private; no-store must
        // not replace it when the feature is off.
        $response->assertHeader('Cache-Control', 'no-cache, private');
    }

    public function testNoStorePathMatchingSendsHeader(): void
    {
        $this->defineHtmlRoute('/api/data');

        config([
            'helmet.no_store' => [
                'enabled' => true,
                'paths' => ['/api'],
            ],
        ]);
        $response = $this->get('/api/data');

        // Laravel appends its own "private" directive to the Cache-Control
        // header during response preparation.
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function testNoStorePathNonMatchingKeepsDefaultCacheControl(): void
    {
        $this->defineHtmlRoute('/public');

        config([
            'helmet.no_store' => [
                'enabled' => true,
                'paths' => ['/api'],
            ],
        ]);
        $response = $this->get('/public');

        // The path does not match, so the framework default stays.
        $response->assertHeader('Cache-Control', 'no-cache, private');
    }

    // ── shared cache directives ───────────────────────────────────────────

    /**
     * A nonce page differs per request, so a shared cache must not store it.
     * An HTML response whose Cache-Control names public comes back with
     * private instead; Symfony's header bag sorts the directives, which is
     * why max-age leads the final value.
     */
    public function testSharedCacheDirectivesAreMadePrivateOnAnHtmlPage(): void
    {
        $this->app['router']->get('/cache-public', static fn() => response(
            '<html><head><title>hi</title></head><body>hi</body></html>',
            200,
            ['Content-Type' => 'text/html', 'Cache-Control' => 'public, max-age=60'],
        ))->middleware('web');

        $response = $this->get('/cache-public');

        $response->assertHeader('Cache-Control', 'max-age=60, private');
    }

    /**
     * s-maxage names a lifetime for shared caches only, so it is dropped
     * along with the public swap: the page may still be cached privately for
     * max-age, but a CDN or browser shared cache must not store it.
     */
    public function testSharedCacheDirectivesDropSMaxageOnAnHtmlPage(): void
    {
        $this->app['router']->get('/cache-smaxage', static fn() => response(
            '<html><head><title>hi</title></head><body>hi</body></html>',
            200,
            [
                'Content-Type' => 'text/html',
                'Cache-Control' => 'max-age=60, s-maxage=120, public',
            ],
        ))->middleware('web');

        $response = $this->get('/cache-smaxage');

        $response->assertHeader('Cache-Control', 'max-age=60, private');
    }

    /**
     * A Cache-Control that already forbids storage is left exactly as the
     * middleware received it. The ", private" suffix is Symfony's own
     * conservative addition to a header that names no visibility (the same
     * one the no-store tests above assert); the middleware did not touch the
     * header.
     */
    public function testSharedCacheDirectivesLeaveNoStoreUntouched(): void
    {
        $this->app['router']->get('/cache-nostore', static fn() => response(
            '<html><head><title>hi</title></head><body>hi</body></html>',
            200,
            ['Content-Type' => 'text/html', 'Cache-Control' => 'no-store'],
        ))->middleware('web');

        $response = $this->get('/cache-nostore');

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Only HTML responses carry the injected nonce meta and the CSP nonce
     * header that makes the page unique per request, so non-HTML responses
     * keep their Cache-Control exactly as written.
     */
    public function testSharedCacheDirectivesKeepPublicOnANonHtmlResponse(): void
    {
        $this->app['router']->get('/cache-json', static fn() => response(
            '{"ok":true}',
            200,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'public'],
        ))->middleware('web');

        $response = $this->get('/cache-json');

        $response->assertHeader('Cache-Control', 'public');
    }

    /**
     * A Cache-Control without public or s-maxage is not shared-cacheable and
     * is left alone. The ", private" suffix is Symfony's own conservative
     * addition to a header that names no visibility, not the middleware's.
     */
    public function testSharedCacheDirectivesLeaveNonSharedHeadersUntouched(): void
    {
        $this->app['router']->get('/cache-maxage', static fn() => response(
            '<html><head><title>hi</title></head><body>hi</body></html>',
            200,
            ['Content-Type' => 'text/html', 'Cache-Control' => 'max-age=60'],
        ))->middleware('web');

        $response = $this->get('/cache-maxage');

        $response->assertHeader('Cache-Control', 'max-age=60, private');
    }

    // ── Clear-Site-Data passthrough ───────────────────────────────────────

    /**
     * SESAME's logout response sends Clear-Site-Data through HELMET's
     * middleware, so the app's header must come out byte for byte unchanged
     * instead of being stripped, replaced or merged. The app's Cache-Control
     * is left alone too, and HELMET's own headers are still added.
     */
    public function testClearSiteDataFromTheAppIsLeftAlone(): void
    {
        $this->app['router']->get('/logout', static fn() => response(
            '<html><head><title>bye</title></head><body>bye</body></html>',
            200,
            [
                'Content-Type' => 'text/html',
                'Clear-Site-Data' => '"cache", "storage"',
                'Cache-Control' => 'no-store',
            ],
        ))->middleware('web');

        $response = $this->get('/logout');

        // The app's Clear-Site-Data header passes through untouched.
        $response->assertHeader('Clear-Site-Data', '"cache", "storage"');
        // The app's Cache-Control passes through untouched. The ", private"
        // suffix is Laravel's own conservative addition to a header that names
        // no visibility, the same one the no-store tests above assert; the
        // middleware did not touch the header.
        $response->assertHeader('Cache-Control', 'no-store, private');
        // HELMET's own headers are still added alongside.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
    }
}

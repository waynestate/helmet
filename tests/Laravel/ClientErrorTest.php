<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Waynestate\Helmet\Error\GenericErrorResponse;

/**
 * H42 for Laravel: with helmet.error_pages on, a 4xx HTTP exception is
 * answered with HELMET's fixed client-error page; with it off (the
 * default) Laravel answers as it always did. Validation, authentication
 * redirects, JSON clients and every 5xx stay with Laravel either way.
 */
final class ClientErrorTest extends TestCase
{
    private const SECRET = 'secret-detail-do-not-leak';

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/missing', static fn() => abort(404, self::SECRET));
        $router->get('/denied', static fn() => abort(403, self::SECRET));
        $router->post('/post-only', static fn() => 'posted');
        $router->get('/server-fault', static fn() => abort(503, self::SECRET));
        $router->get('/crash', static function (): never {
            throw new \RuntimeException(self::SECRET);
        });
        $router->post('/validate', static fn(Request $request) => $request->validate(['name' => 'required']));
        $router->get('/guarded', static function (): never {
            throw new AuthenticationException();
        });
        $router->get('/login', static fn() => 'login')->name('login');
    }

    private function fixedBody(int $status): string
    {
        return (new GenericErrorResponse())->clientError($status)['body'];
    }

    /**
     * HELMET's own SecurityHeaders middleware adds the csp-nonce meta tag to
     * every HTML page, this one included. It carries a fresh random nonce and
     * nothing from the request, so it is taken out before the page is
     * compared with the fixed body.
     */
    private function withoutNonceMeta(string|false $content): string
    {
        return (string) preg_replace('#<meta property="csp-nonce" nonce="[A-Za-z0-9+/=]+">#', '', (string) $content, 1);
    }

    public function testErrorPagesAreOffByDefault(): void
    {
        self::assertFalse(config('helmet.error_pages'));

        $response = $this->get('/missing');

        $response->assertStatus(404);
        self::assertNotSame($this->fixedBody(404), $this->withoutNonceMeta($response->getContent()));
    }

    public function testErrorPagesFollowTheEnvironmentVariable(): void
    {
        $this->putEnv('HELMET_ERROR_PAGES', 'true');

        try {
            $this->reloadPackageConfig();
            self::assertTrue(config('helmet.error_pages'));
        } finally {
            $this->clearEnv('HELMET_ERROR_PAGES');
        }

        $this->reloadPackageConfig();
        self::assertFalse(config('helmet.error_pages'));
    }

    public function testNotFoundGetsTheFixedPageWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->get('/missing');

        $response->assertStatus(404);
        self::assertSame($this->fixedBody(404), $this->withoutNonceMeta($response->getContent()));
        self::assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testAnUnknownRouteGetsTheFixedPageWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->get('/no-such-page');

        $response->assertStatus(404);
        self::assertSame($this->fixedBody(404), $this->withoutNonceMeta($response->getContent()));
    }

    public function testForbiddenHidesTheAbortMessageWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->get('/denied');

        $response->assertStatus(403);
        self::assertSame($this->fixedBody(403), $this->withoutNonceMeta($response->getContent()));
        self::assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    /**
     * A wrong method gets the fixed 405 page and keeps the Allow header the
     * framework set, because the protocol wants it.
     */
    public function testMethodNotAllowedGetsTheFixedPageAndKeepsAllowWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->get('/post-only');

        $response->assertStatus(405);
        self::assertSame($this->fixedBody(405), $this->withoutNonceMeta($response->getContent()));
        self::assertStringContainsString('POST', (string) $response->headers->get('Allow'));
    }

    public function testAJsonClientKeepsLaravelsAnswerWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->getJson('/missing');

        $response->assertStatus(404);
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertNotSame($this->fixedBody(404), $this->withoutNonceMeta($response->getContent()));
    }

    public function testAServerFaultStaysWithLaravelWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $abort = $this->get('/server-fault');
        $abort->assertStatus(503);
        self::assertStringNotContainsString('<title>Bad request.</title>', (string) $abort->getContent());

        $crash = $this->get('/crash');
        $crash->assertStatus(500);
        self::assertStringNotContainsString('<title>Bad request.</title>', (string) $crash->getContent());
    }

    public function testValidationStillGivesLaravels422WhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->postJson('/validate', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function testValidationStillRedirectsBackForAFormWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->from('/form')->post('/validate', []);

        $response->assertRedirect('/form');
    }

    public function testAuthenticationStillRedirectsToLoginWhenOn(): void
    {
        $this->app['config']->set('helmet.error_pages', true);

        $response = $this->get('/guarded');

        $response->assertRedirect('/login');
    }
}

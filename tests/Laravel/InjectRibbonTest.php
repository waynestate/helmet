<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

final class InjectRibbonTest extends TestCase
{
    private const HTML = '<html><head><title>t</title></head><body><p>hello</p></body></html>';

    public function testRibbonIsInjectedAfterBodyInLocal(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');
        $content = $response->getContent();

        $expected = '<div class="helmet-ribbon" data-environment="local">local</div>';
        self::assertStringContainsString($expected, $content);
        // The ribbon sits right after the opening <body> tag.
        self::assertStringContainsString('<body>' . $expected, $content);
    }

    public function testRibbonIsInjectedAfterABodyTagWithAttributes(): void
    {
        $this->app->detectEnvironment(static fn() => 'staging');
        $this->defineHtmlRoute('/test', '<html><body class="home" data-page="1"><p>hello</p></body></html>');

        $response = $this->get('/test');

        self::assertStringContainsString(
            '<body class="home" data-page="1"><div class="helmet-ribbon" data-environment="staging">staging</div>',
            (string) $response->getContent(),
        );
    }

    public function testRibbonIsAbsentInProduction(): void
    {
        $this->app->detectEnvironment(static fn() => 'production');
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');
        $nonce = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        $expected = '<html><head><meta property="csp-nonce" nonce="' . $nonce . '"><title>t</title></head><body><p>hello</p></body></html>';
        self::assertSame($expected, $response->getContent());
    }

    public function testRibbonIsAbsentWhenConfigIsDisabled(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.ribbon' => false]);
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');
        $nonce = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        $expected = '<html><head><meta property="csp-nonce" nonce="' . $nonce . '"><title>t</title></head><body><p>hello</p></body></html>';
        self::assertSame($expected, $response->getContent());
    }

    /**
     * When a policy file is configured, the ribbon shows a version warning for
     * the running PHP version. The policy file puts the running PHP version in
     * its end-of-life phase, so the warning is deterministic regardless of the
     * current date.
     */
    public function testRibbonShowsVersionWarningWhenPolicyIsConfigured(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $policy = [
            'schema_version' => 1,
            'components' => [
                'php' => [
                    $phpVersion => [
                        'active_until' => '2020-01-01',
                        'security_until' => '2020-01-01',
                    ],
                ],
            ],
        ];
        $path = tempnam(sys_get_temp_dir(), 'helmet-policy-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($policy));

        try {
            config(['helmet.policy_path' => $path]);
            $this->defineHtmlRoute('/test', self::HTML);

            $response = $this->get('/test');
            $content = (string) $response->getContent();

            self::assertStringContainsString(
                '<div class="helmet-ribbon-warning">PHP ' . $phpVersion . ' is end of life and no longer receives security updates. Upgrade to a supported version.</div>',
                $content,
            );
        } finally {
            unlink($path);
        }
    }

    /**
     * With no policy file configured, the ribbon shows no version warning.
     */
    public function testRibbonShowsNoVersionWarningWithoutPolicy(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        config(['helmet.policy_path' => null]);
        $this->defineHtmlRoute('/test', self::HTML);

        $response = $this->get('/test');
        $content = (string) $response->getContent();

        self::assertStringContainsString('<div class="helmet-ribbon" data-environment="local">local</div>', $content);
        self::assertStringNotContainsString('helmet-ribbon-warning', $content);
    }

    public function testJsonResponseIsUnchanged(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        $json = json_encode(['ok' => true]);
        $this->app['router']->get('/test', static fn() => response($json, 200, ['Content-Type' => 'application/json']))
            ->middleware('web');

        $response = $this->get('/test');

        self::assertSame($json, $response->getContent());
    }

    public function testHtmlWithoutBodyIsUnchanged(): void
    {
        $this->app->detectEnvironment(static fn() => 'local');
        $html = '<html><head><title>t</title></head></html>';
        $this->defineHtmlRoute('/test', $html);

        $response = $this->get('/test');
        $nonce = $this->nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

        $expected = '<html><head><meta property="csp-nonce" nonce="' . $nonce . '"><title>t</title></head></html>';
        self::assertSame($expected, $response->getContent());
    }

    /**
     * Register a GET route in the "web" group that returns the given HTML.
     */
    private function defineHtmlRoute(string $uri, string $html): void
    {
        $this->app['router']->get($uri, static fn() => response($html, 200, ['Content-Type' => 'text/html']))
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

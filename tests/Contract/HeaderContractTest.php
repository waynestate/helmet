<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Contract;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Proxy\TrustedProxies;
use Waynestate\Helmet\Tests\Laravel\TestCase;

/**
 * One header contract across every consumer path.
 *
 * The same request and the same config run through all four paths that ship
 * HELMET headers:
 *
 *  1. raw PHP: helmet_boot() inside a php -S child process, probed over real
 *     HTTP (the technique from FunctionsTest::testBootHealthPathSendsNoPoweredByHeader);
 *  2. PSR-15: SecurityHeadersMiddleware with nyholm/psr7;
 *  3. Laravel web group: an HTML page through Testbench;
 *  4. Laravel JSON: an Inertia-style XHR answer via response()->json().
 *
 * The contract, asserted identically for paths 1 to 3 on an HTML response
 * over HTTPS (path 4 asserts the same header set, which applies to non-HTML
 * answers too):
 *
 *  - Content-Security-Policy carries script-src with 'nonce-<value>' and
 *    'strict-dynamic', plus base-uri 'self', object-src 'none',
 *    form-action 'self' and frame-ancestors 'self'; the nonce differs
 *    between two requests;
 *  - Strict-Transport-Security with max-age=31536000 on HTTPS, absent on
 *    plain HTTP;
 *  - X-Content-Type-Options: nosniff, Referrer-Policy:
 *    strict-origin-when-cross-origin, Cross-Origin-Opener-Policy:
 *    same-origin, a Permissions-Policy header, and X-Frame-Options as the
 *    builder sends it;
 *  - no X-Powered-By.
 *
 * Header names and values are compared after replacing the nonce with a
 * fixed token, so the three HTML paths must be byte-equal apart from the
 * nonce. The expected values come from SecurityHeaders::build() itself, not
 * from guesses. Headers a path legitimately adds on top (Laravel's
 * Cache-Control, Date and friends) are left alone: only the contract set is
 * compared.
 */
final class HeaderContractTest extends TestCase
{
    /**
     * The fixed token the per-request nonce is replaced with before any
     * comparison, so the paths can be compared byte for byte.
     */
    private const string FIXED_NONCE = 'contract';

    /**
     * The contract header names, lowercase. Everything else a path sends
     * (Cache-Control, Date, Content-Type, ...) is out of scope for this
     * contract and left alone.
     *
     * @var list<string>
     */
    private const array CONTRACT_HEADERS = [
        'content-security-policy',
        'cross-origin-opener-policy',
        'permissions-policy',
        'referrer-policy',
        'strict-transport-security',
        'x-content-type-options',
        'x-frame-options',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // CSPBuilder reads $_SERVER['HTTPS'] in whichever process compiles
        // the policy. Keep it unset so the expected value computed here
        // matches what the raw PHP child process compiles (its probe is
        // plain HTTP; X-Forwarded-Proto is only a header).
        unset($_SERVER['HTTPS']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }

    public function testAllFourConsumerPathsAgreeOverHttps(): void
    {
        config(['helmet.proxies' => ['127.0.0.1']]);
        $this->defineHtmlRoute('/contract-html');
        $this->defineJsonRoute('/contract-json');

        // Path 1: raw PHP. helmet_boot() runs in a php -S child process and
        // the probes below go over real HTTP from a trusted peer.
        $raw = $this->startRawServer();
        try {
            $rawFirst = $this->probe($raw['port'], ['X-Forwarded-Proto' => 'https']);
            $rawSecond = $this->probe($raw['port'], ['X-Forwarded-Proto' => 'https']);
        } finally {
            $this->stopRawServer($raw);
        }

        // Path 2: PSR-15 middleware with nyholm/psr7.
        $psrServerParams = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $psrFirst = $this->psr15Headers($psrServerParams);
        $psrSecond = $this->psr15Headers($psrServerParams);

        // Paths 3 and 4: the Laravel web group, HTML and JSON.
        $laravelFirst = $this->laravelHeaders('/contract-html', true);
        $laravelSecond = $this->laravelHeaders('/contract-html', true);
        $json = $this->laravelHeaders('/contract-json', true);

        // Every path satisfies the contract on its own...
        $this->assertContract('raw PHP', $rawFirst['headers'], true);
        $this->assertContract('PSR-15', $psrFirst, true);
        $this->assertContract('Laravel HTML', $laravelFirst, true);
        $this->assertContract('Laravel JSON', $json, true);

        // ...and the three HTML paths agree with each other byte for byte
        // once the nonce is replaced with the fixed token.
        $rawSubset = $this->contractSubset($this->normalize($rawFirst['headers']));
        $psrSubset = $this->contractSubset($this->normalize($psrFirst));
        $laravelSubset = $this->contractSubset($this->normalize($laravelFirst));

        self::assertSame($rawSubset, $psrSubset, 'raw PHP and PSR-15 disagree on the contract headers.');
        self::assertSame($rawSubset, $laravelSubset, 'raw PHP and Laravel disagree on the contract headers.');

        // The nonce differs between two requests on every path.
        self::assertNotSame(
            $this->nonceFromCsp((string) ($rawFirst['headers']['content-security-policy'] ?? '')),
            $this->nonceFromCsp((string) ($rawSecond['headers']['content-security-policy'] ?? '')),
            'The raw PHP nonce did not change between two requests.',
        );
        self::assertNotSame(
            $this->nonceFromCsp((string) ($psrFirst['content-security-policy'] ?? '')),
            $this->nonceFromCsp((string) ($psrSecond['content-security-policy'] ?? '')),
            'The PSR-15 nonce did not change between two requests.',
        );
        self::assertNotSame(
            $this->nonceFromCsp((string) ($laravelFirst['content-security-policy'] ?? '')),
            $this->nonceFromCsp((string) ($laravelSecond['content-security-policy'] ?? '')),
            'The Laravel nonce did not change between two requests.',
        );
        self::assertNotSame(
            $this->nonceFromCsp((string) ($laravelFirst['content-security-policy'] ?? '')),
            $this->nonceFromCsp((string) ($json['content-security-policy'] ?? '')),
            'The Laravel nonce did not change between the HTML and the JSON request.',
        );
    }

    public function testAllFourConsumerPathsAgreeOnPlainHttpWithoutHsts(): void
    {
        config(['helmet.proxies' => ['127.0.0.1']]);
        $this->defineHtmlRoute('/contract-html');
        $this->defineJsonRoute('/contract-json');

        $raw = $this->startRawServer();
        try {
            $rawFirst = $this->probe($raw['port'], []);
        } finally {
            $this->stopRawServer($raw);
        }

        $psrFirst = $this->psr15Headers(['REMOTE_ADDR' => '127.0.0.1']);
        $laravelFirst = $this->laravelHeaders('/contract-html', false);
        $json = $this->laravelHeaders('/contract-json', false);

        $this->assertContract('raw PHP', $rawFirst['headers'], false);
        $this->assertContract('PSR-15', $psrFirst, false);
        $this->assertContract('Laravel HTML', $laravelFirst, false);
        $this->assertContract('Laravel JSON', $json, false);

        // HSTS is an HTTPS-only header: every path must leave it off.
        foreach ([
            'raw PHP' => $rawFirst['headers'],
            'PSR-15' => $psrFirst,
            'Laravel HTML' => $laravelFirst,
            'Laravel JSON' => $json,
        ] as $label => $headers) {
            self::assertArrayNotHasKey(
                'strict-transport-security',
                $this->normalize($headers),
                "{$label} sent Strict-Transport-Security on plain HTTP.",
            );
        }

        $rawSubset = $this->contractSubset($this->normalize($rawFirst['headers']));
        self::assertSame($rawSubset, $this->contractSubset($this->normalize($psrFirst)), 'raw PHP and PSR-15 disagree on the contract headers.');
        self::assertSame($rawSubset, $this->contractSubset($this->normalize($laravelFirst)), 'raw PHP and Laravel disagree on the contract headers.');
    }

    public function testJsonResponseKeepsItsBodyAndCarriesTheContractHeaders(): void
    {
        config(['helmet.proxies' => ['127.0.0.1']]);
        $this->defineJsonRoute('/contract-json');

        $payload = ['ok' => true, 'items' => [1, 2]];
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/contract-json');

        // The JSON body is untouched: no nonce meta tag is injected into a
        // non-HTML answer, and the payload round-trips exactly.
        self::assertSame($payload, json_decode((string) $response->getContent(), true));
        self::assertStringNotContainsString('csp-nonce', (string) $response->getContent());

        $this->assertContract('Laravel JSON', $this->headerMap($response), true);
    }

    /**
     * The contract expectations, straight from the builder: the subset of
     * SecurityHeaders::build() output whose names are contract names.
     *
     * @return array<string, string>
     */
    private function expectedContract(bool $isHttps): array
    {
        $built = SecurityHeaders::build(SecurityHeaders::defaultCspPolicy(), self::FIXED_NONCE, $isHttps);
        $expected = [];

        foreach ($built as $name => $value) {
            if (in_array(strtolower($name), self::CONTRACT_HEADERS, true)) {
                $expected[strtolower($name)] = $value;
            }
        }

        return $expected;
    }

    /**
     * Assert one path against the contract: every contract header the
     * builder sends is present with the exact builder value (after nonce
     * normalization), and X-Powered-By is gone.
     *
     * @param array<string, string> $actualHeaders
     */
    private function assertContract(string $label, array $actualHeaders, bool $isHttps): void
    {
        $expected = $this->expectedContract($isHttps);
        $actual = $this->normalize($actualHeaders);

        foreach ($expected as $name => $value) {
            self::assertArrayHasKey($name, $actual, "{$label} is missing the {$name} header.");
            self::assertSame($value, $actual[$name], "{$label} disagrees on {$name}.");
        }

        self::assertArrayNotHasKey('x-powered-by', $actual, "{$label} leaked X-Powered-By.");

        // The named CSP content, asserted against what the builder emits.
        $csp = $actual['content-security-policy'];
        self::assertMatchesRegularExpression(
            "#script-src [^;]*'nonce-" . self::FIXED_NONCE . "'[^;]*'strict-dynamic'#",
            $csp,
            "{$label} script-src must carry the nonce and 'strict-dynamic'.",
        );
        self::assertStringContainsString("base-uri 'self'", $csp, "{$label} must pin base-uri.");
        self::assertStringContainsString("object-src 'none'", $csp, "{$label} must pin object-src.");
        self::assertStringContainsString("form-action 'self'", $csp, "{$label} must pin form-action.");
        self::assertStringContainsString("frame-ancestors 'self'", $csp, "{$label} must pin frame-ancestors.");
    }

    /**
     * Lowercase the header names and replace every CSP nonce with the fixed
     * token, so two responses can be compared byte for byte.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = (string) preg_replace(
                "#'nonce-[A-Za-z0-9+/=]+'#",
                "'nonce-" . self::FIXED_NONCE . "'",
                (string) $value,
            );
        }

        return $normalized;
    }

    /**
     * Pick the contract headers out of an already normalized set, so two
     * paths can be compared without the headers each framework adds on top.
     *
     * @param array<string, string> $normalized
     *
     * @return array<string, string>
     */
    private function contractSubset(array $normalized): array
    {
        $subset = [];

        foreach (self::CONTRACT_HEADERS as $name) {
            if (array_key_exists($name, $normalized)) {
                $subset[$name] = $normalized[$name];
            }
        }

        ksort($subset);

        return $subset;
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

    /**
     * Start a php -S child process whose one-line router boots HELMET, and
     * wait for it to announce its port.
     *
     * @return array{process: \resource, port: int, router: string}
     */
    private function startRawServer(): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $router = tempnam(sys_get_temp_dir(), 'helmet-contract-router-');

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . "\Waynestate\Helmet\helmet_boot(['trusted_proxies' => ['127.0.0.1']]);\n"
            . "header('Content-Type: text/html; charset=UTF-8');\n"
            . "echo '<html><head><title>contract</title></head><body>hi</body></html>';\n";

        file_put_contents($router, $script);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', $router],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($server);

        // The server announces its bound port on stderr before it accepts;
        // read non-blocking until that line shows up.
        stream_set_blocking($pipes[2], false);
        $port = null;
        $log = '';
        $deadline = microtime(true) + 10.0;
        while ($port === null && microtime(true) < $deadline) {
            $chunk = fread($pipes[2], 8192);
            if ($chunk === false) {
                break;
            }
            $log .= $chunk;
            if (preg_match('#Development Server \(http://127\.0\.0\.1:(\d+)\) started#', $log, $matches)) {
                $port = (int) $matches[1];
            }
            if ($port === null) {
                usleep(50_000);
            }
        }

        self::assertNotNull($port, "server never announced a port; log: $log");

        return ['process' => $server, 'port' => $port, 'router' => $router];
    }

    /**
     * Stop the raw PHP child process and clean up its router script.
     *
     * @param array{process: \resource, port: int, router: string} $server
     */
    private function stopRawServer(array $server): void
    {
        proc_terminate($server['process']);
        proc_close($server['process']);
        @unlink($server['router']);
    }

    /**
     * Probe the raw PHP child over real HTTP and return its response headers
     * (lowercase name to value) and body.
     *
     * @param array<string, string> $extraHeaders
     *
     * @return array{headers: array<string, string>, body: string}
     */
    private function probe(int $port, array $extraHeaders): array
    {
        $headerLines = '';
        foreach ($extraHeaders as $name => $value) {
            $headerLines .= "{$name}: {$value}\r\n";
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $headerLines,
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);
        $body = @file_get_contents("http://127.0.0.1:{$port}/contract-html", false, $context);

        // http_get_last_response_headers() is the PHP 8.5 name for the
        // $http_response_header magic variable, which 8.5 deprecates.
        if (\function_exists('http_get_last_response_headers')) {
            $lines = \http_get_last_response_headers();
        } else {
            $lines = $http_response_header ?? [];
        }
        self::assertIsArray($lines);

        $headers = [];
        foreach ($lines as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        self::assertNotSame('', $body, "the raw PHP probe got no body; headers: " . implode("\n", $lines));

        return ['headers' => $headers, 'body' => (string) $body];
    }

    /**
     * Run one request through the PSR-15 middleware and return the response
     * headers (lowercase name to value). The inner handler stamps
     * X-Powered-By so the removal is actually exercised.
     *
     * @param array<string, string> $serverParams
     *
     * @return array<string, string>
     */
    private function psr15Headers(array $serverParams): array
    {
        $middleware = new SecurityHeadersMiddleware(new TrustedProxies(['127.0.0.1']));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200, [], '<html><head><title>contract</title></head><body>hi</body></html>'))
                    ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                    ->withHeader('X-Powered-By', 'PHP/8.3');
            }
        };

        $request = new ServerRequest('GET', 'https://app.example/', [], null, '1.1', $serverParams);
        $response = $middleware->process($request, $handler);

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Register a GET route in the "web" group that returns simple HTML.
     */
    private function defineHtmlRoute(string $uri): void
    {
        $this->app['router']->get($uri, static fn() => response('<html><head><title>contract</title></head><body>hi</body></html>', 200, ['Content-Type' => 'text/html']))
            ->middleware('web');
    }

    /**
     * Register a GET route in the "web" group that answers JSON, the way an
     * Inertia-style XHR endpoint does.
     */
    private function defineJsonRoute(string $uri): void
    {
        $this->app['router']->get($uri, static fn() => response()->json(['ok' => true, 'items' => [1, 2]]))
            ->middleware('web');
    }

    /**
     * Send one request at the given URI through the Laravel web group and
     * return the response headers (lowercase name to value).
     *
     * @return array<string, string>
     */
    private function laravelHeaders(string $uri, bool $https): array
    {
        if ($https) {
            $response = $this->withServerVariables([
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ])->get($uri);
        } else {
            $response = $this->get($uri);
        }

        return $this->headerMap($response);
    }

    /**
     * The response headers as a lowercase name to value map. HeaderBag::all()
     * hands back lists of values, so go through get(), which joins them.
     *
     * @return array<string, string>
     */
    private function headerMap(\Illuminate\Testing\TestResponse $response): array
    {
        $headers = [];

        foreach ($response->headers->keys() as $name) {
            $headers[strtolower($name)] = (string) $response->headers->get($name);
        }

        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Contract;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Proxy\TrustedProxies;
use Waynestate\Helmet\Tests\Laravel\TestCase;

/**
 * The header contract as data.
 *
 * contract/ holds one JSON file per consumer path (raw PHP, PSR-15, Laravel
 * Blade and Laravel Inertia), each stating the exact headers HELMET must send
 * over HTTPS and over plain HTTP. This test reads every file, serves that
 * path the same way HeaderContractTest does, and checks the served headers
 * against the file. HeaderContractTest proves the paths agree with each
 * other; this test pins what they agree on, so a drift shows up as a diff
 * against committed data instead of against the builder's own output.
 *
 * The per-request nonce is written as {nonce} in the files. Before any
 * comparison the served value has its 'nonce-...' token replaced with
 * {nonce}, so the comparison is byte-exact for everything else. Plain HTTP
 * carries no Strict-Transport-Security, and the http maps say so by leaving
 * it out. HeaderContractTest asserts no Report-Only CSP variant (the default
 * config sends the enforcing policy), so neither does this test.
 */
final class VuePermutationContractTest extends TestCase
{
    /**
     * What a contract file writes instead of the per-request nonce value.
     */
    private const string NONCE_PLACEHOLDER = '{nonce}';

    protected function setUp(): void
    {
        parent::setUp();

        // HttpsDetector reads $_SERVER['HTTPS'] in whichever process decides
        // the scheme. Keep it unset so the raw PHP child process (probed over
        // plain HTTP, with X-Forwarded-Proto from a trusted peer as the only
        // HTTPS signal) and this process decide it the same way.
        unset($_SERVER['HTTPS']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }

    public function testEveryContractFileMatchesTheServedHeadersOverHttps(): void
    {
        $this->assertAllFiles(true);
    }

    public function testEveryContractFileMatchesTheServedHeadersOnPlainHttp(): void
    {
        $this->assertAllFiles(false);
    }

    /**
     * Serve every contract path over the given scheme and check each served
     * header set against its contract file.
     */
    private function assertAllFiles(bool $https): void
    {
        config(['helmet.proxies' => ['127.0.0.1']]);
        $this->defineHtmlRoute('/contract-html');
        $this->defineJsonRoute('/contract-json');

        $scheme = $https ? 'https' : 'http';

        foreach ($this->contractFiles() as $file) {
            $spec = $this->loadSpec($file);
            $served = $this->normalize($this->servePath($spec['path'], $https));

            foreach ($spec[$scheme] as $name => $value) {
                $key = strtolower($name);
                self::assertArrayHasKey($key, $served, "{$spec['path']} ({$scheme}) is missing the {$name} header.");
                self::assertSame($value, $served[$key], "{$spec['path']} ({$scheme}) disagrees on {$name}.");
            }
        }
    }

    /**
     * Every contract file in the repository, sorted so the suite runs in a
     * stable order.
     *
     * @return list<string>
     */
    private function contractFiles(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/contract/*.json') ?: [];
        sort($files);

        self::assertNotSame([], $files, 'contract/ holds no contract files.');

        return $files;
    }

    /**
     * Read and shape-check one contract file.
     *
     * @return array{path: string, https: array<string, string>, http: array<string, string>}
     */
    private function loadSpec(string $file): array
    {
        $spec = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($spec, "$file is not a JSON object.");
        self::assertArrayHasKey('path', $spec, "$file has no path name.");
        self::assertIsString($spec['path'], "$file has a non-string path name.");

        foreach (['https', 'http'] as $scheme) {
            self::assertArrayHasKey($scheme, $spec, "$file has no {$scheme} map.");
            self::assertIsArray($spec[$scheme], "$file has a non-object {$scheme} map.");
            self::assertNotSame([], $spec[$scheme], "$file names no {$scheme} headers.");

            foreach ($spec[$scheme] as $name => $value) {
                self::assertIsString($name, "$file has a non-string header name in {$scheme}.");
                self::assertIsString($value, "$file has a non-string value for {$name} in {$scheme}.");
            }
        }

        return $spec;
    }

    /**
     * Serve the named consumer path once, the same way HeaderContractTest
     * serves it, and return the response headers (lowercase name to value).
     *
     * @return array<string, string>
     */
    private function servePath(string $path, bool $https): array
    {
        return match ($path) {
            'raw-php' => $this->rawPhpHeaders($https),
            'psr15' => $this->psr15Headers($https
                ? ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']
                : ['REMOTE_ADDR' => '127.0.0.1']),
            'laravel-blade' => $this->laravelHeaders('/contract-html', $https),
            'laravel-inertia' => $this->laravelHeaders('/contract-json', $https),
            default => throw new \LogicException("Unknown contract path {$path}; add a serving case to servePath()."),
        };
    }

    /**
     * Probe the raw PHP child once over real HTTP and return its response
     * headers (lowercase name to value).
     *
     * @return array<string, string>
     */
    private function rawPhpHeaders(bool $https): array
    {
        $server = $this->startRawServer();

        try {
            $probe = $this->probe($server['port'], $https ? ['X-Forwarded-Proto' => 'https'] : []);
        } finally {
            $this->stopRawServer($server);
        }

        return $probe['headers'];
    }

    /**
     * Lowercase the header names and replace every CSP nonce with the
     * contract placeholder, so a served value can be compared byte for byte
     * with the value a contract file states.
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
                "'nonce-" . self::NONCE_PLACEHOLDER . "'",
                (string) $value,
            );
        }

        return $normalized;
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

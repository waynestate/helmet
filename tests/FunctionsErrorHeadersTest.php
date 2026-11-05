<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * H14: the security headers must ride on error responses too, not just on
 * healthy ones. These tests probe the raw PHP path over real HTTP: a child
 * php -S process serves a one-line router that boots HELMET and then throws,
 * and the parent asserts what the wire actually carried. The pattern mirrors
 * FunctionsTest::testBootHealthPathSendsNoPoweredByHeader().
 */
final class FunctionsErrorHeadersTest extends TestCase
{
    /**
     * With error_handler enabled, the uncaught exception ends as HELMET's
     * neutral 500 page, and the page must carry the security headers that
     * helmet_boot() sent before the throw. The exception message stays off
     * the wire.
     */
    public function testExceptionAfterBootStillCarriesTheSecurityHeaders(): void
    {
        [$server, $port, $router] = $this->startServerWithThrowingRouter(true);

        try {
            [$body, $headers] = $this->get("http://127.0.0.1:$port/boom");

            self::assertSame(500, $this->statusCode($headers));
            self::assertStringNotContainsString('secret-detail-do-not-leak', (string) $body);

            $lower = array_map('strtolower', $headers);
            self::assertContains('x-content-type-options: nosniff', $lower);
            self::assertContains('referrer-policy: strict-origin-when-cross-origin', $lower);
            self::assertContains('cache-control: no-store', $lower);
            self::assertTrue(
                (bool) array_filter($lower, static fn(string $line): bool => str_starts_with($line, 'content-security-policy:')),
                'no Content-Security-Policy header on the error response: ' . implode("\n", $headers),
            );
        } finally {
            proc_terminate($server);
            @unlink($router);
        }
    }

    /**
     * With the handler off, the uncaught exception ends as PHP's own fatal
     * answer, but the headers helmet_boot() sent via header() before the
     * throw still go out with it. Only the headers are asserted here: the
     * status of PHP's own fatal answer depends on display_errors, so it is
     * left alone (and pinned off on the child so no trace is printed).
     */
    public function testExceptionAfterBootWithoutTheHandlerStillCarriesTheSecurityHeaders(): void
    {
        [$server, $port, $router] = $this->startServerWithThrowingRouter(false);

        try {
            [, $headers] = $this->get("http://127.0.0.1:$port/boom");

            $lower = array_map('strtolower', $headers);
            self::assertContains('x-content-type-options: nosniff', $lower);
            self::assertTrue(
                (bool) array_filter($lower, static fn(string $line): bool => str_starts_with($line, 'content-security-policy:')),
                'no Content-Security-Policy header on the error response: ' . implode("\n", $headers),
            );
        } finally {
            proc_terminate($server);
            @unlink($router);
        }
    }

    /**
     * Boots a child php -S 127.0.0.1:0 serving a temp router that boots
     * HELMET and then throws. Returns the server process, the announced
     * port, and the router path (the caller unlinks it).
     */
    private function startServerWithThrowingRouter(bool $handlerEnabled): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $router = tempnam(sys_get_temp_dir(), 'helmet-error-router-');

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . "\Waynestate\Helmet\helmet_boot(['trusted_proxies' => ['127.0.0.1'], 'error_handler' => ['enabled' => "
            . var_export($handlerEnabled, true) . "]]);\n"
            . "throw new \RuntimeException('secret-detail-do-not-leak');\n";

        file_put_contents($router, $script);

        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:0', $router],
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

        return [$server, $port, $router];
    }

    /**
     * GETs $url and returns the body plus the raw response header lines
     * (status line included), using http_get_last_response_headers() where
     * it exists and the $http_response_header magic variable otherwise.
     */
    private function get(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents($url, false, $context);

        if (\function_exists('http_get_last_response_headers')) {
            $headers = \http_get_last_response_headers();
        } else {
            $headers = $http_response_header ?? [];
        }
        self::assertIsArray($headers);

        return [(string) $body, $headers];
    }

    /**
     * Pulls the numeric status out of the response's status line.
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                self::assertMatchesRegularExpression('/^HTTP\/\d+(?:\.\d+)? (\d{3})/', $line, "bad status line: $line");

                return (int) substr($line, 9, 3);
            }
        }

        self::fail('response had no status line: ' . implode("\n", $headers));
    }
}

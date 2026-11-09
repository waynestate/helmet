<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Error\GenericErrorResponse;

/**
 * H42: a raw PHP app answers a client fault with HELMET's fixed 4xx page.
 *
 * helmet_client_error_response() is the data twin and is checked in
 * process. helmet_client_error() calls exit(), so it is checked over real
 * HTTP: a child php -S process serves a router that boots HELMET, catches
 * an exception carrying a secret, and answers with helmet_client_error().
 * The parent asserts what the wire carried. The pattern mirrors
 * FunctionsErrorHeadersTest.
 */
final class FunctionsClientErrorTest extends TestCase
{
    private const SECRET = 'secret-detail-do-not-leak';

    /**
     * The data twin returns exactly what GenericErrorResponse::clientError()
     * builds, so the raw helper and the Laravel adapter share one page.
     */
    public function testClientErrorResponseIsTheGenericClientErrorPage(): void
    {
        foreach ([400, 401, 403, 404, 405, 410, 418, 429] as $status) {
            self::assertSame(
                (new GenericErrorResponse())->clientError($status),
                \Waynestate\Helmet\helmet_client_error_response($status),
                "status $status",
            );
        }
    }

    public function testClientErrorResponseCarriesTheStatusHeadersAndFixedBody(): void
    {
        $page = \Waynestate\Helmet\helmet_client_error_response(404);

        self::assertSame(404, $page['status']);
        self::assertSame(
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $page['headers'],
        );
        self::assertStringContainsString('<title>Not found.</title>', $page['body']);
        self::assertStringContainsString('<p>That page does not exist.</p>', $page['body']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function notAClientErrorStatus(): iterable
    {
        yield '500' => [500];
        yield '503' => [503];
        yield '399' => [399];
        yield '302' => [302];
        yield '200' => [200];
        yield '0' => [0];
    }

    /**
     * A 500 (or anything else outside the 400s) is refused before a byte is
     * sent, so the throw is safe to observe in process.
     */
    #[DataProvider('notAClientErrorStatus')]
    public function testClientErrorRefusesAStatusOutsideTheFourHundreds(int $status): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helmet_client_error() takes a 4xx status (400 to 499), got ' . $status . '.');

        \Waynestate\Helmet\helmet_client_error($status);
    }

    #[DataProvider('notAClientErrorStatus')]
    public function testClientErrorResponseRefusesAStatusOutsideTheFourHundreds(int $status): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helmet_client_error_response() takes a 4xx status (400 to 499), got ' . $status . '.');

        \Waynestate\Helmet\helmet_client_error_response($status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function clientErrorStatus(): iterable
    {
        yield '404' => [404];
        yield '405' => [405];
    }

    /**
     * Over real HTTP: the status, HELMET's two headers, the fixed body and
     * nothing else. The exception the router caught never reaches the
     * wire, the security headers helmet_boot() sent still ride along, and
     * nothing after the call runs.
     */
    #[DataProvider('clientErrorStatus')]
    public function testClientErrorSendsTheFixedPageAndExits(int $status): void
    {
        [$server, $port, $router] = $this->startServer();

        try {
            [$body, $headers] = $this->get("http://127.0.0.1:$port/fault?status=$status");
        } finally {
            proc_terminate($server);
            @unlink($router);
        }

        self::assertSame($status, $this->statusCode($headers));
        self::assertSame((new GenericErrorResponse())->clientError($status)['body'], $body);
        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringNotContainsString('REACHED_PAST_CLIENT_ERROR', $body);

        $lower = array_map('strtolower', $headers);
        self::assertContains('content-type: text/html; charset=utf-8', $lower);
        self::assertContains('cache-control: no-store', $lower);
        self::assertContains('x-content-type-options: nosniff', $lower);
        foreach ($lower as $line) {
            self::assertStringNotContainsString(strtolower(self::SECRET), $line);
        }
    }

    /**
     * Boots a child php -S 127.0.0.1:0 serving a temp router that boots
     * HELMET, catches an exception carrying the secret, and answers with
     * helmet_client_error() for the status in the query string. Returns
     * the server process, the announced port, and the router path (the
     * caller unlinks it).
     *
     * @return array{0: resource, 1: int, 2: string}
     */
    private function startServer(): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $router = tempnam(sys_get_temp_dir(), 'helmet-client-error-router-');
        self::assertIsString($router);

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . "\Waynestate\Helmet\helmet_boot(['trusted_proxies' => ['127.0.0.1']]);\n"
            . "try {\n"
            . "    throw new \RuntimeException(" . var_export(self::SECRET, true) . ");\n"
            . "} catch (\RuntimeException \$e) {\n"
            . "    \Waynestate\Helmet\helmet_client_error((int) (\$_GET['status'] ?? 0));\n"
            . "}\n"
            . "echo 'REACHED_PAST_CLIENT_ERROR';\n";

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
     * GETs $url and returns the body plus the raw response header lines.
     *
     * @return array{0: string, 1: list<string>}
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

        return [(string) $body, array_values($headers)];
    }

    /**
     * Pulls the numeric status out of the response's status line.
     *
     * @param list<string> $headers
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

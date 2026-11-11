<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * H7: helmet_csp_report() over real HTTP.
 *
 * helmet_csp_report() calls exit(), so it is checked the way
 * FunctionsClientErrorTest checks helmet_client_error(): a child php -S
 * process serves a public/csp-report.php-shaped router that calls it with a
 * file logger, and the parent asserts what the wire carried and what the log
 * file got. The router never calls helmet_boot(), exactly as the docs
 * recommend.
 */
final class FunctionsCspReportHttpTest extends TestCase
{
    private string $log = '';

    /** @var resource|null */
    private $server = null;

    private string $router = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
        }
        foreach ([$this->router, $this->log] as $file) {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testAReportIsAnsweredWithNoContentAndLogged(): void
    {
        $port = $this->startServer();
        $report = (string) json_encode(['csp-report' => [
            'document-uri' => 'https://app.example.edu/courses/12?tab=grades',
            'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example.com/x.js',
            'original-policy' => "script-src 'self' secret-policy-text",
        ]]);

        [$status, $headers, $body] = $this->request('POST', "http://127.0.0.1:$port/csp-report.php", $report);

        self::assertSame(204, $status);
        self::assertSame('', $body);
        $lower = array_map('strtolower', $headers);
        self::assertContains('cache-control: no-store', $lower);

        $logged = (string) file_get_contents($this->log);
        self::assertStringContainsString(
            'CSP violation: directive=script-src-elem blocked_host=evil.example.com document_path=/courses/12',
            $logged,
        );
        self::assertStringNotContainsString('secret-policy-text', $logged);
        self::assertStringNotContainsString('tab=grades', $logged);
    }

    public function testAGetIsRefusedWithAllowPost(): void
    {
        $port = $this->startServer();

        [$status, $headers, $body] = $this->request('GET', "http://127.0.0.1:$port/csp-report.php");

        self::assertSame(405, $status);
        self::assertSame('Method not allowed.', $body);
        self::assertContains('allow: post', array_map('strtolower', $headers));
        self::assertSame('', (string) file_get_contents($this->log));
    }

    /**
     * Starts php -S 127.0.0.1:0 on a temp router that calls
     * helmet_csp_report() with a Monolog logger writing to a temp file, and
     * returns the port the server announced.
     */
    private function startServer(): int
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $router = tempnam(sys_get_temp_dir(), 'helmet-csp-report-router-');
        $log = tempnam(sys_get_temp_dir(), 'helmet-csp-report-log-');
        self::assertIsString($router);
        self::assertIsString($log);
        $this->router = $router;
        $this->log = $log;

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . '$logger = new \Monolog\Logger(\'csp\', [new \Monolog\Handler\StreamHandler(' . var_export($log, true) . ")]);\n"
            . "\Waynestate\Helmet\helmet_csp_report(\$logger);\n";
        file_put_contents($router, $script);

        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:0', $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        $this->server = $server;

        // The server announces its bound port on stderr before it accepts.
        stream_set_blocking($pipes[2], false);
        $port = null;
        $out = '';
        $deadline = microtime(true) + 10.0;
        while ($port === null && microtime(true) < $deadline) {
            $chunk = fread($pipes[2], 8192);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
            if (preg_match('#Development Server \(http://127\.0\.0\.1:(\d+)\) started#', $out, $matches)) {
                $port = (int) $matches[1];
            }
            if ($port === null) {
                usleep(50_000);
            }
        }
        self::assertNotNull($port, "server never announced a port; log: $out");

        return $port;
    }

    /**
     * @return array{0: int, 1: list<string>, 2: string}
     */
    private function request(string $method, string $url, ?string $body = null): array
    {
        $http = ['method' => $method, 'ignore_errors' => true, 'timeout' => 5];
        if ($body !== null) {
            $http['header'] = "Content-Type: application/csp-report\r\n";
            $http['content'] = $body;
        }

        $answer = @file_get_contents($url, false, stream_context_create(['http' => $http]));

        if (\function_exists('http_get_last_response_headers')) {
            $headers = \http_get_last_response_headers();
        } else {
            $headers = $http_response_header ?? [];
        }
        self::assertIsArray($headers);
        $headers = array_values($headers);

        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\d+(?:\.\d+)? (\d{3})#', $line, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        return [$status, $headers, (string) $answer];
    }
}

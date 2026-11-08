<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * helmet_boot() answers the readiness path when "readiness" is enabled, and
 * then stops, so the probe never reaches the app. helmet_ready() exits, so
 * each case runs in a child PHP process (the pattern from FunctionsTest).
 */
final class FunctionsReadinessTest extends TestCase
{
    /**
     * Run helmet_boot() in a child process for one request.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function boot(string $uri, string $config, string $method = 'GET'): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $tempFile = tempnam(sys_get_temp_dir(), 'helmet-ready-');

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . "\$_SERVER['HTTP_HOST'] = 'app.example';\n"
            . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
            . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n"
            . "\$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
            . "try {\n"
            . "    \\Waynestate\\Helmet\\helmet_boot(" . $config . ");\n"
            . "} catch (\\InvalidArgumentException \$e) {\n"
            . "    fwrite(STDERR, 'INVALID: ' . \$e->getMessage());\n"
            . "    exit(9);\n"
            . "}\n"
            . "echo 'REACHED_THE_APP';\n";

        file_put_contents($tempFile, $script);

        try {
            $process = proc_open(
                [PHP_BINARY, $tempFile],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            self::assertIsResource($process);

            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            @unlink($tempFile);
        }

        return ['exit' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testReadinessIsOffByDefault(): void
    {
        $run = $this->boot('/ready', "['trusted_proxies' => ['127.0.0.1']]");

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('REACHED_THE_APP', $run['stdout']);
    }

    public function testEnabledReadinessAnswersAndStops(): void
    {
        $run = $this->boot('/ready', "['trusted_proxies' => ['127.0.0.1'], 'readiness' => ['enabled' => true]]");

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertStringNotContainsString('REACHED_THE_APP', $run['stdout']);

        /** @var array{status: string, checks: array<string, array<string, mixed>>} $body */
        $body = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ready', $body['status']);
        self::assertSame(['headers', 'trusted_proxies', 'debug_output'], array_keys($body['checks']));
        self::assertSame('enforce', $body['checks']['headers']['csp']);
        self::assertSame(['status' => 'pass', 'count' => 1, 'broad' => 0], $body['checks']['trusted_proxies']);
    }

    public function testReportOnlyModeIsReported(): void
    {
        $run = $this->boot('/ready', "['trusted_proxies' => ['127.0.0.1'], 'csp_report_only' => true, 'readiness' => ['enabled' => true]]");

        /** @var array{checks: array<string, array<string, mixed>>} $body */
        $body = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('report-only', $body['checks']['headers']['csp']);
    }

    public function testTheStyleSrcAttrModeIsReported(): void
    {
        $run = $this->boot('/ready', "['trusted_proxies' => ['127.0.0.1'], 'style_src_attr' => 'report', 'readiness' => ['enabled' => true]]");

        /** @var array{checks: array<string, array<string, mixed>>} $body */
        $body = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('report', $body['checks']['headers']['style_src_attr']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badStyleSrcAttr(): iterable
    {
        yield 'unknown mode' => ["'strict'"];
        yield 'not a string' => ['false'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badStyleSrcAttr')]
    public function testABadStyleSrcAttrFailsAtBoot(string $value): void
    {
        $run = $this->boot('/', "['trusted_proxies' => ['127.0.0.1'], 'style_src_attr' => " . $value . ']');

        self::assertSame(9, $run['exit'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('style', $run['stderr']);
    }

    public function testAFailingAppCheckIsNotReady(): void
    {
        $run = $this->boot(
            '/readyz',
            "['trusted_proxies' => ['127.0.0.1'], 'readiness' => ['enabled' => true, 'path' => '/readyz', 'checks' => ['database' => static fn (): bool => false]]]",
        );

        /** @var array{status: string, checks: array<string, array<string, mixed>>} $body */
        $body = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not ready', $body['status']);
        self::assertSame(['status' => 'fail'], $body['checks']['database']);
    }

    public function testAThrowingCheckKeepsItsMessageOutOfTheBody(): void
    {
        $run = $this->boot(
            '/ready',
            "['trusted_proxies' => ['127.0.0.1'], 'readiness' => ['enabled' => true, 'checks' => ['database' => static function (): bool { throw new \\RuntimeException('password=hunter2'); }]]]",
        );

        self::assertStringContainsString('"database":{"status":"fail"}', $run['stdout']);
        self::assertStringNotContainsString('hunter2', $run['stdout']);
    }

    public function testOtherPathsAndPostReachTheApp(): void
    {
        $config = "['trusted_proxies' => ['127.0.0.1'], 'readiness' => ['enabled' => true]]";

        self::assertSame('REACHED_THE_APP', $this->boot('/', $config)['stdout']);
        self::assertSame('REACHED_THE_APP', $this->boot('/ready', $config, 'POST')['stdout']);
    }

    public function testLivenessStillAnswersUp(): void
    {
        $run = $this->boot(
            '/up',
            "['trusted_proxies' => ['127.0.0.1'], 'readiness' => ['enabled' => true, 'checks' => ['database' => static fn (): bool => false]]]",
        );

        self::assertSame('ok', $run['stdout']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badConfig(): iterable
    {
        yield 'not an array' => ["'yes'", '"readiness" must be an array'];
        yield 'enabled not bool' => ["['enabled' => 'true']", '"readiness.enabled" must be a bool'];
        yield 'relative path' => ["['enabled' => true, 'path' => 'ready']", '"readiness.path"'];
        yield 'same as health' => ["['enabled' => true, 'path' => '/up']", '"readiness.path"'];
        yield 'checks not array' => ["['enabled' => true, 'checks' => 'db']", '"readiness.checks"'];
        yield 'check not callable' => ["['enabled' => true, 'checks' => ['database' => 'no_such_function_here']]", '"readiness.checks"'];
        yield 'bad check name' => ["['enabled' => true, 'checks' => ['Data Base' => static fn (): bool => true]]", 'Data Base'];
        yield 'clashes with a standard check' => ["['enabled' => true, 'checks' => ['headers' => static fn (): bool => true]]", 'headers'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badConfig')]
    public function testBadReadinessConfigFailsAtBoot(string $readiness, string $message): void
    {
        // On an ordinary page, so a typo fails on the first request, not
        // only when the probe arrives.
        $run = $this->boot('/', "['trusted_proxies' => ['127.0.0.1'], 'readiness' => " . $readiness . ']');

        self::assertSame(9, $run['exit'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString($message, $run['stderr']);
    }
}

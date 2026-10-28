<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Headers\InvalidCspReportUriException;
use Waynestate\Helmet\Platform\UnknownPresetException;

final class FunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Make the HTTPS-detection assertions deterministic: start from a
        // known cookie_secure and no forwarded-proto / TLS signal.
        ini_set('session.cookie_secure', '0');
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);

        // Make the request-forgery assertions deterministic: start with no
        // host, Origin or Sec-Fetch-Site header.
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
    }

    protected function tearDown(): void
    {
        // Keep TLS and request-origin signals from leaking into later test
        // files: CSPBuilder reads $_SERVER['HTTPS'] when it compiles a policy.
        unset(
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_SEC_FETCH_SITE'],
        );

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootThrowsWhenSessionAlreadyActive(): void
    {
        // In a separate process no output has been sent, so a session can start.
        session_start();
        self::assertSame(PHP_SESSION_ACTIVE, session_status());

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(\LogicException::class);
        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
        ]);
    }

    public function testBootReturnsNonceWhenNoSessionActive(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertArrayHasKey('logger', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    public function testPresetCitadelResolvesTrustedProxyAndDetectsHttps(): void
    {
        // The citadel preset trusts the Docker bridge gateway 172.18.0.1, so
        // a forwarded-proto from that peer is believed.
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'preset' => 'citadel',
        ]);

        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    public function testPresetDockerTrustsNothing(): void
    {
        // The docker preset trusts nothing, so the same forwarded-proto from
        // the same peer is ignored.
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'preset' => 'docker',
        ]);

        self::assertNotSame('1', ini_get('session.cookie_secure'));
    }

    public function testNoPresetAndNoProxiesTrustsNothing(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([]);

        self::assertNotSame('1', ini_get('session.cookie_secure'));
    }

    public function testExplicitProxiesWinOverPreset(): void
    {
        // The explicit list trusts 127.0.0.1; the citadel preset would trust
        // 172.18.0.1. The peer is 127.0.0.1, so only the explicit list can
        // make this request HTTPS.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'preset' => 'citadel',
        ]);

        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    public function testUnknownPresetThrows(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $this->expectException(UnknownPresetException::class);

        \Waynestate\Helmet\helmet_boot([
            'preset' => 'openshift',
        ]);
    }

    /**
     * The raw health path answers "ok" and stops, no matter what host the
     * request came in on (the orchestrator may probe by IP:port).
     *
     * helmet_boot() calls exit() on the health path, so it cannot be exercised
     * in-process: a separate-process PHPUnit test would report "ended
     * unexpectedly" even on success, because the result file is only written
     * after the test method returns. Instead we shell out to a real PHP
     * process (argument array, no shell) and check its exit code, stdout and
     * stderr. The child writes a marker to stderr if control ever returns past
     * the health path; a correct boot never reaches that line.
     */
    public function testRawHealthPathIsHostAgnostic(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $tempFile = tempnam(sys_get_temp_dir(), 'helmet-up-');

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . "\$_SERVER['HTTP_HOST'] = '10.1.2.3:8080';\n"
            . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
            . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
            . "\$_SERVER['REQUEST_URI'] = '/up';\n"
            . "\Waynestate\Helmet\helmet_boot(['trusted_proxies' => ['127.0.0.1']]);\n"
            . "fwrite(STDERR, 'REACHED_PAST_HEALTH_PATH');\n";

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
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            @unlink($tempFile);
        }

        self::assertSame(0, $exitCode, "child stderr: $stderr");
        self::assertSame('ok', $stdout);
        self::assertStringNotContainsString('REACHED_PAST_HEALTH_PATH', $stderr);
    }

    /**
     * helmet_up_response() is the single definition of the health answer:
     * 200, the two headers, and the body "ok". helmet_up() and
     * helmet_boot() both send it, so pinning the data pins both paths.
     */
    public function testUpResponseReturnsStatusHeadersAndBody(): void
    {
        self::assertSame(
            [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ],
                'body' => 'ok',
            ],
            \Waynestate\Helmet\helmet_up_response(),
        );
    }

    /**
     * helmet_up() is the answer for a raw PHP app with no front controller:
     * it prints "ok" and exits, so a probe that never reaches helmet_boot()
     * still gets the health answer. helmet_up() calls exit(), so it cannot
     * be exercised in-process (see testRawHealthPathIsHostAgnostic): we
     * shell out to a real PHP process and check its exit code, stdout and
     * stderr. The child writes a marker to stderr if control ever returns
     * past helmet_up(); a correct call never reaches that line.
     */
    public function testUpFunctionAnswersOkAndExits(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        $script = 'require ' . var_export($autoload, true) . ";\n"
            . "\Waynestate\Helmet\helmet_up();\n"
            . "fwrite(STDERR, 'REACHED_PAST_UP');\n";

        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, "child stderr: $stderr");
        self::assertSame('ok', $stdout);
        self::assertStringNotContainsString('REACHED_PAST_UP', $stderr);
    }

    /**
     * The csp-nonce meta tag helper escapes the nonce, so a nonce with a
     * quote cannot break out of the attribute.
     */
    public function testCspNonceMetaEscapesTheNonce(): void
    {
        $nonce = 'abc"def';

        $meta = \Waynestate\Helmet\helmet_csp_nonce_meta($nonce);

        self::assertSame(
            '<meta property="csp-nonce" nonce="abc&quot;def">',
            $meta,
        );
    }

    /**
     * The csp-nonce meta tag helper returns the tag with the nonce.
     */
    public function testCspNonceMetaReturnsTheTag(): void
    {
        $nonce = 'testnonce';

        $meta = \Waynestate\Helmet\helmet_csp_nonce_meta($nonce);

        self::assertSame(
            '<meta property="csp-nonce" nonce="testnonce">',
            $meta,
        );
    }

    /**
     * An invalid csp_report_uri is a configuration error, so helmet_boot()
     * refuses to run rather than send a policy that points at a destination
     * the browser cannot reach. The throw happens before any header is sent.
     */
    public function testBootThrowsOnInvalidCspReportUri(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $this->expectException(InvalidCspReportUriException::class);

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csp_report_uri' => 'http://example.com/csp-report',
        ]);
    }

    /**
     * The raw PHP path forwards the reporting config to the core builder: a
     * valid report URI with report-only on boots cleanly and returns a nonce.
     * That proves csp_report_uri reached SecurityHeaders::build() (an invalid
     * one throws, covered by testBootThrowsOnInvalidCspReportUri) and
     * csp_report_only was accepted. The header content itself is asserted in
     * SecurityHeadersTest and the Laravel tests, because header() output is
     * not inspectable in the CLI test SAPI (headers_sent() is always true).
     */
    public function testBootAcceptsReportUriAndReportOnly(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csp_report_uri' => 'https://example.com/csp-report',
            'csp_report_only' => true,
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * The raw PHP path forwards the cross-origin config to the core builder:
     * valid coop, corp and permissions_policy boot cleanly and return a nonce.
     * That proves the keys reached SecurityHeaders::build() (an invalid one
     * throws, covered below). The header content itself is asserted in
     * SecurityHeadersTest and the Laravel tests, because header() output is
     * not inspectable in the CLI test SAPI (headers_sent() is always true).
     */
    public function testBootAcceptsCoopCorpAndPermissionsPolicy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'coop' => 'unsafe-none',
            'corp' => 'same-site',
            'permissions_policy' => ['camera' => ['self']],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * An explicit null coop means "send no COOP header" (the same as the
     * Laravel config), and an empty permissions_policy means "send no
     * Permissions-Policy header". Both boot cleanly.
     */
    public function testBootAcceptsNullCoopAndEmptyPermissionsPolicy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'coop' => null,
            'permissions_policy' => [],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    public function testBootThrowsOnInvalidCoop(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COOP must be one of');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'coop' => 'bogus',
        ]);
    }

    public function testBootThrowsOnInvalidCorp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CORP must be one of');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'corp' => 'bogus',
        ]);
    }

    public function testBootThrowsOnInvalidPermissionsPolicy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/not-the-health-path';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature name "Camera" is invalid');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'permissions_policy' => ['Camera' => []],
        ]);
    }

    /**
     * The request-forgery guard is off by default: a cross-site
     * state-changing request boots cleanly when no "csrf" option is given.
     */
    public function testBootCsrfIsDisabledByDefault(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/submit';
        $_SERVER['HTTP_HOST'] = 'app.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * A safe method passes the request-forgery guard even when it is enabled.
     */
    public function testBootCsrfAllowsSafeMethods(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/submit';
        $_SERVER['HTTP_HOST'] = 'app.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csrf' => ['enabled' => true],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * A same-origin state-changing request passes the request-forgery guard.
     */
    public function testBootCsrfAllowsSameOriginSecFetchSite(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/submit';
        $_SERVER['HTTP_HOST'] = 'app.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csrf' => ['enabled' => true],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * When Sec-Fetch-Site is absent, an Origin header that matches the
     * request's own origin passes the request-forgery guard.
     */
    public function testBootCsrfAllowsSameOriginFallback(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/submit';
        $_SERVER['HTTP_HOST'] = 'app.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csrf' => ['enabled' => true],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * An excepted path skips the request-forgery guard.
     */
    public function testBootCsrfAllowsExceptedPath(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/webhook';
        $_SERVER['HTTP_HOST'] = 'app.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $result = \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csrf' => [
                'enabled' => true,
                'except' => ['/webhook'],
            ],
        ]);

        self::assertArrayHasKey('nonce', $result);
        self::assertIsString($result['nonce']);
        self::assertNotSame('', $result['nonce']);
    }

    /**
     * helmet_forbidden_response() is the single definition of the rejection
     * answer: 403, the two headers, and the body "Forbidden".
     */
    public function testForbiddenResponseReturnsStatusHeadersAndBody(): void
    {
        self::assertSame(
            [
                'status' => 403,
                'headers' => [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ],
                'body' => 'Forbidden',
            ],
            \Waynestate\Helmet\helmet_forbidden_response(),
        );
    }

    /**
     * A cross-site state-changing request is rejected by helmet_boot() and
     * stops. helmet_forbidden() calls exit(), so it cannot be exercised
     * in-process (see testRawHealthPathIsHostAgnostic): we shell out to a
     * real PHP process and check its exit code, stdout and stderr. The child
     * writes a marker to stderr if control ever returns past the guard; a
     * correct rejection never reaches that line.
     */
    public function testBootCsrfRejectsCrossSiteAndExits(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $tempFile = tempnam(sys_get_temp_dir(), 'helmet-csrf-');

        $script = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
            . "\$_SERVER['REQUEST_METHOD'] = 'POST';\n"
            . "\$_SERVER['REQUEST_URI'] = '/submit';\n"
            . "\$_SERVER['HTTP_HOST'] = 'app.example';\n"
            . "\$_SERVER['HTTPS'] = 'on';\n"
            . "\$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';\n"
            . "\Waynestate\Helmet\helmet_boot(['trusted_proxies' => ['127.0.0.1'], 'csrf' => ['enabled' => true]]);\n"
            . "fwrite(STDERR, 'REACHED_PAST_CSRF_GUARD');\n";

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
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            @unlink($tempFile);
        }

        self::assertSame(0, $exitCode, "child stderr: $stderr");
        self::assertSame('Forbidden', $stdout);
        self::assertStringNotContainsString('REACHED_PAST_CSRF_GUARD', $stderr);
    }

    /**
     * An invalid "csrf" option is a configuration error, so helmet_boot()
     * refuses to run rather than apply a guard the caller did not ask for.
     */
    public function testBootThrowsOnInvalidCsrfConfig(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/submit';
        $_SERVER['HTTP_HOST'] = 'app.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('csrf.trusted_origins');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csrf' => [
                'enabled' => true,
                'trusted_origins' => 'https://trusted.example',
            ],
        ]);
    }

    // ── HSTS preload ──────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootHstsPreloadSendsPreloadHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTPS'] = 'on';

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'hsts_preload' => true,
        ]);

        $headers = headers_list();
        $hsts = null;
        foreach ($headers as $header) {
            if (stripos($header, 'Strict-Transport-Security:') === 0) {
                $hsts = trim(substr($header, strlen('Strict-Transport-Security:')));
                break;
            }
        }

        self::assertSame('max-age=31536000; includeSubDomains; preload', $hsts);
    }

    // ── Trusted Types config validation ───────────────────────────────────

    public function testBootThrowsOnInvalidTrustedTypesConfig(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('csp_trusted_types" must be an array');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csp_trusted_types' => 'not-an-array',
        ]);
    }

    public function testBootThrowsOnInvalidTrustedTypesEnabled(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('csp_trusted_types.enabled" must be a bool');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'csp_trusted_types' => [
                'enabled' => 'yes',
            ],
        ]);
    }

    // ── no-store config validation ────────────────────────────────────────

    public function testBootThrowsOnInvalidNoStoreConfig(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_store" must be an array');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'no_store' => 'not-an-array',
        ]);
    }

    public function testBootThrowsOnInvalidNoStoreEnabled(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_store.enabled" must be a bool');

        \Waynestate\Helmet\helmet_boot([
            'trusted_proxies' => ['127.0.0.1'],
            'no_store' => [
                'enabled' => 'yes',
            ],
        ]);
    }
}

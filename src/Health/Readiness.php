<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Health;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * The readiness report: "should this instance get traffic right now?"
 *
 * Liveness (/up, HealthCheck, helmet_up()) answers "is the process alive?"
 * and never looks at anything else, so a database outage cannot restart a
 * healthy app. Readiness is the other question, asked by the load balancer
 * or the orchestrator's traffic probe: it runs a short list of checks and
 * answers 200 when none failed and 503 when one did, so a failing instance
 * is taken out of rotation instead of being killed.
 *
 * HELMET brings three checks of its own, about its own config, and the app
 * adds the rest (its database, its cache) with withCheck():
 *
 *  - "headers": the security header set builds from the current config,
 *    and (in Laravel) the header middleware is registered. Reports the CSP
 *    mode and the style-src-attr mode, never the policy itself.
 *  - "trusted_proxies": how many proxies are trusted. None, or a broad
 *    range, is a warning, not a failure: HTTPS behind a proxy goes
 *    undetected, which is worth seeing but is no reason to drop traffic.
 *  - "debug_output": a warning when error detail would reach users.
 *
 * A warning never turns the answer into a 503; only a failure does. The
 * body is a small JSON document of check names and statuses, plus counts
 * and mode names: no addresses, no paths, no versions, no secrets, and no
 * exception text (that goes to the log, when a logger is given).
 *
 * A check is a plain callable, so there is no timeout here: PHP cannot stop
 * a running function. Give each dependency check its own short timeout (for
 * PDO, PDO::ATTR_TIMEOUT) so a hung database cannot hang the probe.
 *
 * Readiness is immutable: every with*() returns a copy.
 */
final class Readiness
{
    /**
     * The default path the readiness check answers on.
     */
    public const string DEFAULT_PATH = '/ready';

    public const string PASS = 'pass';

    public const string WARN = 'warn';

    public const string FAIL = 'fail';

    /**
     * Each check, by name, in the order it was added. A check returns its
     * status and optional detail values (counts and mode names only).
     *
     * @var array<string, \Closure(): array{status: string, detail: array<string, string|int|bool>}>
     */
    private array $checks = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * The three HELMET checks for a raw PHP or PSR-15 app.
     *
     * $buildHeaders builds the security header set exactly as the app's
     * requests do (the headers check passes when it returns without
     * throwing). The debug check reads PHP's display_errors setting.
     *
     * @param callable(): array<string, string> $buildHeaders
     */
    public static function standard(
        TrustedProxies $proxies,
        callable $buildHeaders,
        bool $cspReportOnly = false,
        string $styleSrcAttr = 'inline',
        ?LoggerInterface $logger = null,
    ): self {
        return (new self($logger))
            ->withHeaders($buildHeaders, $cspReportOnly, $styleSrcAttr)
            ->withTrustedProxies($proxies)
            ->withDebugOutput(self::displayErrorsOn());
    }

    /**
     * Whether PHP's display_errors would send error detail to the response.
     * "stderr" sends it to the server's error stream, not the page, so it
     * does not count.
     */
    public static function displayErrorsOn(): bool
    {
        $value = strtolower(trim((string) ini_get('display_errors')));

        return in_array($value, ['1', 'on', 'true', 'yes', 'stdout'], true);
    }

    /**
     * The "headers" check: the header set builds, and the header middleware
     * is wired. $registered is false only when an adapter can see that the
     * middleware is missing (Laravel); the raw PHP and PSR-15 paths send the
     * headers themselves, so they pass true.
     *
     * @param callable(): array<string, string> $buildHeaders
     */
    public function withHeaders(
        callable $buildHeaders,
        bool $cspReportOnly = false,
        string $styleSrcAttr = 'inline',
        bool $registered = true,
    ): self {
        $logger = $this->logger;

        return $this->with('headers', static function () use ($buildHeaders, $cspReportOnly, $styleSrcAttr, $registered, $logger): array {
            $detail = [
                'csp' => $cspReportOnly ? 'report-only' : 'enforce',
                'style_src_attr' => $styleSrcAttr,
            ];

            if (!$registered) {
                return ['status' => self::FAIL, 'detail' => $detail + ['registered' => false]];
            }

            try {
                $headers = $buildHeaders();
            } catch (\Throwable $e) {
                self::logFailure($logger, 'headers', $e);

                return ['status' => self::FAIL, 'detail' => $detail];
            }

            $cspHeader = $cspReportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            if (!isset($headers[$cspHeader]) || $headers[$cspHeader] === '') {
                return ['status' => self::FAIL, 'detail' => $detail];
            }

            return ['status' => self::PASS, 'detail' => $detail];
        });
    }

    /**
     * The "trusted_proxies" check: a warning when no proxy is trusted (HTTPS
     * behind a proxy is then never detected, so no HSTS and no Secure
     * cookie) or when the list holds a broad range (H21). Reports counts
     * only, never the addresses.
     */
    public function withTrustedProxies(TrustedProxies $proxies): self
    {
        return $this->with('trusted_proxies', static function () use ($proxies): array {
            $count = $proxies->count();
            $broad = count($proxies->broadEntries());
            $status = $count === 0 || $broad > 0 ? self::WARN : self::PASS;

            return ['status' => $status, 'detail' => ['count' => $count, 'broad' => $broad]];
        });
    }

    /**
     * The "debug_output" check: a warning when error detail would reach
     * users (H43). The adapter decides what "on" means: display_errors for
     * raw PHP, app.debug in production for Laravel.
     */
    public function withDebugOutput(bool $on): self
    {
        return $this->with('debug_output', static fn(): array => [
            'status' => $on ? self::WARN : self::PASS,
            'detail' => [],
        ]);
    }

    /**
     * Add an app check, for example the database. The callable returns
     * true when the dependency is usable and false when it is not; null
     * counts as usable. A throw counts as a failure: the exception class and
     * message are logged (when a logger was given) and never shown.
     *
     * @param callable(): (bool|null) $check
     *
     * @throws \InvalidArgumentException If the name is not a lowercase
     *         identifier (letters, digits and underscores, starting with a
     *         letter) or is already taken.
     */
    public function withCheck(string $name, callable $check): self
    {
        $logger = $this->logger;

        return $this->with($name, static function () use ($name, $check, $logger): array {
            try {
                $result = $check();
            } catch (\Throwable $e) {
                self::logFailure($logger, $name, $e);

                return ['status' => self::FAIL, 'detail' => []];
            }

            return ['status' => $result === false ? self::FAIL : self::PASS, 'detail' => []];
        });
    }

    /**
     * The check names, in the order they run.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->checks);
    }

    /**
     * Run every check and build the answer, as data. It sends nothing; the
     * caller does (helmet_ready(), ReadinessCheck, the Laravel controller).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function response(): array
    {
        $ready = true;
        $results = [];
        foreach ($this->checks as $name => $check) {
            $result = $check();
            if ($result['status'] === self::FAIL) {
                $ready = false;
            }
            $results[$name] = ['status' => $result['status']] + $result['detail'];
        }

        $body = json_encode(
            ['status' => $ready ? 'ready' : 'not ready', 'checks' => (object) $results],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return [
            'status' => $ready ? 200 : 503,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => $body,
        ];
    }

    /**
     * @param \Closure(): array{status: string, detail: array<string, string|int|bool>} $check
     *
     * @throws \InvalidArgumentException If the name is invalid or taken.
     */
    private function with(string $name, \Closure $check): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                'A readiness check name must be lowercase letters, digits and underscores, starting with a letter: got "' . $name . '".',
            );
        }
        if (isset($this->checks[$name])) {
            throw new \InvalidArgumentException('A readiness check named "' . $name . '" is already registered.');
        }

        $copy = clone $this;
        $copy->checks[$name] = $check;

        return $copy;
    }

    private static function logFailure(?LoggerInterface $logger, string $name, \Throwable $e): void
    {
        if ($logger === null) {
            return;
        }

        try {
            $logger->warning('helmet.readiness_check_failed', [
                'event' => 'readiness_check_failed',
                'check' => $name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // A logging failure must never break the probe.
        }
    }
}

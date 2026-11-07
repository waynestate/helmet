<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

use Psr\Log\LoggerInterface;

/**
 * Times one request for a framework-free PHP app and records it once.
 *
 * helmet_metrics_track() starts one and arranges for finish() to run at
 * shutdown, so a page that ends with exit is still counted. The app names
 * the route group as soon as it knows it (route()); until then the group is
 * "app". When request metrics are off the timer is inert: the app calls the
 * same methods either way and never checks.
 *
 * Recording can fail (Redis down, a locked SQLite file). The failure is
 * logged, once, and swallowed: the visitor's page has already been sent and
 * must not turn into an error because a counter could not tick.
 */
final class RequestTimer
{
    private string $route = 'app';

    private bool $finished = false;

    /**
     * @param RequestMetrics|null $metrics  Where to record, or null for an
     *        inert timer.
     * @param float $startedAt  When the request started (microtime(true)).
     * @param string $method  The request method.
     */
    public function __construct(
        private readonly ?RequestMetrics $metrics,
        private readonly float $startedAt,
        private readonly string $method,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * An inert timer: every call is a no-op.
     */
    public static function off(): self
    {
        return new self(null, 0.0, 'GET');
    }

    /**
     * Whether this timer records anything.
     */
    public function isOn(): bool
    {
        return $this->metrics !== null;
    }

    /**
     * Name the route group for this request (for example "page", "asset" or
     * "not_found"). A value outside RequestMetrics' pattern is counted as
     * "other".
     */
    public function route(string $group): self
    {
        $this->route = $group;

        return $this;
    }

    /**
     * Record the request: its route group, method, status and duration. Runs
     * once; later calls do nothing.
     *
     * @param int|null $status  The status code. Defaults to the one PHP is
     *        about to send (http_response_code()), or 200.
     */
    public function finish(?int $status = null, ?float $now = null): void
    {
        if ($this->finished || $this->metrics === null) {
            return;
        }
        $this->finished = true;

        if ($status === null) {
            $code = http_response_code();
            $status = is_int($code) ? $code : 200;
        }

        try {
            $this->metrics->observe(
                $this->route,
                $this->method,
                $status,
                ($now ?? microtime(true)) - $this->startedAt,
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('HELMET could not record request metrics; the request was served.', [
                'event' => 'metrics_record_failed',
                'error' => $e->getMessage(),
            ]);
        }
    }
}

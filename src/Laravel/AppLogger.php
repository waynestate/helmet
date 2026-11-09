<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * The app's logger, as the PSR-3 logger the core classes take.
 *
 * The Laravel adapter hands this to HttpsDetector and ClientIp, so the
 * forwarded-header events (helmet.forwarded_proto_rejected and
 * helmet.forwarded_for_rejected) reach the app's own log channels, the same
 * place the rest of the adapter writes through the Log facade. It reads the
 * facade root, so a test that swaps the facade (Log::shouldReceive(),
 * Log::spy()) sees the events too.
 *
 * A logger that cannot be resolved is no logger: the detector then decides
 * exactly as it would without one. A helmet that falls off still leaves the
 * head where it was.
 *
 * @internal
 */
final class AppLogger
{
    public static function get(): ?LoggerInterface
    {
        try {
            $logger = Log::getFacadeRoot();
        } catch (\Throwable) {
            // A logging failure must never change a request.
            return null;
        }

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}

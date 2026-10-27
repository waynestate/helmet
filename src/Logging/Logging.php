<?php declare(strict_types=1);

namespace Waynestate\Helmet\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Creates a structured (JSON) logger for HELMET.
 *
 * The logger writes one JSON object per line to the given stream or file, at
 * the given minimum level. JSON lines are easy to ship to a log aggregator
 * and to grep on the Citadel host.
 */
final class Logging
{
    /**
     * Create a JSON logger.
     *
     * @param string $channel      The logger channel (usually the app name).
     * @param string $streamOrPath A stream (php://stderr) or a file path.
     * @param string $level        Minimum level: debug, info, notice, warning,
     *                             error, critical, alert or emergency.
     */
    public static function create(string $channel, string $streamOrPath, string $level = 'info'): LoggerInterface
    {
        $logger = new Logger($channel);

        $handler = new StreamHandler($streamOrPath, $level);
        $handler->setFormatter(new JsonFormatter());

        $logger->pushHandler($handler);

        return $logger;
    }
}

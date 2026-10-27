<?php declare(strict_types=1);

namespace Waynestate\Helmet\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\LogRecord;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Creates a structured (JSON) logger for HELMET.
 *
 * The logger writes one JSON object per line to the given stream or file, at
 * the given minimum level. JSON lines are easy to ship to a log aggregator
 * and to grep on the Citadel host.
 *
 * Two safety rules are built in:
 *
 *  - Secrets stay out of the logs. A processor redacts known sensitive keys
 *    (passwords, tokens, session IDs, keys, connection strings) from the
 *    record's context and extra before anything is written.
 *  - A logging failure never takes the request down. The configured stream is
 *    wrapped in a fallback group: if it cannot be written (unwritable file,
 *    full disk), the record is written to php://stderr instead.
 */
final class Logging
{
    /**
     * Keys whose values are never written to the log, compared case-insensitively.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pwd',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'session_id',
        'sessionid',
        'sid',
        'secret',
        'client_secret',
        'api_key',
        'apikey',
        'private_key',
        'connection_string',
        'conn',
        'dsn',
    ];

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

        $formatter = new JsonFormatter();

        $primary = new StreamHandler($streamOrPath, $level);
        $primary->setFormatter($formatter);

        // If the configured stream cannot be written, fall back to stderr so
        // a logging failure never takes the request down.
        $fallback = new StreamHandler('php://stderr', $level);
        $fallback->setFormatter($formatter);

        $logger->pushHandler(new FallbackGroupHandler([$primary, $fallback]));

        // Keep secrets and session IDs out of the logs.
        $logger->pushProcessor([self::class, 'redact']);

        return $logger;
    }

    /**
     * Redact sensitive keys from the record's context and extra.
     */
    public static function redact(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $extra = $record->extra;

        $newContext = self::redactArray($context);
        $newExtra = self::redactArray($extra);

        if ($newContext === $context && $newExtra === $extra) {
            return $record;
        }

        return $record->with(context: $newContext, extra: $newExtra);
    }

    /**
     * Replace the value of every sensitive key, at any depth.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }
}

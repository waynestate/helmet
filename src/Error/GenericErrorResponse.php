<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Error;

use Psr\Log\LoggerInterface;

/**
 * Builds the generic error page for an uncaught exception.
 *
 * The user gets a neutral page that says nothing about what broke; the
 * full detail (class, message, file, line, trace) goes to the log at
 * error level. A logger that throws never changes the answer: the same
 * 500 and the same body come back either way.
 */
final class GenericErrorResponse
{
    /**
     * The fixed body of the error page. It is a constant on purpose:
     * nothing from the exception may ever reach it.
     */
    private const BODY = '<!doctype html>' . "\n"
        . '<html lang="en">' . "\n"
        . '<head><meta charset="utf-8"><title>Error</title></head>' . "\n"
        . '<body><p>Something went wrong. Try again in a moment.</p></body>' . "\n"
        . '</html>' . "\n";

    /**
     * Build the error response as data.
     *
     * Logs the exception class, message, file, line and trace at error
     * level through the logger when one is given. A logger from
     * Logging::create() redacts sensitive context keys itself, so this
     * method passes the values straight through and never repeats them
     * into the response.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function build(\Throwable $e, ?LoggerInterface $logger = null): array
    {
        if ($logger !== null) {
            try {
                $logger->error('Uncaught ' . $e::class . ': ' . $e->getMessage(), [
                    'exception' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable) {
                // A failing logger never changes the answer.
            }
        }

        return [
            'status' => 500,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => self::BODY,
        ];
    }
}

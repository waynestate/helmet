<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Error;

use Psr\Log\LoggerInterface;

/**
 * Builds the generic error pages HELMET serves instead of a stack trace.
 *
 * For an uncaught exception the user gets a neutral 500 page that says
 * nothing about what broke; the full detail (class, message, file, line,
 * trace) goes to the log at error level. A logger that throws never
 * changes the answer: the same 500 and the same body come back either
 * way.
 *
 * For a client fault the app asks for a fixed 4xx page by status code.
 * The page text comes only from a constant map, so nothing from the
 * request or an exception can ever reach the body.
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
     * The fixed title and paragraph for each known client-fault status.
     * It is a constant on purpose: nothing from the request may ever
     * reach the page. A status that is not listed falls back to the
     * 400 text.
     */
    private const CLIENT_ERROR_TEXT = [
        400 => ['Bad request.', 'That request could not be understood.'],
        401 => ['Sign in required.', 'Sign in to see this page.'],
        403 => ['Forbidden.', 'You do not have access to this page.'],
        404 => ['Not found.', 'That page does not exist.'],
        405 => ['Method not allowed.', 'That page does not accept this kind of request.'],
        410 => ['Gone.', 'That page is no longer here.'],
        429 => ['Too many requests.', 'Try again in a moment.'],
    ];

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

    /**
     * Build a fixed 4xx page for a client fault as data.
     *
     * Takes only the status code, so there is no parameter that carries a
     * message into the page. The body comes from the constant map, so
     * nothing from the request or an exception can reach it. A status
     * outside the 400s throws.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function clientError(int $status): array
    {
        if ($status < 400 || $status > 499) {
            throw new \InvalidArgumentException('clientError() takes a 4xx status.');
        }

        [$title, $paragraph] = self::CLIENT_ERROR_TEXT[$status] ?? self::CLIENT_ERROR_TEXT[400];

        $body = '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head><meta charset="utf-8"><title>' . $title . '</title></head>' . "\n"
            . '<body><p>' . $paragraph . '</p></body>' . "\n"
            . '</html>' . "\n";

        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => $body,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * H42 for Laravel: answer a client fault with HELMET's fixed 4xx page.
 *
 * When `helmet.error_pages` is on, a 4xx HTTP exception (abort(404), a
 * missing route, a wrong method, a throttle, a model that was not found)
 * is answered with the same page helmet_client_error_response() builds,
 * so the exception message never reaches the visitor. The switch is read
 * on every render, not at boot, so a config change takes effect at once.
 *
 * What stays with Laravel, on purpose:
 *
 * - every 5xx (the framework's handler and APP_DEBUG=false own those; see
 *   H43),
 * - a ValidationException (the 422 with its errors, or the redirect back
 *   with them) and an AuthenticationException (the redirect to the login
 *   page), because neither is an HttpExceptionInterface, so this callback
 *   never sees them,
 * - any request that expects JSON, because an API client wants JSON back,
 *   not an HTML page.
 *
 * The exception's own headers (Allow on a 405, Retry-After on a 429) are
 * kept, because the framework sets them and the protocol wants them;
 * HELMET's Content-Type and Cache-Control win over anything they share.
 */
final class ClientErrorPages
{
    /**
     * Add the renderable callback to Laravel's exception handler. A handler
     * that is not the framework's own (an app that swapped it for something
     * else entirely) is left alone.
     */
    public static function register(ExceptionHandler $handler): void
    {
        if (!$handler instanceof Handler) {
            return;
        }

        $handler->renderable(static fn(HttpExceptionInterface $e, Request $request): ?Response => self::render($e, $request));
    }

    /**
     * The fixed page for a 4xx HTTP exception, or null to let Laravel
     * answer as it always does.
     */
    public static function render(HttpExceptionInterface $e, Request $request): ?Response
    {
        if ((bool) config('helmet.error_pages') !== true) {
            return null;
        }

        $status = $e->getStatusCode();
        if ($status < 400 || $status > 499) {
            return null;
        }

        if ($request->expectsJson()) {
            return null;
        }

        $page = \Waynestate\Helmet\helmet_client_error_response($status);

        /** @var array<string, string|list<string>> $headers */
        $headers = $e->getHeaders();
        foreach ($page['headers'] as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp((string) $existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers[$name] = $value;
        }

        return new Response($page['body'], $page['status'], $headers);
    }
}

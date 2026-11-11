<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * PSR-15 middleware that receives CSP violation reports.
 *
 * On its path (default /csp-report) it answers the request itself, exactly
 * as Route::cspReport() and helmet_csp_report() do, because all three go
 * through CspReport: 204 for a report it accepts, 415, 413 or 400 for one it
 * refuses, 405 with Allow: POST for any other method. The app never sees the
 * request. On any other path it passes the request through untouched.
 *
 * Put it in front of the request-forgery guard and any sign-in middleware:
 * a browser POSTs reports with no CSRF token and often with no cookies. The
 * body is read at most CspReport::MAX_BODY_BYTES plus one byte deep, so an
 * oversized report is refused without being read whole. Like a good line
 * judge, it calls what it sees and never reads the whole replay.
 */
final class CspReportMiddleware implements MiddlewareInterface
{
    /**
     * The path the middleware answers when none is given.
     */
    public const string DEFAULT_PATH = '/csp-report';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $path = self::DEFAULT_PATH,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RequestMetrics $metrics = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->path) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        $answer = CspReport::answer(
            $method,
            $request->getHeaderLine('Content-Type'),
            strtoupper($method) === 'POST' ? self::read($request->getBody()) : '',
            CspReport::declaredLength($request->getHeaderLine('Content-Length')),
            $this->logger,
            $this->metrics,
        );

        $response = $this->responseFactory->createResponse($answer['status']);
        foreach ($answer['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody($this->streamFactory->createStream($answer['body']));
    }

    /**
     * The body, read from the start and at most one byte past the cap, so
     * the cap check still sees an oversized report as oversized.
     */
    private static function read(StreamInterface $body): string
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $limit = CspReport::MAX_BODY_BYTES + 1;
        $read = '';
        while (strlen($read) < $limit && !$body->eof()) {
            $chunk = $body->read($limit - strlen($read));
            if ($chunk === '') {
                break;
            }
            $read .= $chunk;
        }

        return $read;
    }
}

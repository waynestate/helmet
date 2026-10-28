<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Health;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that answers the health check path.
 *
 * The platform's container orchestrator probes a path to decide whether the app
 * is up. This middleware answers that probe with a 200 and the body "ok"
 * without touching the application, so a running container reports up even
 * while the app is slow. Any other request is passed through unchanged.
 */
final class HealthCheck implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $path = '/up',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        if (($method === 'GET' || $method === 'HEAD') && $request->getUri()->getPath() === $this->path) {
            return $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withBody($this->streamFactory->createStream('ok'));
        }

        return $handler->handle($request);
    }
}

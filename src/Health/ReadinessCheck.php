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
 * PSR-15 middleware that answers the readiness path.
 *
 * GET or HEAD on the path (default /ready) runs the Readiness checks and
 * answers 200 or 503 with the JSON report; any other request passes through.
 * Mount it beside HealthCheck, never instead of it: /up stays the liveness
 * answer and touches nothing.
 */
final class ReadinessCheck implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Readiness $readiness,
        private readonly string $path = Readiness::DEFAULT_PATH,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        if (($method !== 'GET' && $method !== 'HEAD') || $request->getUri()->getPath() !== $this->path) {
            return $handler->handle($request);
        }

        $answer = $this->readiness->response();
        $response = $this->responseFactory->createResponse($answer['status']);
        foreach ($answer['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody($this->streamFactory->createStream($answer['body']));
    }
}

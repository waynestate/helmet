<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Error;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Waynestate\Helmet\Error\GenericErrorResponse;

final class GenericErrorResponseTest extends TestCase
{
    public function testTheBodyNeverContainsExceptionDetail(): void
    {
        $exception = new \RuntimeException('SELECT * FROM students WHERE password = hunter2');

        $response = (new GenericErrorResponse())->build($exception);

        self::assertSame(500, $response['status']);
        self::assertSame(
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            $response['headers'],
        );

        // The body is a fixed page: none of the exception's message, class
        // name or file path may appear in it.
        self::assertStringNotContainsString('hunter2', $response['body']);
        self::assertStringNotContainsString('students', $response['body']);
        self::assertStringNotContainsString('RuntimeException', $response['body']);
        self::assertStringNotContainsString('GenericErrorResponseTest', $response['body']);
        self::assertStringNotContainsString(__FILE__, $response['body']);
        self::assertStringNotContainsString(__DIR__, $response['body']);
        self::assertStringContainsString('Something went wrong.', $response['body']);
        self::assertStringContainsString('Try again in a moment.', $response['body']);
    }

    public function testTheSameBodyIsReturnedForEveryException(): void
    {
        $builder = new GenericErrorResponse();

        $first = $builder->build(new \LogicException('first failure'));
        $second = $builder->build(new \InvalidArgumentException('second failure'));

        self::assertSame($first['body'], $second['body']);
    }

    public function testTheLoggerReceivesOneErrorRecordWithMessageAndClass(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $exception = new \DomainException('the door is locked');

        (new GenericErrorResponse())->build($exception, $logger);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringContainsString('the door is locked', $logger->records[0]['message']);
        self::assertStringContainsString(\DomainException::class, $logger->records[0]['message']);
        self::assertSame(\DomainException::class, $logger->records[0]['context']['exception']);
        self::assertSame(__FILE__, $logger->records[0]['context']['file']);
        self::assertIsInt($logger->records[0]['context']['line']);
        self::assertIsString($logger->records[0]['context']['trace']);
    }

    public function testAThrowingLoggerDoesNotChangeTheResult(): void
    {
        $throwing = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }
        };

        $builder = new GenericErrorResponse();
        $exception = new \RuntimeException('boom');

        $withLogger = $builder->build($exception, $throwing);
        $withoutLogger = $builder->build($exception);

        self::assertSame($withoutLogger, $withLogger);
        self::assertSame(500, $withLogger['status']);
    }

    public function testNoLoggerMeansNoLoggingAndTheSameResponse(): void
    {
        $response = (new GenericErrorResponse())->build(new \RuntimeException('quiet failure'));

        self::assertSame(500, $response['status']);
        self::assertStringContainsString('Something went wrong.', $response['body']);
    }

    public function testClientErrorReturnsTheFixedPageForEachKnownStatus(): void
    {
        $builder = new GenericErrorResponse();
        $expected = [
            400 => ['Bad request.', 'That request could not be understood.'],
            401 => ['Sign in required.', 'Sign in to see this page.'],
            403 => ['Forbidden.', 'You do not have access to this page.'],
            404 => ['Not found.', 'That page does not exist.'],
            405 => ['Method not allowed.', 'That page does not accept this kind of request.'],
            410 => ['Gone.', 'That page is no longer here.'],
            429 => ['Too many requests.', 'Try again in a moment.'],
        ];

        $bodies = [];

        foreach ($expected as $status => [$title, $paragraph]) {
            $response = $builder->clientError($status);

            self::assertSame($status, $response['status']);
            self::assertSame(
                [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ],
                $response['headers'],
            );
            self::assertStringContainsString('<title>' . $title . '</title>', $response['body']);
            self::assertStringContainsString('<p>' . $paragraph . '</p>', $response['body']);
            $bodies[] = $response['body'];
        }

        self::assertCount(7, array_unique($bodies));
    }

    public function testClientErrorUsesTheBadRequestTextForAnUnlistedStatus(): void
    {
        $response = (new GenericErrorResponse())->clientError(418);

        self::assertSame(418, $response['status']);
        self::assertStringContainsString('Bad request.', $response['body']);
    }

    public function testClientErrorRejectsAStatusOutsideTheFourHundreds(): void
    {
        $builder = new GenericErrorResponse();

        foreach ([200, 399, 500, 0, 600] as $status) {
            try {
                $builder->clientError($status);
                self::fail('clientError() accepted a status outside the 400s');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('clientError() takes a 4xx status.', $e->getMessage());
                self::assertStringNotContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function testClientErrorBodiesAreStaticAndEscapeFree(): void
    {
        $builder = new GenericErrorResponse();

        self::assertSame(
            $builder->clientError(404)['body'],
            $builder->clientError(404)['body'],
        );

        foreach ([400, 401, 403, 404, 405, 410, 418, 429] as $status) {
            $body = $builder->clientError($status)['body'];

            self::assertStringNotContainsString('<script', $body);
            self::assertStringNotContainsString('$', $body);
            self::assertStringNotContainsString('{', $body);
        }
    }
}

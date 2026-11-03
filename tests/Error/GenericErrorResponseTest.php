<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Error;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
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
        $logger = new class extends AbstractLogger
        {
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
        $throwing = new class implements LoggerInterface
        {
            use \Psr\Log\LoggerTrait;

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('log disk on fire');
            }

            public function debug(string|\Stringable $message, array $context = []): void
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
}

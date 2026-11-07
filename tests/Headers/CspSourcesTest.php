<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Headers\SecurityHeaders;

/**
 * Every CSP source an app can put in its csp option comes out as the browser
 * expects it. Before 1.0, a quoted source other than 'self', 'none' and
 * 'unsafe-inline' was treated as a host: 'sha256-abc=' became
 * "https://'sha256-abc=' http://'sha256-abc=' 'sha256-abc='".
 */
final class CspSourcesTest extends TestCase
{
    /**
     * @param list<string> $sources
     */
    private function directive(string $directive, array $sources): string
    {
        $csp = SecurityHeaders::buildCsp([$directive => $sources], 'n0nce');
        $parts = array_map('trim', explode(';', $csp));
        foreach ($parts as $part) {
            if (str_starts_with($part, $directive . ' ')) {
                return $part;
            }
        }

        self::fail($directive . ' missing from ' . $csp);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function sources(): iterable
    {
        yield 'unsafe-eval' => ['worker-src', ["'self'", "'unsafe-eval'"], "worker-src 'self' 'unsafe-eval'"];
        yield 'wasm-unsafe-eval' => ['worker-src', ["'wasm-unsafe-eval'"], "worker-src 'wasm-unsafe-eval'"];
        yield 'unsafe-hashes with a hash' => ['style-src-attr', ["'unsafe-hashes'", "'sha256-q1w2e3r4t5y6u7i8o9p0+/AbCdEfGhIjKlMnOpQrStU='"], "style-src-attr 'sha256-q1w2e3r4t5y6u7i8o9p0+/AbCdEfGhIjKlMnOpQrStU=' 'unsafe-hashes'"];
        yield 'a hash alone' => ['style-src-attr', ["'sha384-abc'"], "style-src-attr 'sha384-abc'"];
        yield 'sha512' => ['img-src', ["'self'", "'sha512-xyz=='"], "img-src 'self' 'sha512-xyz=='"];
        yield 'report-sample' => ['style-src-attr', ["'self'", "'report-sample'"], "style-src-attr 'self' 'report-sample'"];
        yield 'https scheme' => ['img-src', ['https:'], 'img-src https:'];
        yield 'mediastream scheme' => ['media-src', ["'self'", 'mediastream:'], "media-src 'self' mediastream:"];
        yield 'a full origin is untouched' => ['img-src', ['https://images.example.edu'], 'img-src https://images.example.edu'];
    }

    /**
     * @param list<string> $sources
     */
    #[DataProvider('sources')]
    public function testSourcesComeOutAsWritten(string $directive, array $sources, string $expected): void
    {
        self::assertSame($expected, $this->directive($directive, $sources));
    }

    public function testNoQuotedSourceIsEverPrefixedWithAScheme(): void
    {
        $csp = SecurityHeaders::buildCsp([
            'script-src' => ["'self'", "'unsafe-eval'", "'sha256-abc='"],
            'style-src-attr' => ["'unsafe-hashes'", "'sha256-abc='"],
        ], 'n0nce');

        self::assertStringNotContainsString("://'", $csp);
    }

    public function testAnUnknownQuotedSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'unsafe-inlined'");
        SecurityHeaders::buildCsp(['style-src-attr' => ["'unsafe-inlined'"]], 'n0nce');
    }

    public function testAMalformedHashThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SecurityHeaders::buildCsp(['script-src' => ["'sha1-abc='"]], 'n0nce');
    }
}

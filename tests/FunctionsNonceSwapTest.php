<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The framework-free helmet_csp_nonce_swap() helper.
 *
 * A raw PHP app serving a built Vue/Vite page sets Vite's html.cspNonce to a
 * placeholder at build time; this helper replaces every occurrence of that
 * placeholder with the response nonce helmet_boot() returned. It is pure: no
 * output, no headers, no globals.
 */
final class FunctionsNonceSwapTest extends TestCase
{
    private const PLACEHOLDER = 'CSP_NONCE_SLOT_FOR_HELMET';

    public function testSwapReplacesEveryOccurrence(): void
    {
        $html = '<script type="module" crossorigin src="/assets/index.js">' . self::PLACEHOLDER . '</script><link rel="stylesheet" href="' . self::PLACEHOLDER . '">';

        $swapped = \Waynestate\Helmet\helmet_csp_nonce_swap($html, 'nonce123');

        self::assertSame('<script type="module" crossorigin src="/assets/index.js">nonce123</script><link rel="stylesheet" href="nonce123">', $swapped);
        self::assertStringNotContainsString(self::PLACEHOLDER, $swapped);
    }

    public function testSwapEscapesTheNonce(): void
    {
        $html = '<script nonce="' . self::PLACEHOLDER . '"></script>';

        $swapped = \Waynestate\Helmet\helmet_csp_nonce_swap($html, "ab'c\"d");

        // The escaped form is computed with the same call helmet_csp_nonce_meta() uses, so the expectation holds on every supported PHP branch (8.4+ renders the single quote as &apos; instead of &#039;).
        $escaped = htmlspecialchars("ab'c\"d", ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertStringNotContainsString("nonce=\"ab'c\"d\"", $swapped);
        self::assertSame('<script nonce="' . $escaped . '"></script>', $swapped);
    }

    public function testSwapLeavesHtmlWithoutThePlaceholderUnchanged(): void
    {
        $html = '<html><head><title>No bundle here</title></head><body></body></html>';

        self::assertSame($html, \Waynestate\Helmet\helmet_csp_nonce_swap($html, 'nonce123'));
    }

    public function testSwapRejectsAShortPlaceholder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helmet_csp_nonce_swap() "placeholder" must be at least 16 characters.');

        \Waynestate\Helmet\helmet_csp_nonce_swap('<p>x</p>', 'nonce123', 'short');
    }

    public function testSwapRejectsAnEmptyNonce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helmet_csp_nonce_swap() "nonce" must not be empty.');

        \Waynestate\Helmet\helmet_csp_nonce_swap('<p>x</p>', '');
    }

    public function testSwapSupportsACustomPlaceholder(): void
    {
        $custom = 'MY_VITE_NONCE_SLOT';

        $html = '<script nonce="' . $custom . '"></script>';

        $swapped = \Waynestate\Helmet\helmet_csp_nonce_swap($html, 'nonce123', $custom);

        self::assertSame('<script nonce="nonce123"></script>', $swapped);
    }
}

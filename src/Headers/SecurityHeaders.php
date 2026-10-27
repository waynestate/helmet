<?php declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

use ParagonIE\CSPBuilder\CSPBuilder;

/**
 * The single place that defines HELMET's security headers and the default
 * Content-Security-Policy.
 *
 * Both the PSR-15 middleware and the framework-free helmet_boot() path build
 * their headers from this class, so the two consumer paths always agree.
 */
final class SecurityHeaders
{
    /**
     * The default Content-Security-Policy.
     *
     * This is the union of the two WayneUI recipes (the framework-free raw PHP
     * recipe and the Laravel/Blade recipe) plus frame-ancestors 'self':
     *
     *  - default-src, style-src, style-src-attr, script-src, font-src, img-src
     *    and connect-src come from the raw PHP recipe.
     *  - style-src-elem comes from the Laravel/Blade recipe, which needs
     *    'unsafe-inline' for element style attributes that the raw recipe
     *    covers only via style-src-attr.
     *  - frame-ancestors 'self' is added by HELMET so the default also covers
     *    clickjacking at the CSP layer.
     *
     * @return array<string, list<string>>
     */
    public static function defaultCspPolicy(): array
    {
        return [
            'default-src' => ["'self'"],
            'style-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            'style-src-attr' => ["'unsafe-inline'"],
            'style-src-elem' => ["'self'", "'unsafe-inline'", 'https://wayneui.apps.wayne.edu'],
            'script-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            'font-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            'img-src' => ["'self'", 'data:', 'https://wayneui.apps.wayne.edu'],
            'connect-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            'frame-ancestors' => ["'self'"],
        ];
    }

    /**
     * Generate a CSP nonce: 18 random bytes, base64 encoded.
     */
    public static function generateNonce(): string
    {
        return base64_encode(random_bytes(18));
    }

    /**
     * Build the Content-Security-Policy header value for the given policy and
     * nonce.
     *
     * @param array<string, list<string>> $policy
     */
    public static function buildCsp(array $policy, string $nonce): string
    {
        $builder = new CSPBuilder();

        foreach ($policy as $directive => $sources) {
            foreach ($sources as $source) {
                self::addSource($builder, $directive, $source);
            }
        }

        // The nonce is bound to script-src. script-src is always present in the
        // default policy, so the directive exists before the nonce is added.
        $builder->nonce('script-src', $nonce);

        return $builder->compile();
    }

    /**
     * Build the full list of security headers.
     *
     * @param array<string, list<string>> $policy
     *
     * @return array<string, string> Header name to value.
     */
    public static function build(array $policy, string $nonce, bool $isHttps): array
    {
        $headers = [
            'Content-Security-Policy' => self::buildCsp($policy, $nonce),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        ];

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Add a single source to a directive, using the builder's dedicated
     * methods for the special keywords so they compile in the correct order.
     */
    private static function addSource(CSPBuilder $builder, string $directive, string $source): void
    {
        switch ($source) {
            case "'self'":
                $builder->setSelfAllowed($directive, true);
                break;
            case "'none'":
                $builder->addDirective($directive, false);
                break;
            case 'data:':
                $builder->setDataAllowed($directive, true);
                break;
            case "'unsafe-inline'":
                $builder->setAllowUnsafeInline($directive, true);
                break;
            default:
                $builder->addSource($directive, $source);
                break;
        }
    }
}

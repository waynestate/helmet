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
     * recipe and the Laravel/Blade recipe) plus a set of hardening directives
     * that HELMET adds on top:
     *
     *  - default-src, style-src, style-src-attr, script-src, font-src, img-src
     *    and connect-src come from the raw PHP recipe.
     *  - style-src-elem comes from the Laravel/Blade recipe. It does not carry
     *    'unsafe-inline'; only style-src-attr does, and only for the inline
     *    style="..." attributes the recipes rely on.
     *  - frame-ancestors 'self' covers clickjacking at the CSP layer.
     *  - base-uri 'self', object-src 'none' and form-action 'self' close the
     *    base-tag, plugin and form-targeting gaps that default-src alone does
     *    not cover in every browser.
     *
     * @return array<string, list<string>>
     */
    public static function defaultCspPolicy(): array
    {
        return [
            'default-src' => ["'self'"],
            'style-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            // 'unsafe-inline' is kept only here, on style-src-attr: the WayneUI
            // raw PHP and Laravel/Blade recipes set inline style="..." attributes
            // (block spacing) that are silently dropped without it. style-src-attr
            // governs only the style attribute, not <style> elements or
            // stylesheets, so this is the narrowest allowance that keeps those
            // recipes working. style-src-elem deliberately does not carry it.
            'style-src-attr' => ["'unsafe-inline'"],
            'style-src-elem' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            'script-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            // data: is allowed for fonts: Vite and Vue inline small fonts as
            // data: URIs, and the nonce does not cover font sources.
            'font-src' => ["'self'", 'data:', 'https://wayneui.apps.wayne.edu'],
            'img-src' => ["'self'", 'data:', 'https://wayneui.apps.wayne.edu'],
            'connect-src' => ["'self'", 'https://wayneui.apps.wayne.edu'],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'form-action' => ["'self'"],
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
     * The nonce is bound to script-src, and to style-src and style-src-elem
     * when those directives are present in the merged policy. Vite and Vue
     * inject <style> and <link> tags that carry the nonce, so the style
     * directives need it too. An app may remove a directive with null, so the
     * nonce is only added to the directives that are present.
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

        // The nonce is bound to script-src, and to style-src and style-src-elem
        // when those directives are present. Vite and Vue inject <style> and
        // <link> tags that carry the nonce, so the style directives need it
        // too. An app may remove a directive with null, so only nonce the
        // directives that are present in the merged policy.
        foreach (['script-src', 'style-src', 'style-src-elem'] as $directive) {
            if (array_key_exists($directive, $policy)) {
                $builder->nonce($directive, $nonce);
            }
        }

        // strict-dynamic lets a script that was loaded with the nonce load other
        // scripts dynamically, without opening script-src to 'unsafe-inline'.
        $builder->setStrictDynamic('script-src', true);

        return $builder->compile();
    }

    /**
     * Merge a custom CSP policy over the default, per directive.
     *
     * A directive present in $custom replaces the default's value for that
     * directive. A directive set to null in $custom is removed from the merged
     * policy. Directives absent from $custom keep the default's value. This is
     * how an app extends or narrows the default without re-declaring every
     * directive, and how it removes one it does not want.
     *
     * @param array<string, list<string>> $default
     * @param array<string, list<string>|null> $custom
     *
     * @return array<string, list<string>>
     */
    public static function mergePolicies(array $default, array $custom): array
    {
        $merged = $default;

        foreach ($custom as $directive => $sources) {
            if ($sources === null) {
                unset($merged[$directive]);
            } else {
                $merged[$directive] = $sources;
            }
        }

        return $merged;
    }

    /**
     * Build the full list of security headers.
     *
     * @param array<string, list<string>> $policy
     * @param int $hstsMaxAge The HSTS max-age in seconds. Only used when
     *        $isHttps is true.
     * @param bool $hstsIncludeSubDomains Whether HSTS covers subdomains. Only
     *        used when $isHttps is true.
     *
     * @return array<string, string> Header name to value.
     */
    public static function build(
        array $policy,
        string $nonce,
        bool $isHttps,
        int $hstsMaxAge = 31536000,
        bool $hstsIncludeSubDomains = true
    ): array {
        $headers = [
            'Content-Security-Policy' => self::buildCsp($policy, $nonce),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Disable the browser features a typical app never uses. An
            // injected script cannot use a feature the page has disabled.
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
        ];

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = self::buildHsts($hstsMaxAge, $hstsIncludeSubDomains);
        }

        return $headers;
    }

    /**
     * Build the Strict-Transport-Security header value.
     *
     * Roll out in stages: start with a short max-age, raise it once nothing
     * breaks, and only keep includeSubDomains when every subdomain serves
     * HTTPS. preload is a decision for the domain owner, not for one app, so
     * it is never added here.
     *
     * @param int $maxAge The max-age in seconds.
     * @param bool $includeSubDomains Whether to cover subdomains.
     *
     * @throws \InvalidArgumentException If $maxAge is negative.
     */
    public static function buildHsts(int $maxAge = 31536000, bool $includeSubDomains = true): string
    {
        if ($maxAge < 0) {
            throw new \InvalidArgumentException('HSTS max-age must be a non-negative number of seconds.');
        }

        $value = 'max-age=' . $maxAge;
        if ($includeSubDomains) {
            $value .= '; includeSubDomains';
        }

        return $value;
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
            case 'blob:':
                $builder->setBlobAllowed($directive, true);
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

<?php

declare(strict_types=1);

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
     * The default Permissions-Policy: deny the browser features a typical
     * app never uses.
     *
     * An injected script cannot use a feature the page has disabled, so the
     * default is a deny list of the features that are most useful to an
     * attacker and least useful to a form-filling app: the camera, the
     * location, the microphone, payment and USB. interest-cohort is not
     * included: the browser vendors dropped it, so denying it buys nothing.
     *
     * @return array<string, list<string>>
     */
    public static function defaultPermissionsPolicy(): array
    {
        return [
            'camera' => [],
            'geolocation' => [],
            'microphone' => [],
            'payment' => [],
            'usb' => [],
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
     * When $reportUri is given, the policy also carries `report-to
     * csp-endpoint` (the current mechanism, which needs the
     * Reporting-Endpoints header that build() sends) and `report-uri` (the
     * fallback for older browsers). The URI is validated first.
     *
     * @param array<string, list<string>> $policy
     * @param string|null $reportUri The CSP report destination, or null to
     *        send no reporting directives.
     */
    public static function buildCsp(array $policy, string $nonce, ?string $reportUri = null): string
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

        if ($reportUri !== null) {
            // report-to is the current mechanism and needs the
            // Reporting-Endpoints header (sent by build()); report-uri is the
            // fallback for older browsers. Both point at the same destination.
            self::validateReportUri($reportUri);
            $builder->setReportTo('csp-endpoint');
            $builder->setReportUri($reportUri);
        }

        return $builder->compile();
    }

    /**
     * Validate a CSP report URI and return it unchanged.
     *
     * A report URI is either an absolute https:// URL with a host, or a path
     * relative to the page's origin that starts with a single slash.
     * Whitespace, control characters and double quotes are rejected: a newline
     * would let a caller inject a second header, and a double quote would break
     * the quoted Reporting-Endpoints value.
     *
     * @throws InvalidCspReportUriException when the URI is not a valid report destination
     */
    public static function validateReportUri(string $uri): string
    {
        if ($uri === '') {
            throw new InvalidCspReportUriException($uri);
        }

        if (preg_match('/[\x00-\x20"]/', $uri) === 1) {
            throw new InvalidCspReportUriException($uri);
        }

        // A path relative to the page's origin. A double leading slash would
        // be protocol-relative, which is not a report path.
        if (str_starts_with($uri, '/')) {
            if (str_starts_with($uri, '//')) {
                throw new InvalidCspReportUriException($uri);
            }

            return $uri;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidCspReportUriException($uri);
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidCspReportUriException($uri);
        }

        if ((string) ($parts['host'] ?? '') === '') {
            throw new InvalidCspReportUriException($uri);
        }

        return $uri;
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
     * @param string|null $cspReportUri The CSP report destination, or null to
     *        send no reporting directives.
     * @param bool $cspReportOnly When true, the policy is sent as
     *        Content-Security-Policy-Report-Only instead of
     *        Content-Security-Policy.
     * @param string|null $coop The Cross-Origin-Opener-Policy value, or null
     *        to send no COOP header.
     * @param string|null $corp The Cross-Origin-Resource-Policy value, or
     *        null to send no CORP header.
     * @param array<string, list<string>>|null $permissionsPolicy The
     *        Permissions-Policy feature map, or null to use the default deny
     *        list. An empty array sends no Permissions-Policy header.
     * @param bool $hstsPreload Whether to append the preload directive to
     *        HSTS. Only used when $isHttps is true.
     * @param bool $trustedTypesEnabled Whether to append the Trusted Types
     *        directives to the report-only CSP header.
     * @param list<string> $trustedTypesPolicies The Trusted Types policy
     *        names, used when $trustedTypesEnabled is true.
     *
     * @return array<string, string> Header name to value.
     *
     * @throws \InvalidArgumentException If $coop, $corp, $permissionsPolicy,
     *         $trustedTypesPolicies or the HSTS preload combination is invalid.
     */
    public static function build(
        array $policy,
        string $nonce,
        bool $isHttps,
        int $hstsMaxAge = 31536000,
        bool $hstsIncludeSubDomains = true,
        ?string $cspReportUri = null,
        bool $cspReportOnly = false,
        ?string $coop = 'same-origin',
        ?string $corp = null,
        ?array $permissionsPolicy = null,
        bool $hstsPreload = false,
        bool $trustedTypesEnabled = false,
        array $trustedTypesPolicies = ['default'],
    ): array {
        $cspHeaderName = $cspReportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $cspValue = self::buildCsp($policy, $nonce, $cspReportUri);
        if ($trustedTypesEnabled) {
            // Trusted Types is only ever sent on the report-only header: it
            // reports violations without breaking the page, so an app can see
            // what it would need to allow before enforcing. The policies are
            // validated even when report-only is off, so a bad value fails at
            // boot instead of silently doing nothing.
            $trustedTypes = self::buildTrustedTypes($trustedTypesPolicies);
            if ($cspReportOnly) {
                $cspValue .= ' ' . $trustedTypes;
            }
        }

        $headers = [
            $cspHeaderName => $cspValue,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        // Disable the browser features a typical app never uses. An injected
        // script cannot use a feature the page has disabled. null means "use
        // the default deny list"; an empty array means "send no header".
        $permissionsPolicyHeader = self::buildPermissionsPolicy(
            $permissionsPolicy ?? self::defaultPermissionsPolicy(),
        );
        if ($permissionsPolicyHeader !== null) {
            $headers['Permissions-Policy'] = $permissionsPolicyHeader;
        }

        if ($coop !== null) {
            $headers['Cross-Origin-Opener-Policy'] = self::buildCoop($coop);
        }

        if ($corp !== null) {
            $headers['Cross-Origin-Resource-Policy'] = self::buildCorp($corp);
        }

        if ($cspReportUri !== null) {
            // report-to needs the Reporting-Endpoints header to know where
            // "csp-endpoint" points. It is sent in both enforce and report-only
            // modes, because report-only is exactly when reports are wanted.
            $headers['Reporting-Endpoints'] = 'csp-endpoint="' . $cspReportUri . '"';
        }

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = self::buildHsts($hstsMaxAge, $hstsIncludeSubDomains, $hstsPreload);
        }

        return $headers;
    }

    /**
     * Build the Strict-Transport-Security header value.
     *
     * Roll out in stages: start with a short max-age, raise it once nothing
     * breaks, and only keep includeSubDomains when every subdomain serves
     * HTTPS. preload is a decision for the domain owner, not for one app, so
     * it is opt-in: when asked for it is appended, but only with a max-age of
     * at least a year and includeSubDomains on, because the browser refuses a
     * preload HSTS header that does not meet both.
     *
     * @param int $maxAge The max-age in seconds.
     * @param bool $includeSubDomains Whether to cover subdomains.
     * @param bool $preload Whether to append the preload directive.
     *
     * @throws \InvalidArgumentException If $maxAge is negative, or if $preload
     *         is set without a year-long max-age and includeSubDomains.
     */
    public static function buildHsts(int $maxAge = 31536000, bool $includeSubDomains = true, bool $preload = false): string
    {
        if ($maxAge < 0) {
            throw new \InvalidArgumentException('HSTS max-age must be a non-negative number of seconds.');
        }

        if ($preload && ($maxAge < 31536000 || ! $includeSubDomains)) {
            throw new \InvalidArgumentException(
                'HSTS preload requires a max-age of at least 31536000 and includeSubDomains.',
            );
        }

        $value = 'max-age=' . $maxAge;
        if ($includeSubDomains) {
            $value .= '; includeSubDomains';
        }
        if ($preload) {
            $value .= '; preload';
        }

        return $value;
    }

    /**
     * Build the Trusted Types CSP directives.
     *
     * Trusted Types stops an injected script from creating new scripts through
     * the DOM: only scripts from an allowed policy may run. The directives are
     * only ever sent on the report-only header, so an app can see what it
     * would need to allow before it is forced to.
     *
     * @param list<string> $policies The allowed policy names.
     *
     * @throws \InvalidArgumentException If the list is empty or a name is not
     *         an identifier-like policy name.
     */
    public static function buildTrustedTypes(array $policies): string
    {
        if ($policies === []) {
            throw new \InvalidArgumentException('Trusted Types requires at least one policy.');
        }

        foreach ($policies as $policy) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says list<string>, but a caller may pass a non-string at runtime)
            if (! is_string($policy) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $policy) !== 1) {
                throw new \InvalidArgumentException(
                    'Trusted Types policies must be identifier-like names: letters, digits, '
                    . 'hyphens and underscores, starting with a letter.',
                );
            }
        }

        $quoted = array_map(
            static fn(string $policy): string => "'" . $policy . "'",
            $policies,
        );

        return "require-trusted-types-for 'script'; trusted-types " . implode(' ', $quoted);
    }

    /**
     * Decide whether a response path should carry Cache-Control: no-store.
     *
     * The feature is off unless $enabled is true. When enabled with no paths,
     * every response is covered. When enabled with paths, only a response whose
     * path starts with one of the given prefixes is covered.
     *
     * @param list<string> $paths Path prefixes to cover, or empty for all.
     */
    public static function noStoreApplies(bool $enabled, string $path, array $paths): bool
    {
        if (! $enabled) {
            return false;
        }

        if ($paths === []) {
            return true;
        }

        foreach ($paths as $prefix) {
            // @phpstan-ignore function.alreadyNarrowedType (the docblock says list<string>, but a caller may pass a non-string at runtime)
            if (is_string($prefix) && $prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the Cross-Origin-Opener-Policy header value.
     *
     * COOP puts the page in its own browsing context group, so a cross-origin
     * page cannot reach it through window.opener or the cross-origin Web
     * APIs. The default is same-origin: only same-origin pages may open it.
     *
     * @throws \InvalidArgumentException If $value is not a valid COOP value.
     */
    public static function buildCoop(string $value): string
    {
        $allowed = ['unsafe-none', 'same-origin-allow-popups', 'same-origin', 'noopener-allow-popups'];

        if (! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                'COOP must be one of: ' . implode(', ', $allowed) . '.',
            );
        }

        return $value;
    }

    /**
     * Build the Cross-Origin-Resource-Policy header value.
     *
     * CORP declares who may load this page's resources cross-origin. It is
     * the resource-side half of the cross-origin isolation pair with COOP.
     *
     * @throws \InvalidArgumentException If $value is not a valid CORP value.
     */
    public static function buildCorp(string $value): string
    {
        $allowed = ['same-site', 'same-origin', 'cross-origin'];

        if (! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                'CORP must be one of: ' . implode(', ', $allowed) . '.',
            );
        }

        return $value;
    }

    /**
     * Build the Permissions-Policy header value from a feature map.
     *
     * Each feature maps to its allowlist: an empty list denies the feature,
     * 'self' allows the page's own origin, and an https origin allows that
     * origin. The header is rendered as `feature=(allowlist)`, with the
     * allowlist entries separated by spaces and origins quoted.
     *
     * An empty map returns null: there is nothing to say, so no header.
     *
     * The map is validated at runtime because it comes from user config: each
     * feature must be a lowercase string and each allowlist an array of
     * 'self' and https origins.
     *
     * @param array<int|string, mixed> $policy Feature name to allowlist.
     *
     * @return string|null The header value, or null for an empty map.
     *
     * @throws \InvalidArgumentException If a feature name or an allowlist
     *         entry is invalid.
     */
    public static function buildPermissionsPolicy(array $policy): ?string
    {
        if ($policy === []) {
            return null;
        }

        $parts = [];

        foreach ($policy as $feature => $allowlist) {
            if (! is_string($feature)) {
                throw new \InvalidArgumentException(
                    'Permissions-Policy feature names must be strings: got ' . gettype($feature) . '.',
                );
            }

            if (preg_match('/^[a-z][a-z0-9-]*$/', $feature) !== 1) {
                throw new \InvalidArgumentException(
                    "Permissions-Policy feature name \"{$feature}\" is invalid: "
                    . 'use lowercase letters, digits and hyphens, starting with a letter.',
                );
            }

            if (! is_array($allowlist)) {
                throw new \InvalidArgumentException(
                    "Permissions-Policy allowlist for \"{$feature}\" must be an array of 'self' or https origins.",
                );
            }

            $entries = [];
            foreach ($allowlist as $entry) {
                $entries[] = self::buildPermissionsPolicyEntry($feature, $entry);
            }

            $parts[] = $feature . '=' . ($entries === [] ? '()' : '(' . implode(' ', $entries) . ')');
        }

        return implode(', ', $parts);
    }

    /**
     * Render a single Permissions-Policy allowlist entry.
     *
     * 'self' is the unquoted keyword; anything else must be an https origin
     * with a host and no credentials, path, query or fragment.
     *
     * @param mixed $entry
     *
     * @throws \InvalidArgumentException If the entry is not 'self' or a valid
     *         https origin.
     */
    private static function buildPermissionsPolicyEntry(string $feature, mixed $entry): string
    {
        if (! is_string($entry)) {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry for \"{$feature}\" must be 'self' or an https origin.",
            );
        }

        if ($entry === 'self') {
            return 'self';
        }

        $parts = parse_url($entry);
        if ($parts === false) {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry \"{$entry}\" for \"{$feature}\" is not a valid https origin.",
            );
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry \"{$entry}\" for \"{$feature}\" must be an https origin.",
            );
        }

        if ((string) ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry \"{$entry}\" for \"{$feature}\" must be an https origin with a host.",
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry \"{$entry}\" for \"{$feature}\" must not contain credentials.",
            );
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry \"{$entry}\" for \"{$feature}\" must not contain a path.",
            );
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException(
                "Permissions-Policy allowlist entry \"{$entry}\" for \"{$feature}\" must not contain a query or fragment.",
            );
        }

        return '"' . $entry . '"';
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

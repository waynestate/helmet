<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

/**
 * Why HELMET sends each header and each default CSP directive, in a
 * sentence or two apiece.
 *
 * The reasons live next to the values they explain: SecurityHeaders decides
 * what goes on the wire, this class says why. A test fails when a directive
 * or header is added without a reason, so the explanation cannot fall behind
 * the policy. The ADVISOR module (helmet_headers) and the docs both read it.
 */
final class HeaderReasons
{
    /**
     * One reason per directive HELMET can send. The keys cover the default
     * policy plus the directives HELMET appends (reporting, Trusted Types).
     *
     * @var array<string, string>
     */
    private const DIRECTIVES = [
        'default-src' => "The fallback for every fetch directive not listed here: only the app's own origin.",
        'style-src' => 'Stylesheets from the app and the WayneUI CDN, plus the per-request nonce so the <style> and <link> tags Vite and Vue inject still load.',
        'style-src-attr' => 'The one inline allowance: the WayneUI raw PHP and Blade recipes set style="..." attributes (block spacing) that browsers drop without it. It covers style attributes only, never <style> elements or stylesheets.',
        'style-src-elem' => '<style> elements and stylesheet links: the app, the WayneUI CDN and the nonce. No unsafe-inline here, so an injected <style> block is refused.',
        'script-src' => "Scripts from the app and the WayneUI CDN, plus the per-request nonce with 'strict-dynamic': a nonced script may load what it needs, and an injected inline script has no nonce and never runs. Browsers that understand 'strict-dynamic' trust the nonce and ignore the host list; older ones fall back to the hosts.",
        'font-src' => 'Fonts from the app, the WayneUI CDN and data: URIs, because Vite and Vue inline small fonts as data: and a nonce does not cover fonts.',
        'img-src' => 'Images from the app, the WayneUI CDN and data: URIs (inline icons and small images).',
        'connect-src' => 'fetch, XHR, WebSocket and EventSource reach only the app and the WayneUI CDN, so injected code cannot ship data to another host.',
        'base-uri' => 'Stops an injected <base> tag from re-pointing every relative URL on the page; default-src does not cover it.',
        'object-src' => 'No plugins (<object>, <embed>): an old route for running code that no modern app needs.',
        'form-action' => 'Forms may only submit back to this app, so injected markup cannot send a form (and the password in it) somewhere else.',
        'frame-ancestors' => "Only this app may frame its pages: clickjacking protection at the CSP layer. X-Frame-Options says the same for older browsers.",
        'report-to' => 'Names the Reporting-Endpoints entry that receives violation reports (the current reporting mechanism).',
        'report-uri' => 'The same report destination for browsers that predate report-to.',
        'require-trusted-types-for' => "Report-only: flags every place a script would hand a raw string to a DOM sink such as innerHTML, so an app can see what Trusted Types would block before enforcing it.",
        'trusted-types' => 'Report-only: the Trusted Types policy names the app may create.',
    ];

    /**
     * One reason per header HELMET can send or remove.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'Content-Security-Policy' => 'Says which sources the page may load scripts, styles, images, fonts and connections from; each directive has its own reason.',
        'Content-Security-Policy-Report-Only' => 'The same policy in report-only mode: the browser reports what it would block and blocks nothing. Use it to roll out a stricter policy, then switch back to enforcing.',
        'X-Content-Type-Options' => 'The browser must trust the declared Content-Type instead of guessing, so an uploaded file cannot be run as a script or a stylesheet.',
        'X-Frame-Options' => "Clickjacking protection for browsers that predate CSP frame-ancestors; it matches frame-ancestors 'self'.",
        'Referrer-Policy' => 'Other sites see only the origin of the page that linked to them, never its path or query string, and plain-HTTP sites see nothing.',
        'Permissions-Policy' => 'Turns off browser features a typical app never uses (camera, geolocation, microphone, payment, USB), so an injected script cannot use them either.',
        'Cross-Origin-Opener-Policy' => 'Puts the page in its own browsing context group, so a cross-origin window it opened, or that opened it, cannot reach it through window.opener.',
        'Cross-Origin-Resource-Policy' => "Says which sites may load this app's responses cross-origin: the resource half of cross-origin isolation.",
        'Reporting-Endpoints' => 'Tells the browser where "csp-endpoint" points, so report-to has somewhere to send violation reports.',
        'Strict-Transport-Security' => 'HTTPS responses only: the browser keeps using HTTPS for this host for max-age seconds and refuses a downgrade to plain HTTP. Never sent over plain HTTP, where an attacker could strip it anyway.',
        'Cache-Control' => 'no-store keeps pages with personal data out of shared and browser caches, so the back button on a shared computer shows nothing.',
        'X-Powered-By' => "Removed: it advertises the PHP version, which helps an attacker pick an exploit and helps nobody else.",
    ];

    /**
     * The reason for a CSP directive, or null when HELMET has none (a
     * directive the app added itself).
     */
    public static function directive(string $directive): ?string
    {
        return self::DIRECTIVES[strtolower($directive)] ?? null;
    }

    /**
     * The reason for a header, or null when HELMET does not send it.
     */
    public static function header(string $header): ?string
    {
        foreach (self::HEADERS as $name => $reason) {
            if (strcasecmp($name, $header) === 0) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Every directive that has a reason.
     *
     * @return list<string>
     */
    public static function directives(): array
    {
        return array_keys(self::DIRECTIVES);
    }

    /**
     * Every header that has a reason.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_keys(self::HEADERS);
    }
}

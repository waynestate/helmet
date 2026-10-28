<?php

declare(strict_types=1);

use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Platform\Presets;

/*
|--------------------------------------------------------------------------
| HELMET
|--------------------------------------------------------------------------
|
| The configuration for the HELMET baseline runtime. This file is the only
| place in the package that reads env(); every other part of the package
| reads these values through config().
|
| "preset"      The platform preset that fills "proxies" (see
|               Waynestate\Helmet\Platform\Presets). Set with the
|               HELMET_PRESET env var. null when no preset is named.
|
| "proxies"     The trusted proxies (a list of IPs or CIDR ranges) that may
|               set the X-Forwarded-Proto header. Filled from HELMET_PROXIES
|               (comma-separated) when it is set, otherwise from the
|               HELMET_PRESET preset, otherwise empty. Empty means the app
|               trusts no proxy and HTTPS is detected from the direct
|               connection only. HELMET_PROXIES wins over HELMET_PRESET.
|
| "scope"       Where the SecurityHeaders middleware runs. "global" (the
|               default) adds it to the kernel's global middleware, so every
|               response - including 404s and JSON routes - carries the
|               baseline headers. "web" keeps it in the `web` middleware
|               group only. InjectRibbon always stays in the `web` group,
|               because it edits HTML. Set with the HELMET_SCOPE env var.
|
| "csp"         The Content-Security-Policy, as a policy array in the shape
|               SecurityHeaders::defaultCspPolicy() returns. The middleware
|               merges it over the core default, per directive: a directive
|               you set replaces the default's, a directive you leave out
|               keeps the default's, and a directive set to null is removed
|               (the same rule helmet_boot() applies to its "csp" option).
|               Defaults to the core default policy. Override it with a
|               JSON-encoded policy array via the HELMET_CSP env var, or edit
|               the array here once the config is published.
|
| "csp_report_uri"
|               The CSP report destination: an absolute https:// URL with a
|               host, or a path relative to the page's origin that starts
|               with a single slash. When set, the policy carries
|               "report-to csp-endpoint" and "report-uri <uri>", and the
|               response carries a Reporting-Endpoints header pointing
|               "csp-endpoint" at the same URI. null (the default) sends no
|               reporting directives. Set with the HELMET_CSP_REPORT_URI env
|               var. An invalid value throws
|               InvalidCspReportUriException when the headers are built.
|
| "csp_report_only"
|               When true, the policy is sent as
|               Content-Security-Policy-Report-Only instead of
|               Content-Security-Policy, so the browser reports violations
|               without enforcing them. Use it to roll out a stricter policy:
|               watch the reports, then flip it to false to enforce.
|               Defaults to false. Set with the HELMET_CSP_REPORT_ONLY env
|               var.
|
| "hsts"        The Strict-Transport-Security max-age, in seconds. Only sent
|               on HTTPS responses. Defaults to one year (31536000).
|
| "hsts_include_subdomains"
|               Whether the Strict-Transport-Security header covers
|               subdomains (the includeSubDomains directive). Only sent on
|               HTTPS responses. Defaults to true.
|
| "ribbon"      Whether the environment ribbon is injected into HTML
|               responses in non-production environments. On by default.
|
| "policy_path" The path to a CREED policy.json that the environment ribbon
|               reads to show a version warning for the running PHP (and, in
|               the Laravel adapter, the framework) version. null (the
|               default) means no version warning and no file read. A
|               missing, unreadable or invalid file produces no warning and
|               never breaks the page. Set with the HELMET_POLICY_PATH env
|               var.
|
| "dev_origin"  The Vite dev server origin, used only in the local
|               environment when the dev server is running (a hot file
|               exists). When set, it overrides the hot file contents. The
|               origin must be http or https on localhost, 127.0.0.1 or
|               [::1], with an optional port. An invalid origin is ignored
|               and a warning is logged. Set with the HELMET_DEV_ORIGIN env
|               var. null when not set.
|
| "metrics"     The /metrics endpoint, in the Prometheus text exposition
|               format. Off by default. When enabled, a request is served
|               only if it presents the bearer token or comes from an
|               allowed client IP; anything else is 403, and an
|               enabled-but-unconfigured endpoint (no token and no allow
|               list) is 404 plus a warning. The client IP is resolved
|               through the "proxies" trusted-proxy list, so a spoofed
|               X-Forwarded-For from an untrusted peer cannot grant access.
|
|               "metrics.enabled"
|                       Whether the endpoint is on. Defaults to false. Set
|                       with the HELMET_METRICS_ENABLED env var.
|
|               "metrics.token"
|                       The bearer token a scraper must present
|                       (Authorization: Bearer <token>). null when not set.
|                       Set with the HELMET_METRICS_TOKEN env var.
|
|               "metrics.allow"
|                       A list of client IPs or CIDR ranges allowed to read
|                       the endpoint without a token. Filled from
|                       HELMET_METRICS_ALLOW (comma-separated). Empty by
|                       default.
|
*/

// HELMET_PROXIES wins over HELMET_PRESET, so an app can start from a preset
// and override the proxy list. With neither, nothing is trusted.
$helmetProxies = env('HELMET_PROXIES');
$proxies = [];
if (is_string($helmetProxies) && $helmetProxies !== '') {
    foreach (explode(',', $helmetProxies) as $proxy) {
        $proxy = trim($proxy);
        if ($proxy !== '') {
            $proxies[] = $proxy;
        }
    }
}

$helmetPreset = env('HELMET_PRESET');
$helmetPreset = is_string($helmetPreset) && $helmetPreset !== '' ? $helmetPreset : null;

if ($proxies === [] && $helmetPreset !== null) {
    $proxies = Presets::proxies($helmetPreset);
}

$scope = env('HELMET_SCOPE', 'global');
if ($scope !== 'web') {
    $scope = 'global';
}

$csp = SecurityHeaders::defaultCspPolicy();
$helmetCsp = env('HELMET_CSP');
if (is_string($helmetCsp) && $helmetCsp !== '') {
    $decoded = json_decode($helmetCsp, true);
    if (is_array($decoded) && $decoded !== []) {
        $csp = $decoded;
    }
}

$hstsIncludeSubDomains = (bool) env('HELMET_HSTS_INCLUDE_SUBDOMAINS', true);

// An empty string from env means "not set", so normalize it to null.
$cspReportUri = env('HELMET_CSP_REPORT_URI');
$cspReportUri = is_string($cspReportUri) && $cspReportUri !== '' ? $cspReportUri : null;

$cspReportOnly = (bool) env('HELMET_CSP_REPORT_ONLY', false);

// The /metrics endpoint: off by default. When on, it is protected by a
// bearer token and/or a client IP allow list.
$metricsEnabled = (bool) env('HELMET_METRICS_ENABLED', false);

$metricsToken = env('HELMET_METRICS_TOKEN');
$metricsToken = is_string($metricsToken) && $metricsToken !== '' ? $metricsToken : null;

$metricsAllow = env('HELMET_METRICS_ALLOW');
$metricsAllowList = [];
if (is_string($metricsAllow) && $metricsAllow !== '') {
    foreach (explode(',', $metricsAllow) as $entry) {
        $entry = trim($entry);
        if ($entry !== '') {
            $metricsAllowList[] = $entry;
        }
    }
}

// An empty string from env means "not set", so normalize it to null.
$policyPath = env('HELMET_POLICY_PATH');
$policyPath = is_string($policyPath) && $policyPath !== '' ? $policyPath : null;

return [
    'preset' => $helmetPreset,
    'proxies' => $proxies,
    'scope' => $scope,
    'csp' => $csp,
    'csp_report_uri' => $cspReportUri,
    'csp_report_only' => $cspReportOnly,
    'hsts' => (int) env('HELMET_HSTS_MAX_AGE', 31536000),
    'hsts_include_subdomains' => $hstsIncludeSubDomains,
    'ribbon' => (bool) env('HELMET_RIBBON', true),
    'policy_path' => $policyPath,
    'dev_origin' => env('HELMET_DEV_ORIGIN'),
    'metrics' => [
        'enabled' => $metricsEnabled,
        'token' => $metricsToken,
        'allow' => $metricsAllowList,
    ],
];

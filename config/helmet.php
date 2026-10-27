<?php declare(strict_types=1);

use Waynestate\Helmet\Headers\SecurityHeaders;

/*
|--------------------------------------------------------------------------
| HELMET
|--------------------------------------------------------------------------
|
| The configuration for the HELMET baseline runtime. This file is the only
| place in the package that reads env(); every other part of the package
| reads these values through config().
|
| "proxies"     The trusted proxies (a list of IPs or CIDR ranges) that may
|               set the X-Forwarded-Proto header. Comma-separated in the
|               HELMET_PROXIES env var. Empty by default, which means the
|               app trusts no proxy and HTTPS is detected from the direct
|               connection only.
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
| "hsts"        The Strict-Transport-Security max-age, in seconds. Only sent
|               on HTTPS responses. Defaults to one year (31536000).
|
| "ribbon"      Whether the environment ribbon is injected into HTML
|               responses in non-production environments. On by default.
|
| "policy_path" The path to the CREED policy.json file. Defaults to the
|               copy shipped in vendor/waynestate/creed/policy/policy.json
|               when it exists, otherwise null (no policy).
|
*/

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

$csp = SecurityHeaders::defaultCspPolicy();
$helmetCsp = env('HELMET_CSP');
if (is_string($helmetCsp) && $helmetCsp !== '') {
    $decoded = json_decode($helmetCsp, true);
    if (is_array($decoded) && $decoded !== []) {
        $csp = $decoded;
    }
}

$policyPath = env('HELMET_POLICY_PATH');
if (!is_string($policyPath) || $policyPath === '') {
    $defaultPolicyPath = base_path('vendor/waynestate/creed/policy/policy.json');
    $policyPath = is_file($defaultPolicyPath) ? $defaultPolicyPath : null;
}

return [
    'proxies' => $proxies,
    'csp' => $csp,
    'hsts' => (int) env('HELMET_HSTS_MAX_AGE', 31536000),
    'ribbon' => (bool) env('HELMET_RIBBON', true),
    'policy_path' => $policyPath,
];

# HELMET

![Three members of the band Helmet, two guitarists and a drummer at a Tama kit, tinted blue against a hazy orange and gold backdrop](https://media.tenor.com/WmJKjN9NQqoAAAAM/helmet-band.gif)
<sub>GIF via [Tenor](https://tenor.com/view/helmet-band-wilmas-rainbow-helmet-band-music-gif-25658120)</sub>

[![GitLab pipeline](https://gitlab.example/enterprise-apps/helmet/badges/main/pipeline.svg)](https://gitlab.example/enterprise-apps/helmet/-/pipelines)
[![GitHub CI](https://github.com/waynestate/helmet/actions/workflows/ci.yml/badge.svg)](https://github.com/waynestate/helmet/actions/workflows/ci.yml)
Checks: `composer ci` (the same command runs on GitLab and GitHub).
Two mirrors, one command — the only thing in this repo that plays both sides is the CI.

HELMET is the Wayne State app stack's baseline runtime. One small
framework-free PHP core, `Waynestate\Helmet`, does the five things every app on
Citadel needs before it serves a single page: it sends the security headers,
hardens the session, decides whether the request really arrived over HTTPS,
answers the load balancer's `/up` ping, and writes structured logs.

This repository is published from Wayne State University's internal
GitLab and mirrored here. Changes land there first; Wayne State
developers open issues and merge requests on the internal project.

It is the floor, not the ceiling. An app on raw PHP calls `helmet_boot()` once
at the top of its front controller and is done. An app on Laravel or a PSR-15
stack mounts the same core as middleware and gets the identical headers. The
rules live in one place, and every consumer path agrees.

## Install

```
composer require waynestate/helmet
```

## Platform presets

A preset names a platform's trusted-proxy list, so an app does not hard-code
an address that belongs to the platform, not the app. The presets live in
`Waynestate\Helmet\Platform\Presets`:

| Preset | Trusted proxies |
| --- | --- |
| `citadel` | `172.18.0.1`, the Docker bridge gateway to the HA-Proxy front |
| `docker` | none: a plain Docker network with no front proxy |

Raw PHP passes the preset name:

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
]);
```

Laravel reads it from `HELMET_PRESET` (see the environment table below); an
explicit `HELMET_PROXIES` always wins over the preset.

Off the WSU platform, skip the preset and pass your own list of the addresses
your front proxy connects from:

```php
$helmet = helmet_boot([
    'trusted_proxies' => ['203.0.113.10'],
]);
```

An unknown preset name throws at boot, so a typo fails loudly instead of
silently trusting nothing.

## Usage

### Raw PHP

At the top of the front controller, before anything is echoed:

```php
use function Waynestate\Helmet\helmet_boot;

$helmet = helmet_boot([
    // The platform preset that supplies the trusted proxies. An explicit
    // 'trusted_proxies' list wins over the preset.
    'preset' => 'citadel',

    // Optional. A Monolog JSON logger, one line per record. "stream" may be a
    // stream (php://stderr) or a file path.
    'log' => [
        'channel' => 'app',
        'stream'  => '/var/log/app/helmet.log',
        'level'   => 'info',
    ],
]);

// $helmet['nonce'] is the per-request CSP nonce. Put it in your <script> tags.
// $helmet['logger'] is a Psr\Log\LoggerInterface, or null when no log is set.
```

Use a platform preset (for example `citadel`, which trusts the Docker bridge
gateway), or list only the address the front proxy connects from, never a
whole private range. A broad range such as `10.0.0.0/8` also trusts other
containers on the same network, and any of them could then set
`X-Forwarded-Proto`.

`helmet_boot()` sends the headers, strips `X-Powered-By`, and answers `/up`
with `200 ok` before your code runs. It returns the nonce and the logger so
the rest of the app can use them.

### PSR-15 middleware

The same core, mounted in a middleware pipeline:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Health\HealthCheck;
use Waynestate\Helmet\Platform\Presets;
use Waynestate\Helmet\Proxy\TrustedProxies;

$factory = new Psr17Factory();
$proxies = new TrustedProxies(Presets::proxies('citadel'));

$middleware = [
    new HealthCheck($factory, $factory, '/up'),
    new SecurityHeadersMiddleware($proxies),
];
```

`SecurityHeadersMiddleware` sets the request attribute `helmet.csp_nonce` so a
downstream handler can read the same nonce the CSP header carries.

The headers are sent even when the inner handler throws. By default the
middleware rethrows, so the framework's error handler still runs and produces
the error page; pass `rethrow: false` (and a PSR-17 `ResponseFactory`) to make
the middleware itself return a `500` carrying the same headers. The raw PHP
path needs no such flag: `helmet_boot()` sends the headers before your code
runs, so they are already on the wire by the time an exception can happen.

### Laravel

The Laravel adapter is a service provider, two middlewares and a config file.
It only wires and configures. Every rule (what the headers say, how a nonce is
made, how a CSP is merged) lives in the core, so Laravel and raw PHP cannot
drift apart. The suite runs against Laravel 13 with Orchestra Testbench.

#### Install the adapter

Strap It On:

```
composer require waynestate/helmet
```

Package discovery registers `Waynestate\Helmet\Laravel\HelmetServiceProvider`,
so there is nothing to add to `bootstrap/providers.php`. The provider merges
the `helmet` config and joins the HTTP kernel's middleware:

- `SecurityHeaders` sends the headers in [The headers](#the-headers) and strips
  `X-Powered-By`. By default it is global, so 404s and JSON routes carry them
  too.
- `InjectRibbon` adds the environment ribbon (below). It always stays in the
  `web` group, because it edits HTML.

They are added through the HTTP kernel, so they survive another package adding
to the same group. Set `HELMET_SCOPE=web` to keep `SecurityHeaders` in the
`web` group only; then routes outside `web` and unmatched 404s do not carry
its headers.

#### Publish the config

```
php artisan vendor:publish --tag=helmet-config
```

This copies `config/helmet.php` into your app. Do it: it is the best place to
change the CSP, because the policy is a PHP array there, not JSON squeezed into
`.env`. The config file is also the one place HELMET reads `env()`, so
`php artisan config:cache` keeps working.

#### Trust the proxy

HELMET decides on its own whether a request was really HTTPS, from
`HELMET_PROXIES`. Laravel has a separate trusted-proxy setting that drives
`url()`, secure cookies and `$request->isSecure()`. The provider follows the
same list: when the app has not set `trustedproxy.proxies` itself, it copies
`helmet.proxies` over, so `HELMET_PROXIES` drives both. An app that sets
`trustedproxy.proxies` keeps its own value, and an explicit
`trustProxies(at: ...)` call in `bootstrap/app.php` still wins, because
Laravel applies it before the config fallback.

The provider also narrows which forwarded headers Laravel believes from those
proxies: `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Port`, and
nothing else. Laravel's own default also trusts `X-Forwarded-Host` and
`X-Forwarded-Prefix`, and a client can pre-seed those on a platform whose proxy
does not overwrite them, which poisons `url()`, redirects and password-reset
links. If your app needs one of them, choose the set yourself with
`trustProxies(at: ..., headers: ...)` in `bootstrap/app.php`; HELMET then
leaves your choice alone.

As in the raw PHP section, use a preset or list only the address your front
proxy connects from, never a whole private range.

The `/up` health route is Laravel's own: keep `health: '/up'` in
`withRouting()`. The adapter registers no route.

#### Environment variables

| Variable | Config key | Default | What it does |
| --- | --- | --- | --- |
| `HELMET_SCOPE` | `helmet.scope` | `global` | `global` puts `SecurityHeaders` in the kernel's global middleware; `web` keeps it in the `web` group only. `InjectRibbon` always stays in `web`. |
| `HELMET_PRESET` | `helmet.preset` | none | A platform preset name (`citadel` or `docker`) that supplies the trusted proxies. `HELMET_PROXIES` wins when both are set. |
| `HELMET_PROXIES` | `helmet.proxies` | none | Comma-separated IPs or CIDR ranges allowed to set `X-Forwarded-Proto`. Wins over `HELMET_PRESET`. Also copied to `trustedproxy.proxies` when the app has not set it. Empty trusts nothing. |
| `HELMET_CSP` | `helmet.csp` | the core default policy | A JSON policy array, merged over the default (see below). Empty or invalid JSON keeps the default. |
| `HELMET_HSTS_MAX_AGE` | `helmet.hsts` | `31536000` | The HSTS `max-age` in seconds, sent on HTTPS only. It must not be negative. |
| `HELMET_HSTS_INCLUDE_SUBDOMAINS` | `helmet.hsts_include_subdomains` | `true` | Set `false` to omit the HSTS `includeSubDomains` directive. |
| `HELMET_RIBBON` | `helmet.ribbon` | `true` | Set `false` to switch the ribbon off everywhere. |
| `HELMET_DEV_ORIGIN` | `helmet.dev_origin` | none | The Vite dev server origin, used in `local` only when the dev server is running. Must be `http` or `https` on `localhost`, `127.0.0.1` or `[::1]`. Overrides the hot file. |
| `HELMET_CSP_REPORT_URI` | `helmet.csp_report_uri` | none | The URL browsers POST CSP violation reports to. Adds `report-uri` and `report-to` to the policy and a `Reporting-Endpoints` header. Must be `https`. |
| `HELMET_CSP_REPORT_ONLY` | `helmet.csp_report_only` | `false` | Set `true` to send the policy as `Content-Security-Policy-Report-Only` (observe violations without blocking them). |

#### Customizing the CSP in Laravel

`helmet.csp` takes the same shape as the raw PHP `csp` option and follows the
same rule: it is merged over the default, per directive. A directive you set
replaces the default's, a directive you leave out keeps it, and `null` removes
one. Open the published `config/helmet.php` and replace the
`$csp = SecurityHeaders::defaultCspPolicy();` line:

```php
$csp = [
    'img-src' => ["'self'", 'data:', 'https://images.example.edu'],
    'form-action' => null,
];
```

If you would rather use the environment, `HELMET_CSP` takes the same array as
JSON. In a `.env` file it needs double quotes and escaped inner quotes, which
is the reason to publish the config instead:

```
HELMET_CSP="{\"img-src\":[\"'self'\",\"data:\"],\"form-action\":null}"
```

Invalid JSON is ignored and the default policy stays, so a typo in the
environment cannot loosen the CSP.

#### The nonce in Blade

The middleware puts the per-request nonce on the request as the attribute
`helmet.csp_nonce`, the same name the PSR-15 middleware uses. It also hands
the nonce to Laravel's Vite helper, so `@vite` needs nothing: the `script` and
`style` tags it print already carry the nonce the CSP header sends.

For an inline script, print the nonce from the Vite helper:

```blade
<script nonce="{{ Vite::cspNonce() }}">
    // allowed by the nonce, and free to load more scripts (strict-dynamic)
</script>
```

In a controller it is `$request->attributes->get('helmet.csp_nonce')`. A new
nonce is made for every request. Repetition is a fine Helmet song and a
terrible nonce.

#### Vite and Inertia

The middleware hands the per-request nonce to Laravel's Vite helper and
injects a `csp-nonce` meta tag into `<head>`. `@vite` needs nothing. The meta
tag lets Vite's dev runtime nonce the CSS and preload tags it adds after page
load.

A few rules keep the CSP honest:

- **Nonce every inline script.** The CSP has no `'unsafe-inline'` for
  scripts. Every `<script>` without a `src` needs
  `nonce="{{ Vite::cspNonce() }}"`.
- **No `'unsafe-eval'`.** The CSP never allows it. If your bundle needs
  `eval`, fix the bundle.
- **No scripts in Inertia's `<Head>`.** Inertia's `<Head>` renders into the
  document head after the page loads. A `<script>` there would not carry the
  nonce, and the browser would block it. Put scripts in your layout, where
  `@vite` and the nonce reach them.
- **No second nonce-producing package.** If another package calls
  `Vite::useCspNonce()` after HELMET, the `@vite` tags carry a different nonce
  than the CSP header, and the browser blocks the scripts. The middleware logs
  a warning when it detects this.
- **No shared caching of nonced HTML.** The nonce changes every request. A
  cached page would carry a stale nonce, and the browser would block its
  scripts. Do not cache HTML at a layer that shares it across requests.

In raw PHP, print the meta tag in `<head>` with the nonce `helmet_boot()`
returned:

```php
echo helmet_csp_nonce_meta($helmet['nonce']);
```

The Vite dev server is allowed in the `local` environment only, and only when
it is running (a hot file exists). The origin comes from `HELMET_DEV_ORIGIN`
when set, otherwise from the hot file. It must be `http` or `https` on
`localhost`, `127.0.0.1` or `[::1]`, with an optional port. An invalid origin
is ignored and a warning is logged. In every other environment the dev server
origin is never added.

#### The ribbon

In `local`, `development`, `dev`, `testing`, `test` and `staging`, `InjectRibbon`
adds `<div class="helmet-ribbon" data-environment="staging">staging</div>`
right after the opening `<body>` tag of an HTML response, so nobody mistakes a
test site for the real one. It never appears in `production`, on JSON or other
non-HTML responses, or on HTML with no `<body>`. The package ships no
stylesheet; style `.helmet-ribbon` in your own CSS. Even with
`HELMET_RIBBON=true`, production never gets it, so there is no Aftertaste.

#### Raw PHP and Laravel agree

Both paths call `SecurityHeaders::build()`, so for the same request and
settings they send the same headers. A test pins that down: it compares what
the Laravel middleware sends, name and value, with `build()`. The settings map
like this:

| Setting | Raw PHP (`helmet_boot()`) | Laravel |
| --- | --- | --- |
| Trusted proxies | `trusted_proxies` | `HELMET_PROXIES` / `helmet.proxies` |
| CSP overrides | `csp` | `HELMET_CSP` / `helmet.csp`, same merge, `null` removes |
| HSTS max-age | `hsts_max_age` | `HELMET_HSTS_MAX_AGE` / `helmet.hsts` |
| HSTS subdomains | `hsts_include_subdomains` | `HELMET_HSTS_INCLUDE_SUBDOMAINS` / `helmet.hsts_include_subdomains`, default `true` |
| CSP report URI | `csp_report_uri` | `HELMET_CSP_REPORT_URI` / `helmet.csp_report_uri` |
| CSP report-only | `csp_report_only` | `HELMET_CSP_REPORT_ONLY` / `helmet.csp_report_only`, default `false` |
| Nonce | `$helmet['nonce']` | `request()->attributes->get('helmet.csp_nonce')` |
| Health check | `health_path`, answered by `helmet_boot()` | Laravel's `health: '/up'` |
| Session hardening, JSON logs | applied by `helmet_boot()` | not applied by the adapter; Laravel's session and logging config apply |

For session hardening in Laravel, use the framework's own session config: set `secure`, `http_only`, and `same_site` in `config/session.php` rather than expecting the adapter to apply raw-PHP `SessionHardening`. For structured logs, use Laravel's log channels, for example the `stderr` channel, rather than expecting the adapter to configure a raw-PHP logger.

## The headers

Every response gets the same set, built by one shared function:

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | the WayneUI default policy, plus a per-request nonce and `strict-dynamic` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), geolocation=(), microphone=(), payment=(), usb=()` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` by default, HTTPS only, configurable |

The default CSP is the union of the WayneUI recipes, with `frame-ancestors
'self'`, `base-uri 'self'`, `object-src 'none'` and `form-action 'self'` added
so an app can never be framed by a stranger, have its base tag hijacked, load
plugins, or post its forms to an attacker. `script-src` carries a per-request
nonce plus `strict-dynamic`, so a script loaded with the nonce can load other
scripts without opening the door to `'unsafe-inline'`.

Milquetoast is a Helmet song. It is not a Content-Security-Policy.

### Customizing the CSP

Pass a `csp` policy (raw PHP) or a second constructor argument (middleware).
It is merged over the default, per directive: a directive you set replaces the
default's value for that directive, and a directive you leave out keeps the
default. To remove a directive the default sets, set it to `null`:

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'csp' => [
        // Replace the default img-src.
        'img-src' => ["'self'", 'data:'],
        // Remove form-action entirely.
        'form-action' => null,
    ],
]);
```

### Configuring HSTS

The default is `max-age=31536000; includeSubDomains` (one year, all
subdomains), sent on HTTPS responses only. Roll it out in stages: start with a
short `max-age`, raise it once nothing breaks, and keep `includeSubDomains`
only when every subdomain serves HTTPS. `preload` is a decision for the domain
owner, not for one app, so HELMET never adds it.

Set `hsts_max_age` (seconds) and `hsts_include_subdomains` (raw PHP), or pass
the last two constructor arguments (middleware):

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'hsts_max_age' => 600,
    'hsts_include_subdomains' => false,
]);
```

### CSP reports

By default the policy is enforced: a violation is blocked. To see what would
be blocked before you enforce it, or to keep collecting reports after you do,
point the policy at a report endpoint.

Set `csp_report_uri` (raw PHP) or `HELMET_CSP_REPORT_URI` (Laravel) to the URL
browsers POST reports to. It must be `https`. HELMET then adds `report-uri` and
`report-to` to the policy and sends a `Reporting-Endpoints` header naming the
endpoint. Set `csp_report_only` (or `HELMET_CSP_REPORT_ONLY`) to `true` to send
the policy as `Content-Security-Policy-Report-Only`, which observes violations
without blocking them.

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'csp_report_uri' => 'https://app.example.edu/csp-report',
    'csp_report_only' => true,
]);
```

An invalid URI (not `https`, no host, or a value carrying whitespace or a
quote) throws at boot, so a typo fails loudly instead of pointing the browser
at a destination it cannot reach.

Laravel ships an opt-in endpoint that receives the reports and logs one line
per violation — the violated directive, the blocked URI's host, and the
document path — and nothing else. The route is off until you register it:

```php
// routes/web.php
Route::cspReport();                 // POST /csp-report
Route::cspReport('/reports/csp');   // or your own path
```

The endpoint caps the body at 16 KiB, accepts only the two report content
types, and returns `204`. It carries no authentication of its own; wrap it in
your own middleware if you want to keep it private.

## HTTPS, and why the proxy list matters

Citadel sits behind HA-Proxy, so the PHP process sees the proxy's address, not
the client's, and the real scheme arrives in `X-Forwarded-Proto`. HELMET only
honors that header when the connection came from a trusted proxy. A spoofed
`X-Forwarded-Proto: https` from the public internet is ignored, and no HSTS
header is sent for a request that was not really secure.

The proxy check is the Unsung hero of the headers. Nobody notices it until a
forged `X-Forwarded-Proto` walks in and gets nowhere.

## The session

`SessionHardening::apply()` sets the four `ini` values before the session
starts: strict mode, cookies only, `HttpOnly`, and `SameSite=Lax`. It turns on
`Secure` when the request is HTTPS. It refuses to run once a session is
active, because changing those values mid-session does nothing.

## Tests

No PHP on the machine? Fine. Docker runs the suite:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

## Strongly recommended: a git.wayne.edu instance runner

git.wayne.edu has no runners today, so CI for this repo runs on the test
GitLab instance. Every product in the stack strongly recommends a
git.wayne.edu instance runner.

![Close-up of a man with a mustache and smudged dark eye makeup in front of a purple and gold backdrop, from the Wilma's Rainbow video by the band Helmet](https://media.tenor.com/1dGc4wPaaBQAAAAM/wilmas-rainbow-helmet.gif)
<sub>GIF via [Tenor](https://tenor.com/view/wilmas-rainbow-helmet-wilmas-wilma-rainbow-gif-18685087)</sub>

HELMET, the band, built their sound on precision: short songs, no filler,
every part earning its place. That is the whole point of a baseline runtime.
The floor is tight, the headers are exact, and nothing here is decoration.

Call `helmet_boot()` once and the rest is Exactly What You Wanted. Size
Matters too, which is why the core stays small enough to read in an afternoon.

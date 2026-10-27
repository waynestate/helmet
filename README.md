# HELMET

![Three members of the band Helmet, two guitarists and a drummer at a Tama kit, tinted blue against a hazy orange and gold backdrop](https://media.tenor.com/WmJKjN9NQqoAAAAM/helmet-band.gif)
<sub>GIF via [Tenor](https://tenor.com/view/helmet-band-wilmas-rainbow-helmet-band-music-gif-25658120)</sub>

HELMET is the Wayne State app stack's baseline runtime. One small
framework-free PHP core, `Waynestate\Helmet`, does the five things every app on
Citadel needs before it serves a single page: it sends the security headers,
hardens the session, decides whether the request really arrived over HTTPS,
answers the load balancer's `/up` ping, and writes structured logs.

It is the floor, not the ceiling. An app on raw PHP calls `helmet_boot()` once
at the top of its front controller and is done. An app on Laravel or a PSR-15
stack mounts the same core as middleware and gets the identical headers. The
rules live in one place, and every consumer path agrees.

## Install

```
composer require waynestate/helmet
```

## Usage

### Raw PHP

At the top of the front controller, before anything is echoed:

```php
use function Waynestate\Helmet\helmet_boot;

$helmet = helmet_boot([
    // The address HA-Proxy connects from, as the app sees it. Only these may
    // set X-Forwarded-Proto. An empty list trusts nothing.
    'trusted_proxies' => ['172.18.0.1'],

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

List only the address HA-Proxy connects from (for example the Docker bridge
gateway `172.18.0.1`), never a whole private range. A broad range such as
`10.0.0.0/8` also trusts other containers on the same network, and any of
them could then set `X-Forwarded-Proto`.

`helmet_boot()` sends the headers, strips `X-Powered-By`, and answers `/up`
with `200 ok` before your code runs. It returns the nonce and the logger so
the rest of the app can use them.

### PSR-15 middleware

The same core, mounted in a middleware pipeline:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Health\HealthCheck;
use Waynestate\Helmet\Proxy\TrustedProxies;

$factory = new Psr17Factory();
$proxies = new TrustedProxies(['172.18.0.1']);

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

#### Install

Strap It On:

```
composer require waynestate/helmet
```

Package discovery registers `Waynestate\Helmet\Laravel\HelmetServiceProvider`,
so there is nothing to add to `bootstrap/providers.php`. The provider merges
the `helmet` config and appends two middlewares to the `web` group:

- `SecurityHeaders` sends the headers in [The headers](#the-headers) and strips
  `X-Powered-By`. They go out on error pages too.
- `InjectRibbon` adds the environment ribbon (below).

They are appended through the HTTP kernel, so they run after Laravel's own
`web` middleware and stay in the group when another package adds to it. Two
edges to know about: a route outside the `web` group (the `api` group, say) is
not covered, so add `Waynestate\Helmet\Laravel\SecurityHeaders::class` to it
yourself; and a request that matches no route never enters the `web` group, so
a plain `404` goes out without HELMET's headers.

#### Publish the config

```
php artisan vendor:publish --tag=helmet-config
```

This copies `config/helmet.php` into your app. Do it: it is the best place to
change the CSP, because the policy is a PHP array there, not JSON squeezed into
`.env`. The config file is also the one place HELMET reads `env()`, so
`php artisan config:cache` keeps working.

#### Trust the proxy, once

HELMET decides on its own whether a request was really HTTPS, from
`HELMET_PROXIES`. Laravel has a separate trusted-proxy setting that drives
`url()`, secure cookies and `$request->isSecure()`, and it can only be set in
`bootstrap/app.php`, not in a service provider. Add this to the
`Application::configure(...)` chain there, once, listing the same address as
`HELMET_PROXIES`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: ['172.18.0.1']);
})
```

Use a literal list. That closure runs before the configuration is loaded, so
`config('helmet.proxies')` does not exist yet and calling it throws. As in the
raw PHP section, list only the address HA-Proxy connects from, never a whole
private range.

The `/up` health route is Laravel's own: keep `health: '/up'` in
`withRouting()`. The adapter registers no route.

#### Environment variables

| Variable | Config key | Default | What it does |
| --- | --- | --- | --- |
| `HELMET_PROXIES` | `helmet.proxies` | none | Comma-separated IPs or CIDR ranges allowed to set `X-Forwarded-Proto`, for example `172.18.0.1`. Empty trusts nothing. |
| `HELMET_CSP` | `helmet.csp` | the core default policy | A JSON policy array, merged over the default (see below). Empty or invalid JSON keeps the default. |
| `HELMET_HSTS_MAX_AGE` | `helmet.hsts` | `31536000` | The HSTS `max-age` in seconds, sent on HTTPS only. It must not be negative. |
| `HELMET_RIBBON` | `helmet.ribbon` | `true` | Set `false` to switch the ribbon off everywhere. |
| `HELMET_POLICY_PATH` | `helmet.policy_path` | `vendor/waynestate/creed/policy/policy.json` if it exists, else `null` | The CREED `policy.json`. The Laravel middlewares do not read it yet. |

#### Customizing the CSP

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
`helmet.csp_nonce`, the same name the PSR-15 middleware uses. In Blade:

```blade
<script nonce="{{ request()->attributes->get('helmet.csp_nonce') }}">
    // allowed by the nonce, and free to load more scripts (strict-dynamic)
</script>
```

In a controller it is `$request->attributes->get('helmet.csp_nonce')`. A new
nonce is made for every request. Repetition is a fine Helmet song and a
terrible nonce.

If you load your bundle with `@vite`, hand Laravel's Vite helper the same
nonce before the tag, so the `script` tags it prints satisfy the CSP:

```blade
@php(\Illuminate\Support\Facades\Vite::useCspNonce(request()->attributes->get('helmet.csp_nonce')))
@vite(['resources/js/app.js'])
```

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
| HSTS subdomains | `hsts_include_subdomains` | always on; there is no Laravel setting yet |
| Nonce | `$helmet['nonce']` | `request()->attributes->get('helmet.csp_nonce')` |
| Health check | `health_path`, answered by `helmet_boot()` | Laravel's `health: '/up'` |
| Session hardening, JSON logs | applied by `helmet_boot()` | not applied by the adapter; Laravel's session and logging config apply |

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
    'trusted_proxies' => ['172.18.0.1'],
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
    'trusted_proxies' => ['172.18.0.1'],
    'hsts_max_age' => 600,
    'hsts_include_subdomains' => false,
]);
```

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

git.wayne.edu has no runners today, so CI for this repo runs on GitLab. Every
product in the stack strongly recommends a git.wayne.edu instance runner. The
case for it is written up in `design/instance-runner-case.md` in the
coordination repo (`the stack`). In the Meantime, GitLab carries the load.

![Close-up of a man with a mustache and smudged dark eye makeup in front of a purple and gold backdrop, from the Wilma's Rainbow video by the band Helmet](https://media.tenor.com/1dGc4wPaaBQAAAAM/wilmas-rainbow-helmet.gif)
<sub>GIF via [Tenor](https://tenor.com/view/wilmas-rainbow-helmet-wilmas-wilma-rainbow-gif-18685087)</sub>

HELMET, the band, built their sound on precision: short songs, no filler,
every part earning its place. That is the whole point of a baseline runtime.
The floor is tight, the headers are exact, and nothing here is decoration.

Call `helmet_boot()` once and the rest is Exactly What You Wanted. Size
Matters too, which is why the core stays small enough to read in an afternoon.

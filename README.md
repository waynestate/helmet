# HELMET

<!-- TODO(lead): GIF -->
TODO(lead): GIF (HELMET, "The Devil's Account" live)

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
    // The private ranges HA-Proxy sits behind. Only these may set
    // X-Forwarded-Proto. An empty list trusts nothing.
    'trusted_proxies' => ['10.0.0.0/8'],

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
$proxies = new TrustedProxies(['10.0.0.0/8']);

$middleware = [
    new HealthCheck($factory, $factory, '/up'),
    new SecurityHeadersMiddleware($proxies),
];
```

`SecurityHeadersMiddleware` sets the request attribute `helmet.csp_nonce` so a
downstream handler can read the same nonce the CSP header carries.

## The headers

Every response gets the same set, built by one shared function:

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | the WayneUI default policy, plus a per-request nonce |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), geolocation=(), microphone=()` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`, HTTPS only |

The default CSP is the union of the WayneUI recipes, with `frame-ancestors
'self'` added so an app can never be framed by a stranger.

## HTTPS, and why the proxy list matters

Citadel sits behind HA-Proxy, so the PHP process sees the proxy's address, not
the client's, and the real scheme arrives in `X-Forwarded-Proto`. HELMET only
honors that header when the connection came from a trusted proxy. A spoofed
`X-Forwarded-Proto: https` from the public internet is ignored, and no HSTS
header is sent for a request that was not really secure.

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
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli vendor/bin/phpunit
```

## Strongly recommended: a git.wayne.edu instance runner

git.wayne.edu has no runners today, so CI for this repo runs on GitLab. Every
product in the stack strongly recommends a git.wayne.edu instance runner. The
case for it is written up in `design/instance-runner-case.md` in the
coordination repo (`the stack`).

<!-- TODO(lead): GIF -->
TODO(lead): GIF (HELMET, "The Bad Time" live)

HELMET, the band, built their sound on precision: short songs, no filler,
every part earning its place. That is the whole point of a baseline runtime.
The floor is tight, the headers are exact, and nothing here is decoration.

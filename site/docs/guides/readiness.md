---
title: "Readiness | HELMET docs"
description: "The \"send me traffic?\" answer on its own path, apart from the \"am I alive?\" answer on /up, for Citadel, OpenShift, Coolify and Docker."
url: https://helmet.apps.wayne.edu/docs/guides/readiness.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Readiness[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#readiness)

Two questions, two paths. **Liveness** (`/up`) asks "is the process running?" and a failure means "restart me". **Readiness** (`/ready`) asks "should this instance get traffic right now?" and a failure means "skip me for a while". Mix them up and a database blip restarts every healthy instance at once, which turns a short outage into a long one.

HELMET answers both. `/up` stays what it always was: `200`, `ok`, no checks, no config read. `/ready` is new and off until you turn it on.

## What `/ready` answers[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#what-ready-answers)

```json
{"status":"ready","checks":{"headers":{"status":"pass","csp":"enforce","style_src_attr":"inline"},"trusted_proxies":{"status":"pass","count":1,"broad":0},"debug_output":{"status":"pass"},"database":{"status":"pass"}}}
```

- `200` and `"ready"` while no check failed; `503` and `"not ready"` once one did. A `warn` never fails the probe.
- `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-store`, and the usual security headers.
- Each check has a `status` of `pass`, `warn` or `fail`, plus a little detail that is safe to show anyone.

HELMET's own checks:

| Check | Fails when | Warns when | Detail in the body |
| --- | --- | --- | --- |
| `headers` | the header set does not build (a bad config value) or has no CSP; in Laravel, also when the header middleware is not registered | never | `csp`: `enforce` or `report-only`; `style_src_attr`: the inline-style mode |
| `trusted_proxies` | never | the list is empty (HTTPS is never trusted, so HSTS is never sent) or holds a broad range | `count` and `broad`, numbers only |
| `debug_output` | never | raw PHP: `display_errors` is on; Laravel: `app.debug` is true in production | none |

Your own checks join them by name (lowercase letters, digits and underscores). A check returns `true` (or `null`) to pass and `false` to fail. A check that throws fails too, and its message goes to the log as `helmet.readiness_check_failed`, never to the body.

> \[!IMPORTANT\] The probe is as public as the app. The body names checks and statuses, never an address, a hostname, a DSN, an exception message or the policy text. Keep it that way in your own checks: return a bool and log the rest.

Give every dependency check a short timeout (two seconds is plenty). A probe that hangs is a probe that fails, and it holds a worker while it does.

## Turn it on[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#turn-it-on)

### Raw PHP[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#raw-php)

```php
use function Waynestate\Helmet\helmet_boot;

$helmet = helmet_boot([
    'preset' => 'citadel',
    'readiness' => [
        'enabled' => true,                       // default false
        'path' => '/ready',                      // the default; must differ from health_path
        'checks' => [
            'database' => static function (): bool {
                $pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                return $pdo->query('SELECT 1') !== false;
            },
        ],
    ],
]);
```

`helmet_boot()` validates the `readiness` option on every request, so a typo (a relative path, a check name with a space, a name that clashes with `headers`) throws on the first page view, not only when the probe arrives. The answer goes out after the security headers and before the session starts, and `helmet_boot()` stops there.

No front controller? Answer from a small public file instead:

```php
<?php // public/ready.php
use Waynestate\Helmet\Health\Readiness;
use function Waynestate\Helmet\helmet_ready;

require __DIR__ . '/../vendor/autoload.php';

helmet_ready((new Readiness())->withCheck('database', static fn (): bool => db_ping()));
```

### PSR-15[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#psr-15)

```php
use Waynestate\Helmet\Health\Readiness;
use Waynestate\Helmet\Health\ReadinessCheck;

$readiness = (new Readiness($logger))
    ->withTrustedProxies($proxies)
    ->withCheck('database', static fn (): bool => db_ping());

$app->add(new ReadinessCheck($responseFactory, $streamFactory, $readiness, '/ready'));
```

`Readiness::standard($proxies, $buildHeaders)` adds all three HELMET checks when you have the header set's builder at hand.

### Laravel[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#laravel)

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    health: '/up',
    then: function () {
        Route::helmetReady('/ready', [
            'database' => \App\Health\DatabaseReady::class,
        ]);
    },
)
```

Each check is an invokable class, resolved from the container and called with no arguments, so `php artisan route:cache` keeps working:

```php
namespace App\Health;

use Illuminate\Support\Facades\DB;

final class DatabaseReady
{
    public function __invoke(): bool
    {
        DB::select('select 1');

        return true;
    }
}
```

Register the route in `then:`, outside the `web` group: a readiness probe has no session and no cookies. The route carries no throttle by default, because a throttled probe reads as "not ready"; pass one as the third argument if the path is exposed. Keep `health: '/up'` for liveness and leave the `DiagnosingHealth` listener alone: it fires for `/up`.

## Point each platform at the right path[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#point-each-platform-at-the-right-path)

### Citadel[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#citadel)

Citadel apps keep answering `/up`, as they do today, for anything that restarts or replaces the app. When a monitor or balancer in front of the app asks "send me traffic?", and the app has a dependency worth checking, give that check `/ready`. An app with nothing to check keeps `/up` everywhere and leaves readiness off.

### OpenShift and Kubernetes[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#openshift-and-kubernetes)

```yaml
livenessProbe:
  httpGet: { path: /up, port: 8080 }
  periodSeconds: 10
  failureThreshold: 3
readinessProbe:
  httpGet: { path: /ready, port: 8080 }
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 2
startupProbe:
  httpGet: { path: /up, port: 8080 }
  failureThreshold: 30
  periodSeconds: 2
```

Never point `livenessProbe` at `/ready`: a database restart would then restart every pod.

### Coolify[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#coolify)

Coolify has one health check per service, and a failing one keeps the new deploy from taking traffic. Point it at `/ready` (path `/ready`, expected status `200`) when you want a deploy held back until its database answers; keep `/up` when you would rather it went live and degraded.

### Docker[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#docker)

A Dockerfile `HEALTHCHECK` decides whether the container counts as healthy, and some orchestrators restart unhealthy containers, so it is a liveness check: keep it on `/up`.

```dockerfile
HEALTHCHECK --interval=30s --timeout=3s CMD curl -fsS http://127.0.0.1:8080/up || exit 1
```

## Reading a 503[#](https://helmet.apps.wayne.edu/docs/guides/readiness.html#reading-a-503)

The body says which check failed; the log says why. Search the app log for `helmet.readiness_check_failed` and the `check` field. A `headers` failure means the header config does not build (the same error would break every page), and in Laravel it can also mean `SecurityHeaders` is not in the middleware stack.

Like the band, a readiness probe is loud about one thing at a time.

---
title: "Metrics | HELMET docs"
description: "HELMET can expose a Prometheus metrics endpoint with version, PHP support, request and CSP metrics. Off by default, gated by a bearer token or an IP allow list, with a Grafana dashboard to import."
url: https://helmet.apps.wayne.edu/docs/guides/metrics.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Metrics[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#metrics)

This page is for the opt-in Prometheus metrics endpoint: what it reports, how to turn it on for one site, how to gate it, where request counts are kept, and how to graph the result in Grafana. Read it before you expose `/metrics`.

HELMET can expose a Prometheus metrics endpoint. It is off by default and stays off until you turn it on. HELMET does not write the exposition format by hand: it uses [promphp/prometheus_client_php](https://github.com/PromPHP/prometheus_client_php) and keeps its own layer to naming, label bounding and access control.

## The goal[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#the-goal)

One endpoint per app that answers three questions for a fleet dashboard: is it up, is it current (PHP and HELMET versions, and how long until its PHP release loses security support), and how is it doing (requests, latency, errors and CSP violations). Labels never carry personal data or raw paths, so the number of series stays small no matter what the internet sends.

## What it reports[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#what-it-reports)

| Metric | Type | Labels | Needs a store |
| --- | --- | --- | --- |
| `helmet_info` | gauge, always 1 | `php_version`, `helmet_version` | no |
| `wsu_stack_info` | gauge, always 1 | `package`, `version` (each installed `waynestate/*` package) | no |
| `helmet_php_version_status` | gauge, 1 for the current status | `status` (`active`, `security`, `eol`, `unknown`) | no, needs a policy |
| `helmet_php_eol_timestamp_seconds` | gauge | `php_minor` (for example `8.5`) | no, needs a policy |
| `helmet_metrics_storage_up` | gauge, 1 or 0 | none | only shown when a store is set |
| `helmet_http_request_duration_seconds` | histogram (`_bucket`, `_sum`, `_count`) | `route`, `method`, `status` | yes |
| `helmet_csp_violations_total` | counter | `directive` | yes |

The first four are built fresh on every scrape, so they need nothing shared between PHP workers. The last two count across requests and need a store (see [Pick a store](https://helmet.apps.wayne.edu/docs/guides/metrics.html#pick-a-store)).

The policy is the CREED `policy.json` (set `HELMET_POLICY_PATH`). Status is looked up by PHP major and minor version, so PHP 8.5.12 reads the `8.5` entry. The end-of-support date is exposed as a Unix timestamp, which is the Prometheus way; the dashboard turns it into days.

### How labels stay small[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#how-labels-stay-small)

HELMET bounds every label before it reaches the store, and anything it will not accept becomes `other`:

- `route` is a **route group the app names**, never the raw path. Laravel apps get the route name, else the URI template (`users/{user}`), else `unmatched` (a request no route took, usually a 404). Raw PHP apps pass their own group. Values must be lowercase letters, digits and `. _ / { } : -`, 64 characters at most.
- `method` is one of the standard HTTP verbs, else `OTHER`.
- `status` is the class (`1xx` to `5xx`), never the exact code.
- `directive` is a CSP Level 3 directive name, else `other`, so a forged report cannot mint a new series.

The request count is the histogram's `_count` series; there is no separate counter to keep in sync.

## Enable it[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#enable-it)

| Variable | What it does |
| --- | --- |
| `HELMET_METRICS_ENABLED` | `true` turns the endpoint on. Off when unset. |
| `HELMET_METRICS_TOKEN` | A bearer token. The scraper sends `Authorization: Bearer <token>`. Compared with `hash_equals`. |
| `HELMET_METRICS_ALLOW` | Comma-separated IPs or CIDR ranges allowed to scrape without a token. |
| `HELMET_METRICS_STORAGE` | `apcu`, `redis` or `sqlite` turns on request and CSP metrics. Unset means version and policy gauges only. |
| `HELMET_METRICS_REDIS_URL` | `redis://[:password@]host[:port][/db]` when the store is `redis`. |
| `HELMET_METRICS_SQLITE_PATH` | The database file when the store is `sqlite`. Defaults to `helmet-metrics.sqlite` in the system temp directory. |
| `HELMET_POLICY_PATH` | Path to a CREED `policy.json`, for the PHP support status and end-of-support gauges. |

Warning

With neither a token nor an allow list set, the endpoint returns `404` and logs a warning, so a misconfigured app does not hand its metrics to the whole network.

The token is read from the `Authorization` header wherever the server puts it: `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, or the request headers themselves. That last one matters on Apache with mod_php, which keeps the header out of `$_SERVER`.

## Pick a store[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#pick-a-store)

PHP shares nothing between requests, so a request counter kept in memory would count to 1 and vanish. Request and CSP metrics need a store that every worker in the app can reach. HELMET does not pick one for you.

| Store | Use it when | Limits |
| --- | --- | --- |
| `apcu` | The app runs under PHP-FPM or Apache mod_php with the APCu extension. Fastest. | Per server: each container or VM counts on its own (Prometheus sums them). Lost on restart. Not shared with the CLI. Needs `ext-apcu`. |
| `redis` | Several containers serve one app, or the counts should survive a restart. | Needs `ext-redis` and a Redis the app can reach. A slow Redis adds its round trip to every request. |
| `sqlite` | No APCu and no Redis, for example a stock PHP Apache image. | Per server, like APCu. Every request takes a short write lock, so it suits small and medium traffic, not a busy API. A file in the temp directory is lost with the container. Needs `ext-pdo_sqlite`. |

Counters that reset on restart are fine: Prometheus's `rate()` and `increase()` handle resets. If the store breaks, the page still renders: the failure is logged once per request (`metrics_storage_unusable` or `metrics_record_failed`), the version gauges keep answering, and `helmet_metrics_storage_up` drops to `0` so the dashboard shows it.

## Turn it on for one site[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#turn-it-on-for-one-site)

### Laravel[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#laravel)

Set the variables, then register the routes you want. Nothing is created until you call the macro:

```dotenv
HELMET_METRICS_ENABLED=true
HELMET_METRICS_TOKEN=<a long random string, from your secret store>
HELMET_METRICS_STORAGE=apcu
HELMET_POLICY_PATH=/path/to/creed/policy.json
```

```php
// routes/web.php
Route::helmetMetrics();                             // GET /metrics, throttle:60,1
Route::helmetMetrics('/metrics', 'throttle:120,1'); // your own throttle
Route::cspReport();                                 // POST /csp-report, counts violations by directive
```

With `HELMET_METRICS_ENABLED` true and `HELMET_METRICS_STORAGE` set, the provider adds a terminable middleware to the front of the global stack, so it times the whole request (including your other middleware) and records after the response has been sent. Without it the middleware is not registered at all. The controller reads `helmet.metrics` from the published config and resolves the client IP through the same trusted-proxy list the headers use.

### Raw PHP[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#raw-php)

Put this at the top of the front controller, after the autoloader:

```php
use function Waynestate\Helmet\helmet_metrics;
use function Waynestate\Helmet\helmet_metrics_env;
use function Waynestate\Helmet\helmet_metrics_track;

$config = helmet_metrics_env();   // reads HELMET_METRICS_* and HELMET_POLICY_PATH
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/metrics') {
    $result = helmet_metrics(['preset' => 'citadel'] + $config);
    http_response_code($result['status']);
    header('Content-Type: ' . $result['content_type']);
    echo $result['body'];
    exit;
}

// Name the route group yourself; never pass the raw path.
$group = str_starts_with($path, '/reports') ? 'reports' : 'page';
helmet_metrics_track($config)->route($group);
```

`helmet_metrics_track()` starts the clock at `REQUEST_TIME_FLOAT` and records once at shutdown, so a page that ends with `exit` is still counted, with whatever status code it sent. It does nothing when request metrics are off, so the same front controller works with or without a store.

To count CSP violations in raw PHP, call `helmet_metrics_requests($config)?->cspViolation($directive)` from the handler that receives your reports.

### Check it[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#check-it)

```bash
curl -s -H "Authorization: Bearer $HELMET_METRICS_TOKEN" https://your-app.example.test/metrics | head
```

Expect `helmet_info` first. Without the token you should get `403`; with metrics off, `404`.

## Graph it in Grafana[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#graph-it-in-grafana)

The fleet dashboard ships in [`docs/grafana/helmet.json`](https://git.wayne.edu/enterprise-apps/helmet/-/blob/main/docs/grafana/helmet.json). In Grafana, open Dashboards, New, Import, upload the file, and pick your Prometheus data source for the `DS_PROMETHEUS` input. It has three rows:

- **Fleet**: apps reporting, targets down, PHP support status, days to PHP end of support, the HELMET and PHP versions in use, and the stack packages each app runs.
- **Requests**: request rate by route group and by status class, p50 and p95 latency, p95 per route group, the share of 5xx answers, store health, and CSP violations by directive. Empty until an app sets a store.
- **Scrape**: whether Prometheus reached each target over time, and every reporting instance with its versions.

`job` and `instance` variables at the top narrow every panel. The dashboard was checked against a local Prometheus and Grafana with three apps on APCu, SQLite and Redis, in light and dark themes.

A scrape config for one app, with the token kept in a file:

```yaml
scrape_configs:
  - job_name: helmet
    metrics_path: /metrics
    scheme: https
    authorization:
      credentials_file: /etc/prometheus/secrets/helmet-token
    static_configs:
      - targets:
          - your-app.example.test
```

Queries worth keeping:

```promql
# Days until each app's PHP release loses security support
min by (instance) ((helmet_php_eol_timestamp_seconds - time()) / 86400)

# Requests per second by route group (the histogram's _count is the request count)
sum by (route) (rate(helmet_http_request_duration_seconds_count[5m]))

# p95 latency
histogram_quantile(0.95, sum by (le) (rate(helmet_http_request_duration_seconds_bucket[5m])))
```

WSU has no Prometheus server yet, so the dashboard is ready, not live: it graphs as soon as a server exists and scrapes `/metrics`. The stack's own sites turn it on first.

## Limits[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#limits)

- HELMET reports; it does not alert. Alert rules belong with the Prometheus server.
- Request metrics start at the front controller. A static file the web server answers on its own is never counted.
- The status gauge says `unknown` when no policy is set or the policy has no entry for the running PHP release.

The endpoint is the only place in HELMET that says "I am still here" without saying "here is your page". A lullaby, in a way. A helmet with a heart-rate monitor, if you like.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#related)

- [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html)
- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
- [The session](https://helmet.apps.wayne.edu/docs/guides/session.html)
- [The request-forgery guard](https://helmet.apps.wayne.edu/docs/guides/csrf.html)

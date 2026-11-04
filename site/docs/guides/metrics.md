---
title: "Metrics | HELMET docs"
description: "HELMET can expose a Prometheus metrics endpoint, off by default and gated by a bearer token or an IP allow list."
url: https://helmet.apps.wayne.edu/docs/guides/metrics.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Metrics[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#metrics)

This page is for the opt-in Prometheus metrics endpoint: how to turn it on, how to gate access, and what the response carries. Read it before you expose `/metrics`, then pick the section that matches your stack.

HELMET can expose a Prometheus metrics endpoint. It is off by default and stays off until you turn it on. No Prometheus scraper is wired yet; the endpoint is there for when one is.

## The goal[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#the-goal)

Expose a single endpoint that reports the PHP and HELMET versions, gated so only an authorized scraper can read it. The endpoint speaks Prometheus exposition format, uses no personal data in labels, and keeps labels low-cardinality.

## Enable it[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#enable-it)

Set `HELMET_METRICS_ENABLED=true` and choose at least one way to prove who is asking:

| Variable | What it does |
| --- | --- |
| `HELMET_METRICS_TOKEN` | A bearer token. The scraper sends `Authorization: Bearer <token>`. Compared with `hash_equals`. |
| `HELMET_METRICS_ALLOW` | Comma-separated IPs or CIDR ranges allowed to scrape without a token. |

Warning

With neither a token nor an allow list set, the endpoint returns `404` and logs a warning, so a misconfigured app does not hand its metrics to the whole network.

## Laravel[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#laravel)

The provider registers a `Route::helmetMetrics()` macro. The route is not created until you call it:

```php
// routes/web.php
Route::helmetMetrics();                          // GET /metrics, throttle:60,1
Route::helmetMetrics('/metrics', 'throttle:120,1'); // your own throttle
Route::helmetMetrics('/metrics', null);           // no throttle
```

The controller reads `helmet.metrics` from the published config, resolves the client IP through the same trusted-proxy list the headers use, and renders `text/plain; version=0.0.4`.

## Raw PHP[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#raw-php)

```php
use function Waynestate\Helmet\helmet_metrics;

$result = helmet_metrics([
    'enabled' => true,
    'token'   => 's3cret',
    'allow'   => ['10.0.0.0/8'],
    'preset'  => 'citadel',
]);

http_response_code($result['status']);
header('Content-Type: ' . $result['content_type']);
echo $result['body'];
```

## What the response carries[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#what-the-response-carries)

The response carries a `helmet_info` gauge with the PHP and HELMET versions, and a `helmet_php_version_status` gauge when a `PolicySource` is available. No personal data in labels; labels stay low-cardinality.

The endpoint is the only place in HELMET that says "I am still here" without saying "here is your page". A lullaby, in a way.

## Graph it in Grafana[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#graph-it-in-grafana)

The fleet dashboard for these metrics ships in [`docs/grafana/helmet.json`](https://nougat.snu.gs/enterprise-apps/helmet/blob/HEAD/docs/grafana/helmet.json): in Grafana, open Dashboards, New, Import, paste the file, and pick your Prometheus data source for the `DS_PROMETHEUS` input. It shows how many apps report, which HELMET and PHP versions they run, the PHP support status of each app, every reporting instance, and whether Prometheus can reach them.

A sample scrape config for a Prometheus server, with a bearer token file and example targets:

```yaml
scrape_configs:
  - job_name: helmet
    metrics_path: /metrics
    authorization:
      credentials_file: /etc/prometheus/secrets/helmet-token
    static_configs:
      - targets:
          - app-one.example.test:80
          - app-two.example.test:80
```

WSU has no Prometheus server yet (decision D-024), so the dashboard is ready, not live: it graphs as soon as a server exists and scrapes `/metrics`.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/metrics.html#related)

- [The session](https://helmet.apps.wayne.edu/docs/guides/session.html)
- [The request-forgery guard](https://helmet.apps.wayne.edu/docs/guides/csrf.html)

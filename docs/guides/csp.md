---
title: Customizing the CSP
description: A csp policy passed to helmet_boot() or the middleware is merged over the default, per directive.
---

# Customizing the CSP

The default policy is a starting point, not a cage. Pass a `csp` policy (raw PHP) or a second constructor argument (middleware) and it is merged over the default, per directive: a directive you set replaces the default's value for that directive, and a directive you leave out keeps the default. The default itself, exactly as the code defines it, is on [The headers](headers.md).

## Merging over the default

To remove a directive the default sets, set it to `null`:

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

In Laravel the same array goes in `helmet.csp` (published config) or `HELMET_CSP` (JSON in the environment); see [Laravel](usage-laravel.md). The merge rule is identical on both paths, because both call the same core function.

## Inline style attributes

The default keeps one allowance: `style-src-attr 'unsafe-inline'`, because WayneUI's partials and examples set `style="..."` attributes (spacing, skeleton sizes) that a stricter policy drops without a word. It covers the attribute only; `<style>` blocks and stylesheets still need the nonce or an allowed origin. One setting chooses how strict to be:

| Mode | Raw PHP / PSR-15 | Laravel | What the browser gets |
| --- | --- | --- | --- |
| `inline` (default) | `'style_src_attr' => 'inline'` | `HELMET_CSP_STYLE_SRC_ATTR=inline` | `style-src-attr 'unsafe-inline'`, as before |
| `report` | `'style_src_attr' => 'report'` | `HELMET_CSP_STYLE_SRC_ATTR=report` | the same enforced policy, plus `Content-Security-Policy-Report-Only: style-src-attr 'none'` |
| `none` | `'style_src_attr' => 'none'` | `HELMET_CSP_STYLE_SRC_ATTR=none` | `style-src-attr 'none'`: inline style attributes are blocked |

Start with `report`. The browser console (and your report endpoint, when `csp_report_uri` is set) then names each attribute a strict policy would block, without blocking any. When the list is empty, switch to `none`. For PSR-15 the mode is the last argument of `SecurityHeadersMiddleware`.

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'csp_report_uri' => '/csp-report',
    'style_src_attr' => 'report',
]);
```

The setting only replaces HELMET's own allowance: if you set `style-src-attr` yourself in `csp`, your value wins in every mode. An unknown mode throws at boot. Why the default stays `inline` for now, and what would change it, is in [ADR-0001](../adr/0001-style-src-attr.md).

## Reporting setup

By default the policy is enforced: a violation is blocked. To see what would be blocked before you enforce it, or to keep collecting reports after you do, point the policy at a report endpoint.

Set `csp_report_uri` (raw PHP) or `HELMET_CSP_REPORT_URI` (Laravel) to the URL browsers POST reports to. An absolute URL must be `https` with a host; a path relative to the page's origin that starts with a single slash is valid too. HELMET then adds `report-uri` and `report-to` to the policy and sends a `Reporting-Endpoints` header naming the endpoint. Set `csp_report_only` (or `HELMET_CSP_REPORT_ONLY`) to `true` to send the policy as `Content-Security-Policy-Report-Only`, which observes violations without blocking them.

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
    'csp_report_uri' => 'https://app.example.edu/csp-report',
    'csp_report_only' => true,
]);
```

> [!WARNING]
> An invalid URI throws at boot, so a typo fails loudly instead of pointing the browser at a destination it cannot reach: an empty value, one carrying whitespace, a control character or a double quote, a protocol-relative `//...` value, or an absolute URL that is not `https` or has no host are all refused. A single-slash path relative to the page's origin is valid.

> [!TIP]
> Run report-only first. That is how you see what would be blocked before you enforce it; once the policy is clean, switch off `csp_report_only` and keep collecting.

## Receiving the reports in Laravel

Laravel ships an opt-in endpoint that receives the reports and logs one line per violation (the violated directive, the blocked URI's host, and the document path) and nothing else. It accepts both report formats: the legacy `application/csp-report` object, and the Reporting API's `application/reports+json` array, from which it logs the `csp-violation` entries (at most 20 per POST; browsers batch other report types into the same request, and those are ignored). The route is off until you register it:

```php
// routes/web.php
Route::cspReport();                 // POST /csp-report
Route::cspReport('/reports/csp');   // or your own path
```

The endpoint caps the body at 16 KiB, returns `204`, and carries no authentication of its own; wrap it in your own middleware if you want to keep it private. Browsers POST reports without a CSRF token, so the route excludes the `web` group's CSRF middleware. Because the endpoint is public and its input is attacker-influenced, it is throttled by default to 60 reports a minute per IP (`throttle:60,1`); pass a second argument to use a different throttle, or `null` to disable it:

```php
Route::cspReport('/reports/csp', 'throttle:120,1'); // your own limit
Route::cspReport('/reports/csp', null);             // no throttle
```

## Receiving the reports in raw PHP

Raw PHP gets the same endpoint as one call. Give it a file of its own, so it runs without `helmet_boot()`: a browser POSTs reports with no session and no CSRF token, and the boot's CSRF guard would refuse every one of them.

```php
<?php
// public/csp-report.php
require __DIR__ . '/../vendor/autoload.php';

use Waynestate\Helmet\Logging\Logging;
use function Waynestate\Helmet\helmet_csp_report;

helmet_csp_report(Logging::create('csp', 'php://stderr'));
```

Then point the policy at it with `'csp_report_uri' => '/csp-report.php'`. `helmet_csp_report()` answers exactly as the Laravel route does, because both go through the same core class: the same two formats, the same 16 KiB and 20-report caps, `204` with `Cache-Control: no-store` on success, `415`, `413` or `400` on a report it refuses, and `405` with `Allow: POST` on any other method. Each violation is logged at warning level with the three fields as context, and nothing else from the report is kept. The logger is optional, and a logger that throws never changes the answer. Pass `helmet_metrics_requests($config)` as the second argument to count violations by directive (see [Metrics](metrics.md)).

`helmet_csp_report()` sends the answer and exits. To send it yourself (a front controller, a test), call `helmet_csp_report_response()` with the same arguments: it returns the status, the headers and the body. There is no throttle on this path; the body and batch caps bound the work one request can force, and a rate limit belongs in your web server if you want one.

## Receiving the reports in PSR-15

A PSR-15 pipeline mounts `CspReportMiddleware`. On its path it answers the report itself, through the same core class, and the app never sees the request; on any other path it passes through. Mount it before `RequestForgeryGuard` and any sign-in middleware, for the same reason the raw PHP file skips `helmet_boot()`:

```php
use Waynestate\Helmet\Headers\CspReportMiddleware;

$middleware = [
    new CspReportMiddleware($factory, $factory, '/csp-report', $logger, $metrics),
    new RequestForgeryGuard($proxies, $factory, $factory),
    new SecurityHeadersMiddleware($proxies, cspReportUri: '/csp-report'),
];
```

The arguments are the PSR-17 response and stream factories, the path (default `/csp-report`), and the optional logger and `RequestMetrics`. It reads the body at most 16 KiB plus one byte deep, so an oversized report is refused without being read whole. Like the raw PHP path, it has no throttle of its own.

## Related

- [The headers](headers.md)
- [Laravel](usage-laravel.md)
- [Raw PHP](usage-raw-php.md)
- [PSR-15 middleware](usage-psr15.md)
- [Environment variables](../reference/configuration.md)
- [Troubleshooting](../troubleshooting.md)

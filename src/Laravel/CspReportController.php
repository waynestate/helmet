<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Waynestate\Helmet\Headers\CspReport;

/**
 * The endpoint that receives Content-Security-Policy violation reports.
 *
 * Browsers POST a report here when a page's CSP (or
 * Content-Security-Policy-Report-Only) is violated and the policy names this
 * endpoint via report-to / report-uri. The parsing lives in CspReport, which
 * the raw PHP helmet_csp_report() and the PSR-15 CspReportMiddleware share:
 * it caps the body size, accepts only the two report content types, and
 * keeps three fields per violation (the violated directive, the blocked URI's
 * host and the document path), never the full body. This controller logs
 * each one through the app's logger and counts it when request metrics are
 * on.
 *
 * The route is not registered by the service provider. An app opts in by
 * calling Route::cspReport() (see CspReportRoute), so the endpoint is off
 * unless the app wants it.
 */
final class CspReportController
{
    /**
     * The largest report body, in bytes, that the endpoint will accept (see
     * CspReport::MAX_BODY_BYTES).
     */
    public const int MAX_BODY_BYTES = CspReport::MAX_BODY_BYTES;

    /**
     * The most entries of a Reporting API batch that the endpoint processes
     * (see CspReport::MAX_REPORTS).
     */
    public const int MAX_REPORTS = CspReport::MAX_REPORTS;

    public function __invoke(Request $request): Response
    {
        $result = CspReport::parse(
            (string) $request->headers->get('Content-Type', ''),
            $request->getContent(),
            CspReport::declaredLength($request->headers->get('Content-Length')),
        );

        foreach ($result['violations'] as $violation) {
            Log::warning(CspReport::logLine($violation));

            // With request metrics on, count it too: helmet_csp_violations_total,
            // by directive only (a known directive name or "other"), so a forged
            // report cannot mint a time series. A counter that cannot tick must
            // not turn the browser's report into an error.
            try {
                app(RequestMetricsBinding::class)->get()?->cspViolation($violation['directive']);
            } catch (\Throwable) {
                // The violation is already in the log.
            }
        }

        return new Response($result['body'], $result['status']);
    }
}

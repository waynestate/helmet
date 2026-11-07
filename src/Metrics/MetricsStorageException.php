<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

/**
 * The request-metrics storage was named but cannot be used here.
 *
 * Callers catch it, log it, and carry on without request metrics: a metrics
 * problem must never break a page.
 */
final class MetricsStorageException extends \RuntimeException {}

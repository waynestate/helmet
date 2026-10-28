<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Waynestate\Helmet\Ribbon\Ribbon;

/**
 * Laravel middleware that injects the HELMET environment ribbon into HTML
 * responses.
 *
 * The ribbon is a small visual indicator that shows which environment an app
 * is running in, so a developer or operator can see at a glance that they are
 * not looking at production. It is only injected:
 *
 *  - when config('helmet.ribbon') is true,
 *  - when the response Content-Type is HTML,
 *  - when the environment is one of the non-production environments
 *    (local, development, dev, testing, test, staging). It is never injected
 *    for production, prod, live, or an empty environment.
 *
 * The ribbon HTML is inserted right after the opening <body ...> tag. If the
 * content has no <body> tag, it is left unchanged. A ribbon error must never
 * break the page, so any failure is swallowed and the response is returned
 * as-is.
 */
final class InjectRibbon
{
    /**
     * The environments the ribbon is shown for. Any other environment
     * (production, prod, live, empty, or unknown) never gets the ribbon.
     *
     * @var list<string>
     */
    private const RIBBON_ENVIRONMENTS = ['local', 'development', 'dev', 'testing', 'test', 'staging'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->maybeInjectRibbon($response);
        } catch (Throwable) {
            // A ribbon error must not break the page.
        }

        return $response;
    }

    private function maybeInjectRibbon(Response $response): void
    {
        if (!(bool) config('helmet.ribbon')) {
            return;
        }

        if (!$this->shouldShowForEnvironment()) {
            return;
        }

        if (!$this->isHtml($response)) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }

        $ribbon = new Ribbon((string) app()->environment());
        $ribbonHtml = $ribbon->html();
        if ($ribbonHtml === '') {
            return;
        }

        $modified = preg_replace_callback(
            '/<body[^>]*>/i',
            static function (array $matches) use ($ribbonHtml): string {
                return $matches[0] . $ribbonHtml;
            },
            $content,
            1,
        );

        if (is_string($modified) && $modified !== $content) {
            $response->setContent($modified);
        }
    }

    /**
     * Whether the ribbon should be shown for the current environment.
     */
    private function shouldShowForEnvironment(): bool
    {
        return in_array(app()->environment(), self::RIBBON_ENVIRONMENTS, true);
    }

    /**
     * Whether the response Content-Type is HTML.
     */
    private function isHtml(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');
        if (!is_string($contentType) || $contentType === '') {
            return false;
        }

        $mime = strtolower(trim(explode(';', $contentType)[0]));

        return $mime === 'text/html' || $mime === 'application/xhtml+xml';
    }
}

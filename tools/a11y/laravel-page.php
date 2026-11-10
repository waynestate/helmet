<?php

declare(strict_types=1);

/**
 * Render the Vue fixture's page through Laravel and HELMET, for the Vue
 * browser check (H51).
 *
 * Boots a Testbench application with HELMET's service provider at its
 * defaults, serves one route in the `web` group whose Blade view calls
 * @vite('src/main.js') against the fixture's Laravel build
 * (tools/vue-vite/dist-laravel, built by `npm run build:laravel` there), and
 * prints the response as JSON: {"headers": {name: value}, "html": "..."}.
 * Everything in the page is what a WELD or Inertia app would send: the CSP
 * header from HELMET's middleware, the nonce Laravel's Vite helper put on
 * the tags HELMET handed it, and the csp-nonce meta tag the middleware
 * injected. tools/a11y/vue-check.mjs serves that JSON and the built assets
 * in Chromium. An Inertia root view is a Blade view with the same @vite, so
 * this covers it.
 *
 * Usage: php tools/a11y/laravel-page.php > build/laravel-page.json
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Orchestra\Testbench\Foundation\Application;
use Waynestate\Helmet\Laravel\HelmetServiceProvider;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$publicPath = dirname(__DIR__) . '/vue-vite/dist-laravel';
if (!is_file($publicPath . '/build/manifest.json')) {
    fwrite(STDERR, "tools/vue-vite/dist-laravel/build/manifest.json is missing: run npm ci and npm run build:laravel in tools/vue-vite\n");
    exit(2);
}

$app = Application::create(options: ['extra' => ['providers' => [HelmetServiceProvider::class]]]);
$app->usePublicPath($publicPath);
// EncryptCookies in the web group needs a key: a throwaway one, per run.
$app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

// Root-relative asset URLs, so the page works on whatever origin serves it.
Vite::createAssetPathsUsing(static fn(string $path): string => '/' . ltrim($path, '/'));

$view = <<<'BLADE'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>HELMET: Vue through Laravel's Vite helper</title>
    @vite('src/main.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>
BLADE;

Route::middleware('web')->get('/', static fn() => Blade::render($view));

$kernel = $app->make(Kernel::class);
$request = Request::create('/', 'GET');
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

if ($response->getStatusCode() !== 200) {
    $error = property_exists($response, 'exception') && $response->exception instanceof Throwable
        ? ': ' . $response->exception->getMessage()
        : '';
    fwrite(STDERR, 'the page answered ' . $response->getStatusCode() . ', not 200' . $error . "\n");
    exit(2);
}

$headers = [];
foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
    // The check sets its own type and length, and the date says nothing.
    if (in_array(strtolower($name), ['content-type', 'content-length', 'date'], true)) {
        continue;
    }
    $headers[$name] = implode(', ', $values);
}

echo json_encode(
    ['headers' => $headers, 'html' => (string) $response->getContent()],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
), PHP_EOL;

<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * HELMET's configuration must stay documented as it grows. This test pins
 * three surfaces to their docs:
 *
 * - every key in config/helmet.php (top level and one level down) is named
 *   in backticks in docs/reference/configuration.md,
 * - every HELMET_ variable name read in config/helmet.php or under src/
 *   appears in docs/reference/configuration.md,
 * - every top-level option of helmet_boot() is named in backticks in
 *   README.md.
 *
 * The package reads env() in exactly one place (config/helmet.php), and this
 * test re-evaluates that file directly, the way
 * tests/Laravel/TestCase::reloadPackageConfig() does for the Laravel tests.
 * When the suite runs without the Laravel helper on the path, the tiny env()
 * below stands in, reading the same sources Laravel does.
 */
final class ConfigDocsTest extends TestCase
{
    public function testEveryConfigKeyIsDocumented(): void
    {
        $config = $this->loadPackageConfig();
        $spans = $this->backtickSpans($this->repoRoot() . '/docs/reference/configuration.md');

        $missing = [];
        foreach ($config as $key => $value) {
            $key = (string) $key;
            if (!$this->spanNames($spans, $key)) {
                $missing[] = $key;
            }

            // The value of "csp" is the policy itself: its keys are CSP
            // directive names (data), not config sub-keys, so they are not
            // listed here. Every other array value is a group of settings.
            if ($key === 'csp' || !is_array($value)) {
                continue;
            }
            foreach (array_keys($value) as $subKey) {
                if (!is_string($subKey)) {
                    continue;
                }
                $dotted = $key . '.' . $subKey;
                if (!$this->spanNames($spans, $dotted)) {
                    $missing[] = $dotted;
                }
            }
        }

        self::assertSame([], $missing, 'config keys missing from docs/reference/configuration.md (in backticks): ' . implode(', ', $missing));
    }

    public function testEveryEnvVariableIsDocumented(): void
    {
        $page = (string) file_get_contents($this->repoRoot() . '/docs/reference/configuration.md');
        $variables = $this->helmetVariables([
            $this->repoRoot() . '/config/helmet.php',
            ...$this->phpFiles($this->repoRoot() . '/src'),
        ]);

        $missing = [];
        foreach ($variables as $name => $files) {
            if (!str_contains($page, $name)) {
                $missing[] = $name . ' (read in ' . implode(', ', $files) . ')';
            }
        }

        self::assertSame([], $missing, 'HELMET_ variables missing from docs/reference/configuration.md: ' . implode('; ', $missing));
    }

    public function testEveryHelmetBootKeyIsDocumented(): void
    {
        $spans = $this->backtickSpans($this->repoRoot() . '/README.md');
        $keys = $this->helmetBootKeys();

        $missing = [];
        foreach ($keys as $key) {
            if (!$this->spanNames($spans, $key)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'helmet_boot() options missing from README.md (in backticks): ' . implode(', ', $missing));
    }

    /**
     * Re-evaluate config/helmet.php against the current environment.
     *
     * @return array<string, mixed>
     */
    private function loadPackageConfig(): array
    {
        return require $this->repoRoot() . '/config/helmet.php';
    }

    /**
     * The top-level options of helmet_boot(): the names in the docblock's
     * @param array{...} shape, or, when there is no such shape, the
     * $config['...'] and array_key_exists('...', $config) accesses in the
     * function body.
     *
     * @return list<string>
     */
    private function helmetBootKeys(): array
    {
        $source = (string) file_get_contents($this->repoRoot() . '/src/functions.php');
        $function = strpos($source, 'function helmet_boot(');
        self::assertNotFalse($function, 'helmet_boot() not found in src/functions.php');

        $docblock = $this->docblockBefore($source, $function);
        if ($docblock !== null && preg_match('/@param\s+array\{/', $docblock) === 1) {
            return $this->shapeKeys($docblock);
        }

        $body = $this->functionBody($source, $function);
        $keys = [];
        preg_match_all("/\\\$config\\[\\s*'([A-Za-z_][A-Za-z0-9_]*)'\\s*\\]/", $body, $direct);
        preg_match_all("/array_key_exists\\(\\s*'([A-Za-z_][A-Za-z0-9_]*)'\\s*,\\s*\\\$config\\s*\\)/", $body, $existence);
        foreach (array_merge($direct[1], $existence[1]) as $key) {
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * The docblock that ends immediately before the position, or null.
     */
    private function docblockBefore(string $source, int $position): ?string
    {
        $close = strrpos(substr($source, 0, $position), '*/');
        if ($close === false) {
            return null;
        }
        $open = strrpos(substr($source, 0, $close), '/**');
        if ($open === false) {
            return null;
        }

        return substr($source, $open, $close + 2 - $open);
    }

    /**
     * The top-level key names of the @param array{...} shape in a docblock.
     * Each line of the shape names one key at the start of the line; nested
     * shapes sit inline after their parent's key and are skipped.
     *
     * @return list<string>
     */
    private function shapeKeys(string $docblock): array
    {
        $start = strpos($docblock, '@param array{');
        self::assertNotFalse($start, 'the @param array{ shape has no opening brace');
        $lines = explode("\n", substr($docblock, $start + strlen('@param array{')));

        $keys = [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            // The closing line ends the shape: "* } $config".
            if ($i > 0 && preg_match('/^\*?\s*\}\s*(?:\$[A-Za-z_][A-Za-z0-9_]*)?\s*$/', $trimmed) === 1) {
                break;
            }
            if (preg_match('/^\*?\s*([A-Za-z_][A-Za-z0-9_]*)\??\s*:/', $trimmed, $match) === 1) {
                $keys[] = $match[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The body of the function at the position, from its first brace to the
     * matching close.
     */
    private function functionBody(string $source, int $function): string
    {
        $open = strpos($source, '{', $function);
        self::assertNotFalse($open, 'helmet_boot() has no body');
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        self::fail('the helmet_boot() body is not closed');
    }

    /**
     * The HELMET_ names read in the given files, by name with the files that
     * read them.
     *
     * @param list<string> $files
     *
     * @return array<string, list<string>>
     */
    private function helmetVariables(array $files): array
    {
        $found = [];
        foreach ($files as $file) {
            $matches = [];
            if (@preg_match_all('/HELMET_[A-Z0-9_]+/', (string) file_get_contents($file), $matches) <= 0) {
                continue;
            }
            foreach (array_unique($matches[0]) as $name) {
                $found[$name][] = $this->relativeToRepo($file);
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Every PHP file under the directory, recursively.
     *
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The inline code spans of a Markdown page: the text between single
     * backticks, one entry per span. Fenced code blocks are stripped first,
     * so a line like 'preset' => 'citadel' inside a fence does not count.
     *
     * @return list<string>
     */
    private function backtickSpans(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = (string) preg_replace('/^ {0,3}`{3,}.*?^ {0,3}`{3,}[ \t]*$/ms', '', $raw);
        $raw = (string) preg_replace('/^ {0,3}~{3,}.*?^ {0,3}~{3,}[ \t]*$/ms', '', $raw);

        preg_match_all('/`([^`\n]+)`/', $raw, $matches);

        return $matches[1];
    }

    /**
     * Whether a backtick span names the key: the span is the key itself, or
     * the Laravel config form of it (helmet.<key>).
     *
     * @param list<string> $spans
     */
    private function spanNames(array $spans, string $key): bool
    {
        foreach ($spans as $span) {
            if ($span === $key || $span === 'helmet.' . $key) {
                return true;
            }
        }

        return false;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relativeToRepo(string $path): string
    {
        $root = $this->repoRoot() . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}

// A tiny env() stand-in for running this test without the Laravel helper on
// the path: it reads the same sources Laravel's env() reads ($_ENV,
// $_SERVER and putenv), with the same true/false/null/empty casts. When the
// helper is loaded (the normal case), this stays out of the way.
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            'empty' => '',
            default => $value,
        };
    }
}

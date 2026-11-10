<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A helmet only protects what it actually covers, and a best-practices
 * guide is the same: every claim needs something underneath it. This keeps
 * BEST_PRACTICES.md honest. One tag per practice, an Enforced practice
 * names the tests that hold it, and every test and class it names exists
 * where it claims to.
 */
final class BestPracticesTest extends TestCase
{
    private const TAGS = ['Enforced', 'Partly enforced', 'Checked', 'Documented', 'Not yet'];

    public function testEveryPracticeHasExactlyOneStackTag(): void
    {
        $practices = $this->practices();
        self::assertGreaterThanOrEqual(50, count($practices), 'expected the H-numbered practices in BEST_PRACTICES.md');

        $wrong = [];
        foreach ($practices as $number => $text) {
            preg_match_all('/^-\s*\*\*Stack:\*\*\s+`([^`]*)`/m', $text, $tags);

            if (count($tags[1]) !== 1) {
                $wrong[] = 'H' . $number . ' has ' . count($tags[1]) . ' Stack tags';
            } elseif (!in_array($tags[1][0], self::TAGS, true)) {
                $wrong[] = 'H' . $number . ' has the unknown Stack tag `' . $tags[1][0] . '`';
            }
        }

        self::assertSame([], $wrong, 'practices without exactly one valid Stack tag: ' . implode('; ', $wrong));
    }

    public function testEveryEnforcedPracticeNamesATest(): void
    {
        $bare = [];
        foreach ($this->practices() as $number => $text) {
            if (preg_match('/^-\s*\*\*Stack:\*\*\s+`Enforced`/m', $text) !== 1) {
                continue;
            }

            if (!preg_match('/`(test[A-Z][A-Za-z0-9_]*|[A-Z][A-Za-z0-9]*Test(?:::test[A-Za-z0-9_]+)?)`/', $text)) {
                $bare[] = 'H' . $number;
            }
        }

        self::assertSame([], $bare, 'Enforced practices must name the tests that hold them: ' . implode(', ', $bare));
    }

    public function testEveryTestMethodNamedInBestPracticesExists(): void
    {
        $missing = array_values(array_diff($this->citedMethodTokens(), $this->allTestMethods()));

        self::assertSame([], $missing, 'BEST_PRACTICES.md names test methods that do not exist under tests/ (' . $this->citedBy($missing) . '): ' . implode(', ', $missing));
    }

    public function testEveryTestClassNamedInBestPracticesExists(): void
    {
        preg_match_all('/`([A-Z][A-Za-z0-9]*Test)(?:::|`)/', $this->bestPracticesContent(), $matches);
        $missing = array_values(array_diff(array_unique($matches[1]), array_keys($this->testMethodsByClass())));

        self::assertSame([], $missing, 'BEST_PRACTICES.md names test classes with no file under tests/ (' . $this->citedBy($missing) . '): ' . implode(', ', $missing));
    }

    public function testEveryClassAndMethodPairIsInThatClass(): void
    {
        preg_match_all('/`([A-Z][A-Za-z0-9]*Test)::(test[A-Za-z0-9_]+)`/', $this->bestPracticesContent(), $matches, PREG_SET_ORDER);
        self::assertNotSame([], $matches, 'expected at least one backticked Class::method citation in BEST_PRACTICES.md, so this check cannot pass vacuously');

        $methods = $this->testMethodsByClass();
        $wrong = [];
        foreach ($matches as [, $class, $method]) {
            if (!in_array($method, $methods[$class] ?? [], true)) {
                $wrong[] = "{$class}::{$method}";
            }
        }

        self::assertSame([], $wrong, 'BEST_PRACTICES.md names a method in a class that does not declare it (' . $this->citedBy($wrong) . '): ' . implode(', ', $wrong));
    }

    /**
     * A line cite into a test file (`tests/X.php:N`) points at the
     * declaration of a test the same guide line names, so a test that moves
     * takes its cite with it instead of leaving it pointing at the wrong
     * method, a comment or a blank line.
     */
    public function testEveryCitedTestLineDeclaresATestNamedOnTheSameLine(): void
    {
        $checked = 0;
        $wrong = [];
        foreach (explode("\n", $this->bestPracticesContent()) as $index => $line) {
            preg_match_all('/`(tests\/[A-Za-z0-9_\/]+\.php):(\d+)`/', $line, $cites, PREG_SET_ORDER);
            foreach ($cites as [, $file, $number]) {
                $checked++;
                $declared = $this->lineOf($file, (int) $number);
                if ($declared === null) {
                    $wrong[] = "{$file}:{$number} (BEST_PRACTICES.md line " . ($index + 1) . ', no such line)';
                } elseif (preg_match('/function\s+(test[A-Za-z0-9_]+)\s*\(/', $declared, $method) !== 1) {
                    $wrong[] = "{$file}:{$number} (BEST_PRACTICES.md line " . ($index + 1) . ', not a test declaration: ' . trim($declared) . ')';
                } elseif (!str_contains($line, '`' . $method[1] . '`')) {
                    $wrong[] = "{$file}:{$number} (BEST_PRACTICES.md line " . ($index + 1) . ', declares ' . $method[1] . ', which that line does not name)';
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'expected line cites into tests/ in BEST_PRACTICES.md, so this check cannot pass vacuously');
        self::assertSame([], $wrong, 'BEST_PRACTICES.md line cites into tests/ that drifted: ' . implode('; ', $wrong));
    }

    /**
     * A line cite into the source (`src/X.php:N` or `:N-M`) names a file that
     * exists and a range inside it that does not start on a blank line. It
     * cannot prove the line is the right one, but code that moves usually
     * leaves its old cite on a blank line or past the end of the file.
     */
    public function testEverySourceLineCiteIsInsideItsFile(): void
    {
        preg_match_all('/`(src\/[A-Za-z0-9_\/]+\.php):(\d+)(?:-(\d+))?`/', $this->bestPracticesContent(), $cites, PREG_SET_ORDER);
        self::assertNotSame([], $cites, 'expected line cites into src/ in BEST_PRACTICES.md, so this check cannot pass vacuously');

        $wrong = [];
        foreach ($cites as $cite) {
            [$whole, $file, $start] = $cite;
            $end = isset($cite[3]) ? (int) $cite[3] : (int) $start;
            $first = $this->lineOf($file, (int) $start);
            if ($first === null || $this->lineOf($file, $end) === null || $end < (int) $start) {
                $wrong[] = $whole . ' (outside the file)';
            } elseif (trim($first) === '') {
                $wrong[] = $whole . ' (starts on a blank line)';
            }
        }

        self::assertSame([], array_values(array_unique($wrong)), 'BEST_PRACTICES.md line cites into src/ that drifted: ' . implode('; ', array_unique($wrong)));
    }

    /**
     * Line $number (1-based) of a repo file, or null when the file or the line does not exist.
     */
    private function lineOf(string $file, int $number): ?string
    {
        $path = $this->repoRoot() . '/' . $file;
        if ($number < 1 || !is_file($path)) {
            return null;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));

        return $lines[$number - 1] ?? null;
    }

    /**
     * Every testX token the guide names, anywhere in it, unique.
     *
     * @return list<string>
     */
    private function citedMethodTokens(): array
    {
        preg_match_all('/\btest[A-Z][A-Za-z0-9_]*\b/', $this->bestPracticesContent(), $matches);

        return array_values(array_unique($matches[0]));
    }

    private function bestPracticesContent(): string
    {
        $path = $this->repoRoot() . '/BEST_PRACTICES.md';
        self::assertFileExists($path, 'BEST_PRACTICES.md must sit at the repository root');

        $content = file_get_contents($path);
        self::assertNotFalse($content, 'could not read BEST_PRACTICES.md');

        return str_replace("\r\n", "\n", $content);
    }

    /**
     * Each `### Hn.` practice, keyed by its number, up to the next heading.
     *
     * @return array<int, string>
     */
    private function practices(): array
    {
        preg_match_all('/^### H(\d+)\. (.*?)(?=^#{2,3} |\z)/ms', $this->bestPracticesContent(), $matches, PREG_SET_ORDER);
        $practices = [];

        foreach ($matches as [$whole, $number]) {
            $practices[(int) $number] = $whole;
        }

        return $practices;
    }

    /**
     * Every test method declared under tests/, flattened.
     *
     * @return list<string>
     */
    private function allTestMethods(): array
    {
        return array_values(array_merge(...array_values($this->testMethodsByClass())));
    }

    /**
     * Test methods by the short class name of the file that declares them. Two files may share a short name
     * (the unit and Laravel ReadinessTest), so their methods are merged.
     *
     * @return array<string, list<string>>
     */
    private function testMethodsByClass(): array
    {
        $byClass = [];
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repoRoot() . '/tests', FilesystemIterator::SKIP_DOTS));

        foreach ($directory as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/function\s+(test[A-Z][A-Za-z0-9_]*)\s*\(/', (string) file_get_contents($file->getPathname()), $matches);
            $class = $file->getBasename('.php');
            $byClass[$class] = array_values(array_unique(array_merge($byClass[$class] ?? [], $matches[1])));
        }

        return $byClass;
    }

    /**
     * Which practices cite each of the given tokens, as "name (H10, H28)".
     *
     * @param list<string> $tokens
     */
    private function citedBy(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as $token) {
            $where = [];
            foreach ($this->practices() as $number => $text) {
                if (str_contains($text, $token)) {
                    $where[] = 'H' . $number;
                }
            }

            $parts[] = $token . ' (' . (implode(', ', $where) ?: 'outside the practices') . ')';
        }

        return implode('; ', $parts);
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

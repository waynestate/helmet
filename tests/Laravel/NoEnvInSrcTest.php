<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The package reads env() in exactly one place: config/helmet.php. No file
 * under src/ may call env() directly; they read config() instead.
 *
 * This test tokenizes every PHP file under src/ and fails if it finds a real
 * env() call. Comments and strings are separate tokens, so a docblock that
 * merely mentions env() (for example "this middleware never reads env()
 * directly") does not count, and neither does a function merely named env():
 * a declaration is not a call.
 */
final class NoEnvInSrcTest extends TestCase
{
    public function testNoFileUnderSrcCallsEnv(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        self::assertDirectoryExists($srcDir);

        $offenders = $this->envOffenders($srcDir);

        self::assertSame([], $offenders, 'env() calls found under src/: ' . implode(', ', $offenders));
    }

    /**
     * The scanner still bites: a real env('X') call in a throwaway tree is
     * reported, while a function merely named env() next to it is not.
     */
    public function testRealEnvCallIsStillCaught(): void
    {
        $temp = sys_get_temp_dir() . '/helmet-no-env-src-' . getmypid() . '-' . substr(md5((string) mt_rand()), 0, 8);
        mkdir($temp, 0775, true);
        file_put_contents($temp . '/calls-env.php', "<?php\n\necho env('X');\n");
        file_put_contents($temp . '/declares-env.php', "<?php\n\nfunction env(): string\n{\n    return 'x';\n}\n");

        try {
            $offenders = $this->envOffenders($temp);

            self::assertCount(1, $offenders);
            self::assertStringStartsWith($temp . '/calls-env.php:', $offenders[0]);
        } finally {
            @unlink($temp . '/calls-env.php');
            @unlink($temp . '/declares-env.php');
            @rmdir($temp);
        }
    }

    /**
     * Every "path:line" of a global env() call in the PHP files under $dir.
     *
     * @return list<string>
     */
    private function envOffenders(string $dir): array
    {
        $offenders = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            foreach ($this->envCallLines($tokens) as $line) {
                $offenders[] = $file->getPathname() . ':' . $line;
            }
        }

        return $offenders;
    }

    /**
     * The line numbers of every global env() call in the token stream.
     *
     * A global env() call is a T_STRING "env" immediately followed by "(",
     * where the preceding meaningful token is not "->" or "::" (which would
     * make it a method or static call) and not "function" (which would make
     * it a declaration).
     *
     * @param list<mixed> $tokens
     *
     * @return list<int>
     */
    private function envCallLines(array $tokens): array
    {
        $lines = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'env') {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $i);
            $prev = $this->prevMeaningful($tokens, $i);

            if ($next === '(' && $prev !== '->' && $prev !== '::' && $prev !== 'function') {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /**
     * The next meaningful token (skipping whitespace and comments), as a
     * string, or null if there is none.
     *
     * @param list<mixed> $tokens
     */
    private function nextMeaningful(array $tokens, int $i): ?string
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }

    /**
     * The previous meaningful token (skipping whitespace and comments), as a
     * string, or null if there is none.
     *
     * @param list<mixed> $tokens
     */
    private function prevMeaningful(array $tokens, int $i): ?string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }
}

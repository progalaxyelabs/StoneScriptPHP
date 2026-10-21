<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

/**
 * Lint for SQL function sources: a `RAISE EXCEPTION '[public...] ...'` publishes its text to the API client
 * VERBATIM, so it must never interpolate a value (`%`). A `%` in such a message would send whatever the caller
 * passed (an e-mail, a phone number, another column) straight into an HTTP response.
 *
 * Use it from a test over your own `src/postgresql/` tree:
 *
 *     $this->assertSame([], PublicRaiseLint::scanDirectory(SRC_PATH . 'postgresql'));
 */
final class PublicRaiseLint
{
    /**
     * @return list<int> 1-based line numbers of offending RAISE statements
     */
    public static function scan(string $sql): array
    {
        $hits = [];
        // RAISE [level] '<format>' ... ;  the format string is the first single-quoted literal ('' escapes a quote)
        if (preg_match_all("/RAISE\\s+(?:EXCEPTION\\s+)?'((?:[^']|'')*)'/i", $sql, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as [$format, $offset]) {
                if (preg_match('/^\\s*\\[public[^\\]]*\\]/', $format) === 1 && preg_match('/(?<!%)%(?!%)/', str_replace('%%', '', $format)) === 1) {
                    $hits[] = substr_count(substr($sql, 0, (int) $offset), "\n") + 1;
                }
            }
        }
        return $hits;
    }

    /**
     * @return array<string,list<int>> file => offending line numbers
     */
    public static function scanDirectory(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && preg_match('/\\.(pgsql|sql|pssql)$/', $file->getFilename()) === 1) {
                $hits = self::scan((string) file_get_contents($file->getPathname()));
                if ($hits !== []) {
                    $out[$file->getPathname()] = $hits;
                }
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

/**
 * Lint for SQL function sources. Two rules about `RAISE`, because the message text is an API:
 *
 *  1. `public-interpolation`: a RAISE whose message starts with `[public` publishes its text to the API client
 *     VERBATIM, so it must never interpolate a value. Flagged: `%` in the format string, `||` concatenation or
 *     `format()` with arguments in `USING MESSAGE = ...`, in plain, E'' and dollar-quoted strings.
 *  2. `leading-interpolation`: no RAISE message may START with an interpolated value (`RAISE '% is taken', v`,
 *     `USING MESSAGE = v || ' ...'`). The first token of a message is read as its application error code or public
 *     marker, so a value there lets a caller choose it.
 *
 * Self-contained (no framework dependencies) so `php stone validate sqlintegrity` can load it directly. That command
 * runs it by default over every function file; use it from a test too:
 *
 *     $this->assertSame([], PublicRaiseLint::scanDirectory(SRC_PATH . 'postgresql'));
 */
final class PublicRaiseLint
{
    private const OPTION_KEYWORDS = 'DETAIL|HINT|ERRCODE|COLUMN|CONSTRAINT|DATATYPE|TABLE|SCHEMA|MESSAGE';

    /**
     * @return list<int> 1-based line numbers of offending RAISE statements
     */
    public static function scan(string $sql): array
    {
        return array_values(array_unique(array_map(static fn (array $h): int => $h['line'], self::scanDetailed($sql))));
    }

    /**
     * @return list<array{line:int, rule:string, message:string}>
     */
    public static function scanDetailed(string $sql): array
    {
        // A deliberately unsafe fixture can opt out: `-- stonescript-lint: ignore-file` on its own line.
        if (preg_match('/^\s*--\s*stonescript-lint:\s*ignore-file\b/m', $sql) === 1) {
            return [];
        }
        $src = self::stripComments($sql);
        $hits = [];
        if (preg_match_all('/\bRAISE\b/i', $src, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }
        foreach ($m[0] as [, $pos]) {
            $stmt = self::parseRaise($src, (int) $pos + 5);
            if ($stmt === null) {
                continue;
            }
            $line = substr_count(substr($src, 0, (int) $pos), "\n") + 1;
            foreach (self::judge($stmt) as $rule => $msg) {
                $hits[] = ['line' => $line, 'rule' => $rule, 'message' => $msg];
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
            if ($file instanceof \SplFileInfo && $file->isFile() && preg_match('/\.(pgsql|sql|pssql)$/', $file->getFilename()) === 1) {
                $hits = self::scan((string) file_get_contents($file->getPathname()));
                if ($hits !== []) {
                    $out[$file->getPathname()] = $hits;
                }
            }
        }
        return $out;
    }

    /**
     * @param array{literal: ?string, using: ?string} $stmt `literal` = the RAISE format string, `using` = the raw
     *        expression of `USING MESSAGE = <expr>`
     * @return array<string,string> rule => explanation
     */
    private static function judge(array $stmt): array
    {
        $out = [];
        $format = $stmt['literal'];
        $expr = $stmt['using'];

        if ($format !== null) {
            $public = preg_match('/^\s*\[public/', $format) === 1;
            if (preg_match('/^\s*%(?!%)/', $format) === 1) {
                $out['leading-interpolation'] = 'a RAISE message must not start with an interpolated value';
            }
            if ($public && preg_match('/(?<!%)%(?!%)/', str_replace('%%', '', $format)) === 1) {
                $out['public-interpolation'] = 'a [public...] RAISE is published verbatim and must not interpolate a value';
            }
        }
        if ($expr !== null) {
            $trim = ltrim($expr);
            $isFormat = preg_match('/^format\s*\(\s*/i', $trim, $fm) === 1;
            $lit = self::readLiteral($isFormat ? substr($trim, strlen($fm[0])) : $trim, 0);
            $text = $lit['text'] ?? '';
            if ($lit === null || preg_match('/^\s*%/', $text) === 1) {
                $out['leading-interpolation'] = 'a RAISE message must not start with an interpolated value';
            }
            if (preg_match('/^\s*\[public/', $text) === 1) {
                $residue = self::stripLiterals($trim);
                if ($isFormat || str_contains($residue, '||') || preg_match('/[A-Za-z_]/', $residue) === 1) {
                    $out['public-interpolation'] = 'a [public...] RAISE is published verbatim and must not interpolate a value (USING MESSAGE)';
                }
            }
        }
        return $out;
    }

    /** `--` and block comments removed, newlines kept so line numbers stay right. String literals are respected. */
    private static function stripComments(string $sql): string
    {
        $out = '';
        $n = strlen($sql);
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $chunk = $end === false ? substr($sql, $i) : substr($sql, $i, $end + 2 - $i);
                $out .= str_repeat("\n", substr_count($chunk, "\n"));
                $i += strlen($chunk) - 1;
                continue;
            }
            if ($c === "'") {
                $lit = self::readLiteral($sql, $i);
                if ($lit !== null) {
                    $out .= substr($sql, $i, $lit['end'] - $i);
                    $i = $lit['end'] - 1;
                    continue;
                }
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * Reads a string literal starting exactly at $i: '...', E'...', $tag$...$tag$.
     *
     * @return array{text:string, end:int}|null `end` is the offset just after the literal
     */
    private static function readLiteral(string $s, int $i): ?array
    {
        $n = strlen($s);
        $c = $s[$i] ?? '';
        $escape = false;
        if (($c === 'E' || $c === 'e') && ($s[$i + 1] ?? '') === "'") {
            $escape = true;
            $i++;
            $c = "'";
        }
        if ($c === "'") {
            $j = $i + 1;
            $text = '';
            while ($j < $n) {
                if ($escape && $s[$j] === '\\') {
                    $text .= ($s[$j + 1] ?? '');
                    $j += 2;
                    continue;
                }
                if ($s[$j] === "'") {
                    if (($s[$j + 1] ?? '') === "'") {
                        $text .= "'";
                        $j += 2;
                        continue;
                    }
                    return ['text' => $text, 'end' => $j + 1];
                }
                $text .= $s[$j++];
            }
            return null;
        }
        if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $s, $m, 0, $i) === 1) {
            $tag = $m[0];
            $close = strpos($s, $tag, $i + strlen($tag));
            if ($close === false) {
                return null;
            }
            return ['text' => substr($s, $i + strlen($tag), $close - $i - strlen($tag)), 'end' => $close + strlen($tag)];
        }
        return null;
    }

    /** @return array{text:string, at:int, end:int}|null the first string literal in $s and its offset */
    private static function firstLiteral(string $s): ?array
    {
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $lit = self::readLiteral($s, $i);
            if ($lit !== null) {
                return ['text' => $lit['text'], 'at' => $i, 'end' => $lit['end']];
            }
        }
        return null;
    }

    private static function stripLiterals(string $s): string
    {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $lit = self::readLiteral($s, $i);
            if ($lit !== null) {
                $i = $lit['end'] - 1;
                continue;
            }
            $out .= $s[$i];
        }
        return $out;
    }

    /**
     * Parses one RAISE statement starting just after the RAISE keyword.
     *
     * @return array{literal: ?string, using: ?string}|null
     */
    private static function parseRaise(string $s, int $i): ?array
    {
        $n = strlen($s);
        $skip = static function () use ($s, $n, &$i): void {
            while ($i < $n && ctype_space($s[$i])) {
                $i++;
            }
        };
        $skip();
        // level or condition name / SQLSTATE '...'
        if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/', $s, $m, 0, $i) === 1) {
            $i += strlen($m[1]);
            $skip();
            if (strcasecmp($m[1], 'SQLSTATE') === 0) {
                $lit = self::readLiteral($s, $i);
                if ($lit === null) {
                    return null;
                }
                $i = $lit['end'];
                $skip();
            }
            if (strcasecmp($m[1], 'USING') === 0) {
                $i -= 5;
                $skip();
            }
        }
        $literal = null;
        $lit = self::readLiteral($s, $i);
        if ($lit !== null) {
            $literal = $lit['text'];
            $i = $lit['end'];
        }
        // find USING MESSAGE = expr  /  end of statement
        $using = null;
        $depth = 0;
        for ($j = $i; $j < $n; $j++) {
            $lit = self::readLiteral($s, $j);
            if ($lit !== null) {
                $j = $lit['end'] - 1;
                continue;
            }
            $ch = $s[$j];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ';' && $depth <= 0) {
                break;
            } elseif ($depth <= 0 && preg_match('/\GUSING\b/i', $s, $m, 0, $j) === 1 && ($j === 0 || !ctype_alnum($s[$j - 1]))) {
                $using = self::usingMessage($s, $j + 5);
                break;
            }
        }
        if ($literal === null && $using === null) {
            return null;
        }
        return ['literal' => $literal, 'using' => $using];
    }

    /** The expression of `MESSAGE = <expr>` inside a USING list starting at $i, or null when absent. */
    private static function usingMessage(string $s, int $i): ?string
    {
        $n = strlen($s);
        $depth = 0;
        $start = null;
        for ($j = $i; $j < $n; $j++) {
            $lit = self::readLiteral($s, $j);
            if ($lit !== null) {
                $j = $lit['end'] - 1;
                continue;
            }
            $ch = $s[$j];
            if ($start === null) {
                if (preg_match('/\GMESSAGE\s*=\s*/i', $s, $m, 0, $j) === 1 && ($j === $i || !ctype_alnum($s[$j - 1]))) {
                    $start = $j + strlen($m[0]);
                    $j = $start - 1;
                } elseif ($ch === ';' && $depth <= 0) {
                    return null;
                }
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($depth <= 0 && ($ch === ';' || ($ch === ',' && preg_match('/\G,\s*(?:' . self::OPTION_KEYWORDS . ')\s*=/i', $s, $m2, 0, $j) === 1))) {
                return trim(substr($s, $start, $j - $start));
            }
        }
        return $start === null ? null : trim(substr($s, $start));
    }
}

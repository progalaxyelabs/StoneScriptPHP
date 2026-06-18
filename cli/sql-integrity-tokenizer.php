<?php

declare(strict_types=1);

/**
 * SQL Integrity — Tokenizer
 *
 * Converts raw SQL/PL/pgSQL text into a flat SqlToken stream.
 *
 * Handles:
 *   - Single-quoted strings  'text with ''escaped'' quotes'   → STRING (content stripped)
 *   - Dollar-quoted strings  $$text$$ / $tag$text$tag$        → STRING
 *   - Line comments          -- comment                        → skipped
 *   - Block comments         /* comment *\/                    → skipped
 *   - Type casts             ::TEXT ::UUID[]                   → CAST  (type name not a ref)
 *   - Qualified names        alias.column                      → QUALIFIED (single unit)
 *   - Multi-word keywords    GROUP BY, LEFT JOIN, INSERT INTO  → KEYWORD
 *   - Parentheses                                              → PAREN_OPEN / PAREN_CLOSE
 *   - Everything else                                          → IDENT / NUMBER / OPERATOR / OTHER
 */

// ── Exception ─────────────────────────────────────────────────────────────────

class ParserDepthLimitException extends \RuntimeException
{
    public function __construct(
        string              $message,
        public readonly int $depth,
        public readonly int $parserLine,  // named parserLine: Exception::$line is reserved
        public readonly string $fnName = '',
    ) {
        parent::__construct($message);
    }
}

// ── Token ─────────────────────────────────────────────────────────────────────

class SqlToken
{
    // Type constants
    public const KEYWORD     = 'KW';  // recognised clause/block keyword
    public const IDENT       = 'ID';  // plain identifier
    public const QUALIFIED   = 'QU';  // left.right  (potential alias.column)
    public const STRING      = 'ST';  // 'literal' — contents stripped, not a ref source
    public const CAST        = 'CA';  // ::TYPE — right-hand type is not a column ref
    public const PAREN_OPEN  = 'PO';
    public const PAREN_CLOSE = 'PC';
    public const COMMA       = 'CM';
    public const SEMICOLON   = 'SC';
    public const NUMBER      = 'NU';
    public const OPERATOR    = 'OP';
    public const OTHER       = 'OT';

    public function __construct(
        public readonly string $type,
        public readonly string $value,  // as written in source
        public readonly string $upper,  // uppercase; use for keyword comparison
        public readonly int    $line,
    ) {}

    public function isKeyword(string ...$kws): bool
    {
        if ($this->type !== self::KEYWORD) {
            return false;
        }
        foreach ($kws as $kw) {
            if ($this->upper === $kw) {
                return true;
            }
        }
        return false;
    }
}

// ── Tokenizer ─────────────────────────────────────────────────────────────────

class SqlTokenizer
{
    private string $src  = '';
    private int    $pos  = 0;
    private int    $len  = 0;
    private int    $line = 1;

    /**
     * Words that begin a multi-word keyword, mapped to their possible
     * completions (longest alternative first so we try the greedier match).
     */
    private const MULTI = [
        'GROUP'      => [['BY']],
        'ORDER'      => [['BY']],
        'PARTITION'  => [['BY']],
        'LEFT'       => [['OUTER', 'JOIN'], ['JOIN']],
        'RIGHT'      => [['OUTER', 'JOIN'], ['JOIN']],
        'FULL'       => [['OUTER', 'JOIN'], ['JOIN']],
        'INNER'      => [['JOIN']],
        'CROSS'      => [['JOIN']],
        'INSERT'     => [['INTO']],
        'DELETE'     => [['FROM']],
        'RETURN'     => [['QUERY']],
        'UNION'      => [['ALL']],
        'END'        => [['IF'], ['LOOP'], ['CASE']],
    ];

    /**
     * Complete set of keywords the tree builder uses as segment boundaries.
     * Must include every possible result of tryMulti() as well as
     * single-word keywords.
     */
    private const KW_SET = [
        // DQL
        'SELECT', 'FROM', 'WHERE', 'ON', 'HAVING',
        'GROUP BY', 'ORDER BY', 'PARTITION BY',
        'LIMIT', 'OFFSET', 'FETCH',
        'DISTINCT', 'ALL',
        // JOINs
        'JOIN',
        'INNER JOIN',
        'LEFT JOIN',   'LEFT OUTER JOIN',
        'RIGHT JOIN',  'RIGHT OUTER JOIN',
        'FULL JOIN',   'FULL OUTER JOIN',
        'CROSS JOIN',
        // Set ops
        'UNION', 'UNION ALL', 'INTERSECT', 'EXCEPT',
        // CTE / alias
        'WITH', 'AS',
        // DML
        'INSERT INTO', 'VALUES', 'SET', 'RETURNING',
        'UPDATE', 'DELETE FROM',
        // PL/pgSQL statement launchers
        'INTO', 'PERFORM', 'RETURN', 'RETURN QUERY',
        'EXECUTE', 'RAISE',
        // PL/pgSQL block structure
        'DECLARE', 'BEGIN', 'END',
        'END IF', 'END LOOP', 'END CASE',
        // PL/pgSQL control flow
        'IF', 'THEN', 'ELSIF', 'ELSE',
        'LOOP', 'FOR', 'WHILE', 'FOREACH', 'IN',
        // CASE expressions (SQL, not PL/pgSQL block)
        'CASE', 'WHEN',
    ];

    /** @return SqlToken[] */
    public function tokenize(string $sql): array
    {
        $this->src  = $sql;
        $this->pos  = 0;
        $this->len  = strlen($sql);
        $this->line = 1;

        $out = [];

        while ($this->pos < $this->len) {
            $this->skipWs();
            if ($this->pos >= $this->len) {
                break;
            }

            $c    = $this->src[$this->pos];
            $line = $this->line;

            // ── Comments ─────────────────────────────────────────────────────
            if ($c === '-' && ($this->src[$this->pos + 1] ?? '') === '-') {
                while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
                    $this->pos++;
                }
                continue;
            }
            if ($c === '/' && ($this->src[$this->pos + 1] ?? '') === '*') {
                $this->skipBlockComment();
                continue;
            }

            // ── String literals ───────────────────────────────────────────────
            if ($c === "'") {
                $this->skipSingleQuoteString();
                $out[] = new SqlToken(SqlToken::STRING, "''", "''", $line);
                continue;
            }

            // ── Dollar-quoted strings ─────────────────────────────────────────
            if ($c === '$') {
                if ($this->skipDollarString()) {
                    $out[] = new SqlToken(SqlToken::STRING, '$...$', '$...$', $line);
                } else {
                    // $1, $2 positional parameter — not a ref
                    $out[] = new SqlToken(SqlToken::OTHER, '$', '$', $line);
                    $this->pos++;
                }
                continue;
            }

            // ── Type cast  :: ─────────────────────────────────────────────────
            if ($c === ':' && ($this->src[$this->pos + 1] ?? '') === ':') {
                $out[] = $this->scanCast($line);
                continue;
            }

            // ── Structural punctuation ────────────────────────────────────────
            if ($c === '(') {
                $out[] = new SqlToken(SqlToken::PAREN_OPEN,  '(', '(', $line);
                $this->pos++;
                continue;
            }
            if ($c === ')') {
                $out[] = new SqlToken(SqlToken::PAREN_CLOSE, ')', ')', $line);
                $this->pos++;
                continue;
            }
            if ($c === ';') {
                $out[] = new SqlToken(SqlToken::SEMICOLON, ';', ';', $line);
                $this->pos++;
                continue;
            }
            if ($c === ',') {
                $out[] = new SqlToken(SqlToken::COMMA, ',', ',', $line);
                $this->pos++;
                continue;
            }

            // ── Quoted identifier  "name" ─────────────────────────────────────
            if ($c === '"') {
                $out[] = $this->scanQuotedIdent($line);
                continue;
            }

            // ── Number ────────────────────────────────────────────────────────
            if (ctype_digit($c)) {
                $out[] = $this->scanNumber($line);
                continue;
            }

            // ── Identifier / keyword ──────────────────────────────────────────
            if (ctype_alpha($c) || $c === '_') {
                $out[] = $this->scanIdentOrKeyword($line);
                continue;
            }

            // ── Operators ─────────────────────────────────────────────────────
            static $opChars = ['=',  '<', '>', '!', '|', '+', '-',
                               '*', '/', '%', '~', '^', '&', '#', '@'];
            if (in_array($c, $opChars, true)) {
                $out[] = $this->scanOperator($line);
                continue;
            }

            // ── Anything else (dot handled as OTHER; normally consumed by qualified scanner) ──
            $out[] = new SqlToken(SqlToken::OTHER, $c, strtoupper($c), $line);
            $this->pos++;
        }

        return $out;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function skipWs(): void
    {
        while ($this->pos < $this->len && ctype_space($this->src[$this->pos])) {
            if ($this->src[$this->pos] === "\n") {
                $this->line++;
            }
            $this->pos++;
        }
    }

    private function skipBlockComment(): void
    {
        $this->pos += 2; // skip /*
        while ($this->pos < $this->len - 1) {
            if ($this->src[$this->pos] === "\n") {
                $this->line++;
            }
            if ($this->src[$this->pos] === '*' && $this->src[$this->pos + 1] === '/') {
                $this->pos += 2;
                return;
            }
            $this->pos++;
        }
    }

    private function skipSingleQuoteString(): void
    {
        $this->pos++; // skip opening '
        while ($this->pos < $this->len) {
            if ($this->src[$this->pos] === "\n") {
                $this->line++;
            }
            if ($this->src[$this->pos] === "'") {
                $this->pos++;
                if ($this->pos < $this->len && $this->src[$this->pos] === "'") {
                    $this->pos++; // escaped '' — continue
                    continue;
                }
                return; // end of string
            }
            $this->pos++;
        }
    }

    private function skipDollarString(): bool
    {
        // Match $tag$ ... $tag$  where tag is [a-zA-Z0-9_]*
        $saved     = $this->pos;
        $savedLine = $this->line;
        $this->pos++; // skip first $

        $tag = '';
        while ($this->pos < $this->len
            && (ctype_alnum($this->src[$this->pos]) || $this->src[$this->pos] === '_')
        ) {
            $tag .= $this->src[$this->pos++];
        }

        if ($this->pos >= $this->len || $this->src[$this->pos] !== '$') {
            $this->pos  = $saved;
            $this->line = $savedLine;
            return false; // not a dollar-quoted string
        }
        $this->pos++; // skip closing $ of opening tag

        $endTag = '$' . $tag . '$';
        $endLen = strlen($endTag);

        while ($this->pos < $this->len) {
            if ($this->src[$this->pos] === "\n") {
                $this->line++;
            }
            if (substr($this->src, $this->pos, $endLen) === $endTag) {
                $this->pos += $endLen;
                return true;
            }
            $this->pos++;
        }

        return true; // unterminated — consumed anyway
    }

    private function scanCast(int $line): SqlToken
    {
        $this->pos += 2; // skip ::
        $this->skipWs();

        // Read type name (identifier)
        $s = $this->pos;
        while ($this->pos < $this->len
            && (ctype_alnum($this->src[$this->pos]) || $this->src[$this->pos] === '_')
        ) {
            $this->pos++;
        }
        $type = substr($this->src, $s, $this->pos - $s);

        // Skip optional precision: VARCHAR(255)
        if ($this->pos < $this->len && $this->src[$this->pos] === '(') {
            $d = 1;
            $this->pos++;
            while ($this->pos < $this->len && $d > 0) {
                if ($this->src[$this->pos] === '(') {
                    $d++;
                } elseif ($this->src[$this->pos] === ')') {
                    $d--;
                }
                $this->pos++;
            }
        }

        // Skip optional array brackets: TEXT[]
        while ($this->pos < $this->len && $this->src[$this->pos] === '[') {
            $this->pos++;
            while ($this->pos < $this->len && $this->src[$this->pos] !== ']') {
                $this->pos++;
            }
            if ($this->pos < $this->len) {
                $this->pos++;
            }
        }

        $upper = '::' . strtoupper($type);
        return new SqlToken(SqlToken::CAST, '::' . $type, $upper, $line);
    }

    private function scanQuotedIdent(int $line): SqlToken
    {
        $this->pos++; // skip opening "
        $name = '';
        while ($this->pos < $this->len && $this->src[$this->pos] !== '"') {
            $name .= $this->src[$this->pos++];
        }
        if ($this->pos < $this->len) {
            $this->pos++; // skip closing "
        }
        return new SqlToken(SqlToken::IDENT, '"' . $name . '"', strtoupper($name), $line);
    }

    private function scanNumber(int $line): SqlToken
    {
        $s = $this->pos;
        while ($this->pos < $this->len
            && (ctype_digit($this->src[$this->pos]) || $this->src[$this->pos] === '.')
        ) {
            $this->pos++;
        }
        $val = substr($this->src, $s, $this->pos - $s);
        return new SqlToken(SqlToken::NUMBER, $val, $val, $line);
    }

    private function scanIdentOrKeyword(int $line): SqlToken
    {
        // Read base word
        $s = $this->pos;
        while ($this->pos < $this->len
            && (ctype_alnum($this->src[$this->pos]) || $this->src[$this->pos] === '_')
        ) {
            $this->pos++;
        }
        $word  = substr($this->src, $s, $this->pos - $s);
        $upper = strtoupper($word);

        // Try to extend into a multi-word keyword (e.g. GROUP → GROUP BY)
        $multi = $this->tryExtendMulti($upper);
        if ($multi !== null) {
            return new SqlToken(SqlToken::KEYWORD, $multi, $multi, $line);
        }

        // Try qualified name: word.word  (e.g. alias.column or table.column)
        if ($this->pos < $this->len && $this->src[$this->pos] === '.') {
            $savedPos  = $this->pos;
            $savedLine = $this->line;
            $this->pos++; // consume '.'
            $this->skipWs(); // allow (unusual) whitespace before second ident
            if ($this->pos < $this->len
                && (ctype_alpha($this->src[$this->pos]) || $this->src[$this->pos] === '_')
            ) {
                $s2 = $this->pos;
                while ($this->pos < $this->len
                    && (ctype_alnum($this->src[$this->pos]) || $this->src[$this->pos] === '_')
                ) {
                    $this->pos++;
                }
                $word2    = substr($this->src, $s2, $this->pos - $s2);
                $qualified = $word . '.' . $word2;
                return new SqlToken(
                    SqlToken::QUALIFIED,
                    $qualified,
                    strtoupper($qualified),
                    $line
                );
            }
            // Dot not followed by identifier — back up
            $this->pos  = $savedPos;
            $this->line = $savedLine;
        }

        // Single-word keyword?
        if (in_array($upper, self::KW_SET, true)) {
            return new SqlToken(SqlToken::KEYWORD, $word, $upper, $line);
        }

        return new SqlToken(SqlToken::IDENT, $word, $upper, $line);
    }

    /**
     * Attempt to extend $firstUpper into a multi-word keyword.
     * Returns the combined keyword string on success, null on failure.
     * Resets position on failure so caller can continue normally.
     */
    private function tryExtendMulti(string $firstUpper): ?string
    {
        if (!isset(self::MULTI[$firstUpper])) {
            return null;
        }

        $savedPos  = $this->pos;
        $savedLine = $this->line;

        foreach (self::MULTI[$firstUpper] as $seq) {
            $this->pos  = $savedPos;
            $this->line = $savedLine;
            $combined   = $firstUpper;
            $ok         = true;

            foreach ($seq as $needed) {
                $this->skipWs();
                if ($this->pos >= $this->len) {
                    $ok = false;
                    break;
                }
                // Peek at next word without advancing permanently
                $p = $this->pos;
                while ($p < $this->len
                    && (ctype_alnum($this->src[$p]) || $this->src[$p] === '_')
                ) {
                    $p++;
                }
                $got = strtoupper(substr($this->src, $this->pos, $p - $this->pos));
                if ($got !== $needed) {
                    $ok = false;
                    break;
                }
                $combined  .= ' ' . $needed;
                $this->pos  = $p;
            }

            if ($ok && in_array($combined, self::KW_SET, true)) {
                return $combined;
            }
        }

        // No match — restore position
        $this->pos  = $savedPos;
        $this->line = $savedLine;
        return null;
    }

    private function scanOperator(int $line): SqlToken
    {
        static $opChars = ['=', '<', '>', '!', '|', '+', '-',
                           '*', '/', '%', '~', '^', '&', '#', '@'];
        $s = $this->pos;
        while ($this->pos < $this->len && in_array($this->src[$this->pos], $opChars, true)) {
            $this->pos++;
        }
        $op = substr($this->src, $s, $this->pos - $s);
        return new SqlToken(SqlToken::OPERATOR, $op, $op, $line);
    }
}

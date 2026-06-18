<?php

declare(strict_types=1);

/**
 * SQL Integrity — Tree / Scope Builder
 *
 * Takes a token stream produced by SqlTokenizer and builds a FunctionAnalysis
 * containing:
 *   - aliases[]       qualifier (lower) → table_name (lower)
 *                     Extracted from depth-0 FROM / JOIN clauses only.
 *                     Depth-0 aliases are in scope for the entire statement.
 *   - cteNames[]      Set of CTE names defined by WITH.
 *                     Qualified refs whose left-hand side is a CTE name
 *                     are suppressed (column source is unknown).
 *   - qualifiedRefs[] Every  alias.column  or  table.column  token seen
 *                     anywhere in the function body (any depth).
 *
 * Depth guard
 * ───────────
 * Every '(' increments depth. If depth would exceed $maxDepth, a
 * ParserDepthLimitException is thrown. The CALLER is responsible for
 * catching it, emitting a NOTICE, and skipping column-checking for
 * that function. This prevents infinite loops from parser bugs.
 *
 * A ')' that would take depth below zero also throws immediately.
 *
 * Alias scoping model (v1 — deliberately conservative)
 * ─────────────────────────────────────────────────────
 * Aliases are extracted ONLY from depth-0 FROM/JOIN clauses.
 * Subquery-local aliases (depth > 0) are ignored.
 *
 * When validating a qualified ref, if the qualifier is not in the
 * depth-0 alias map, we SKIP it (rather than guess).
 * This means:
 *   ✅  outer-alias refs at any depth  (json_build_object('k', u.col))
 *   ✅  direct table-name refs         (users.email_verified)
 *   ✅  CTE refs suppressed correctly  (filtered.col → skip)
 *   ✅  subquery-local refs skipped    (sub.col → skip — no false positive)
 *   ❌  subquery-column checks         (defer to v2 with full scope stack)
 */

require_once __DIR__ . '/sql-integrity-tokenizer.php';

// ── Data structures ───────────────────────────────────────────────────────────

class SqlQualifiedRef
{
    public function __construct(
        public readonly string $qualifier, // lower-case — the left side of alias.column
        public readonly string $column,    // lower-case — the right side
        public readonly int    $line,
        public readonly string $clause,    // what clause keyword we were in (SELECT, WHERE, …)
    ) {}
}

class FunctionAnalysis
{
    /** @var array<string,string>  lower(qualifier) → lower(table_name) */
    public array $aliases = [];

    /** @var string[]  lower-case CTE names; refs whose qualifier is in here are suppressed */
    public array $cteNames = [];

    /** @var SqlQualifiedRef[] */
    public array $qualifiedRefs = [];
}

// ── Tree builder ──────────────────────────────────────────────────────────────

class SqlTreeBuilder
{
    // Keywords that introduce a FROM-like source → extract alias at depth 0
    private const FROM_KWS = [
        'FROM', 'JOIN',
        'INNER JOIN', 'LEFT JOIN', 'LEFT OUTER JOIN',
        'RIGHT JOIN', 'RIGHT OUTER JOIN',
        'FULL JOIN', 'FULL OUTER JOIN', 'CROSS JOIN',
        'UPDATE',        // UPDATE table SET …
        'DELETE FROM',
    ];

    // Keywords after which we skip content — dynamic SQL or DDL, no column refs
    private const SKIP_KWS = ['EXECUTE', 'RAISE', 'DECLARE'];

    public function __construct(private readonly int $maxDepth = 5) {}

    /**
     * Analyse one function body string.
     *
     * @throws ParserDepthLimitException  if nesting exceeds $maxDepth
     *                                    OR a ')' makes depth go negative
     */
    public function analyse(string $body, int $startLine, string $fnName = ''): FunctionAnalysis
    {
        $tokenizer = new SqlTokenizer();
        $tokens    = $tokenizer->tokenize($body);

        $analysis = new FunctionAnalysis();

        // Split into individual SQL statements at ';' (respecting paren depth),
        // then process each statement.
        foreach ($this->splitStatements($tokens) as $stmt) {
            if (empty($stmt)) {
                continue;
            }
            $this->processStatement($stmt, $analysis, $fnName);
        }

        return $analysis;
    }

    // ── Statement splitter ────────────────────────────────────────────────────

    /**
     * Split the token stream into per-statement slices at ';' tokens
     * that appear at depth 0.
     *
     * @param  SqlToken[] $tokens
     * @return SqlToken[][]
     */
    private function splitStatements(array $tokens): array
    {
        $stmts   = [];
        $current = [];
        $depth   = 0;

        foreach ($tokens as $tok) {
            if ($tok->type === SqlToken::PAREN_OPEN) {
                $depth++;
                $current[] = $tok;
            } elseif ($tok->type === SqlToken::PAREN_CLOSE) {
                $depth = max(0, $depth - 1); // depth guard here is lenient; real guard is in processTokens
                $current[] = $tok;
            } elseif ($tok->type === SqlToken::SEMICOLON && $depth === 0) {
                if (!empty($current)) {
                    $stmts[]  = $current;
                    $current  = [];
                }
            } else {
                $current[] = $tok;
            }
        }

        if (!empty($current)) {
            $stmts[] = $current;
        }

        return $stmts;
    }

    // ── Statement processor ───────────────────────────────────────────────────

    /**
     * Process one statement's tokens, populating $analysis.
     * This is the core of the builder — a single left-to-right scan
     * with a depth counter and a small alias-extraction state machine.
     *
     * @param SqlToken[] $tokens
     * @throws ParserDepthLimitException
     */
    private function processStatement(array $tokens, FunctionAnalysis $analysis, string $fnName): void
    {
        // Pre-scan: collect CTE names defined by WITH so we can suppress them
        $this->collectCteNames($tokens, $analysis);

        $n     = count($tokens);
        $depth = 0;

        // Current clause keyword context
        $clause    = 'UNKNOWN';
        $inSkip    = false; // inside EXECUTE / RAISE / DECLARE — skip refs
        $inDeclare = false; // inside DECLARE block before BEGIN

        // Alias-extraction state machine
        // After seeing a FROM/JOIN keyword at depth 0:
        //   state EXPECT_TABLE → next IDENT or QUALIFIED is a table name
        //   state HAVE_TABLE   → next IDENT (non-keyword) is an alias
        //   state EXPECT_ALIAS → saw AS keyword, next IDENT is definitely an alias
        $aliasState   = 'NONE';   // NONE | EXPECT_TABLE | HAVE_TABLE | EXPECT_ALIAS
        $pendingTable = '';        // table name waiting for its alias

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            // ── Depth tracking (the guard lives here) ─────────────────────────
            if ($tok->type === SqlToken::PAREN_OPEN) {
                $newDepth = $depth + 1;
                if ($newDepth > $this->maxDepth) {
                    throw new ParserDepthLimitException(
                        "Opening '(' would reach nesting depth $newDepth"
                        . " — MAX_NESTING_DEPTH={$this->maxDepth}"
                        . ($fnName !== '' ? " in function $fnName" : ''),
                        $newDepth,
                        $tok->line,  // parserLine
                        $fnName,
                    );
                }
                $depth++;

                // Entering a subquery / function call — reset alias state
                // (we never extract aliases from inside parens)
                $aliasState   = 'NONE';
                $pendingTable = '';
                continue;
            }

            if ($tok->type === SqlToken::PAREN_CLOSE) {
                if ($depth === 0) {
                    throw new ParserDepthLimitException(
                        "Unbalanced ')' at line {$tok->line} — depth went negative"
                        . ($fnName !== '' ? " in function $fnName" : ''),
                        -1,
                        $tok->line,  // parserLine
                        $fnName,
                    );
                }
                $depth--;

                // When returning to depth 0, alias state was already cleared on '('
                continue;
            }

            // ── Keyword dispatch ──────────────────────────────────────────────
            if ($tok->type === SqlToken::KEYWORD) {
                $kw = $tok->upper;

                // Block-ending tokens — no new clause, reset alias machine
                if (in_array($kw, ['END', 'END IF', 'END LOOP', 'END CASE'], true)) {
                    $aliasState   = 'NONE';
                    $pendingTable = '';
                    continue;
                }

                // BEGIN ends DECLARE block
                if ($kw === 'BEGIN') {
                    $inDeclare    = false;
                    $inSkip       = false;
                    $aliasState   = 'NONE';
                    $pendingTable = '';
                    $clause       = 'BEGIN';
                    continue;
                }

                // DECLARE — skip variable declarations until BEGIN
                if ($kw === 'DECLARE') {
                    $inDeclare  = true;
                    $inSkip     = false;
                    $aliasState = 'NONE';
                    $clause     = 'DECLARE';
                    continue;
                }

                // EXECUTE / RAISE — skip dynamic SQL content
                if (in_array($kw, self::SKIP_KWS, true)) {
                    $inSkip     = true;
                    $aliasState = 'NONE';
                    $clause     = $kw;
                    continue;
                }

                // Any other SQL keyword clears the skip flag
                // (we're past the dynamic content, a new clause started)
                if ($inSkip && !in_array($kw, ['THEN', 'ELSE', 'ELSIF'], true)) {
                    $inSkip = false;
                }

                // WITH — CTE names already collected in pre-scan; just set clause
                if ($kw === 'WITH') {
                    $clause     = 'WITH';
                    $aliasState = 'NONE';
                    continue;
                }

                // AS keyword — signals an alias is next
                if ($kw === 'AS') {
                    if ($aliasState === 'HAVE_TABLE') {
                        $aliasState = 'EXPECT_ALIAS';
                    }
                    continue;
                }

                // FROM / JOIN — start alias extraction (depth 0 only)
                if (in_array($kw, self::FROM_KWS, true) && $depth === 0) {
                    $clause       = $kw;
                    $aliasState   = 'EXPECT_TABLE';
                    $pendingTable = '';
                    continue;
                }

                // All other clause keywords — new clause, reset alias machine
                $clause       = $kw;
                $aliasState   = 'NONE';
                $pendingTable = '';
                continue;
            }

            // ── Skip EXECUTE/RAISE/DECLARE content ────────────────────────────
            if ($inSkip || $inDeclare) {
                continue;
            }

            // ── COMMA at depth 0 inside a FROM source list ────────────────────
            // e.g. FROM a, b   or  FROM a a1, b b1
            if ($tok->type === SqlToken::COMMA && $depth === 0) {
                if ($clause === 'FROM') {
                    // Another source follows
                    $aliasState   = 'EXPECT_TABLE';
                    $pendingTable = '';
                } else {
                    // Column list or value list — cancel alias state
                    $aliasState   = 'NONE';
                    $pendingTable = '';
                }
                continue;
            }

            // ── QUALIFIED token  (alias.column  or  table.column) ────────────
            if ($tok->type === SqlToken::QUALIFIED) {
                [$left, $right] = explode('.', strtolower($tok->value), 2);

                // In FROM/JOIN context at depth 0: this is schema.table, not alias.col
                // Treat right side as table name, left side as schema prefix (ignore)
                if ($aliasState === 'EXPECT_TABLE' && $depth === 0) {
                    $pendingTable = $right;          // e.g. public.users → users
                    $analysis->aliases[$right] = $right;
                    $aliasState = 'HAVE_TABLE';
                    continue;
                }

                // Column reference — collect for later validation
                $analysis->qualifiedRefs[] = new SqlQualifiedRef($left, $right, $tok->line, $clause);

                // After a qualified ref, alias state is cancelled (this token consumed the slot)
                $aliasState   = 'NONE';
                $pendingTable = '';
                continue;
            }

            // ── IDENT token ───────────────────────────────────────────────────
            if ($tok->type === SqlToken::IDENT) {
                $lower = strtolower($tok->value);

                // ── Alias state machine ───────────────────────────────────────
                if ($depth === 0) {
                    switch ($aliasState) {
                        case 'EXPECT_TABLE':
                            // This identifier is the table name
                            $pendingTable = $lower;
                            $analysis->aliases[$lower] = $lower; // direct table.col access
                            $aliasState = 'HAVE_TABLE';
                            continue 2; // continue outer for-loop

                        case 'HAVE_TABLE':
                            // Identifier immediately after table name (without AS) = alias
                            // But guard: ON / WHERE / SET / JOIN keywords would have been
                            // caught by the KEYWORD branch above. An IDENT here really is an alias.
                            $analysis->aliases[$lower]        = $pendingTable;
                            $analysis->aliases[$pendingTable] = $pendingTable;
                            $aliasState   = 'NONE';
                            $pendingTable = '';
                            continue 2;

                        case 'EXPECT_ALIAS':
                            // Saw AS keyword before this — definitely an alias
                            $analysis->aliases[$lower]        = $pendingTable;
                            $analysis->aliases[$pendingTable] = $pendingTable;
                            $aliasState   = 'NONE';
                            $pendingTable = '';
                            continue 2;
                    }
                }

                // Not in alias state — not a column ref — move on
                continue;
            }

            // ── Other tokens (CAST, NUMBER, OPERATOR, STRING, OTHER) ──────────
            // CAST and NUMBER can appear between table name and alias (e.g. rare but possible)
            // without cancelling alias state.  Everything else resets it.
            if (!in_array($tok->type, [SqlToken::CAST, SqlToken::NUMBER, SqlToken::OPERATOR], true)) {
                if ($aliasState !== 'NONE' && $tok->type !== SqlToken::OTHER) {
                    $aliasState   = 'NONE';
                    $pendingTable = '';
                }
            }
        }
    }

    // ── CTE name pre-scan ─────────────────────────────────────────────────────

    /**
     * First pass through a statement's tokens to harvest CTE names.
     * Looks for the pattern:  WITH  ident  AS  (
     *                           or  ,  ident  AS  (
     * at depth 0 (not inside a subquery).
     *
     * @param SqlToken[] $tokens
     */
    private function collectCteNames(array $tokens, FunctionAnalysis $analysis): void
    {
        $n              = count($tokens);
        $depth          = 0;
        $sawWith        = false;
        $expectCteName  = false;
        $sawCteNameIdent = false;
        $lastName       = '';

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            if ($tok->type === SqlToken::PAREN_OPEN)  { $depth++; continue; }
            if ($tok->type === SqlToken::PAREN_CLOSE) { $depth = max(0, $depth - 1); continue; }

            if ($depth > 0) {
                continue; // inside CTE body or subquery — skip
            }

            if ($tok->isKeyword('WITH')) {
                $sawWith       = true;
                $expectCteName = true;
                continue;
            }

            if (!$sawWith) {
                continue;
            }

            // After WITH or , at depth 0 — next IDENT followed by AS is a CTE name
            if ($expectCteName && $tok->type === SqlToken::IDENT) {
                $lastName        = strtolower($tok->value);
                $sawCteNameIdent = true;
                continue;
            }

            if ($sawCteNameIdent && $tok->isKeyword('AS')) {
                // Confirmed: lastName is a CTE name
                if (!in_array($lastName, $analysis->cteNames, true)) {
                    $analysis->cteNames[] = $lastName;
                }
                $sawCteNameIdent = false;
                $expectCteName   = false;
                continue;
            }

            // Comma at depth 0 after WITH = another CTE follows
            if ($tok->type === SqlToken::COMMA) {
                $expectCteName   = true;
                $sawCteNameIdent = false;
                continue;
            }

            // Main SELECT/INSERT/etc. starts — done collecting CTE names
            if ($tok->isKeyword('SELECT', 'INSERT', 'UPDATE', 'DELETE')) {
                break;
            }

            $sawCteNameIdent = false;
        }
    }
}

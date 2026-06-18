<?php

declare(strict_types=1);

/**
 * SQL Integrity — Column Schema + Validator
 *
 * discoverTableColumns()   — parses every tables/*.pgsql file and extracts
 *                            the column names for each CREATE TABLE block.
 *
 * checkColumnIntegrity()   — runs SqlTreeBuilder on every function body,
 *                            then validates qualified refs (alias.col /
 *                            table.col) against the column schema.
 *                            Functions that exceed maxDepth are skipped with
 *                            a NOTICE rather than crashing the whole run.
 */

require_once __DIR__ . '/sql-integrity-tree.php';

// ── Table schema discovery ────────────────────────────────────────────────────

/**
 * Discover all CREATE TABLE definitions with their column names.
 *
 * @param  string[] $tableFiles  Pre-built list of table .pgsql files (schema-scoped by caller).
 * @return array<string, array{file:string, columns:array<string,true>}>
 *         table_lower_name → { file, columns }
 */
function discoverTableColumns(array $tableFiles): array
{
    $result = [];

    foreach ($tableFiles as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }

        // Find each CREATE TABLE block in the file
        if (!preg_match_all(
            '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z_][a-z0-9_]*)\s*\(/i',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            continue;
        }

        foreach ($matches[1] as $idx => $match) {
            $tableName = strtolower($match[0]);
            // $bodyStart = character position immediately after the opening '('
            $bodyStart = $matches[0][$idx][1] + strlen($matches[0][$idx][0]);

            $columns = extractColumnNames($content, $bodyStart);

            $result[$tableName] = [
                'file'    => $file,
                'columns' => array_fill_keys($columns, true),
            ];
        }
    }

    return $result;
}

/**
 * Extract column names from the body of CREATE TABLE (…).
 *
 * Strategy:
 *   1. Collect all text between the opening '(' (at $start) and the
 *      matching closing ')' at depth 0 — tracking inner parens for
 *      CHECK(…) and DEFAULT(…) expressions.
 *   2. Split that text by commas at depth 0 → one item per column/constraint.
 *   3. For each item, strip comments, take the first word; if it is NOT a
 *      SQL constraint keyword, it is a column name.
 *
 * @return string[]  lower-case column names
 */
function extractColumnNames(string $content, int $start): array
{
    $len   = strlen($content);
    $pos   = $start;
    $depth = 1; // we are already inside the opening '('
    $body  = '';

    // Collect the table body between the outermost parens
    while ($pos < $len && $depth > 0) {
        $c = $content[$pos];

        // String literals inside DEFAULT / CHECK — skip contents
        if ($c === "'") {
            $pos++;
            while ($pos < $len && $content[$pos] !== "'") {
                $pos++;
            }
            if ($pos < $len) {
                $pos++; // closing '
            }
            $body .= "''"; // placeholder so commas/parens inside are gone
            continue;
        }

        if ($c === '(') {
            $depth++;
            $body .= $c;
            $pos++;
            continue;
        }
        if ($c === ')') {
            $depth--;
            if ($depth === 0) {
                break; // end of CREATE TABLE body
            }
            $body .= $c;
            $pos++;
            continue;
        }

        $body .= $c;
        $pos++;
    }

    // Strip inline SQL comments BEFORE splitting — keep newlines so that
    // a comment on line N cannot eat an identifier on line N+1
    $body = preg_replace('/--[^\n]*/', '', (string) $body);

    // Split body by top-level commas (newlines are just whitespace here)
    $items = splitAtTopLevelCommas($body);

    // Constraint starters — lines beginning with these are not column definitions
    static $constraintKeywords = [
        'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK',
        'FOREIGN', 'EXCLUDE', 'LIKE', 'INDEX',
    ];

    $columns = [];

    foreach ($items as $item) {
        // Comments already stripped from the body; just trim whitespace here
        $item = trim((string) $item);

        if ($item === '') {
            continue;
        }

        // Quoted identifier  "column_name"
        if ($item[0] === '"' && preg_match('/^"([^"]+)"/', $item, $m)) {
            $columns[] = strtolower($m[1]);
            continue;
        }

        // Bare identifier — first word
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)/', $item, $m)) {
            $word = strtoupper($m[1]);
            if (!in_array($word, $constraintKeywords, true)) {
                $columns[] = strtolower($m[1]);
            }
        }
    }

    return $columns;
}

/**
 * Split $text by ',' characters that are at nesting depth 0.
 * Used to separate column/constraint items inside CREATE TABLE (…).
 *
 * @return string[]
 */
function splitAtTopLevelCommas(string $text): array
{
    $parts   = [];
    $depth   = 0;
    $current = '';
    $len     = strlen($text);

    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($c === '(') {
            $depth++;
            $current .= $c;
        } elseif ($c === ')') {
            $depth = max(0, $depth - 1);
            $current .= $c;
        } elseif ($c === ',' && $depth === 0) {
            $parts[]  = $current;
            $current  = '';
        } else {
            $current .= $c;
        }
    }

    if (trim($current) !== '') {
        $parts[] = $current;
    }

    return $parts;
}

// ── Column integrity checker ──────────────────────────────────────────────────

/**
 * Qualifier names that are known pseudo-tables or external-service tables.
 * Qualified refs like  excluded.email  or  new.status  are never flagged.
 */
function isSuppressedQualifier(string $qualifier): bool
{
    static $list = [
        // PostgreSQL DML pseudo-tables
        'excluded', 'new', 'old',
        // System schemas (should never appear as qualifiers in function bodies,
        // but guard just in case)
        'pg_catalog', 'information_schema', 'public',
    ];
    return in_array($qualifier, $list, true);
}

/**
 * Run column integrity check across all parsed function bodies.
 *
 * @param array<string, array{file:string, columns:array<string,true>}>  $tableSchema
 *        Output of discoverTableColumns().
 * @param array<string, array<array{name:string, body:string, body_start_line:int}>>  $fnBodiesByFile
 *        Map of file path → list of parsed function bodies.
 *        (Built from parseFunctionBodies() in validate-sqlintegrity.php.)
 * @param int   $maxDepth   Max nesting depth before the parser bails.
 *
 * @return array{
 *   errors:  array<array{file:string,fn:string,table:string,column:string,line:int,clause:string,message:string}>,
 *   notices: array<array{file:string,fn:string,line:int,msg:string}>
 * }
 */
function checkColumnIntegrity(
    array $tableSchema,
    array $fnBodiesByFile,
    int   $maxDepth = 5
): array {
    $errors  = [];
    $notices = [];
    $seen    = []; // dedup: file|fn|table|column → true (report first occurrence only)

    $builder = new SqlTreeBuilder($maxDepth);

    foreach ($fnBodiesByFile as $file => $fnBodies) {
        foreach ($fnBodies as $fn) {
            // ── Parse ────────────────────────────────────────────────────────
            try {
                $analysis = $builder->analyse($fn['body'], $fn['body_start_line'], $fn['name']);
            } catch (ParserDepthLimitException $e) {
                $notices[] = [
                    'file' => $file,
                    'fn'   => $fn['name'],
                    'line' => $e->parserLine,
                    'msg'  => "Parser skipped — {$e->getMessage()}. "
                            . "Column-checking disabled for this function (depth guard hit).",
                ];
                continue;
            }

            // Build CTE name lookup set
            $cteSet = array_fill_keys($analysis->cteNames, true);

            // ── Validate each qualified ref ───────────────────────────────────
            foreach ($analysis->qualifiedRefs as $ref) {
                $qual = $ref->qualifier;
                $col  = $ref->column;

                // 1. Suppress known pseudo-tables and CTEs
                if (isSuppressedQualifier($qual) || isset($cteSet[$qual])) {
                    continue;
                }

                // 2. Resolve qualifier → table name via depth-0 alias map
                $tableName = $analysis->aliases[$qual] ?? null;

                // 3. If unresolvable (subquery alias, external table, unknown)
                //    → skip rather than false-positive
                if ($tableName === null) {
                    continue;
                }

                // 4. If the table itself is not in our schema, the table-existence
                //    check in the main validator already reported it → don't double-report
                if (!isset($tableSchema[$tableName])) {
                    continue;
                }

                // 5. Column check
                if (isset($tableSchema[$tableName]['columns'][$col])) {
                    continue; // column exists — all good
                }

                // 6. Deduplicate: one report per (fn, table, column) pair
                $key = "{$fn['name']}|$tableName|$col";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $errors[] = [
                    'file'    => $file,
                    'fn'      => $fn['name'],
                    'table'   => $tableName,
                    'column'  => $col,
                    'line'    => $ref->line,
                    'clause'  => $ref->clause,
                    'message' => "Column '$col' does not exist on table '$tableName'."
                               . " (in {$ref->clause} clause)",
                ];
            }
        }
    }

    return ['errors' => $errors, 'notices' => $notices];
}

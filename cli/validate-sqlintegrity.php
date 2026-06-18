<?php

declare(strict_types=1);

/**
 * SQL Integrity Validator
 *
 * Statically analyses PostgreSQL function files and reports references to
 * tables or functions that have no corresponding definition file.
 *
 * This catches bugs like:
 *   - INSERT INTO tenant_memberships when tenant_memberships has no tables/ file
 *     (the gateway drops tables with no definition → runtime 500)
 *   - Calls to a helper function that was renamed/deleted
 *
 * Usage:
 *   php stone validate sqlintegrity
 *   php stone validate sqlintegrity --strict        # warnings become errors
 *   php stone validate sqlintegrity --json          # machine-readable output
 *
 * Exit codes: 0 = clean, 1 = errors found, 2 = warnings only (unless --strict)
 */

require_once __DIR__ . '/generate-common.php';   // provides detect_root_path()
require_once __DIR__ . '/helpers/color.php';
require_once __DIR__ . '/sql-integrity-columns.php'; // Phase 2: column checker (pulls in tokenizer + tree)

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', detect_root_path());
}

// ── CLI args ─────────────────────────────────────────────────────────────────

$argv          = $_SERVER['argv'] ?? [];
$strict        = in_array('--strict', $argv, true);
$json          = in_array('--json', $argv, true);
$noColumnCheck = in_array('--no-column-check', $argv, true);

$maxDepth = 5;
foreach ($argv as $arg) {
    if (preg_match('/^--max-depth=(\d+)$/', $arg, $m)) {
        $maxDepth = max(1, (int) $m[1]);
        break;
    }
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php stone validate sqlintegrity [options]\n\n";
    echo "Options:\n";
    echo "  --strict             Treat warnings (unknown function calls) as errors\n";
    echo "  --json               Machine-readable JSON output\n";
    echo "  --no-column-check    Skip Phase 2 column-name integrity check\n";
    echo "  --max-depth=N        Max paren nesting depth for column parser (default: 5)\n\n";
    echo "Phase 1 — Table existence:\n";
    echo "  Scans src/postgresql/ for table + function definitions, then checks\n";
    echo "  every function body for references to tables or functions with no\n";
    echo "  matching definition file. Critical check:\n\n";
    echo "    INSERT INTO <table>  when <table> has no tables/*.pgsql file\n";
    echo "    → gateway schema-sync will DROP the table → 500 at runtime\n\n";
    echo "Phase 2 — Column name integrity:\n";
    echo "  Parses table definitions for column names, then checks qualified refs\n";
    echo "  (alias.column / table.column) inside function bodies to detect typos\n";
    echo "  like  users.is_email_verified  when the column is  email_verified.\n\n";
    echo "  Uses a tokenizer + keyword-segmented tree with depth tracking.\n";
    echo "  Functions that exceed --max-depth have column checking skipped\n";
    echo "  (emitted as NOTICE, not an error).\n\n";
    exit(0);
}

$srcPath = ROOT_PATH . 'src';
if (!is_dir($srcPath)) {
    echo Color::red("Error: src/ directory not found at " . ROOT_PATH) . "\n";
    echo "Run this command from the API project root (where composer.json lives).\n";
    exit(1);
}

// ── Step 1: Discover all table definitions ───────────────────────────────────

/**
 * @return array<string, string>  table_name => file_path
 */
function discoverTables(string $srcPath): array
{
    $tables = [];
    $tableFiles = findFiles($srcPath, 'tables');

    foreach ($tableFiles as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }
        // Match: CREATE TABLE [IF NOT EXISTS] table_name (
        if (preg_match_all(
            '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z_][a-z0-9_]*)\s*[(\s]/i',
            $content,
            $matches
        )) {
            foreach ($matches[1] as $name) {
                $tables[strtolower($name)] = $file;
            }
        }
    }

    return $tables;
}

// ── Step 2: Discover all function definitions ─────────────────────────────────

/**
 * @return array<string, string>  fn_name => file_path
 */
function discoverFunctions(string $srcPath): array
{
    $functions = [];
    $fnFiles = findFiles($srcPath, 'functions');

    foreach ($fnFiles as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }
        // Match: CREATE [OR REPLACE] FUNCTION fn_name(
        if (preg_match_all(
            '/\bCREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+([a-z_][a-z0-9_]*)\s*\(/i',
            $content,
            $matches
        )) {
            foreach ($matches[1] as $name) {
                $functions[strtolower($name)] = $file;
            }
        }
    }

    return $functions;
}

// ── Step 3: Extract references from a function body ──────────────────────────

/**
 * @return array{tables: array<array{name:string,line:int}>, functions: array<array{name:string,line:int}>}
 */
function extractReferences(string $body, int $bodyStartLine): array
{
    $tableRefs = [];
    $fnRefs    = [];
    $lines     = explode("\n", $body);

    // First pass: collect CTE names so we don't flag them as missing tables
    $cteNames = [];
    if (preg_match_all('/\bWITH\s+([a-z_][a-z0-9_]*)\s+AS\s*\(/i', $body, $m)) {
        foreach ($m[1] as $cte) {
            $cteNames[strtolower($cte)] = true;
        }
    }
    // Comma CTEs:  , name AS (
    if (preg_match_all('/,\s*([a-z_][a-z0-9_]*)\s+AS\s*\(/i', $body, $m)) {
        foreach ($m[1] as $cte) {
            $cteNames[strtolower($cte)] = true;
        }
    }

    foreach ($lines as $i => $line) {
        $lineNo = $bodyStartLine + $i;
        $trimmed = ltrim($line);

        // Skip pure comment lines
        if (str_starts_with($trimmed, '--')) {
            continue;
        }

        // Strip inline comments (-- ...) and single-quoted string literals
        // before applying regex — prevents false positives from comment text
        // and string content containing word( patterns.
        $cleanLine = preg_replace("/--[^\n]*/", '', $line);          // strip -- comment
        $cleanLine = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $cleanLine); // strip 'string'
        $line = $cleanLine;   // use cleaned line for all regex below

        // Table references: FROM table, JOIN table, INSERT INTO table, UPDATE table SET
        $tablePatterns = [
            '/\bFROM\s+([a-z_][a-z0-9_]*)\b/i',
            '/\bJOIN\s+([a-z_][a-z0-9_]*)\b/i',
            '/\bINSERT\s+INTO\s+([a-z_][a-z0-9_]*)\b/i',
            '/\bUPDATE\s+([a-z_][a-z0-9_]*)\s+SET\b/i',
            // TRUNCATE (we also want to know about these)
            '/\bTRUNCATE\s+(?:TABLE\s+)?([a-z_][a-z0-9_]*)\b/i',
        ];

        // Track table names found on THIS line — these are not function calls
        $lineTableNames = [];

        foreach ($tablePatterns as $pattern) {
            if (preg_match_all($pattern, $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $lower = strtolower($name);
                    // Skip: system tables, CTEs, and PG built-in functions used as
                    // set-returning functions in FROM/JOIN (e.g. FROM jsonb_array_elements(...))
                    if (!isset($cteNames[$lower]) && !isPgSystemTable($lower) && !isPgBuiltinFunction($lower) && !isSqlKeyword($lower)) {
                        $tableRefs[] = ['name' => $lower, 'line' => $lineNo];
                    }
                    $lineTableNames[$lower] = true;  // always record to suppress fn-call false positive
                }
            }
        }

        // INTO varname FROM table  (PL/pgSQL SELECT ... INTO var FROM table)
        // already covered by FROM pattern above

        // Function calls: fn_name( — but not SQL keywords, pg built-ins, or table names on this line
        if (preg_match_all('/\b([a-z_][a-z0-9_]+)\s*\(/i', $line, $matches)) {
            foreach ($matches[1] as $name) {
                $lower = strtolower($name);
                // Skip if: pg built-in, SQL keyword, or already identified as a table ref on this line
                if (!isPgBuiltinFunction($lower) && !isSqlKeyword($lower) && !isset($lineTableNames[$lower])) {
                    $fnRefs[] = ['name' => $lower, 'line' => $lineNo];
                }
            }
        }
    }

    return ['tables' => $tableRefs, 'functions' => $fnRefs];
}

// ── Step 4: Parse function bodies from a file ─────────────────────────────────

/**
 * Returns all function bodies found in a .pgsql file.
 * Each entry: ['name' => string, 'body' => string, 'body_start_line' => int]
 *
 * @return array<array{name:string,body:string,body_start_line:int}>
 */
function parseFunctionBodies(string $file): array
{
    $content = file_get_contents($file);
    if ($content === false) {
        return [];
    }

    $results = [];
    $lines   = explode("\n", $content);

    // We need to locate:  CREATE [OR REPLACE] FUNCTION name(...) ... AS $$ ... $$ LANGUAGE
    // Dollar-quoting can use $$ or $tag$...$tag$
    // Strategy: find each CREATE [OR REPLACE] FUNCTION header, then find body delimiters

    $i = 0;
    $totalLines = count($lines);

    while ($i < $totalLines) {
        $line = $lines[$i];

        // Look for function header
        if (preg_match('/\bCREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+([a-z_][a-z0-9_]*)\s*\(/i', $line, $m)) {
            $fnName = strtolower($m[1]);
            $headerLine = $i;

            // Scan forward to find the AS $$ (or AS $tag$)
            $bodyDelimiter  = null;
            $bodyStartLine  = null;
            $bodyLines      = [];
            $inBody         = false;
            $j              = $i;

            while ($j < $totalLines) {
                $l = $lines[$j];

                if (!$inBody) {
                    // Look for AS $$ or AS $tag$
                    if (preg_match('/\bAS\s+(\$[a-z_]*\$)/i', $l, $dm)) {
                        $bodyDelimiter = $dm[1];
                        // Body may start on the same line after the delimiter
                        $pos = strpos($l, $bodyDelimiter);
                        $rest = substr($l, $pos + strlen($bodyDelimiter));

                        // Check if closing delimiter is on the same line (inline body — unusual)
                        if (str_contains($rest, $bodyDelimiter)) {
                            // Entire body on one line — extract between delimiters
                            $inner = substr($rest, 0, strpos($rest, $bodyDelimiter));
                            $bodyLines = [$inner];
                            $bodyStartLine = $j + 1;
                            $j++;
                            $inBody = false;
                            break;
                        }

                        // Body continues on next lines
                        if (trim($rest) !== '') {
                            $bodyLines[] = $rest;
                        }
                        $bodyStartLine = $j + 1;
                        $inBody = true;
                        $j++;
                        continue;
                    }
                } else {
                    // Inside body — look for closing delimiter
                    if (str_contains($l, $bodyDelimiter)) {
                        // End of body (take content before the closing delimiter)
                        $pos = strpos($l, $bodyDelimiter);
                        $bodyLines[] = substr($l, 0, $pos);
                        $j++;
                        break;
                    }
                    $bodyLines[] = $l;
                }
                $j++;
            }

            if ($bodyStartLine !== null && !empty($bodyLines)) {
                $results[] = [
                    'name'            => $fnName,
                    'body'            => implode("\n", $bodyLines),
                    'body_start_line' => $bodyStartLine,
                ];
            }

            $i = $j; // continue after this function
        } else {
            $i++;
        }
    }

    return $results;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Recursively find all .pgsql files inside directories named $dirName.
 *
 * @return string[]
 */
function findFiles(string $srcPath, string $dirName): array
{
    $result = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        // Only .pgsql files inside a directory named $dirName
        $parts = explode(DIRECTORY_SEPARATOR, $file->getPath());
        if (!in_array($dirName, $parts, true)) {
            continue;
        }
        // Exclude migration files — they're one-off scripts, not live definitions
        if (in_array('migrations', $parts, true)) {
            continue;
        }
        if ($file->getExtension() === 'pgsql') {
            $result[] = $file->getPathname();
        }
    }

    return $result;
}

/**
 * PostgreSQL system tables / views that are always available — not user-defined.
 */
function isPgSystemTable(string $name): bool
{
    static $systemTables = null;
    if ($systemTables === null) {
        $systemTables = [
            // pg_catalog views
            'pg_stat_activity', 'pg_tables', 'pg_indexes', 'pg_class',
            'pg_attribute', 'pg_namespace', 'pg_type', 'pg_constraint',
            'pg_proc', 'pg_database', 'pg_stat_user_tables', 'pg_locks',
            'pg_sequences', 'pg_views', 'pg_matviews', 'pg_trigger',
            'pg_inherits', 'pg_enum',
            // information_schema
            'information_schema',
        ];
        // Also treat anything starting with pg_ as system
    }
    if (str_starts_with($name, 'pg_') || str_starts_with($name, 'information_schema')) {
        return true;
    }
    return in_array($name, $systemTables, true);
}

/**
 * PostgreSQL built-in functions — these are never in user's functions/ dir.
 */
function isPgBuiltinFunction(string $name): bool
{
    static $builtins = null;
    if ($builtins === null) {
        $builtins = [
            // Aggregate
            'count', 'sum', 'avg', 'min', 'max', 'array_agg', 'string_agg',
            'json_agg', 'jsonb_agg', 'json_object_agg', 'jsonb_object_agg',
            'bool_and', 'bool_or', 'bit_and', 'bit_or',
            // JSON builders
            'json_build_object', 'jsonb_build_object', 'json_build_array',
            'jsonb_build_array', 'json_object', 'jsonb_object',
            'to_json', 'to_jsonb', 'row_to_json',
            // String
            'concat', 'concat_ws', 'length', 'lower', 'upper', 'trim', 'ltrim', 'rtrim',
            'substring', 'replace', 'split_part', 'regexp_replace',
            'regexp_match', 'regexp_matches', 'format', 'quote_ident',
            'quote_literal', 'initcap', 'lpad', 'rpad', 'repeat', 'reverse',
            'left', 'right', 'position', 'strpos', 'chr', 'ascii',
            // Numeric
            'round', 'ceil', 'floor', 'abs', 'mod', 'power', 'sqrt',
            'random', 'trunc', 'sign', 'greatest', 'least',
            // Date/time
            'now', 'current_timestamp', 'current_date', 'current_time',
            'date_trunc', 'date_part', 'extract', 'make_date', 'make_time',
            'make_timestamp', 'age', 'timezone', 'to_timestamp', 'to_char',
            'to_date', 'clock_timestamp', 'statement_timestamp',
            'date', 'interval',
            // Type conversion / cast helpers
            'cast', 'coalesce', 'nullif', 'ifnull', 'isnull',
            'pg_typeof', 'oid',
            // Array
            'array_length', 'array_upper', 'array_lower', 'unnest',
            'array_to_string', 'string_to_array', 'array_append',
            'array_prepend', 'array_cat', 'array_position',
            // Regex / pattern
            'regexp_split_to_table', 'regexp_split_to_array',
            // UUID
            'gen_random_uuid', 'uuid_generate_v4',
            // Hash / crypto
            'md5', 'sha256', 'encode', 'decode', 'crypt', 'gen_salt',
            // Conditional
            'case', 'when', 'then', 'else', 'end',
            // Window
            'row_number', 'rank', 'dense_rank', 'lag', 'lead',
            'first_value', 'last_value', 'nth_value', 'ntile', 'percent_rank',
            // System info
            'pg_database_size', 'pg_relation_size', 'pg_total_relation_size',
            'pg_size_pretty', 'pg_postmaster_start_time', 'pg_sleep',
            'current_database', 'current_schema', 'current_user', 'session_user',
            // Interval constructors
            'make_interval', 'make_timestamptz',
            // Array / JSONB lengths
            'jsonb_array_length', 'json_array_length', 'array_length',
            'cardinality', 'jsonb_object_keys', 'json_object_keys',
            // Misc
            'nullif', 'row', 'exists', 'any', 'all', 'some',
            'generate_series', 'generate_subscripts', 'xmax',
            'json_each', 'json_each_text', 'jsonb_each', 'jsonb_each_text',
            'json_array_elements', 'jsonb_array_elements',
            'json_populate_record', 'jsonb_populate_record',
            'json_to_record', 'jsonb_to_record',
            'json_extract_path', 'jsonb_extract_path',
            'json_extract_path_text', 'jsonb_extract_path_text',
            'setval', 'nextval', 'currval', 'lastval',
            'txid_current',
        ];
    }
    return in_array($name, $builtins, true);
}

/**
 * SQL / PL/pgSQL keywords that look like function calls but aren't.
 */
function isSqlKeyword(string $name): bool
{
    static $keywords = null;
    if ($keywords === null) {
        $keywords = [
            // PL/pgSQL control
            'if', 'elsif', 'else', 'end', 'loop', 'while', 'for', 'foreach',
            'begin', 'declare', 'return', 'raise', 'exception', 'perform',
            'execute', 'get', 'found', 'rowtype', 'type', 'record',
            // SQL clauses
            'select', 'insert', 'update', 'delete', 'from', 'where',
            'join', 'inner', 'outer', 'left', 'right', 'full', 'cross',
            'on', 'using', 'group', 'order', 'having', 'limit', 'offset',
            'union', 'intersect', 'except', 'distinct', 'all',
            'into', 'values', 'set', 'returning', 'with', 'as',
            'not', 'and', 'or', 'in', 'between', 'like', 'ilike',
            'is', 'null', 'true', 'false', 'case', 'when', 'then',
            // ON CONFLICT / UPSERT keywords
            'conflict', 'do', 'nothing', 'excluded',
            // Window function keywords
            'over', 'partition', 'filter', 'window', 'rows', 'range',
            'preceding', 'following', 'current', 'unbounded',
            // LATERAL joins
            'lateral',
            // Function definition keywords (appear before '(' in some contexts)
            'language', 'plpgsql', 'sql', 'volatile', 'stable', 'immutable',
            'security', 'definer', 'invoker', 'strict', 'called', 'parallel',
            'safe', 'unsafe', 'restricted', 'returns', 'setof', 'void',
            // Type names (can appear like function calls in casts)
            'integer', 'bigint', 'text', 'boolean', 'numeric', 'decimal',
            'varchar', 'char', 'uuid', 'json', 'jsonb', 'timestamptz',
            'timestamp', 'date', 'time', 'interval', 'bytea', 'oid',
            'smallint', 'real', 'float', 'double',
        ];
    }
    return in_array($name, $keywords, true);
}

/**
 * Shorten an absolute path to relative from ROOT_PATH for cleaner output.
 */
function relativePath(string $path): string
{
    $root = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
        return substr($path, strlen($root));
    }
    return $path;
}

// ── Main ──────────────────────────────────────────────────────────────────────

if (!$json) {
    echo Color::blue("SQL Integrity Check") . "\n";
    echo "Scanning: " . relativePath($srcPath) . "\n\n";
}

$knownTables    = discoverTables($srcPath);
$knownFunctions = discoverFunctions($srcPath);

// Phase 2: column schema (parsed columns from tables/*.pgsql)
$tableSchema = $noColumnCheck ? [] : discoverTableColumns($srcPath);

if (!$json) {
    echo "  Tables defined:    " . count($knownTables) . "\n";
    echo "  Functions defined: " . count($knownFunctions) . "\n";
    if (!$noColumnCheck) {
        $colCount = array_sum(array_map(fn($t) => count($t['columns']), $tableSchema));
        echo "  Columns indexed:   $colCount across " . count($tableSchema) . " tables\n";
    }
    echo "\n";
}

// Find all function files to analyse
$allFunctionFiles = findFiles($srcPath, 'functions');

$errors   = [];  // table not in definitions → gateway will drop it
$warnings = [];  // called function not in inventory

// Phase 2 input: collect all parsed function bodies keyed by file
// (built during the Phase 1 loop so we only parse files once)
$fnBodiesByFile = [];

foreach ($allFunctionFiles as $file) {
    $fnBodies = parseFunctionBodies($file);
    $fnBodiesByFile[$file] = $fnBodies;

    foreach ($fnBodies as $fn) {
        $refs = extractReferences($fn['body'], $fn['body_start_line']);

        // Check table references
        foreach ($refs['tables'] as $ref) {
            $tbl = $ref['name'];
            if (!isset($knownTables[$tbl])) {
                $errors[] = [
                    'type'    => 'missing_table',
                    'file'    => $file,
                    'fn'      => $fn['name'],
                    'ref'     => $tbl,
                    'line'    => $ref['line'],
                    'message' => "Table '$tbl' is not defined in any tables/*.pgsql file.\n"
                               . "             The gateway will DROP this table on schema-sync → 500 at runtime.",
                ];
            }
        }

        // Check function calls (warnings unless --strict)
        foreach ($refs['functions'] as $ref) {
            $callee = $ref['name'];
            if (!isset($knownFunctions[$callee])) {
                $warnings[] = [
                    'type'    => 'missing_function',
                    'file'    => $file,
                    'fn'      => $fn['name'],
                    'ref'     => $callee,
                    'line'    => $ref['line'],
                    'message' => "Function '$callee' is called but has no definition in any functions/*.pgsql file.",
                ];
            }
        }
    }
}

// ── Phase 2: Column name integrity ───────────────────────────────────────────

$columnErrors  = [];
$columnNotices = [];

if (!$noColumnCheck && !empty($tableSchema)) {
    $colResult    = checkColumnIntegrity($tableSchema, $fnBodiesByFile, $maxDepth);
    $columnErrors = $colResult['errors'];
    $columnNotices = $colResult['notices'];
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($json) {
    echo json_encode([
        'tables_defined'    => count($knownTables),
        'functions_defined' => count($knownFunctions),
        'errors'            => array_map(fn($e) => [
            'type'    => $e['type'],
            'file'    => relativePath($e['file']),
            'fn'      => $e['fn'],
            'ref'     => $e['ref'],
            'line'    => $e['line'],
        ], $errors),
        'warnings'          => array_map(fn($w) => [
            'type'    => $w['type'],
            'file'    => relativePath($w['file']),
            'fn'      => $w['fn'],
            'ref'     => $w['ref'],
            'line'    => $w['line'],
        ], $warnings),
        'column_errors'     => array_map(fn($c) => [
            'type'    => 'bad_column',
            'file'    => relativePath($c['file']),
            'fn'      => $c['fn'],
            'table'   => $c['table'],
            'column'  => $c['column'],
            'line'    => $c['line'],
            'clause'  => $c['clause'],
        ], $columnErrors),
        'notices'           => array_map(fn($n) => [
            'file'    => relativePath($n['file']),
            'fn'      => $n['fn'],
            'line'    => $n['line'],
            'msg'     => $n['msg'],
        ], $columnNotices),
    ], JSON_PRETTY_PRINT) . "\n";
    $hasFailure = !empty($errors) || !empty($columnErrors) || ($strict && !empty($warnings));
    exit($hasFailure ? 1 : 0);
}

// Human-readable output

// Phase 1: Table existence errors
if (!empty($errors)) {
    echo Color::red("ERRORS [Phase 1] — missing table definitions (will cause runtime 500s):") . "\n\n";
    foreach ($errors as $e) {
        echo Color::red("  ERROR") . " in " . Color::yellow($e['fn'] . "()") . "\n";
        echo "        " . $e['message'] . "\n";
        echo "        → " . relativePath($e['file']) . ":" . $e['line'] . "\n\n";
    }
}

// Phase 1: Missing function warnings
if (!empty($warnings)) {
    $label = $strict ? Color::red("ERRORS [Phase 1, --strict]") : Color::yellow("WARNINGS [Phase 1]");
    echo $label . " — called functions not found in definitions:\n\n";
    foreach ($warnings as $w) {
        $tag = $strict ? Color::red("  ERROR") : Color::yellow("  WARN ");
        echo "$tag in " . Color::yellow($w['fn'] . "()") . "\n";
        echo "        " . $w['message'] . "\n";
        echo "        → " . relativePath($w['file']) . ":" . $w['line'] . "\n\n";
    }
}

// Phase 2: Column name errors
if (!empty($columnErrors)) {
    echo Color::red("ERRORS [Phase 2] — column name mismatches:") . "\n\n";
    foreach ($columnErrors as $c) {
        echo Color::red("  ERROR") . " in " . Color::yellow($c['fn'] . "()") . "\n";
        echo "        " . $c['message'] . "\n";
        echo "        → " . relativePath($c['file']) . ":" . $c['line'] . "\n\n";
    }
}

// Notices: functions the parser skipped (depth limit hit)
if (!empty($columnNotices)) {
    echo Color::yellow("NOTICES [Phase 2] — parser skipped (depth limit):") . "\n\n";
    foreach ($columnNotices as $n) {
        echo Color::yellow("  NOTICE") . " " . $n['fn'] . "()\n";
        echo "         " . $n['msg'] . "\n";
        echo "         → " . relativePath($n['file']) . ":" . $n['line'] . "\n\n";
    }
    echo Color::yellow("Tip:") . " Use --max-depth=N (current: $maxDepth) to analyse deeper functions,\n";
    echo "     or --no-column-check to skip Phase 2 entirely.\n\n";
}

// Summary
$errCount    = count($errors);
$warnCount   = count($warnings);
$colErrCount = count($columnErrors);
$noticeCount = count($columnNotices);

$totalErrors = $errCount + $colErrCount + ($strict ? $warnCount : 0);

if ($totalErrors === 0 && $warnCount === 0) {
    echo Color::green("✓ All clear.") . " "
        . count($knownTables) . " tables, "
        . count($knownFunctions) . " functions, "
        . count($allFunctionFiles) . " function files checked — no issues found.";
    if ($noticeCount > 0) {
        echo " ($noticeCount function(s) skipped by depth guard)";
    }
    echo "\n";
    exit(0);
}

$summary = [];
if ($errCount > 0)    $summary[] = Color::red("$errCount table-existence error(s)");
if ($colErrCount > 0) $summary[] = Color::red("$colErrCount column-name error(s)");
if ($warnCount > 0)   $summary[] = ($strict ? Color::red("$warnCount warning(s) [--strict]") : Color::yellow("$warnCount warning(s)"));
if ($noticeCount > 0) $summary[] = Color::yellow("$noticeCount notice(s) (parser skipped)");

echo implode(', ', $summary) . " found across "
    . count($allFunctionFiles) . " function file(s).\n";

if (!$strict && $errCount === 0 && $colErrCount === 0 && $warnCount > 0) {
    echo Color::yellow("Tip:") . " Use --strict to treat function-call warnings as errors in CI.\n";
}

$hasFailure = $errCount > 0 || $colErrCount > 0 || ($strict && $warnCount > 0);
exit($hasFailure ? 1 : 2);

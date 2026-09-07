<?php

/**
 * Model Generator
 *
 * Generates PHP model classes from PostgreSQL function definitions.
 *
 * Usage:
 *   php generate model <filename.pssql>
 *
 * Example:
 *   php generate model get_user.pssql
 */

require_once __DIR__ . '/generate-common.php';

// Use $_SERVER values if set by stone script, otherwise use global $argc/$argv
$argc = $_SERVER['argc'] ?? $argc;
$argv = $_SERVER['argv'] ?? $argv;

// Check for help flag
if ($argc === 1 || ($argc === 2 && in_array($argv[1], ['--help', '-h', 'help']))) {
    echo "Model Generator\n";
    echo "===============\n\n";
    echo "Usage: php generate model <filename>\n\n";
    echo "Arguments:\n";
    echo "  filename    PostgreSQL function file (searches functions/, tenant/postgresql/functions/, main/postgresql/functions/)\n";
    echo "              Extension is optional (.pgsql, .pssql, .sql supported)\n\n";
    echo "Examples:\n";
    echo "  php generate model get_user.pgsql\n";
    echo "  php generate model get_user         # Auto-detects extension\n";
    echo "  php generate model get_howtos.pssql\n";
    exit(0);
}

if ($argc !== 2) {
    echo "Error: Invalid number of arguments (got $argc, expected 2)\n";
    echo "Arguments received: " . implode(', ', $argv) . "\n\n";
    echo "Usage: php generate model <filename>\n";
    echo "Run 'php generate model --help' for more information.\n";
    exit(1);
}

// Get the base filename (without path separators for security)
$filename = str_replace('..', '.', $argv[1]);

// Search directories in priority order (supports gateway-compatible directory structure)
$search_dirs = [
    ROOT_PATH . 'src' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'functions' . DIRECTORY_SEPARATOR,
    ROOT_PATH . 'src' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'functions' . DIRECTORY_SEPARATOR,
    ROOT_PATH . 'src' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'functions' . DIRECTORY_SEPARATOR,
];

// Filter to only existing directories
$search_dirs = array_filter($search_dirs, 'is_dir');

// Check if user provided extension
if (preg_match('/\.(pgsql|pssql|sql)$/i', $filename)) {
    // User specified extension - search all directories
    $src_filepath = null;
    foreach ($search_dirs as $dir) {
        $test_path = $dir . $filename;
        if (file_exists($test_path)) {
            $src_filepath = $test_path;
            break;
        }
    }

    if ($src_filepath === null) {
        echo "Error: PostgreSQL function file not found\n\n";
        echo "Searched in:\n";
        foreach ($search_dirs as $dir) {
            echo "  - {$dir}{$filename}\n";
        }
        echo "\nPlease ensure the file exists with the exact name you specified.\n";
        exit(1);
    }
} else {
    // No extension provided - try to auto-detect across all directories
    $found_files = [];
    foreach ($search_dirs as $dir) {
        foreach (['pgsql', 'pssql', 'sql'] as $ext) {
            $test_path = $dir . $filename . '.' . $ext;
            if (file_exists($test_path)) {
                $found_files[] = $test_path;
            }
        }
    }

    if (count($found_files) === 0) {
        echo "Error: PostgreSQL function file not found\n\n";
        echo "Searched in:\n";
        foreach ($search_dirs as $dir) {
            foreach (['pgsql', 'pssql', 'sql'] as $ext) {
                echo "  - {$dir}{$filename}.{$ext}\n";
            }
        }
        echo "\nPlease ensure the file exists in one of the postgresql/functions/ directories.\n";
        echo "Or specify the exact filename with extension.\n";
        exit(1);
    }

    if (count($found_files) > 1) {
        echo "Error: Multiple files found with the same base name\n\n";
        echo "Found:\n";
        foreach ($found_files as $file) {
            echo "  - " . str_replace(ROOT_PATH, '', $file) . "\n";
        }
        echo "\nPlease specify the exact filename with extension to avoid ambiguity.\n";
        echo "Example: php stone generate model {$filename}.pgsql\n";
        exit(1);
    }

    $src_filepath = $found_files[0];
}

$content = file_get_contents($src_filepath);

// Strip SQL comments (-- style) from the beginning of the file
// This allows functions to have documentation comments
$content = preg_replace('/^(--.*\n)+/', '', $content);
$content = trim($content);

// echo $content . PHP_EOL;

// ============================================================
// STEP-BY-STEP PARSER (replaces single regex)
// Handles nested parentheses in types like VARCHAR(255),
// NUMERIC(15,2) etc. that break simple regex approaches.
// ============================================================

/**
 * Given a string and a position of an opening '(', walk forward
 * counting paren depth until the matching ')' is found.
 * Returns the position of the matching ')'.
 */
function find_matching_paren(string $str, int $open_pos): int
{
    $depth = 0;
    $len = strlen($str);
    for ($i = $open_pos; $i < $len; $i++) {
        if ($str[$i] === '(') {
            $depth++;
        } elseif ($str[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return -1; // unbalanced
}

// Pass 1: Find function name
$fn_match = preg_match('/create\s+or\s+replace\s+function\s+([a-z0-9_]+)/is', $content, $fn_parts);
if (!$fn_match) {
    echo "Error: Could not find CREATE OR REPLACE FUNCTION in file\n";
    die(0);
}
$parsed_fn_name = $fn_parts[1];
$after_fn_name_pos = $fn_parts[0][strlen($fn_parts[0]) - 1] === $parsed_fn_name[strlen($parsed_fn_name) - 1]
    ? strpos($content, $fn_parts[0]) + strlen($fn_parts[0])
    : 0;

// Pass 2: Find the opening '(' for input params
$input_open_pos = strpos($content, '(', $after_fn_name_pos);
if ($input_open_pos === false) {
    echo "Error: Could not find opening '(' for function parameters\n";
    die(0);
}

// Walk parens to find the matching ')' for input params
$input_close_pos = find_matching_paren($content, $input_open_pos);
if ($input_close_pos === -1) {
    echo "Error: Unbalanced parentheses in function parameters\n";
    die(0);
}

// Extract input params string (between the parens, exclusive)
$parsed_input_params = substr($content, $input_open_pos + 1, $input_close_pos - $input_open_pos - 1);

// ============================================================
// NULLABILITY-DRIFT GUARD (see cli/generate-model.php header
// docblock-equivalent below / GenerateModelTypedParamsCommandTest).
//
// A `-- comment` sitting INSIDE the parameter parens corrupts
// split_parameters()'s per-parameter text (the comment text glues onto the
// following parameter's name/type/DEFAULT tokens), which can silently turn a
// DEFAULT-bearing (nullable) SQL parameter into a garbage-typed or
// wrong-named PHP property -- exactly the "generator silently emits the
// wrong nullability" bug class this guard exists to make impossible. This is
// not hypothetical: a real production .pgsql function in the fleet has an
// explicit comment recording that its author discovered this the hard way
// and had to relocate an explanatory comment OUTSIDE the parameter list to
// avoid corrupting the model generator.
//
// Rather than attempt to cleverly distinguish a real inline comment from a
// `--` appearing inside a quoted DEFAULT string literal (fragile, and wrong
// in exactly the cases that matter most), we FAIL LOUD and tell the author to
// move the comment. That is always safe advice and never silently produces a
// wrong DTO.
if (preg_match('/--/', $parsed_input_params)) {
    fwrite(STDERR, "Error: SQL parameter list for function '{$parsed_fn_name}' contains an inline '--' comment.\n");
    fwrite(STDERR, "Inline comments INSIDE CREATE FUNCTION(...) corrupt the model generator's parameter\n");
    fwrite(STDERR, "parser and can silently emit the WRONG PHP nullability/type/name for the parameters\n");
    fwrite(STDERR, "around the comment -- refusing to generate a possibly-wrong DTO.\n\n");
    fwrite(STDERR, "Fix: move the comment BEFORE the CREATE FUNCTION line, or AFTER the closing ')'\n");
    fwrite(STDERR, "of the parameter list (e.g. between the params and RETURNS/RETURNS TABLE), then\n");
    fwrite(STDERR, "re-run 'php stone generate model'.\n");
    exit(1);
}

// Pass 3: Check for RETURNS TABLE in the text after input params
$after_input_params = substr($content, $input_close_pos + 1);
$parsed_returns_table_columns = '';

$returns_table_match = preg_match('/returns\s+table\s*\(/is', $after_input_params, $rt_parts, PREG_OFFSET_CAPTURE);
if ($returns_table_match) {
    // Found RETURNS TABLE — now find its opening '(' and walk to matching ')'
    $rt_keyword_pos_in_remainder = $rt_parts[0][1];
    $rt_text = $rt_parts[0][0]; // e.g. "RETURNS TABLE("
    $rt_open_paren_pos = $rt_keyword_pos_in_remainder + strlen($rt_text) - 1; // position of '('
    $rt_close_paren_pos = find_matching_paren($after_input_params, $rt_open_paren_pos);

    if ($rt_close_paren_pos === -1) {
        echo "Error: Unbalanced parentheses in RETURNS TABLE\n";
        die(0);
    }

    // Extract the columns between the parens (exclusive)
    $parsed_returns_table_columns = substr(
        $after_input_params,
        $rt_open_paren_pos + 1,
        $rt_close_paren_pos - $rt_open_paren_pos - 1
    );
}

// Build a matches-like structure for compatibility with existing code
$matches = [
    0 => '',                         // full match (unused)
    1 => '',                         // CREATE OR REPLACE FUNCTION (unused)
    2 => $parsed_fn_name,            // function name
    3 => '',                         // opening paren (unused)
    4 => $parsed_input_params,       // input params string
    5 => '',                         // closing paren (unused)
    6 => $parsed_returns_table_columns, // returns table columns (just the inner content, no wrapper)
];

/**
 * Split parameter string by commas, respecting parenthesized groups (e.g., numeric(15,2))
 *
 * @param string $str Parameter string to split
 * @return array Array of parameter strings
 */
function split_parameters(string $str): array
{
    $params = [];
    $current_param = '';
    $paren_depth = 0;

    for ($i = 0; $i < strlen($str); $i++) {
        $char = $str[$i];

        if ($char === '(') {
            $paren_depth++;
            $current_param .= $char;
        } elseif ($char === ')') {
            $paren_depth--;
            $current_param .= $char;
        } elseif ($char === ',' && $paren_depth === 0) {
            // This comma is a parameter separator
            if (trim($current_param) !== '') {
                $params[] = trim($current_param);
            }
            $current_param = '';
            // Skip the space after comma if present
            if ($i + 1 < strlen($str) && $str[$i + 1] === ' ') {
                $i++;
            }
        } else {
            $current_param .= $char;
        }
    }

    // Don't forget the last parameter
    if (trim($current_param) !== '') {
        $params[] = trim($current_param);
    }

    return $params;
}

/**
 * Renders the PHP type for a NULLABLE property. Every type in $type_map gets
 * a `?` prefix (e.g. `?int`) EXCEPT `mixed`, which is a real PHP fatal error
 * ("Type mixed cannot be marked as nullable since mixed already includes
 * null") -- `mixed` is implicitly nullable already. This was a genuine,
 * previously-undetected codegen bug: any DEFAULT'd `json`/`jsonb` parameter
 * (a common, everyday pattern -- e.g. an items/metadata payload) produced a
 * `?mixed` property and made the ENTIRE generated file a PHP fatal error,
 * caught here by actually running the schema-contract proof against a real
 * function with a DEFAULT'd jsonb parameter.
 */
function nullable_php_type(string $type): string
{
    return $type === 'mixed' ? 'mixed' : '?' . $type;
}

/**
 * A parsed SQL parameter name MUST be a plain identifier. If it isn't, some
 * upstream parsing step (comment corruption, an unhandled SQL construct,
 * a future regex regression, etc.) has silently produced garbage -- which is
 * exactly how a nullable/DEFAULT param could get mis-typed without anyone
 * noticing until a caller hits a TypeError in production. Fail loud instead.
 */
function assert_safe_param_identifier(string $name, string $raw_line, string $fn_name): void
{
    if ($name === '' || !preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
        fwrite(STDERR, "Error: could not safely parse a parameter name for function '{$fn_name}'.\n");
        fwrite(STDERR, "Parsed name: " . var_export($name, true) . "\n");
        fwrite(STDERR, "From SQL fragment: " . trim($raw_line) . "\n\n");
        fwrite(STDERR, "Refusing to generate a DTO from an unparseable parameter -- a bad name\n");
        fwrite(STDERR, "here means the type/DEFAULT/nullability for this parameter (and possibly\n");
        fwrite(STDERR, "its neighbours) cannot be trusted either. Fix the .pgsql function signature\n");
        fwrite(STDERR, "and re-run 'php stone generate model'.\n");
        exit(1);
    }
}

function get_input_params(string $str, array $type_map, string $fn_name): array
{
    $params_str = strtolower(trim(preg_replace('#[\s]+#', ' ', $str)));
    $lines = split_parameters($params_str);
    $typed_input_params = [];
    $input_params = [];
    foreach ($lines as $line) {
        $trimmed_line = trim($line);
        if (empty($trimmed_line)) {
            continue;
        }
        if (str_starts_with($trimmed_line, 'out ')) {
            continue;
        }

        // Remove DEFAULT clause before parsing (e.g., "p_name text default 'value'" -> "p_name text")
        $trimmed_line = preg_replace('/\s+default\s+.*$/i', '', $trimmed_line);

        $parts = explode(' ', trim($trimmed_line));
        $name = preg_replace('#^i_#', '', $parts[0]);
        assert_safe_param_identifier($name, $line, $fn_name);
        $raw_type = preg_replace('/\(.*\)/', '', $parts[1] ?? ''); // Strip precision e.g. numeric(15,2) -> numeric
        $type = $type_map[$raw_type] ?? 'mixed';
        $typed_input_params[] = "$type $$name";
        $input_params[] = "$$name";
    }

    return [$typed_input_params, $input_params];
}

/**
 * Ordered per-parameter specs for the generated typed params object
 * (`{Class}Params`, see the FnXxx::run()/Database::fnTyped() typed-boundary
 * work) -- name, PHP type, and whether the SQL parameter declared a DEFAULT
 * (=> nullable property with a `= null` default in PHP, since we have no way
 * to reproduce an arbitrary SQL default expression as a PHP literal; a
 * DEFAULT'd SQL param is, by definition, optional to the caller).
 *
 * DECLARATION ORDER IS LOAD-BEARING: Database::fnTyped() reflects the params
 * object's public properties in declaration order to build the positional
 * wire call, so this must walk the SQL parameter list in the exact order
 * PostgreSQL declared it.
 *
 * @return array<int, array{name: string, type: string, nullable: bool}>
 */
function get_input_param_specs(string $str, array $type_map, string $fn_name): array
{
    $params_str = trim(preg_replace('#[\s]+#', ' ', $str));
    $lines = split_parameters($params_str);
    $specs = [];

    foreach ($lines as $line) {
        $trimmed_line = trim($line);
        if (empty($trimmed_line)) {
            continue;
        }
        if (stripos($trimmed_line, 'out ') === 0) {
            continue;
        }

        $has_default = preg_match('/\s+default\s+/i', $trimmed_line) === 1;
        $clean_line = strtolower(preg_replace('/\s+default\s+.*$/i', '', $trimmed_line));

        $parts = explode(' ', trim($clean_line));
        $name = preg_replace('#^i_#', '', $parts[0]);
        assert_safe_param_identifier($name, $line, $fn_name);
        $raw_type = preg_replace('/\(.*\)/', '', $parts[1] ?? '');
        $type = $type_map[$raw_type] ?? 'mixed';

        $specs[] = ['name' => $name, 'type' => $type, 'nullable' => $has_default];
    }

    return $specs;
}

/**
 * NULLABILITY-DRIFT GUARD, layer 2: an independent re-derivation of "does
 * this SQL parameter have a DEFAULT" using a DIFFERENT regex strategy
 * (`\bdefault\b` word-boundary match on the untouched per-parameter text)
 * than the one get_input_param_specs() uses (`\s+default\s+` on a
 * whitespace-collapsed, DEFAULT-clause-stripped line). Two independently
 * written detectors agreeing is much stronger evidence than one detector
 * agreeing with itself -- if either implementation regresses in the future
 * (e.g. someone "simplifies" one of them and breaks a corner case), this
 * cross-check catches the disagreement and refuses to silently emit the
 * wrong PHP nullability, instead of shipping a DTO that only fails at
 * runtime, in production, on the first legitimately-null caller.
 *
 * @param array<int, array{name: string, type: string, nullable: bool}> $specs
 */
function assert_nullability_double_checked(string $raw_params_str, array $specs, string $fn_name): void
{
    $params_str = trim(preg_replace('#[\s]+#', ' ', $raw_params_str));
    $lines = array_values(array_filter(
        split_parameters($params_str),
        fn (string $line) => trim($line) !== '' && stripos(trim($line), 'out ') !== 0
    ));

    if (count($lines) !== count($specs)) {
        // A count mismatch here means the two passes disagree about how many
        // parameters even exist -- almost certainly parser corruption. Don't
        // try to line them up positionally and guess; fail loud instead.
        fwrite(STDERR, "Error: internal parameter-count mismatch while double-checking nullability for function '{$fn_name}'.\n");
        fwrite(STDERR, "Pass 1 found " . count($specs) . " parameter(s), pass 2 found " . count($lines) . ".\n");
        fwrite(STDERR, "Refusing to generate a possibly-wrong DTO -- please file this as a stonescriptphp bug\n");
        fwrite(STDERR, "with the offending .pgsql function attached.\n");
        exit(1);
    }

    foreach ($specs as $i => $spec) {
        $independent_has_default = preg_match('/\bdefault\b/i', $lines[$i]) === 1;
        if ($independent_has_default !== $spec['nullable']) {
            fwrite(STDERR, "Error: nullability-drift guard tripped for function '{$fn_name}', parameter '{$spec['name']}'.\n");
            fwrite(STDERR, "Primary detector says nullable=" . ($spec['nullable'] ? 'true' : 'false')
                . ", independent cross-check says nullable=" . ($independent_has_default ? 'true' : 'false') . ".\n");
            fwrite(STDERR, "SQL fragment: " . trim($lines[$i]) . "\n\n");
            fwrite(STDERR, "This is the exact bug class that made every legitimate-null call to a real\n");
            fwrite(STDERR, "production function fail with a PHP TypeError before the database was ever\n");
            fwrite(STDERR, "reached. Refusing to generate a DTO whose nullability the generator itself\n");
            fwrite(STDERR, "cannot agree on -- fix the .pgsql signature or file a stonescriptphp bug.\n");
            exit(1);
        }
    }
}

/**
 * NULLABILITY-DRIFT GUARD, layer 3 (last line of defense): after building the
 * literal PHP source lines for the `{Class}Params` class, verify each
 * property line actually emitted matches what its spec says it should be --
 * `public ?Type $name = null;` for a nullable/DEFAULT'd param, `public Type
 * $name;` (no `?`, no `= null`) for a required one. This guards the EMISSION
 * step independently of the DETECTION steps above: a future edit to the
 * `$lines[] = ...` block that drops the `?`/`= null` (a typo, a refactor,
 * a bad merge) would otherwise silently produce a wrong-but-plausible-looking
 * DTO. This function is the generator refusing to trust its own output.
 *
 * @param array<int, array{name: string, type: string, nullable: bool}> $specs
 */
function assert_emitted_params_match_specs(array $lines, array $specs, string $fn_name): void
{
    $body = implode("\n", $lines);
    foreach ($specs as $spec) {
        $name = $spec['name'];
        $type = $spec['type'];
        if ($spec['nullable']) {
            $expected = "public " . nullable_php_type($type) . " \${$name} = null;";
        } else {
            $expected = "public {$type} \${$name};";
        }
        if (!str_contains($body, $expected)) {
            fwrite(STDERR, "Error: emission self-check failed for function '{$fn_name}', parameter '{$name}'.\n");
            fwrite(STDERR, "Expected the generated Params class to contain exactly:\n");
            fwrite(STDERR, "    {$expected}\n");
            fwrite(STDERR, "but it did not. Refusing to write a DTO the generator cannot verify against its\n");
            fwrite(STDERR, "own nullability computation -- this indicates a bug in the code-emission step\n");
            fwrite(STDERR, "of cli/generate-model.php itself. Please file this as a stonescriptphp bug.\n");
            exit(1);
        }
    }
}

function get_output_params(string $input_str, string $returns_columns_str, array $type_map): array
{
    $input_str_clean = strtolower(trim(preg_replace('#[\s]+#', ' ', $input_str)));
    // returns_columns_str is already just the inner content (no RETURNS TABLE(...) wrapper)
    $returns_columns_clean = strtolower(trim(preg_replace('#[\s]+#', ' ', $returns_columns_str)));
    $output_params = [];
    $is_return_table = false;

    if (!empty($returns_columns_clean)) {
        $is_return_table = true;
        $lines = split_parameters($returns_columns_clean);
        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            $parts = explode(' ', $trimmed_line);
            $name = preg_replace('#^o_#', '', $parts[0]);
            $raw_type = preg_replace('/\(.*\)/', '', $parts[1] ?? '');
            $type = $type_map[$raw_type] ?? 'mixed';
            $output_params[$name] = $type;
        }
    } else {
        $is_return_table = false;
        $lines = split_parameters($input_str_clean);
        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            if (!str_starts_with($trimmed_line, 'out ')) {
                continue;
            }
            $parts = explode(' ', $trimmed_line);
            $name = preg_replace('#^o_#', '', $parts[1]);
            $raw_type = preg_replace('/\(.*\)/', '', $parts[2] ?? '');
            $type = $type_map[$raw_type] ?? 'mixed';
            $output_params[$name] = $type;
        }
    }

    return [$output_params, $is_return_table];
}

$type_map = [
    'integer' => 'int',
    'int' => 'int',
    'bigint' => 'int',
    'smallint' => 'int',
    'serial' => 'int',
    'text' => 'string',
    'varchar' => 'string',
    'char' => 'string',
    'uuid' => 'string',
    'json' => 'mixed',
    'jsonb' => 'mixed',
    'boolean' => 'bool',
    'bool' => 'bool',
    'timestamptz' => 'string',
    'timestamp' => 'string',
    'date' => 'string',
    'time' => 'string',
    'numeric' => 'float',
    'decimal' => 'float',
    'real' => 'float',
    'float' => 'float',
    'double' => 'float',
];

list($typed_input_params, $input_params) = get_input_params($matches[4], $type_map, $parsed_fn_name);
$input_param_specs = get_input_param_specs($matches[4], $type_map, $parsed_fn_name);
assert_nullability_double_checked($matches[4], $input_param_specs, $parsed_fn_name);
list($output_params, $is_return_table) = get_output_params($matches[4], $matches[6], $type_map);

$sql_fn_name = strtolower($matches[2]);
$class_name = implode('', array_map(fn ($item) => ucfirst($item), explode('_', $sql_fn_name)));
$model_class_name = $class_name . 'Model';
$params_class_name = $class_name . 'Params';
$fn_class_name = 'Fn' . $class_name;
$has_params = count($input_param_specs) > 0;

$lines = [];
$lines[] = '<?php';
$lines[] = '';
$lines[] = 'namespace App\Database\Functions;';
$lines[] = '';
$lines[] = 'use StoneScriptPHP\Database;';
if ($is_return_table) {
    $lines[] = 'use StoneScriptPHP\Binding\TypedArray;';
}
$lines[] = '';
$lines[] = "class $model_class_name";
$lines[] = '{';
foreach ($output_params as $name => $type) {
    $lines[] = "   public $type $$name;";
}
$lines[] = '}';
$lines[] = '';

// Typed params-object input (the Database typed boundary's IN side, see
// Database::fnTyped()'s docblock): one public property per SQL argument, IN
// DECLARATION ORDER (Database::fnTyped() reflects declaration order to build
// the positional wire call -- this order is load-bearing, not cosmetic). A
// SQL parameter with a DEFAULT becomes a nullable property defaulted to
// null in PHP -- we cannot reproduce an arbitrary SQL default expression as
// a PHP literal, and a DEFAULT'd SQL param is, by definition, optional to
// the caller, so `null` is the correct "caller didn't set this" sentinel.
// No params class is emitted for a zero-argument function -- run() just
// takes no arguments in that case.
if ($has_params) {
    $lines[] = "class $params_class_name";
    $lines[] = '{';
    foreach ($input_param_specs as $spec) {
        $prop_type = $spec['nullable'] ? nullable_php_type($spec['type']) : $spec['type'];
        $default = $spec['nullable'] ? ' = null' : '';
        $lines[] = "    public $prop_type \${$spec['name']}$default;";
    }
    $lines[] = '}';
    $lines[] = '';

    // Layer 3 guard: verify what we just emitted actually matches what the
    // detectors computed, BEFORE this becomes the params class body used by
    // the emission self-check below (and before anything is written to disk).
    assert_emitted_params_match_specs($lines, $input_param_specs, $parsed_fn_name);
}

$lines[] = "class $fn_class_name";
$lines[] = '{';

$run_arg = $has_params ? "$params_class_name \$params" : '';

$lines[] = '    /**';
$lines[] = '     * @return ' . ($is_return_table ? "TypedArray<$model_class_name>" : $model_class_name);
$lines[] = '     */';
$lines[] = "    public static function run($run_arg): " . ($is_return_table ? 'TypedArray' : $model_class_name);
$lines[] = '    {';
$lines[] = '        $function_name = ' . "'" . $sql_fn_name . "'" . ';';
if ($has_params) {
    $lines[] = '        $rows = Database::fnTyped($function_name, $params);';
} else {
    $lines[] = '        $rows = Database::fn($function_name, []);';
}
$lines[] = '        return Database::' . ($is_return_table ? 'result_as_typed_table' : 'result_as_object') . '($function_name, $rows, ' . $model_class_name . '::class);';
$lines[] = '    }';
$lines[] = '}';

$dst_filepath = SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . $fn_class_name . '.php';

// Create directory if it doesn't exist
$dst_dir = dirname($dst_filepath);
if (!is_dir($dst_dir)) {
    mkdir($dst_dir, 0755, true);
}

$status = file_put_contents($dst_filepath, join("\n", $lines));
if ($status === false) {
    echo 'error writing to file ' . $dst_filepath . PHP_EOL;
    die(0);
}

echo 'created file ' . $dst_filepath . PHP_EOL;

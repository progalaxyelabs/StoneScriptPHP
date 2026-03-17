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

function get_input_params(string $str, array $type_map): array
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
        $raw_type = preg_replace('/\(.*\)/', '', $parts[1] ?? ''); // Strip precision e.g. numeric(15,2) -> numeric
        $type = $type_map[$raw_type] ?? 'mixed';
        $typed_input_params[] = "$type $$name";
        $input_params[] = "$$name";
    }

    return [$typed_input_params, $input_params];
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

list($typed_input_params, $input_params) = get_input_params($matches[4], $type_map);
list($output_params, $is_return_table) = get_output_params($matches[4], $matches[6], $type_map);

$sql_fn_name = strtolower($matches[2]);
$class_name = implode('', array_map(fn ($item) => ucfirst($item), explode('_', $sql_fn_name)));
$model_class_name = $class_name . 'Model';
$fn_class_name = 'Fn' . $class_name;

$lines = [];
$lines[] = '<?php';
$lines[] = '';
$lines[] = 'namespace App\Database\Functions;';
$lines[] = '';
$lines[] = 'use StoneScriptPHP\Database;';
$lines[] = '';
$lines[] = "class $model_class_name";
$lines[] = '{';
foreach ($output_params as $name => $type) {
    $lines[] = "   public $type $$name;";
}
$lines[] = '}';
$lines[] = '';
$lines[] = "class $fn_class_name";
$lines[] = '{';

$typed_input_params_str = join(', ', $typed_input_params);
$input_params_str = join(', ', $input_params);

$lines[] = '    /**';
$lines[] = '     * @return ' . $model_class_name . ($is_return_table ? '[]' : '');
$lines[] = '     */';
$lines[] = "    public static function run($typed_input_params_str): " . ($is_return_table ? 'array' : $model_class_name);
$lines[] = '    {';
$lines[] = '        $function_name = ' . "'" . $sql_fn_name . "'" . ';';
$lines[] = '        $rows = Database::fn($function_name, [' . $input_params_str . ']);';
$lines[] = '        return Database::' . ($is_return_table ? 'result_as_table' : 'result_as_object') . '($function_name, $rows, ' . $model_class_name . '::class);';
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

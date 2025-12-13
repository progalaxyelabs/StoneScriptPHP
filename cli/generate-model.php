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
    echo "  filename    PostgreSQL function file in src/postgresql/functions/\n";
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
$functions_dir = ROOT_PATH . 'src' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'functions' . DIRECTORY_SEPARATOR;

// Check if user provided extension
if (preg_match('/\.(pgsql|pssql|sql)$/i', $filename)) {
    // User specified extension - use exactly what they provided
    $src_filepath = $functions_dir . $filename;

    if (!file_exists($src_filepath)) {
        echo "Error: PostgreSQL function file not found\n\n";
        echo "Looked for: $src_filepath\n";
        echo "\nPlease ensure the file exists with the exact name you specified.\n";
        exit(1);
    }
} else {
    // No extension provided - try to auto-detect
    $found_files = [];
    foreach (['pgsql', 'pssql', 'sql'] as $ext) {
        $test_path = $functions_dir . $filename . '.' . $ext;
        if (file_exists($test_path)) {
            $found_files[] = $test_path;
        }
    }

    if (count($found_files) === 0) {
        echo "Error: PostgreSQL function file not found\n\n";
        echo "Searched for:\n";
        echo "  - {$functions_dir}{$filename}.pgsql\n";
        echo "  - {$functions_dir}{$filename}.pssql\n";
        echo "  - {$functions_dir}{$filename}.sql\n";
        echo "\nPlease ensure the file exists in src/postgresql/functions/\n";
        echo "Or specify the exact filename with extension.\n";
        exit(1);
    }

    if (count($found_files) > 1) {
        echo "Error: Multiple files found with the same base name\n\n";
        echo "Found:\n";
        foreach ($found_files as $file) {
            echo "  - " . basename($file) . "\n";
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

// Updated regex to handle:
// - Multi-line parameters (newlines)
// - DEFAULT values in parameters (e.g., "DEFAULT 'IN'")
// - More flexible matching for parameter types and values
$regex = '#^(create\s+or\s+replace\s+function\s+)([a-z0-9_]+)(\s*\()(.*?)(\)\s*)(returns\s+table\s*\(.*?\))?\s*(.*)$#is';
$matches = [];
if (!preg_match($regex, $content, $matches)) {
    echo 'error parsing file' . PHP_EOL;
    die(0);
}

function get_input_params(string $str, array $type_map): array
{
    $params_str = strtolower(trim(preg_replace('#[\s]+#', ' ', $str)));
    $lines = explode(', ', $params_str);
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
        $type = $type_map[$parts[1]] ?? 'mixed';
        $typed_input_params[] = "$type $$name";
        $input_params[] = "$$name";
    }

    return [$typed_input_params, $input_params];
}

function get_output_params(string $input_str, string $returns_str, array $type_map): array
{
    $input_str_clean = strtolower(trim(preg_replace('#[\s]+#', ' ', $input_str)));
    $returns_str_clean = strtolower(trim(preg_replace('#[\s]+#', ' ', $returns_str)));
    $output_params = [];
    $is_return_table = false;

    if (!empty($returns_str_clean)) {
        $is_return_table = true;
        $params_str = rtrim(preg_replace('#^returns table[\s]*\(#', '', $returns_str_clean), ')');
        $lines = explode(', ', $params_str);
        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            $parts = explode(' ', $trimmed_line);
            $name = preg_replace('#^o_#', '', $parts[0]);
            $type = $type_map[$parts[1]];
            $output_params[$name] = $type;
        }
    } else {
        $is_return_table = false;
        $lines = explode(', ', $input_str_clean);
        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            if (!str_starts_with($trimmed_line, 'out ')) {
                continue;
            }
            $parts = explode(' ', $trimmed_line);
            $name = preg_replace('#^o_#', '', $parts[1]);
            $type = $type_map[$parts[2]];
            $output_params[$name] = $type;
        }
    }

    return [$output_params, $is_return_table];
}

$type_map = [
    'integer' => 'int',
    'int' => 'int',
    'text' => 'string',
    'boolean' => 'bool',
    'bool' => 'bool',
    'timestamptz' => 'string',
    'date' => 'string'
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
$lines[] = 'use Framework\Database;';
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

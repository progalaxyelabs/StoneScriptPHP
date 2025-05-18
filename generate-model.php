<?php

// php generate-model.php filename.pssql

if ($argc !== 2) {
    echo 'usage: php generate-model.php filename.pssql' . PHP_EOL;
    die(0);
}

$src_filepath = join(DIRECTORY_SEPARATOR, [
    __DIR__,
    'src',
    'postgresql',
    'functions',
    str_replace('..', '.', $argv[1])
]);


if (!file_exists($src_filepath)) {
    echo "file does not exist [$src_filepath]" . PHP_EOL;
    die(0);
}

$content = file_get_contents($src_filepath);
// echo $content . PHP_EOL;

$regex = '#^(create\s+or\s+replace\s+function\s+)([a-z0-9_]+)(\s*\()([a-z0-9_\s,]*)(\)\s*)(returns\s+table\s*\(\s*[a-z0-9_\s,]*\))?\s*(.*)$#is';
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
        $parts = explode(' ', $trimmed_line);
        $name = preg_replace('#^i_#', '', $parts[0]);
        $type = $type_map[$parts[1]];
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
$class_name = implode('', array_map(fn($item) => ucfirst($item), explode('_', $sql_fn_name)));
$model_class_name = $class_name . 'Model';
$fn_class_name = 'DbFn' . $class_name;

$typed_input_params_str = join(', ', $typed_input_params);
$input_params_str = join(', ', $input_params);

$output_properties_section = "";
if (!empty($output_params)) {
    $property_lines = [];
    foreach ($output_params as $name => $type) {
        $property_lines[] = "public $type $$name;";
    }
    $output_properties_section = join("\n    ", $property_lines);
}

$return_type_annotation = $model_class_name . ($is_return_table ? '[]' : '');
$actual_return_type = $is_return_table ? 'array' : $model_class_name;
$database_method = $is_return_table ? 'result_as_table' : 'result_as_object';

$generated_code = <<<PHP
<?php

namespace App\Database\Functions;

use Framework\Database;

class {$model_class_name}
{
    {$output_properties_section}
}

class {$fn_class_name}
{
    /**
     * @return {$return_type_annotation}
     */
    public static function run({$typed_input_params_str}): {$actual_return_type}
    {
        \$function_name = '{$sql_fn_name}';
        \$rows = Database::fn(\$function_name, [{$input_params_str}]);
        return Database::{$database_method}(\$function_name, \$rows, {$model_class_name}::class);
    }
}

// function db_fn_create_project({$typed_input_params_str}): {$actual_return_type}
// {
//     \$function_name = '{$sql_fn_name}';
//     \$rows = Database::fn(\$function_name, [{$input_params_str}]);
//     return Database::{$database_method}(\$function_name, \$rows, {$model_class_name}::class);
// }
PHP;

$database_folder = join(DIRECTORY_SEPARATOR, [
    __DIR__,
    'src',
    'App',
    'Database'
]);
$functions_folder = $database_folder . DIRECTORY_SEPARATOR . 'Functions';
$dst_filepath = $functions_folder . DIRECTORY_SEPARATOR . $fn_class_name . '.php';
if (!file_exists($database_folder)) {
    mkdir($database_folder);
}
if (!file_exists($functions_folder)) {
    mkdir($functions_folder);
}
$status = file_put_contents($dst_filepath, $generated_code);
if ($status === false) {
    echo 'error writing to file ' . $dst_filepath . PHP_EOL;
    die(0);
}

echo 'created file ' . $dst_filepath . PHP_EOL;

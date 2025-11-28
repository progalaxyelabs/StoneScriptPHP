<?php

/**
 * Database Administration Tool
 *
 * Provides utilities for executing database queries and SQL files.
 *
 * Usage:
 *   php generate dba <command> [arguments...]
 *
 * Commands:
 *   query <sql>        Execute a SQL query
 *   file <filename>    Execute SQL from a file in the postgresql directory
 *
 * Examples:
 *   php generate dba query "SELECT version()"
 *   php generate dba file schema/create_tables.sql
 */

// Determine the root path (go up two levels from Framework/cli)
define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

use Framework\Database;

$options = array_slice($argv, 1);

$available_commands = ['query', 'file'];
$command = '';

$num_options = count($options);
if ($num_options === 0) {
    echo "Database Administration Tool\n";
    echo "============================\n\n";
    echo "Usage: php generate dba <command> [arguments...]\n\n";
    echo "Available Commands:\n";
    echo "  query <sql>        Execute a SQL query\n";
    echo "  file <filename>    Execute SQL from a file in the postgresql directory\n\n";
    echo "Examples:\n";
    echo "  php generate dba query \"SELECT version()\"\n";
    echo "  php generate dba file schema/create_tables.sql\n";
    exit(1);
} else {
    $command = $options[0];
    if (!in_array($command, $available_commands)) {
        echo "Error: Invalid command '$command'. Must be " . implode(' or ', $available_commands) . "\n";
        exit(1);
    }
}

switch ($command) {
    case 'query':
        handle_query_command($options);
        break;
    // case 'function':        
    //     handle_function_command($options);
    //     break;
    case 'file':
        handle_file_command($options);
        break;
}

// function handle_function_command($options)
// {
//     $filename = $options[1] ?? '';
//     if (!$filename) {
//         echo ' filename not spedified' . PHP_EOL;
//         die(0);
//     }
//     $filepath = '..' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . 'functions' . DIRECTORY_SEPARATOR . str_replace('..', '.', $filename);
//     echo $filepath . PHP_EOL;
//     if (!file_exists($filepath)) {
//         echo 'file does not exist' . PHP_EOL;
//         die(0);
//     }
//     echo 'file ok' . PHP_EOL;
//     include '../Framework/bootstrap.php';    
//     $sql = file_get_contents($filepath);
//     echo $sql . PHP_EOL;
//     $status = Database::query($sql);
//     if (empty($status)) {
//         echo 'Query executed. No error.' . PHP_EOL;
//     } else {
//         echo 'Error executing query: ' . $status . PHP_EOL;
//     }
//     die(0);
// }

function handle_file_command($options)
{
    $filename = $options[1] ?? '';
    if (!$filename) {
        echo "Error: Filename not specified\n";
        echo "Usage: php generate dba file <filename>\n";
        exit(1);
    }

    $filepath = ROOT_PATH . 'postgresql' . DIRECTORY_SEPARATOR . str_replace('..', '.', $filename);
    echo "Executing SQL file: $filepath\n";

    if (!file_exists($filepath)) {
        echo "Error: File does not exist: $filepath\n";
        exit(1);
    }

    require_once ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $sql = file_get_contents($filepath);
    echo $sql . "\n";
    echo str_repeat('-', 60) . "\n";

    $status = Database::query($sql);
    echo "Executed. Status: $status\n";
    exit(0);
}

function handle_query_command($options) {
    $statement = $options[1] ?? '';
    if(empty($statement)) {
        echo "Error: No query specified\n";
        echo "Usage: php generate dba query \"<SQL statement>\"\n";
        exit(1);
    }

    require_once ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    echo "Executing query: $statement\n";
    echo str_repeat('-', 60) . "\n";

    $status = Database::query($statement);
    echo "Executed. Status: $status\n";
    exit(0);
}

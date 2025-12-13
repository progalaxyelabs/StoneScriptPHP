<?php

// php dba.php function filename.pssql

// var_dump($argc);
// var_dump($argv);

use StoneScriptPHP\Database;

$options = array_slice($argv, 1);
// var_dump($options);

// $available_commands = ['query', 'file', 'function'];
$available_commands = ['query', 'file'];
$command = '';

$num_options = count($options);
if ($num_options === 0) {
    echo 'No command specified' . PHP_EOL;
    die(0);
} else {
    $command = $options[0];
    if (in_array($command, $available_commands)) {
        // echo "detected command [$command]" . PHP_EOL;
    } else {
        echo 'invalid command ' . $command . '. must be ' . implode(' or ', $available_commands) . PHP_EOL;
        die(0);
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
        echo ' filename not spedified' . PHP_EOL;
        die(0);
    }
    $filepath = '..' . DIRECTORY_SEPARATOR . 'postgresql' . DIRECTORY_SEPARATOR . str_replace('..', '.', $filename);
    echo $filepath . PHP_EOL;
    if (!file_exists($filepath)) {
        echo 'file does not exist' . PHP_EOL;
        die(0);
    }
    echo 'file ok' . PHP_EOL;

    // Load autoloader
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        echo "Error: vendor/autoload.php not found. Run: composer install\n";
        exit(1);
    }
    require_once $autoloadPath;

    $sql = file_get_contents($filepath);
    echo $sql . PHP_EOL;
    $status = Database::query($sql);
    // if (empty($status)) {
    //     echo 'Query executed. No error.' . PHP_EOL;
    // } else {
    //     echo 'Error executing query: ' . $status . PHP_EOL;
    // }
    echo 'Executed. ' . $status . PHP_EOL;
    die(0);
}

function handle_query_command($options) {
    $statement = $options[1] ?? '';
    if(empty($statement)) {
        echo 'no query specified' . PHP_EOL;
        die(0);
    }

    // Load autoloader
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        echo "Error: vendor/autoload.php not found. Run: composer install\n";
        exit(1);
    }
    require_once $autoloadPath;

    $status = Database::query($statement);
    echo 'Executed. ' . $status . PHP_EOL;
    die(0);
}

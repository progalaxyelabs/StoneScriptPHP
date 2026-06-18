<?php

declare(strict_types=1);

/**
 * Validate Command Dispatcher
 * Routes to specific validators based on subcommand.
 *
 * Usage: php stone validate <subcommand> [options]
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];

if ($argc === 1 || in_array($argv[1] ?? '', ['--help', '-h', 'help'], true)) {
    echo "Validate Command\n";
    echo "================\n\n";
    echo "Usage: php stone validate <subcommand> [options]\n\n";
    echo "Available subcommands:\n";
    echo "  sqlintegrity     Check SQL functions for references to undefined tables or functions\n\n";
    echo "Options (sqlintegrity):\n";
    echo "  --schema=main|tenant  Scope Phase 1+2 checks to a single DB schema\n";
    echo "  --strict              Treat warnings as errors (for CI)\n";
    echo "  --json                Machine-readable JSON output\n\n";
    echo "Examples:\n";
    echo "  php stone validate sqlintegrity                    # Phase 0 layout check only\n";
    echo "  php stone validate sqlintegrity --schema=main      # main schema ref check\n";
    echo "  php stone validate sqlintegrity --schema=tenant    # tenant schema ref check\n";
    echo "  php stone validate sqlintegrity --schema=main --strict\n";
    echo "  php stone validate sqlintegrity --json\n\n";
    echo "Exit codes:\n";
    echo "  0  Clean — no issues found\n";
    echo "  1  Errors found (table missing from definitions, etc.)\n";
    echo "  2  Warnings only (unknown function calls)\n";
    exit(0);
}

$subcommand = $argv[1] ?? '';

$validators = [
    'sqlintegrity' => 'cli/validate-sqlintegrity.php',
];

if (!isset($validators[$subcommand])) {
    echo "Error: Unknown subcommand '$subcommand'\n";
    echo "Run 'php stone validate --help' for available subcommands\n";
    exit(1);
}

// Validators are sibling files in the same cli/ directory as this dispatcher
$validatorFile = __DIR__ . DIRECTORY_SEPARATOR . basename($validators[$subcommand]);

if (!file_exists($validatorFile)) {
    echo "Error: Validator not found: $validatorFile\n";
    exit(1);
}

// Strip 'validate' from argv so the validator sees remaining args
array_splice($_SERVER['argv'], 1, 1);
$_SERVER['argc'] = count($_SERVER['argv']);

require $validatorFile;

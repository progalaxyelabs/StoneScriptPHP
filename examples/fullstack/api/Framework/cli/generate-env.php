#!/usr/bin/env php
<?php

/**
 * Environment File Generator
 *
 * Generates or updates .env file based on the schema defined in Framework/Env.php
 *
 * Usage:
 *   php Framework/cli/generate-env.php
 *   php Framework/cli/generate-env.php --force  (overwrites existing values with defaults)
 */

// Determine the root path
$root_path = dirname(__DIR__, 2);
$env_file_path = $root_path . DIRECTORY_SEPARATOR . '.env';
$env_example_path = $root_path . DIRECTORY_SEPARATOR . '.env.example';

// Load the Env class to get the schema
require_once $root_path . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR . 'Env.php';

// Check for help flag
if (in_array('--help', $argv ?? []) || in_array('-h', $argv ?? []) || in_array('help', $argv ?? [])) {
    echo "Environment Configuration Generator\n";
    echo "====================================\n\n";
    echo "Usage: php generate env [options]\n\n";
    echo "Options:\n";
    echo "  --force      Overwrite existing values with defaults\n";
    echo "  --example    Generate .env.example instead of .env\n";
    echo "  --help, -h   Show this help message\n\n";
    echo "Examples:\n";
    echo "  php generate env                # Generate/update .env file\n";
    echo "  php generate env --force        # Regenerate with defaults\n";
    echo "  php generate env --example      # Generate .env.example\n";
    exit(0);
}

$force = in_array('--force', $argv ?? []);
$example = in_array('--example', $argv ?? []);

if ($example) {
    echo "Generating .env.example file...\n";
    generateEnvFile($env_example_path, true, true);
    echo "✓ .env.example created successfully\n";
    exit(0);
}

echo "Environment Configuration Generator\n";
echo "====================================\n\n";

// Check if .env already exists
$existing_values = [];
if (file_exists($env_file_path)) {
    if ($force) {
        echo "⚠ --force flag detected. Existing values will be replaced with defaults.\n\n";
    } else {
        echo "ℹ .env file exists. Existing values will be preserved.\n\n";
        $existing_values = parseEnvFile($env_file_path);
    }
} else {
    echo "ℹ Creating new .env file...\n\n";
}

// Generate the .env file
generateEnvFile($env_file_path, $force, false, $existing_values);

echo "\n✓ .env file generated successfully!\n";
echo "\nNext steps:\n";
echo "  1. Edit .env and set your environment-specific values\n";
echo "  2. Never commit .env to version control\n";
echo "  3. Run 'php Framework/cli/generate-env.php --example' to create .env.example for documentation\n";

/**
 * Parse an existing .env file
 *
 * @param string $filepath Path to the .env file
 * @return array Key-value pairs from the file
 */
function parseEnvFile(string $filepath): array
{
    $values = [];
    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments and empty lines
        $line = trim($line);
        if (empty($line) || $line[0] === '#') {
            continue;
        }

        // Parse KEY=VALUE
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Remove quotes if present
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                // Unescape quotes
                $value = str_replace('\\"', '"', $value);
                $value = str_replace("\\'", "'", $value);
            }
        }

        $values[$key] = $value;
    }

    return $values;
}

/**
 * Generate or update the .env file
 *
 * @param string $filepath Path to the .env file
 * @param bool $force Whether to force overwrite existing values
 * @param bool $is_example Whether generating .env.example (uses placeholders)
 * @param array $existing_values Existing values from current .env
 */
function generateEnvFile(string $filepath, bool $force, bool $is_example, array $existing_values = []): void
{
    $schema = Framework\Env::getSchema();
    $output = [];

    // Header
    $output[] = "# Environment Configuration";
    $output[] = "# Generated: " . date('Y-m-d H:i:s');
    $output[] = "#";
    $output[] = "# This file contains environment-specific configuration.";
    $output[] = "# DO NOT commit this file to version control!";
    $output[] = "#";
    $output[] = "";

    // Group variables by prefix for better organization
    $groups = groupVariablesByPrefix($schema);

    foreach ($groups as $prefix => $variables) {
        // Add group header
        $output[] = "# " . str_repeat("=", 60);
        $output[] = "# " . strtoupper(str_replace('_', ' ', $prefix)) . " Configuration";
        $output[] = "# " . str_repeat("=", 60);
        $output[] = "";

        foreach ($variables as $key => $config) {
            // Add description as comment
            if (!empty($config['description'])) {
                $output[] = "# " . $config['description'];
            }

            // Add type and requirement info
            $info = "Type: {$config['type']}";
            if ($config['required']) {
                $info .= " | Required";
            } else {
                $info .= " | Optional";
            }
            $output[] = "# $info";

            // Determine the value to use
            $value = determineValue($key, $config, $existing_values, $force, $is_example);

            // Format the line
            if ($value === null || $value === '') {
                $output[] = "# $key=";
            } else {
                // Escape special characters in value
                $escaped_value = escapeEnvValue($value);
                $output[] = "$key=$escaped_value";
            }

            $output[] = "";
        }
    }

    // Write to file
    file_put_contents($filepath, implode("\n", $output));
}

/**
 * Group variables by their prefix (e.g., DATABASE_, ZEPTOMAIL_)
 *
 * @param array $schema The environment schema
 * @return array Grouped variables
 */
function groupVariablesByPrefix(array $schema): array
{
    $groups = [];

    foreach ($schema as $key => $config) {
        // Extract prefix (everything before the last underscore, or the whole key if no underscore)
        $parts = explode('_', $key);
        if (count($parts) > 1) {
            array_pop($parts);
            $prefix = implode('_', $parts);
        } else {
            $prefix = 'GENERAL';
        }

        if (!isset($groups[$prefix])) {
            $groups[$prefix] = [];
        }

        $groups[$prefix][$key] = $config;
    }

    return $groups;
}

/**
 * Determine the value to use for an environment variable
 *
 * @param string $key Variable name
 * @param array $config Variable configuration
 * @param array $existing_values Existing values from .env
 * @param bool $force Force use of default
 * @param bool $is_example Whether generating example file
 * @return mixed The value to use
 */
function determineValue(string $key, array $config, array $existing_values, bool $force, bool $is_example)
{
    // For example files, use placeholders
    if ($is_example) {
        if ($config['default'] !== null) {
            return $config['default'];
        }
        return getPlaceholder($key, $config['type']);
    }

    // If force flag is set, use default
    if ($force && $config['default'] !== null) {
        return $config['default'];
    }

    // If existing value exists, use it
    if (isset($existing_values[$key])) {
        return $existing_values[$key];
    }

    // Otherwise use default
    return $config['default'];
}

/**
 * Get a placeholder value for example files
 *
 * @param string $key Variable name
 * @param string $type Variable type
 * @return string Placeholder value
 */
function getPlaceholder(string $key, string $type): string
{
    switch ($type) {
        case 'string':
            if (strpos($key, 'PASSWORD') !== false || strpos($key, 'TOKEN') !== false) {
                return 'your_' . strtolower($key) . '_here';
            }
            return 'your_value_here';
        case 'int':
            return '0';
        case 'bool':
            return 'false';
        case 'float':
            return '0.0';
        default:
            return '';
    }
}

/**
 * Escape special characters in environment values
 *
 * @param mixed $value The value to escape
 * @return string Escaped value
 */
function escapeEnvValue($value): string
{
    $value = (string) $value;

    // If value contains spaces, quotes, or special characters, wrap in quotes
    if (preg_match('/[\s#"\']/', $value)) {
        // Escape existing quotes
        $value = str_replace('"', '\\"', $value);
        return '"' . $value . '"';
    }

    return $value;
}

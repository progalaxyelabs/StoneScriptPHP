#!/usr/bin/env php
<?php

/**
 * Environment File Generator
 *
 * Generates or updates .env file based on the schema defined in StoneScriptPHP\Env class
 *
 * Usage:
 *   php stone generate env
 *   php stone generate env --force  (overwrites existing values with defaults)
 */

/**
 * Resolve the PROJECT root (where .env and vendor/ live).
 *
 * When the framework is installed as a dependency this file sits at
 * <project>/vendor/progalaxyelabs/stonescriptphp/cli, so dirname(__DIR__) is the
 * package directory, not the project. `php stone` defines ROOT_PATH correctly; when it
 * is not defined (file run directly) the vendor layout is detected from the path.
 */
function resolveEnvGeneratorRoot(string $cliDir, ?string $definedRoot = null): string
{
    if ($definedRoot !== null && $definedRoot !== '') {
        return rtrim($definedRoot, '/\\');
    }
    $normalised = str_replace('\\', '/', $cliDir);
    if (str_ends_with($normalised, '/vendor/progalaxyelabs/stonescriptphp/cli')) {
        return dirname($cliDir, 4);
    }
    return dirname($cliDir);
}

// Determine the root path
$root_path = resolveEnvGeneratorRoot(__DIR__, defined('ROOT_PATH') ? (string) ROOT_PATH : null);
$env_file_path = $root_path . DIRECTORY_SEPARATOR . '.env';
$env_example_path = $root_path . DIRECTORY_SEPARATOR . '.env.example';

// Test seam: when required with this constant defined (e.g. by a unit test),
// only the helper functions below are exposed and the CLI flow is skipped.
// Top-level function declarations are compiled before execution, so they remain
// available despite this early return. CLI usage never defines the constant.
if (defined('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY')) {
    return;
}

// Load the Env class from vendor or autoloader (already loaded when run via `php stone`)
if (!class_exists('StoneScriptPHP\\Env', false)) {
    if (file_exists($root_path . '/vendor/autoload.php')) {
        require_once $root_path . '/vendor/autoload.php';
    } else {
        echo "Error: vendor/autoload.php not found under {$root_path}. Run: composer install\n";
        exit(1);
    }
}

// Try to load Env class
if (!class_exists('StoneScriptPHP\\Env')) {
    echo "Error: StoneScriptPHP\\Env class not found\n";
    echo "Make sure StoneScriptPHP framework is installed via composer\n";
    exit(1);
}

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

if (is_dir($env_file_path)) {
    echo "Error: $env_file_path is a directory, not a file. Remove it (or fix the bind mount) first.\n";
    exit(1);
}

if (!file_exists($env_file_path)) {
    echo "ℹ Creating new .env file...\n\n";
    generateEnvFile($env_file_path, false, false);
    echo "✓ .env created (mode 0640).\n";
    exit(0);
}

// An EXISTING .env holds real settings and secrets. It is never regenerated from the schema alone:
// anything the Env schema does not know (GOOGLE_CLIENT_ID, payment/mail keys, JWT_PRIVATE_KEY, ...) is
// read through Env::secret() by application code and must survive.
$raw = file_get_contents($env_file_path);
if ($raw === false) {
    echo "Error: cannot read $env_file_path\n";
    exit(1);
}
try {
    $existing_values = parseEnvContent($raw);
} catch (\Dotenv\Exception\ExceptionInterface $e) {
    // Reason by exception class only: Dotenv's own text quotes the offending (possibly secret) line.
    echo "Error: $env_file_path is not valid dotenv syntax; refusing to touch it. Fix it by hand "
        . "(use '# comment' lines and KEY=value; quote values containing spaces), then re-run.\n";
    exit(1);
}

if ($force) {
    // --force: reset every setting the Env schema knows to its default. Settings the schema does NOT know are
    // kept (re-written with correct quoting); the previous file is saved as a mode-0600 backup first.
    $backup = $env_file_path . '.bak.' . date('YmdHis');
    // 0600 from the first byte: the backup holds the secrets, so it must never exist with a wider mode, even briefly.
    $oldUmask = umask(0177);
    $bh = @fopen($backup, 'xb');
    umask($oldUmask);
    if ($bh === false || fwrite($bh, $raw) !== strlen($raw) || !fclose($bh)) {
        echo "Error: could not write backup $backup; nothing was changed.\n";
        exit(1);
    }
    echo "⚠ --force: schema settings reset to defaults. Previous file saved as " . basename($backup) . " (contains secrets; mode 0600, git-ignored via .env.*; delete it when done and keep it out of images).\n";
    $schemaKeys = array_keys(currentEnvSchema());
    $preserved = array_diff_key($existing_values, array_flip($schemaKeys));
    $content = renderEnvContent(true, false, [], $preserved);
    try {
        writeFileAtomic($env_file_path, $content, 0640);
    } catch (\RuntimeException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
    echo "✓ .env regenerated; " . count($preserved) . " non-schema setting(s) preserved.\n";
    exit(0);
}

// Default: leave the file byte-for-byte as it is and only APPEND schema settings that are missing, commented out.
$missing = array_diff_key(currentEnvSchema(), $existing_values);
if ($missing === []) {
    echo "ℹ .env already has every setting in the schema; nothing to change.\n";
    exit(0);
}
$block = ["", "# --- Added by php stone env on " . date('Y-m-d H:i:s') . " (uncomment to override the default) ---"];
foreach ($missing as $key => $config) {
    $block[] = "# Type: {$config['type']} | " . ($config['required'] ? 'Required' : 'Optional');
    $block[] = "# $key=" . ($config['default'] !== null ? escapeEnvValue($config['default']) : '');
}
$content = rtrim($raw, "\r\n") . "\n" . implode("\n", $block) . "\n";
try {
    writeFileAtomic($env_file_path, $content, 0640);
} catch (\RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
echo "✓ .env kept as is; " . count($missing) . " missing setting(s) appended as comments.\n";
exit(0);

/**
 * Parse dotenv content with the framework's own parser (phpdotenv).
 *
 * @return array<string, string>
 * @throws \Dotenv\Exception\ExceptionInterface when the content is not valid dotenv syntax
 */
function parseEnvContent(string $content): array
{
    return array_map(static fn($v) => (string) $v, \Dotenv\Dotenv::parse($content));
}

/**
 * Write a file atomically (temp file in the same directory + rename). An existing file's mode and, when
 * running as root, owner/group are preserved; a new file gets $newMode.
 */
function writeFileAtomic(string $path, string $content, int $newMode): void
{
    // Write through a symlink to its TARGET: renaming over the link would replace the link itself with a regular
    // file and leave the real file (e.g. a mounted secret) stale.
    if (is_link($path)) {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new \RuntimeException("$path is a dangling symlink; refusing to write.");
        }
        $path = $resolved;
    }
    $exists = file_exists($path);
    $mode = $exists ? (fileperms($path) & 0777) : $newMode;
    $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

    // A rename gives the new file OUR owner/group. If we cannot keep the existing owner/group, the runtime user
    // may lose read access: refuse rather than silently break the deployment.
    if ($exists && !$isRoot && function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) {
        throw new \RuntimeException("$path is owned by another user; run as its owner (or root) so ownership is preserved. Nothing was changed.");
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $oldUmask = umask(0177);
    $fh = fopen($tmp, 'xb');
    umask($oldUmask);
    if ($fh === false) {
        throw new \RuntimeException("Cannot create temporary file next to $path");
    }
    $ok = fwrite($fh, $content) === strlen($content);
    $ok = fclose($fh) && $ok;
    if (!$ok) {
        unlink($tmp);
        throw new \RuntimeException("Cannot write $path");
    }
    if ($exists) {
        if ($isRoot) {
            @chown($tmp, fileowner($path));
        }
        if (filegroup($tmp) !== filegroup($path)) {
            @chgrp($tmp, filegroup($path));
            clearstatcache(true, $tmp);
            if (filegroup($tmp) !== filegroup($path)) {
                unlink($tmp);
                throw new \RuntimeException("Cannot keep the group of $path (this user is not a member); the new file would lose group access. Nothing was changed.");
            }
        }
    }
    chmod($tmp, $mode);
    if (!rename($tmp, $path)) {
        unlink($tmp);
        throw new \RuntimeException("Cannot replace $path");
    }
}

/**
 * Generate or update the .env file
 *
 * @param string $filepath Path to the .env file
 * @param bool $force Whether to force overwrite existing values
 * @param bool $is_example Whether generating .env.example (uses placeholders)
 * @param array $existing_values Existing values from current .env
 */
/**
 * Build the env-var schema from an Env class's public typed properties.
 *
 * Replaces the removed Env::getSchema() (dropped in the v2.3.0 Env refactor).
 * Env vars are declared as public typed properties whose name IS the env var
 * name (the same convention Env's own constructor uses to read them).
 *
 * Returns the same shape the rest of this generator consumes:
 *   [ ENV_VAR => ['type' => string, 'required' => bool, 'default' => ?string, 'description' => string] ]
 *
 * Derivation:
 *   - type        ← the property's reflection type (string|int|bool|float)
 *   - default     ← the property's default value, stringified for .env emission
 *                   (bool → 'true'/'false', int/float → string, string → as-is,
 *                   no default → null). Stringifying is required because
 *                   escapeEnvValue() casts via (string), which would turn a raw
 *                   bool false into an empty value.
 *   - required    ← no default value AND not nullable. This reproduces exactly
 *                   the vars Env's constructor hard-validates (DB_GATEWAY_URL,
 *                   DB_GATEWAY_PLATFORM).
 *   - description ← not derivable: Env properties carry only free-form `//`
 *                   group comments, not `/** *​/` docblocks, so per-var
 *                   descriptions cannot be reflected and are intentionally
 *                   omitted. (A future enhancement could add docblocks to
 *                   src/Env.php properties to restore description comments.)
 *
 * Inherited properties are included, so a consuming project's App\Env subclass
 * emits its inherited + custom env vars.
 *
 * @param \ReflectionClass $rc Env (or App\Env) class reflection
 * @return array<string, array{type:string, required:bool, default:?string, description:string}>
 */
function buildSchemaFromReflection(\ReflectionClass $rc): array
{
    $schema = [];

    foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->isStatic()) {
            continue;
        }

        $type = $property->getType();
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

        $hasDefault = $property->hasDefaultValue();
        $default = $hasDefault ? $property->getDefaultValue() : null;

        // Stringify the default into its .env representation.
        if (is_bool($default)) {
            $default = $default ? 'true' : 'false';
        } elseif (is_int($default) || is_float($default)) {
            $default = (string) $default;
        }
        // string defaults pass through; null (no default / null default) stays null.

        $required = !$hasDefault && !($type !== null && $type->allowsNull());

        $schema[$property->getName()] = [
            'type' => $typeName,
            'required' => $required,
            'default' => $default,
            'description' => '',
        ];
    }

    return $schema;
}

/** The env-var schema of the project's Env class (App\Env if present, else the framework's). */
function currentEnvSchema(): array
{
    $envClass = class_exists('App\\Env') ? 'App\\Env' : 'StoneScriptPHP\\Env';
    return buildSchemaFromReflection(new \ReflectionClass($envClass));
}

/**
 * Write a NEW .env / .env.example from the schema (never used to rewrite an existing .env without
 * preserving it - see the flow at the top of this file).
 */
function generateEnvFile(string $filepath, bool $force, bool $is_example, array $existing_values = []): void
{
    writeFileAtomic($filepath, renderEnvContent($force, $is_example, $existing_values), $is_example ? 0644 : 0640);
}

/**
 * @param array<string,string> $existing_values values to use for schema keys (unless $force)
 * @param array<string,string> $preserved       non-schema settings to carry over verbatim (re-quoted)
 */
function renderEnvContent(bool $force, bool $is_example, array $existing_values = [], array $preserved = []): string
{
    $schema = currentEnvSchema();

    $output = [];

    // Header. Comments MUST use '#': the framework loads .env with vlucas/phpdotenv, which
    // rejects ';' lines ("invalid name") and would make the whole file unloadable.
    $output[] = "# Environment Configuration";
    $output[] = "# Generated: " . date('Y-m-d H:i:s');
    $output[] = "#";
    $output[] = "# This file contains environment-specific configuration.";
    $output[] = "# DO NOT commit this file to version control";
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

    if ($preserved !== []) {
        $output[] = "# " . str_repeat("=", 60);
        $output[] = "# Settings not in the Env schema (preserved)";
        $output[] = "# " . str_repeat("=", 60);
        foreach ($preserved as $key => $value) {
            $output[] = $value === '' ? "$key=" : "$key=" . escapeEnvValue($value);
        }
        $output[] = "";
    }

    return implode("\n", $output) . "\n";
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

    // Plain tokens stay bare.
    if (preg_match('~^[A-Za-z0-9_@%+=:,./-]*$~', $value)) {
        return $value;
    }

    // Everything else is double-quoted with phpdotenv's escapes. Backslash and '$' MUST be escaped: unescaped,
    // '\' sequences are interpreted and '${NAME}' is expanded, silently changing the value (or, for a stray
    // '$' / quote, making the whole file unparseable).
    $value = str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '\\n', '\\r'], $value);
    return '"' . $value . '"';
}

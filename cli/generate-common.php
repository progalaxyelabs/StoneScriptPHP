<?php

/**
 * Common Functions for Generate Scripts
 *
 * Detects the correct ROOT_PATH whether running from:
 * - vendor/progalaxyelabs/stonescriptphp/cli/ (standard)
 * - project/api/vendor/progalaxyelabs/stonescriptphp/cli/ (api subdirectory)
 * - project/cli/ (backward compatibility)
 *
 * Automatically defines ROOT_PATH, SRC_PATH, CONFIG_PATH if not already set.
 */

if (!function_exists('detect_root_path')) {
    function detect_root_path(): string {
        $cliDir = __DIR__;
        $frameworkDir = dirname($cliDir);

        // Check if we're in vendor/
        if (strpos($frameworkDir, 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp') !== false) {
            // We're in vendor/progalaxyelabs/stonescriptphp/cli
            // Go up: cli -> stonescriptphp -> progalaxyelabs -> vendor -> project/api or project
            $vendorDir = dirname(dirname($frameworkDir));
            $projectDir = dirname($vendorDir);

            // Check if project directory is 'api'
            if (basename($projectDir) === 'api' && file_exists($projectDir . DIRECTORY_SEPARATOR . 'composer.json')) {
                // We're in project/api/vendor/...
                return $projectDir . DIRECTORY_SEPARATOR;
            }

            // Standard: project/vendor/...
            return $projectDir . DIRECTORY_SEPARATOR;
        }

        // Not in vendor - backward compatibility (project/cli/)
        return dirname($frameworkDir) . DIRECTORY_SEPARATOR;
    }
}

// Auto-define paths if not already defined (e.g., by stone script)
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', detect_root_path());
}
if (!defined('SRC_PATH')) {
    define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);
}

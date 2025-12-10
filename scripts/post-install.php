<?php

/**
 * Composer Post-Install/Update Script
 *
 * Copies the 'stone' CLI entry point to the project root
 * so users can run 'php stone' commands.
 */

// Determine paths
$vendorDir = dirname(__DIR__, 2); // Go up from vendor/progalaxyelabs/stonescriptphp to vendor
$projectRoot = dirname($vendorDir); // Go up from vendor to project root
$sourceStone = __DIR__ . '/../stone';
$targetStone = $projectRoot . '/stone';

echo "StoneScriptPHP: Installing CLI entry point...\n";

// Check if source exists
if (!file_exists($sourceStone)) {
    echo "  ⚠️  Warning: stone script not found in framework package\n";
    exit(0);
}

// Copy stone to project root
if (copy($sourceStone, $targetStone)) {
    chmod($targetStone, 0755); // Make executable
    echo "  ✅ stone script installed to project root\n";
    echo "  Run 'php stone --help' to see available commands\n";
} else {
    echo "  ⚠️  Warning: Could not copy stone script to project root\n";
    echo "  You may need to copy it manually from vendor/progalaxyelabs/stonescriptphp/stone\n";
}

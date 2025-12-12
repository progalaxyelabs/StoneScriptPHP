<?php

/**
 * StoneScriptPHP Upgrade Tool
 *
 * Updates CLI scripts and tools from the latest StoneScriptPHP-Server release.
 *
 * This is needed because the project skeleton is copied, not installed as a dependency.
 * Running `composer update` only updates vendor packages, not project files.
 */

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// Colors for output
class Color {
    const RED = "\033[0;31m";
    const GREEN = "\033[0;32m";
    const YELLOW = "\033[1;33m";
    const BLUE = "\033[0;34m";
    const NC = "\033[0m";
}

echo Color::BLUE . "StoneScriptPHP Upgrade Tool\n" . Color::NC;
echo "============================\n\n";

// Check for help flag
if ($argc > 1 && in_array($argv[1], ['--help', '-h', 'help'])) {
    echo "Usage: php stone upgrade [options]\n\n";
    echo "Options:\n";
    echo "  --check     Check for updates without installing\n";
    echo "  --force     Force upgrade even if version is the same\n";
    echo "  --dry-run   Show what would be updated without making changes\n\n";
    echo "This command updates CLI scripts from the latest StoneScriptPHP-Server release.\n";
    echo "It downloads and replaces files in the cli/ directory and the stone script.\n";
    exit(0);
}

$options = [
    'check' => in_array('--check', $argv),
    'force' => in_array('--force', $argv),
    'dry_run' => in_array('--dry-run', $argv),
];

// GitHub repository details
$repo = 'progalaxyelabs/StoneScriptPHP-Server';
$apiUrl = "https://api.github.com/repos/$repo/releases/latest";

echo "📡 Fetching latest release information...\n";

// Fetch latest release info
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'StoneScriptPHP-Upgrade-Tool');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo Color::RED . "❌ Failed to fetch release information (HTTP $httpCode)\n" . Color::NC;
    echo "Please check your internet connection or try again later.\n";
    exit(1);
}

$release = json_decode($response, true);

if (!$release || !isset($release['tag_name'])) {
    echo Color::RED . "❌ Failed to parse release information\n" . Color::NC;
    exit(1);
}

$latestVersion = $release['tag_name'];
$releaseUrl = $release['html_url'];

echo Color::GREEN . "✓ Latest version: $latestVersion\n" . Color::NC;

// Get current version from composer.json
$composerFile = ROOT_PATH . 'composer.json';
if (file_exists($composerFile)) {
    $composer = json_decode(file_get_contents($composerFile), true);
    $currentVersion = $composer['version'] ?? 'unknown';
    echo "📦 Current version: $currentVersion\n";

    if ($currentVersion === $latestVersion && !$options['force']) {
        echo Color::GREEN . "✓ Already up to date!\n" . Color::NC;
        if (!$options['check']) {
            echo "Use --force to upgrade anyway.\n";
        }
        exit(0);
    }
}

if ($options['check']) {
    echo Color::YELLOW . "\n📋 Update available: $latestVersion\n" . Color::NC;
    echo "Run 'php stone upgrade' to install.\n";
    exit(0);
}

// Files to update
$filesToUpdate = [
    'stone' => 'stone',
    'cli/generate-route.php' => 'cli/generate-route.php',
    'cli/generate-model.php' => 'cli/generate-model.php',
    'cli/generate-auth.php' => 'cli/generate-auth.php',
    'cli/generate-client.php' => 'cli/generate-client.php',
    'cli/generate-env.php' => 'cli/generate-env.php',
    'cli/generate-jwt-keys.php' => 'cli/generate-jwt-keys.php',
    'cli/migrate.php' => 'cli/migrate.php',
    'cli/upgrade.php' => 'cli/upgrade.php',
];

// Get raw file URLs
$rawBaseUrl = "https://raw.githubusercontent.com/$repo/$latestVersion/";

echo "\n🔄 " . ($options['dry_run'] ? "Would update" : "Updating") . " " . count($filesToUpdate) . " files...\n\n";

$updated = 0;
$failed = 0;

foreach ($filesToUpdate as $source => $dest) {
    $url = $rawBaseUrl . $source;
    $destPath = ROOT_PATH . $dest;

    echo "  - $dest ... ";

    if ($options['dry_run']) {
        echo Color::BLUE . "[dry-run]\n" . Color::NC;
        continue;
    }

    // Download file
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$content) {
        echo Color::RED . "failed (HTTP $httpCode)\n" . Color::NC;
        $failed++;
        continue;
    }

    // Backup existing file
    if (file_exists($destPath)) {
        $backupPath = $destPath . '.backup-' . date('YmdHis');
        copy($destPath, $backupPath);
    }

    // Write new file
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (file_put_contents($destPath, $content) !== false) {
        // Make executable if it's the stone script
        if ($dest === 'stone') {
            chmod($destPath, 0755);
        }
        echo Color::GREEN . "✓\n" . Color::NC;
        $updated++;
    } else {
        echo Color::RED . "failed (write error)\n" . Color::NC;
        $failed++;
    }
}

// Update composer.json version
if (!$options['dry_run'] && file_exists($composerFile)) {
    $composer['version'] = $latestVersion;
    file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo "\n";

if ($options['dry_run']) {
    echo Color::BLUE . "📋 Dry run complete. No files were modified.\n" . Color::NC;
} else {
    echo Color::GREEN . "✅ Upgrade complete!\n" . Color::NC;
    echo "  - Updated: $updated files\n";
    if ($failed > 0) {
        echo Color::YELLOW . "  - Failed: $failed files\n" . Color::NC;
    }
    echo "\nBackup files were created with .backup-* extension.\n";
    echo "Release notes: $releaseUrl\n";
}

exit($failed > 0 ? 1 : 0);

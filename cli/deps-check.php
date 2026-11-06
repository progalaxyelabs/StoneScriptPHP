<?php
/**
 * stone deps:check — fail on an unapproved package, a known vulnerability, or an unreviewed autoload "files" package.
 *
 * Usage: php stone deps:check [--no-audit] [--dev] [--json]
 *   --no-audit  skip `composer audit` (offline); the report says so, exit code unaffected
 *   --dev       also check require-dev packages (default: production packages only)
 * Exit: 0 clean, 1 findings.
 */
require_once __DIR__ . '/helpers/post-install.php';

$flags = array_slice($argv, 1);
$audit = in_array('--no-audit', $flags, true) ? null : ssp_run_composer_audit(rtrim(ROOT_PATH, '/'));
$report = ssp_check_dependencies(rtrim(ROOT_PATH, '/'), $audit, in_array('--dev', $flags, true));
if (!in_array('--no-audit', $flags, true) && $audit === null) {
    $report['errors'][] = 'composer audit could not be run (composer missing or no composer.lock) — run it, or pass --no-audit explicitly';
}

if (in_array('--json', $flags, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($report['autoload_files'] as $name => $files) {
        echo "ℹ️  autoload files: {$name} → " . implode(', ', $files) . "\n";
    }
    foreach ($report['warnings'] as $w) {
        echo "⚠️  {$w}\n";
    }
    foreach ($report['errors'] as $e) {
        fwrite(STDERR, "❌ {$e}\n");
    }
    echo $report['errors'] === [] ? "✅ deps:check passed\n" : '❌ deps:check failed: ' . count($report['errors']) . " finding(s)\n";
}
$stoneExitCode = $report['errors'] === [] ? 0 : 1;

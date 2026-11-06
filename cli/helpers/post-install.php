<?php
/**
 * StoneScriptPHP CLI Helper — `stone post-install` and `stone deps:check` core logic.
 *
 * Why this exists: Composer only ever runs the ROOT composer.json's scripts, so the app's post-install hook is
 * the one place our code runs after `composer install/update`. Previously that hook was several inline
 * commands that copied files out of vendor/ and included whatever cli/ script was there. It is now one
 * framework-owned command (`@php vendor/progalaxyelabs/stonescriptphp/stone post-install`) that:
 *
 *   (a) verifies the installed framework files against the hash manifest shipped in the release
 *       (framework-manifest.txt) and the installed.json version, and REFUSES if anything differs;
 *   (b) refreshes the project's `stone` CLI copy;
 *   (c) stages vendor SQL ONLY from packages named (with a version constraint) in the app's
 *       stone-packages.json "schema_allowlist", noticing every progalaxyelabs/* package that ships SQL but is not listed;
 *   (d) prunes its own staging dir (src/postgresql/vendor/) — never anything outside it.
 *
 * Honest limit of (a): it detects ACCIDENTAL damage (another package or a bad deploy overwriting/deleting
 * framework files, a partial install). A package that deliberately rewrites vendor/ AND this script defeats it;
 * that threat is handled by denying Composer plugins and by `stone deps:check`, not by this hash check.
 */

require_once __DIR__ . '/package-constraints.php';
require_once __DIR__ . '/vendor-schema-sync.php';

const SSP_FRAMEWORK_PACKAGE = 'progalaxyelabs/stonescriptphp';
const SSP_MANIFEST_FILE = 'framework-manifest.txt';
const SSP_PACKAGES_CONFIG = 'stone-packages.json';
/** Paths (relative to the framework root) covered by the integrity manifest. */
const SSP_MANIFEST_ROOTS = ['src', 'cli', 'config', 'stone', 'composer.json'];

/**
 * @return array{schema_allowlist: array<string,string>, approved: array{vendors: list<string>, packages: list<string>},
 *               autoload_files_reviewed: array<string,string>, plugins_approved: list<string>}
 * @throws RuntimeException on malformed JSON
 */
function ssp_read_packages_config(string $projectRoot): array
{
    $defaults = [
        'schema_allowlist' => [],
        'approved' => ['vendors' => [], 'packages' => []],
        'autoload_files_reviewed' => [],
        'plugins_approved' => [],
    ];
    $file = $projectRoot . '/' . SSP_PACKAGES_CONFIG;
    if (!is_file($file)) {
        return $defaults;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException(SSP_PACKAGES_CONFIG . ' is not valid JSON');
    }
    return [
        'schema_allowlist' => array_map('strval', (array) ($data['schema_allowlist'] ?? [])),
        'approved' => [
            'vendors' => array_values(array_map('strval', (array) ($data['approved']['vendors'] ?? []))),
            'packages' => array_values(array_map('strval', (array) ($data['approved']['packages'] ?? []))),
        ],
        'autoload_files_reviewed' => array_map('strval', (array) ($data['autoload_files_reviewed'] ?? [])),
        'plugins_approved' => array_values(array_map('strval', (array) ($data['plugins_approved'] ?? []))),
    ];
}

/**
 * sha256 of every file under SSP_MANIFEST_ROOTS (sorted, relative paths).
 *
 * @return array<string,string> relative path => sha256
 */
function ssp_build_framework_manifest(string $frameworkRoot): array
{
    $out = [];
    foreach (SSP_MANIFEST_ROOTS as $rel) {
        $path = $frameworkRoot . '/' . $rel;
        if (is_file($path)) {
            $out[$rel] = hash_file('sha256', $path);
        } elseif (is_dir($path)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $out[ltrim(substr($f->getPathname(), strlen($frameworkRoot)), '/')] = hash_file('sha256', $f->getPathname());
                }
            }
        }
    }
    ksort($out);
    return $out;
}

/**
 * Manifest text format (sha256sum-compatible, so `sha256sum -c` works from the framework root):
 *     # version 14.4.0
 *     <sha256>  <relative path>
 * (Plain text rather than JSON: a path-keyed JSON map of hashes trips generic secret scanners.)
 *
 * @param array<string,string> $files
 */
function ssp_format_manifest(string $version, array $files): string
{
    $out = "# version {$version}\n";
    foreach ($files as $rel => $hash) {
        $out .= "{$hash}  {$rel}\n";
    }
    return $out;
}

/** @return array{version: string, files: array<string,string>}|null */
function ssp_parse_manifest(string $text): ?array
{
    $version = null;
    $files = [];
    foreach (explode("\n", $text) as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^# version (\S+)$/', $line, $m)) {
            $version = $m[1];
        } elseif (preg_match('/^([0-9a-f]{64})  (\S.*)$/', $line, $m)) {
            $files[$m[2]] = $m[1];
        } else {
            return null;
        }
    }
    return $version === null || $files === [] ? null : ['version' => $version, 'files' => $files];
}

/**
 * Verify the installed framework against its shipped manifest.
 *
 * @return list<string> problems (empty = intact)
 */
function ssp_verify_framework_integrity(string $projectRoot): array
{
    $root = $projectRoot . '/vendor/progalaxyelabs/stonescriptphp';
    if (!is_dir($root)) {
        return ['framework not installed at vendor/progalaxyelabs/stonescriptphp'];
    }
    $manifestFile = $root . '/' . SSP_MANIFEST_FILE;
    if (!is_file($manifestFile)) {
        return [SSP_MANIFEST_FILE . ' is missing from the installed framework (release too old, or the file was removed)'];
    }
    $manifest = ssp_parse_manifest((string) file_get_contents($manifestFile));
    if ($manifest === null) {
        return [SSP_MANIFEST_FILE . ' is not a valid manifest'];
    }

    $problems = [];
    $installed = ssp_installed_versions($projectRoot)[SSP_FRAMEWORK_PACKAGE] ?? null;
    if ($installed === null) {
        $problems[] = 'framework is not listed in vendor/composer/installed.json';
    } elseif ($installed !== ltrim($manifest['version'], 'vV')) {
        $problems[] = "installed.json says framework {$installed} but the shipped manifest is for {$manifest['version']}";
    }
    foreach ($manifest['files'] as $rel => $hash) {
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) {
            $problems[] = "missing: {$rel}";
        } elseif (!hash_equals((string) $hash, (string) hash_file('sha256', $abs))) {
            $problems[] = "modified: {$rel}";
        }
    }
    return $problems;
}

/**
 * Copy the framework's `stone` over the project's. Returns true when it changed.
 */
function ssp_refresh_stone_cli(string $projectRoot): bool
{
    $src = $projectRoot . '/vendor/progalaxyelabs/stonescriptphp/stone';
    $dst = $projectRoot . '/stone';
    if (!is_file($src)) {
        return false;
    }
    if (is_file($dst) && hash_file('sha256', $src) === hash_file('sha256', $dst)) {
        return false;
    }
    copy($src, $dst);
    chmod($dst, 0755);
    return true;
}

/**
 * Prune: remove the staging dir (and its manifest) — and ONLY that. Refuses a path that is a symlink or that does
 * not resolve to <root>/src/postgresql/vendor.
 */
function ssp_prune_staging(string $projectRoot): void
{
    $dir = $projectRoot . '/src/postgresql/vendor';
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir)) {
        throw new RuntimeException('refusing to prune: src/postgresql/vendor is a symlink');
    }
    $real = realpath($dir);
    $expected = realpath($projectRoot . '/src/postgresql');
    if ($real === false || $expected === false || $real !== $expected . '/vendor') {
        throw new RuntimeException('refusing to prune: staging dir does not resolve inside src/postgresql/');
    }
    ssp_prune_tree($real);
}

/** rm -r that never follows a symlink out of the tree. */
function ssp_prune_tree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = $dir . '/' . $item;
        if (is_link($p) || is_file($p)) {
            unlink($p);
        } else {
            ssp_prune_tree($p);
        }
    }
    rmdir($dir);
}

/**
 * The whole post-install run. Pure with respect to output: returns lines + exit code.
 *
 * @return array{exit: int, lines: list<string>}
 */
function ssp_run_post_install(string $projectRoot): array
{
    $lines = [];
    $problems = ssp_verify_framework_integrity($projectRoot);
    if ($problems !== []) {
        $lines[] = 'stone post-install: REFUSING — the installed StoneScriptPHP framework does not match its release:';
        foreach (array_slice($problems, 0, 20) as $p) {
            $lines[] = "  - {$p}";
        }
        if (count($problems) > 20) {
            $lines[] = '  … and ' . (count($problems) - 20) . ' more';
        }
        $lines[] = 'Another package or a manual edit changed framework files. Fix: `rm -rf vendor/progalaxyelabs/stonescriptphp && composer install`,';
        $lines[] = 'then find which package writes there (`composer why-not`, review recent composer.json changes).';
        return ['exit' => 1, 'lines' => $lines];
    }
    $lines[] = '✅ Framework files match the release manifest';

    if (ssp_refresh_stone_cli($projectRoot)) {
        $lines[] = '✅ Updated stone CLI from framework';
    }

    try {
        $config = ssp_read_packages_config($projectRoot);
        ssp_prune_staging($projectRoot);
    } catch (RuntimeException $e) {
        $lines[] = 'stone post-install: ' . $e->getMessage();
        return ['exit' => 1, 'lines' => $lines];
    }
    $notices = [];
    $result = syncAllVendorSchema($projectRoot, $projectRoot . '/src/postgresql/vendor/postgresql', $config['schema_allowlist'], $notices);
    foreach ($notices as $n) {
        $lines[] = "⚠️  {$n}";
    }
    if ($result['copied'] > 0) {
        $lines[] = "📦 Staged {$result['copied']} vendor-provided schema file(s) from " . implode(', ', $result['packages']) . ' into src/postgresql/vendor/';
        $lines[] = '   Review, then run: php stone gateway:migrate-vendor-main';
    }
    return ['exit' => 0, 'lines' => $lines];
}

/**
 * Composer events whose scripts run while packages are installed, updated, removed or autoloaded, or before any
 * Composer command. Only the framework's own `stone post-install` may be wired to them.
 */
const SSP_INSTALL_TIME_HOOKS = [
    'pre-install-cmd', 'post-install-cmd', 'pre-update-cmd', 'post-update-cmd',
    'pre-autoload-dump', 'post-autoload-dump',
    'pre-package-install', 'post-package-install', 'pre-package-update', 'post-package-update',
    'pre-package-uninstall', 'post-package-uninstall',
    'pre-command-run', 'command',
];

/** True only for the exact framework command, in the forms an app's composer.json uses to call it. */
function ssp_is_framework_post_install_command(string $cmd): bool
{
    return (bool) preg_match('#^(@php|php) (\./)?(vendor/progalaxyelabs/stonescriptphp/stone|stone) post-install$#', trim($cmd));
}

/**
 * `stone deps:check` core. Returns structured findings.
 *
 * @param array<string,mixed>|null $audit  result of composer audit (null = not run)
 * @return array{errors: list<string>, warnings: list<string>, autoload_files: array<string,list<string>>}
 */
function ssp_check_dependencies(string $projectRoot, ?array $audit, bool $includeDev = false): array
{
    $config = ssp_read_packages_config($projectRoot);
    $errors = [];
    $warnings = [];
    $autoloadFiles = [];

    $packages = ssp_read_installed_packages($projectRoot);
    if ($packages === []) {
        $errors[] = 'vendor/composer/installed.json is missing or empty — run composer install first';
    }
    foreach (ssp_verify_framework_integrity($projectRoot) as $problem) {
        $errors[] = "framework integrity: {$problem}";
    }
    $devNames = [];
    $data = is_file($projectRoot . '/vendor/composer/installed.json')
        ? json_decode((string) file_get_contents($projectRoot . '/vendor/composer/installed.json'), true) : [];
    foreach ((array) ($data['dev-package-names'] ?? []) as $n) {
        $devNames[(string) $n] = true;
    }

    $approvedVendors = array_flip($config['approved']['vendors']);
    $approvedPackages = array_flip($config['approved']['packages']);
    foreach ($packages as $pkg) {
        $name = (string) ($pkg['name'] ?? '');
        if ($name === '' || (!$includeDev && isset($devNames[$name]))) {
            continue;
        }
        $version = ltrim((string) ($pkg['version'] ?? ''), 'vV');
        $vendor = explode('/', $name)[0];
        if ($name !== SSP_FRAMEWORK_PACKAGE && !isset($approvedPackages[$name]) && !isset($approvedVendors[$vendor])) {
            $errors[] = "unapproved package: {$name} {$version} (add it to stone-packages.json \"approved\" after review)";
        }
        if (($pkg['type'] ?? '') === 'composer-plugin' && !in_array($name, $config['plugins_approved'], true)) {
            $errors[] = "unapproved Composer plugin: {$name} (plugins run code during composer install; approve in \"plugins_approved\" AND config.allow-plugins)";
        }
        $files = $pkg['autoload']['files'] ?? [];
        // The framework's own autoload files are covered by the integrity check, not by a human review entry.
        if ($name !== SSP_FRAMEWORK_PACKAGE && is_array($files) && $files !== []) {
            $autoloadFiles[$name] = array_values(array_map('strval', $files));
            $constraint = $config['autoload_files_reviewed'][$name] ?? null;
            if ($constraint === null) {
                $errors[] = "unreviewed autoload \"files\" (runs on EVERY request): {$name} {$version} → " . implode(', ', $autoloadFiles[$name]);
            } elseif (!ssp_version_satisfies($version, $constraint)) {
                $errors[] = "autoload \"files\" of {$name} {$version} were reviewed only for '{$constraint}' — re-review the new version and update stone-packages.json";
            }
        }
    }

    // Root composer.json: plugins must stay denied unless approved.
    $rootJson = is_file($projectRoot . '/composer.json') ? json_decode((string) file_get_contents($projectRoot . '/composer.json'), true) : null;
    if (is_array($rootJson)) {
        $allow = $rootJson['config']['allow-plugins'] ?? null;
        if ($allow === true) {
            $errors[] = 'composer.json config.allow-plugins is true (allows every plugin) — use false';
        } elseif ($allow === false) {
            // denied outright: nothing to review
        } elseif (!is_array($allow)) {
            $errors[] = 'composer.json config.allow-plugins is not set — set "allow-plugins": false to deny all plugins';
        } else {
            if ($allow === []) {
                $warnings[] = 'composer.json config.allow-plugins is an empty map: an interactive Composer run asks whether to allow a plugin — use false';
            }
            foreach ($allow as $plugin => $enabled) {
                if ($enabled === true && !in_array((string) $plugin, $config['plugins_approved'], true)) {
                    $errors[] = "composer.json allows plugin {$plugin} but it is not in stone-packages.json \"plugins_approved\"";
                }
            }
        }
        $scripts = $rootJson['scripts'] ?? [];
        foreach (SSP_INSTALL_TIME_HOOKS as $hook) {
            foreach ((array) ($scripts[$hook] ?? []) as $cmd) {
                if (!is_string($cmd) || !ssp_is_framework_post_install_command($cmd)) {
                    $errors[] = "composer.json scripts.{$hook} contains a command other than the framework's `stone post-install`: " . (is_string($cmd) ? substr($cmd, 0, 80) : 'non-string');
                }
            }
        }
    }

    if ($audit === null) {
        $warnings[] = 'composer audit was not run';
    } else {
        foreach ((array) ($audit['advisories'] ?? []) as $pkgName => $advisories) {
            foreach ((array) $advisories as $adv) {
                $errors[] = "known vulnerability in {$pkgName}: " . ($adv['advisoryId'] ?? '?') . ' — ' . ($adv['title'] ?? '') . ' ' . ($adv['link'] ?? '');
            }
        }
        foreach ((array) ($audit['abandoned'] ?? []) as $pkgName => $replacement) {
            $warnings[] = "abandoned package: {$pkgName}" . ($replacement ? " (use {$replacement})" : '');
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'autoload_files' => $autoloadFiles];
}

/** Run `composer audit --locked --no-plugins --no-scripts --format=json` (no project code runs). Returns null when composer is unavailable or output unparseable. */
function ssp_run_composer_audit(string $projectRoot): ?array
{
    $composer = trim((string) @shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '') {
        return null;
    }
    $cmd = 'cd ' . escapeshellarg($projectRoot) . ' && ' . escapeshellarg($composer) . ' audit --locked --no-interaction --no-plugins --no-scripts --format=json 2>/dev/null';
    $json = shell_exec($cmd);
    $parsed = is_string($json) ? json_decode($json, true) : null;
    return is_array($parsed) ? $parsed : null;
}

<?php
/**
 * StoneScriptPHP — sync vendor-provided optional schema
 *
 * Opt-in framework features (RequestLogging, etc.) AND installed consumer
 * modules (e.g. progalaxyelabs/stonescriptphp-invoice) ship their own SQL under
 * vendor/<package>/src/<Feature>/Schema/{tables,functions,...} (bucket layout)
 * or .../Schema/main/NNN_*.pgsql (ordered-scope layout).
 * `gateway:register-main` / `gateway:migrate-main` only ever read the platform's
 * OWN committed src/postgresql/ tree — they have no path into vendor/ — so a
 * platform could upgrade past the version that introduces a feature/module and
 * never discover it exists (see stonescriptphp-server's
 * IMPROVEMENT-SUGGESTIONS-2026-07.md for the incident this closes).
 *
 * This script scans EVERY installed progalaxyelabs/* package (via
 * vendor/composer/installed.json), not just the framework, and stages (copies,
 * never applies) every Schema/ folder it finds into src/postgresql/vendor/postgresql/
 * — a build artifact, not hand-edited, regenerated fresh on every run so it
 * always reflects whatever packages/versions are currently installed. Run by
 * stonescriptphp-server's post-install-cmd/post-update-cmd, same as the existing
 * `stone` CLI copy.
 *
 * Ordered modules (Schema/main/NNN_*.pgsql) are staged into the `migrations/`
 * bucket, namespaced per package, so the gateway applies them as one ordered,
 * run-once stream in the module's global prefix order (see the helper header).
 *
 * Deliberately NOT auto-migrated: activating what lands here is a separate,
 * explicit act (`php stone gateway:migrate-vendor-main`) a maintainer runs
 * after reviewing what changed — this script only makes the files visible.
 *
 * Usage: php vendor/progalaxyelabs/stonescriptphp/cli/sync-vendor-schema.php
 */

require_once __DIR__ . '/helpers/vendor-schema-sync.php';

$projectRoot = getcwd();
$result = syncAllVendorSchema(
    $projectRoot,
    $projectRoot . '/src/postgresql/vendor/postgresql'
);

if ($result['copied'] > 0) {
    $pkgs = $result['packages'] ?? [];
    echo "📦 Staged {$result['copied']} vendor-provided schema file(s) from " . implode(', ', $pkgs) . " into src/postgresql/vendor/\n";
    echo "   Review, then run: php stone gateway:migrate-vendor-main\n";
}

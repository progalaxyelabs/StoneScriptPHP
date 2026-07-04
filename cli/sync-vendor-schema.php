<?php
/**
 * StoneScriptPHP — sync vendor-provided optional schema
 *
 * Opt-in framework features (RequestLogging, etc.) ship their own SQL under
 * vendor/progalaxyelabs/stonescriptphp/src/<Feature>/Schema/{tables,functions,...}/.
 * `gateway:register-main` / `gateway:migrate-main` only ever read the platform's
 * OWN committed src/postgresql/ tree — they have no path into vendor/ — so a
 * platform could upgrade past the framework version that introduces a feature
 * and never discover it exists (see stonescriptphp-server's
 * IMPROVEMENT-SUGGESTIONS-2026-07.md for the incident this closes).
 *
 * This script stages (copies, never applies) every such Schema/ folder it finds
 * into src/postgresql/vendor/postgresql/ — a build artifact, not hand-edited,
 * regenerated fresh on every run so it always reflects whatever framework
 * version is currently installed. Run by stonescriptphp-server's
 * post-install-cmd/post-update-cmd, same as the existing `stone` CLI copy.
 *
 * Deliberately NOT auto-migrated: activating what lands here is a separate,
 * explicit act (`php stone gateway:migrate-vendor-main`) a maintainer runs
 * after reviewing what changed — this script only makes the files visible.
 *
 * Usage: php vendor/progalaxyelabs/stonescriptphp/cli/sync-vendor-schema.php
 */

require_once __DIR__ . '/helpers/vendor-schema-sync.php';

$projectRoot = getcwd();
$result = syncVendorSchema(
    $projectRoot . '/vendor/progalaxyelabs/stonescriptphp/src',
    $projectRoot . '/src/postgresql/vendor/postgresql'
);

if ($result['copied'] > 0) {
    echo "📦 Staged {$result['copied']} vendor-provided schema file(s) from " . implode(', ', $result['features']) . " into src/postgresql/vendor/\n";
    echo "   Review, then run: php stone gateway:migrate-vendor-main\n";
}

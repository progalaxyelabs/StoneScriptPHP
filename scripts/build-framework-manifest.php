#!/usr/bin/env php
<?php
/**
 * Writes framework-manifest.txt (sha256 of every shipped framework file) at the framework root.
 * Run as the LAST step of cutting a release, after the version in composer.json is final:
 *     php scripts/build-framework-manifest.php
 * `stone post-install` in every app verifies vendor/progalaxyelabs/stonescriptphp against it.
 * tests/Unit/PostInstallTest fails if this file is stale.
 */
require_once dirname(__DIR__) . '/cli/helpers/post-install.php';

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$version = (string) ($composer['version'] ?? '');
if ($version === '') {
    fwrite(STDERR, "composer.json has no version\n");
    exit(1);
}
$files = ssp_build_framework_manifest($root);
file_put_contents($root . '/' . SSP_MANIFEST_FILE, ssp_format_manifest($version, $files));
echo 'wrote ' . SSP_MANIFEST_FILE . ' (' . count($files) . " files, version {$version})\n";

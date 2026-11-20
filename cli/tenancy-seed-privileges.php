<?php

/**
 * php stone tenancy:seed-privileges [--tenant=<uuid>] [--dry-run]
 *
 * Applies config/tenant-privileges.php to every provisioned tenant database (idempotent, reports per tenant).
 * Thin wiring around StoneScriptPHP\Tenancy\Maintenance\TenancyFleetCommands.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . '/helpers/cli-guard.php';
cli_install_guard();
require_once __DIR__ . '/helpers/cli-env.php';
cliLoadEnv();

use StoneScriptPHP\Tenancy\Maintenance\DatabaseTenantFleet;
use StoneScriptPHP\Tenancy\Maintenance\TenancyFleetCommands;
use StoneScriptPHP\Tenancy\Provisioning\TenantPrivilegeSeed;

try {
    $grants = TenantPrivilegeSeed::fromFile(rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . '/config/tenant-privileges.php');
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: config/tenant-privileges.php is not a valid privilege seed: ' . $e->getMessage() . "\n");
    $stoneExitCode = 1;
    return;
}

$commands = new TenancyFleetCommands(
    static function (string $t): void { fwrite(STDOUT, $t); },
    static function (string $t): void { fwrite(STDERR, $t); },
    new DatabaseTenantFleet(),
);
$stoneExitCode = $commands->seedPrivileges(array_slice($_SERVER['argv'], 1), $grants);

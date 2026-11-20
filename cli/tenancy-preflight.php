<?php

/**
 * php stone tenancy:preflight
 *
 * MANDATORY before LEGACY_TENANCY is set to false: fails if any provisioned tenant has a role that users hold but that
 * has zero privileges. Read-only. Thin wiring around StoneScriptPHP\Tenancy\Maintenance\TenancyFleetCommands.
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

$commands = new TenancyFleetCommands(
    static function (string $t): void { fwrite(STDOUT, $t); },
    static function (string $t): void { fwrite(STDERR, $t); },
    new DatabaseTenantFleet(),
);
$stoneExitCode = $commands->preflight();

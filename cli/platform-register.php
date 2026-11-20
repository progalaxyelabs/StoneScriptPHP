<?php

/**
 * php stone platform:register --name "<name>" --slug <slug>
 *
 * Thin wiring around StoneScriptPHP\Tenancy\Platform\PlatformRegisterCommand (all behaviour and tests
 * live there). The operator token is read from ~/.stone/operator.env or the STONE_OPERATOR_TOKEN
 * variable of this one command, never from the application's .env. Secrets are never printed.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . '/helpers/cli-guard.php';
cli_install_guard();
require_once __DIR__ . '/helpers/cli-env.php';

use StoneScriptPHP\Tenancy\Platform\DatabasePlatformRowStore;
use StoneScriptPHP\Tenancy\Platform\PlatformRegisterCommand;
use StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient;

$argv = $_SERVER['argv'];
$args = array_slice($argv, 1);

// Help and usage errors must not need a working application environment.
$needsEnv = !in_array('--help', $args, true) && !in_array('-h', $args, true);

$projectAuthUrl = null;
if ($needsEnv) {
    $projectAuthUrl = in_array(true, array_map(static fn ($a) => str_starts_with((string) $a, '--auth-url'), $args), true)
        ? null
        : cliEnv('AUTH_SERVICE_URL');
}

$command = new PlatformRegisterCommand(
    static function (string $text): void { fwrite(STDOUT, $text); },
    static function (string $text): void { fwrite(STDERR, $text); },
    static fn (string $baseUrl): AuthTenancyClient => new AuthTenancyClient($baseUrl),
    new DatabasePlatformRowStore(),
);

// The operator token is deliberately read from the PROCESS environment (set for this one command), never
// through Env, which would also consult the application's .env and secret files.
$home = (string) (getenv('HOME') ?: ''); // @phpstan-ignore stonescriptphp.rawEnvAccess
$processEnv = [PlatformRegisterCommand::TOKEN_VAR => (string) (getenv(PlatformRegisterCommand::TOKEN_VAR) ?: '')]; // @phpstan-ignore stonescriptphp.rawEnvAccess

$stoneExitCode = $command->run($args, $processEnv, $home, rtrim(ROOT_PATH, DIRECTORY_SEPARATOR), $projectAuthUrl);

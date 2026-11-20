<?php

/**
 * php stone platform:rotate-credential [--platform-id <uuid>]
 *
 * Thin wiring around StoneScriptPHP\Tenancy\Platform\PlatformRotateCredentialCommand (behaviour and tests
 * live there). Same operator-token rules as platform:register. Secrets are never printed.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . '/helpers/cli-guard.php';
cli_install_guard();
require_once __DIR__ . '/helpers/cli-env.php';

use StoneScriptPHP\Tenancy\Platform\OperatorTokenSource;
use StoneScriptPHP\Tenancy\Platform\PlatformRotateCredentialCommand;
use StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient;

$args = array_slice($_SERVER['argv'], 1);

$projectAuthUrl = in_array(true, array_map(static fn ($a) => str_starts_with((string) $a, '--auth-url'), $args), true)
    ? null
    : cliEnv('AUTH_SERVICE_URL');

$command = new PlatformRotateCredentialCommand(
    static function (string $text): void { fwrite(STDOUT, $text); },
    static function (string $text): void { fwrite(STDERR, $text); },
    static fn (string $baseUrl): AuthTenancyClient => new AuthTenancyClient($baseUrl),
);

// The operator token is read from the PROCESS environment of this one command, never through Env (which would
// also consult the application's .env and secret files).
$home = (string) (getenv('HOME') ?: ''); // @phpstan-ignore stonescriptphp.rawEnvAccess
$processEnv = [OperatorTokenSource::TOKEN_VAR => (string) (getenv(OperatorTokenSource::TOKEN_VAR) ?: '')]; // @phpstan-ignore stonescriptphp.rawEnvAccess

$stoneExitCode = $command->run($args, $processEnv, $home, rtrim(ROOT_PATH, DIRECTORY_SEPARATOR), $projectAuthUrl);

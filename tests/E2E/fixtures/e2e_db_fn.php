<?php
// Child process: calls Database::fn() through the REAL gateway and reports what a developer would see.
// argv: 1=function 2=json params 3=mode (catch|uncaught). Env: DB_GATEWAY_URL, DB_GATEWAY_PLATFORM, DB_GATEWAY_SCHEMA_NAME.
declare(strict_types=1);

$root = dirname(__DIR__, 3);
define('DEBUG_MODE', true);
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('SRC_PATH', $root . '/src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
require $root . '/vendor/autoload.php';
ini_set('display_errors', '1');

$fn = $argv[1];
$params = json_decode($argv[2], true);
$mode = $argv[3] ?? 'catch';

if ($mode === 'uncaught') {
    \StoneScriptPHP\Database::fn($fn, $params);
    exit(0);
}
try {
    \StoneScriptPHP\Database::fn($fn, $params);
    echo "E2E_RESULT:" . json_encode(['ok' => true]) . "\n";
} catch (\Throwable $e) {
    $gw = null;
    for ($c = $e; $c !== null; $c = $c->getPrevious()) {
        if ($c instanceof \StoneScriptDB\GatewayException) {
            $gw = $c;
        }
    }
    [$status, $public] = \StoneScriptPHP\Persistence\DbErrorMapper::classify($e);
    echo "E2E_RESULT:" . json_encode([
        'ok' => false,
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'string' => (string) $e,
        'print_r' => print_r($e, true),
        'describe' => \StoneScriptPHP\Persistence\LogSanitizer::describe($e),
        'sqlstate' => $gw?->getSqlState(),
        'constraint' => $gw?->getConstraint(),
        'column' => $gw?->getColumn(),
        'table' => $gw?->getSafeContext()['table'] ?? null,
        'app_code' => $gw?->getAppErrorCode(),
        'has_raw' => $gw?->hasRawCause(),
        'raw_has_text' => $gw !== null && $gw->getRawCauseForDebug() !== null && $gw->getRawCauseForDebug() !== '',
        'mapped_status' => $status,
        'mapped_message' => $public,
    ]) . "\n";
    \StoneScriptPHP\Persistence\DbErrorMapper::logFailure($e);
}

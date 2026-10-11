<?php

/**
 * php stone env:check
 *
 * Performs the SAME environment load the running application does and exits non-zero with the reason if it
 * would refuse to boot (unreadable / unparseable .env, missing required gateway settings, invalid DB_MODE).
 *
 * Run it as the user PHP-FPM runs as - file permissions are part of what is being checked:
 *   sudo -u <fpm-user> php stone env:check
 *
 * Exit codes: 0 = environment loads cleanly, 1 = the application would not boot.
 */

use StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar;
use StoneScriptPHP\Env;
use StoneScriptPHP\Support\BootCheck;

$user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
$envFile = rtrim((string) ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . '.env';

echo "Environment check\n";
echo "  project root : " . ROOT_PATH . "\n";
echo "  running as   : $user (the application's PHP-FPM user may differ - run this as that user)\n";
if (is_dir($envFile)) {
    echo "  .env         : is a DIRECTORY (ignored at runtime; real environment variables only)\n";
} elseif (is_file($envFile)) {
    echo "  .env         : present, " . (is_readable($envFile) ? 'readable' : 'NOT READABLE by this user') . "\n";
} else {
    echo "  .env         : absent (real environment variables only)\n";
}

// bootstrap.php swallows the first failure for CLI use, and discards the singleton on a load failure,
// so ask again: the second attempt reproduces exactly what the runtime sees. The decision itself is
// StoneScriptPHP\Support\BootCheck::environment() - the very function Application::run() calls.
try {
    $env = Env::get_instance();
    $failure = BootCheck::environment($env);
    if ($failure !== null) {
        throw new \RuntimeException($failure);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFAIL: the application would refuse to boot.\n");
    fwrite(STDERR, "  " . ($e instanceof \Exception ? $e->getMessage() : get_class($e)) . "\n");
    exit(1);
}

// Checks that depend on application config (Application::run's $config) cannot be decided here. Show what they
// would say, as warnings: they only matter if the app uses tenancy / 'typed_auth' => true.
$warnings = [];
$tenant = BootCheck::tenantSchema($env);
if ($tenant !== null) {
    $warnings[] = $tenant . " (only needed if the app routes tenants; Application::run() enforces it when tenancy is configured)";
}
try {
    AuthMiddlewareRegistrar::forMode($env->AUTH_MODE, $env);
} catch (\RuntimeException $e) {
    $warnings[] = "typed_auth wiring for AUTH_MODE={$env->AUTH_MODE} would fail: " . $e->getMessage()
        . " (only matters if the app passes 'typed_auth' => true)";
}

// Public routes under the tenant URL param are exempt from tenant_url_match / store_access. Report them once
// here (CI / deploy) so they are audited; this reads routes.php only, so it cannot tell whether the guards
// are enabled - hence a warning, not a failure.
$routesFile = defined('CONFIG_PATH') ? CONFIG_PATH . 'routes.php' : null;
if ($routesFile !== null && is_file($routesFile)) {
    try {
        $checkRouter = new \StoneScriptPHP\Routing\Router();
        $checkRouter->loadRoutes(require $routesFile);
        $publicTenant = BootCheck::publicTenantRoutesWarning(BootCheck::publicTenantRoutes($checkRouter->getRouteMeta()));
        if ($publicTenant !== null) {
            $warnings[] = $publicTenant . " (matters if the app enables 'tenant_url_match' or 'store_access')";
        }
    } catch (\Throwable $e) {
        $warnings[] = 'could not read ' . basename($routesFile) . ' to list public tenant routes: ' . get_class($e);
    }
}

echo "\nOK: environment loads cleanly (AUTH_MODE={$env->AUTH_MODE}, DB_MODE={$env->DB_MODE}).\n";
foreach ($warnings as $w) {
    echo "WARN: $w\n";
}
exit(0);

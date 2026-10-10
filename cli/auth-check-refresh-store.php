<?php

/**
 * php stone auth:check-refresh-store
 *
 * Read-only readiness check for REFRESH_TOKEN_STORE=postgres: is the vendor schema (table auth_refresh_tokens and
 * the auth_rt_* functions) applied to the main database? Exit 0 = ready, 1 = not ready (message says what to run).
 * Run it in the deploy pipeline BEFORE switching REFRESH_TOKEN_STORE on, and after
 * `php stone gateway:migrate-vendor-main`.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Auth\RefreshTokens\PostgresRefreshTokenStore;

try {
    (new PostgresRefreshTokenStore())->ensureSchema();
    echo "OK: refresh-token schema is applied and usable.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "NOT READY: " . $e->getMessage() . "\n");
    exit(1);
}

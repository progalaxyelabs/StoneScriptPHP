<?php

/**
 * php stone auth:purge-refresh-tokens [--batch=1000] [--older-than=0] [--max-batches=1000]
 *
 * Deletes expired rows from auth_refresh_tokens in small batches (many short transactions, resumable;
 * never one giant delete). Schedule it (cron/systemd timer, e.g. hourly). Safe to run concurrently
 * and to interrupt. Requires REFRESH_TOKEN_STORE=postgres and the vendor schema applied.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Auth\RefreshTokens\PostgresRefreshTokenStore;

$opts = ['batch' => 1000, 'older-than' => 0, 'max-batches' => 1000];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(batch|older-than|max-batches)=(\d+)$/', $arg, $m)) {
        $opts[$m[1]] = (int) $m[2];
    }
}

$store = new PostgresRefreshTokenStore();
$store->ensureSchema();
$total = 0;
for ($i = 0; $i < max(1, $opts['max-batches']); $i++) {
    $n = $store->purgeExpired($opts['batch'], $opts['older-than']);
    $total += $n;
    if ($n === 0) {
        break;
    }
}
echo "Purged $total expired refresh token row(s).\n";

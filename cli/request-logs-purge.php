<?php

/**
 * php stone request-logs:purge [--days=30] [--batch=5000] [--max-batches=2000] [--sleep-ms=100]
 *
 * Retention for request_logs: deletes rows older than the window in small batches (many short transactions,
 * resumable, safe to interrupt and to run concurrently). Window: --days, else REQUEST_LOG_RETENTION_DAYS, else 30.
 * Schedule it daily (systemd timer templates: contrib/systemd/). Needs the request-logging vendor schema applied
 * (function rl_purge_request_logs).
 *
 * Exit codes: 0 = purge ran (complete or capped), 1 = the purge could not run (bad option, function missing, gateway down).
 */

use StoneScriptPHP\RequestLogging\RequestLogRetention;

$opts = ['days' => null, 'batch' => RequestLogRetention::DEFAULT_BATCH, 'max-batches' => RequestLogRetention::DEFAULT_MAX_BATCHES, 'sleep-ms' => 100];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(days|batch|max-batches|sleep-ms)=(\d+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (str_starts_with($arg, '--') && !in_array($arg, ['--help', '-h'], true)) {
        fwrite(STDERR, "Unknown or malformed option: $arg\n");
        exit(1);
    }
}
if ($opts['days'] !== null && (int) $opts['days'] < 1) {
    fwrite(STDERR, "--days must be a whole number >= 1\n");
    exit(1);
}

$days = RequestLogRetention::resolveDays($opts['days'] === null ? null : (string) $opts['days']);
try {
    $result = (new RequestLogRetention())->run($days, (int) $opts['batch'], (int) $opts['max-batches'], (int) $opts['sleep-ms']);
} catch (\Throwable $e) {
    fwrite(STDERR, 'request-logs:purge failed: ' . get_class($e) . "\n");
    exit(1);
}

echo "Purged {$result['deleted']} request_logs row(s) older than $days day(s) in {$result['batches']} batch(es)."
    . ($result['complete'] ? '' : ' Batch cap reached; more old rows remain, the next run continues.') . "\n";
exit(0);

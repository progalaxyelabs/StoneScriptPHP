<?php

declare(strict_types=1);

namespace StoneScriptPHP\RequestLogging;

use StoneScriptPHP\Database;

/**
 * Retention for request_logs: drives the batched `rl_purge_request_logs` SQL function in a loop.
 *
 * Every call to the function is one short transaction deleting at most $batch rows, so the purge never holds a
 * long lock and can be interrupted and resumed at any point. Used by `php stone request-logs:purge`.
 */
final class RequestLogRetention
{
    public const DEFAULT_DAYS = 30;
    public const DEFAULT_BATCH = 5000;
    public const DEFAULT_MAX_BATCHES = 2000;
    public const SQL_BATCH_CAP = 100000;

    /** @var callable(int, int): int  fn(days, batch): rows deleted */
    private $purge;

    /** @param (callable(int, int): int)|null $purge test seam; default calls the SQL function through the gateway */
    public function __construct(?callable $purge = null)
    {
        $this->purge = $purge ?? [self::class, 'callSqlFunction'];
    }

    /**
     * Retention window in days from (in order) an explicit value, REQUEST_LOG_RETENTION_DAYS, the default.
     * Anything that is not a whole number >= 1 falls back to the default: a bad setting must never mean "delete everything".
     */
    public static function resolveDays(?string $explicit = null): int
    {
        $raw = $explicit ?? ($_ENV['REQUEST_LOG_RETENTION_DAYS'] ?? $_SERVER['REQUEST_LOG_RETENTION_DAYS'] ?? getenv('REQUEST_LOG_RETENTION_DAYS'));
        if (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1 && (int) trim($raw) >= 1) {
            return (int) trim($raw);
        }
        return self::DEFAULT_DAYS;
    }

    /**
     * Purge until a batch comes back short (nothing left) or $maxBatches is reached.
     *
     * @return array{deleted:int, batches:int, complete:bool}  complete=false means the batch cap stopped the run
     *         (more old rows remain; the next scheduled run continues).
     */
    public function run(int $days, int $batch = self::DEFAULT_BATCH, int $maxBatches = self::DEFAULT_MAX_BATCHES, int $sleepMs = 0): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Retention days must be >= 1');
        }
        $batch = min(self::SQL_BATCH_CAP, max(1, $batch)); // the SQL function clamps at the same cap; a larger PHP value would read a capped batch as 'short' and stop early
        $maxBatches = max(1, $maxBatches);

        $total = 0;
        $batches = 0;
        $complete = false;
        while ($batches < $maxBatches) {
            $n = ($this->purge)($days, $batch);
            $batches++;
            $total += $n;
            if ($n < $batch) {
                $complete = true;
                break;
            }
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        return ['deleted' => $total, 'batches' => $batches, 'complete' => $complete];
    }

    /** Default purge: one rl_purge_request_logs call against the MAIN database. */
    public static function callSqlFunction(int $days, int $batch): int
    {
        $rows = self::onMain(static fn(): array => Database::fn('rl_purge_request_logs', [$days, $batch]));
        $first = $rows[0] ?? null;
        $n = is_array($first) ? ($first['o_deleted'] ?? reset($first)) : (is_object($first) ? ($first->o_deleted ?? 0) : 0);
        return (int) $n;
    }

    /** @param \Closure():array $fn */
    private static function onMain(\Closure $fn): array
    {
        if (!Database::isGatewayMode()) {
            return $fn();
        }
        $gw = Database::getGatewayClient();
        $previous = $gw->getTenantId();
        $gw->setTenantId(null); // request_logs lives in the main database
        try {
            return $fn();
        } finally {
            $gw->setTenantId($previous);
        }
    }
}

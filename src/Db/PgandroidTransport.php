<?php

declare(strict_types=1);

namespace StoneScriptPHP\Db;

use Throwable;

/**
 * DB_MODE=pgandroid — dispatches to on-device PostgreSQL via the
 * android-server C++ embed host's bridge function, forwarding to
 * libpgandroid. No PHP PG extension is involved at all: the "connection"
 * lives entirely on the native side.
 *
 * SHARED CONTRACT (pin this name/signature in exactly one place — here): the
 * C++ embed host registers a global PHP function
 *
 *     androidserver_db_exec(string $fn, string $params_json): string  // returns JSON
 *
 * — a JSON-encoded array of positional params in, a JSON-encoded array of
 * result rows out. This class is the ONLY place in the framework that calls
 * it. Any android-server code generator and the C++ embed host itself must
 * match this exact name and signature.
 *
 * Only valid inside the android-server embed host, where that global
 * function is registered at startup — DB_MODE=pgandroid anywhere else fails
 * loud the first time Database::fn() is called (not at boot, matching how
 * DirectTransport only validates the pdo_pgsql extension lazily too).
 *
 * PINNED BRIDGE ERROR-SIGNALING CONTRACT (the libphpandroid C++ host must
 * implement this exact half; PHP-side classification lives in
 * isConnectionFailure()/extractSqlstate() below, reusing DirectTransport's
 * SQLSTATE table — no parallel classification table exists in this
 * framework):
 *
 *   - SUCCESS: androidserver_db_exec() returns a JSON array of result rows
 *     (the bridge itself unwraps pgandroid's {status:ok,rows,rowcount}
 *     envelope before returning — this class never sees that envelope).
 *
 *   - QUERY ERROR (bad SQL, RAISE EXCEPTION, constraint violation — i.e. the
 *     engine is alive and answered with a Postgres error): the bridge
 *     THROWS a PHP Throwable whose getMessage() contains the literal
 *     substring `SQLSTATE[xxxxx]` (5-char Postgres SQLSTATE code in
 *     brackets — the exact convention PDO already uses, e.g.
 *     `SQLSTATE[23505]: duplicate key value violates unique constraint`).
 *     Classified here via the SQLSTATE, exactly like DirectTransport
 *     classifies PDOException: 500 unless the code happens to fall in
 *     DirectTransport::CONNECTION_FAILURE_SQLSTATES (e.g. a genuine 08xxx/
 *     57xxx mid-query disconnect), in which case 503.
 *
 *   - ENGINE UNUSABLE (pgandroid not initialized yet, or
 *     pgandroid_exec_params() returned NULL — OOM/native crash, i.e. no
 *     Postgres error was ever produced): the bridge THROWS a PHP Throwable
 *     whose getMessage() does NOT contain a `SQLSTATE[xxxxx]` substring.
 *     Absence of a parseable SQLSTATE IS the distinct connection-failure
 *     signal — always classified as a 503 connection failure, matching the
 *     gateway's `connection_failed`.
 */
final class PgandroidTransport implements DbTransport
{
    private bool $connected = false;

    /** @var callable(string $fn, string $paramsJson): string */
    private $bridge;

    /**
     * @param callable(string $fn, string $paramsJson): string|null $bridge
     *   Injectable for unit tests to mock the native bridge without a
     *   device/embed host. Defaults to calling the global
     *   androidserver_db_exec() function registered by the C++ host.
     */
    public function __construct(?callable $bridge = null)
    {
        $this->bridge = $bridge ?? function (string $fn, string $paramsJson): string {
            if (!function_exists('androidserver_db_exec')) {
                throw new \Exception(
                    "PgandroidTransport: androidserver_db_exec() is not registered. " .
                    "DB_MODE=pgandroid is only valid inside the android-server C++ embed host, " .
                    "which registers this bridge function at startup."
                );
            }

            /** @var string $result */
            $result = androidserver_db_exec($fn, $paramsJson);
            return $result;
        };
    }

    public function callFunction(string $function_name, array $params): array
    {
        // Normalize associative params to positional order — same convention
        // GatewayClient::callFunction() / DirectTransport already apply.
        $positional = array_is_list($params) ? $params : array_values($params);

        $paramsJson = json_encode($positional);
        if ($paramsJson === false) {
            throw new DbTransportException(
                'PgandroidTransport: failed to encode params as JSON: ' . json_last_error_msg()
            );
        }

        try {
            $resultJson = ($this->bridge)($function_name, $paramsJson);
        } catch (Throwable $e) {
            // Classify by SQLSTATE, mirroring DirectTransport — a query-level
            // failure (bad SQL, business-rule RAISE EXCEPTION, constraint
            // violation) reaches a LIVE engine and must map to 500, not 503.
            // Only a genuinely engine-unusable condition (not initialized,
            // OOM) is a connection failure. See the class-level "PINNED
            // BRIDGE ERROR-SIGNALING CONTRACT" docblock above.
            throw new DbTransportException(
                'pgandroid bridge call failed: ' . $e->getMessage(),
                isConnectionFailure: $this->isConnectionFailure($e),
                previous: $e
            );
        }

        $decoded = json_decode($resultJson, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new DbTransportException(
                'PgandroidTransport: failed to decode bridge response as JSON: ' . json_last_error_msg()
            );
        }

        if (!is_array($decoded)) {
            throw new DbTransportException(
                'PgandroidTransport: bridge returned a non-array JSON response (' . get_debug_type($decoded) . ')'
            );
        }

        $this->connected = true;

        return $decoded;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Classifies a bridge-thrown Throwable per the pinned error-signaling
     * contract (see class docblock): extract a `SQLSTATE[xxxxx]` code from
     * the message if present and reuse DirectTransport's connection-failure
     * SQLSTATE table (single source of truth, not duplicated here) to
     * decide 500 vs 503. No SQLSTATE in the message means the bridge is
     * signaling an engine-unusable condition (not initialized / OOM) —
     * always a connection failure.
     */
    private function isConnectionFailure(Throwable $e): bool
    {
        $sqlstate = $this->extractSqlstate($e->getMessage());

        if ($sqlstate === null) {
            return true;
        }

        return in_array($sqlstate, DirectTransport::CONNECTION_FAILURE_SQLSTATES, true);
    }

    /**
     * Extracts a 5-character Postgres SQLSTATE from a `SQLSTATE[xxxxx]`
     * substring, matching the exact format PDO already uses (and that
     * DirectTransportTest's fixtures already assume) — the format the
     * pinned bridge contract requires the C++ host to emit for query-level
     * errors.
     */
    private function extractSqlstate(string $message): ?string
    {
        if (preg_match('/SQLSTATE\[([0-9A-Za-z]{5})\]/', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

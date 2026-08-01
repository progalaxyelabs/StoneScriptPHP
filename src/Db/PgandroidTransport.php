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
            // The bridge call itself failing (host unreachable/not registered,
            // native crash surfaced as a PHP exception) is treated as a
            // connection-level failure — the pgandroid analogue of the
            // gateway's connection_failed. A query-level failure (bad SQL,
            // business-rule RAISE EXCEPTION) is expected to come back as a
            // normal JSON response, handled below, not as a thrown exception.
            throw new DbTransportException(
                'pgandroid bridge call failed: ' . $e->getMessage(),
                isConnectionFailure: true,
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
}

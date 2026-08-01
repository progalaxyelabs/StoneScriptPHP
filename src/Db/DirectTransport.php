<?php

declare(strict_types=1);

namespace StoneScriptPHP\Db;

use PDO;
use PDOException;
use StoneScriptPHP\Database\DbConnectionPool;

/**
 * DB_MODE=direct — talks to PostgreSQL directly via PDO (SELECT * FROM
 * fn($1, ...)), no gateway hop. Useful for simple single-DB deployments
 * that don't need the gateway's multi-tenant schema routing.
 *
 * Env config: DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD. Needs the
 * pdo_pgsql PHP extension.
 *
 * Reuses the framework's existing StoneScriptPHP\Database\DbConnectionPool
 * (pre-existing, previously-unused-by-Database::fn() PDO pooling helper) for
 * connection reuse across calls in the same process, instead of opening a
 * fresh connection per Database::fn() call.
 */
final class DirectTransport implements DbTransport
{
    private bool $connected = false;

    /**
     * SQLSTATE class 08 (connection exception) + Postgres admin-shutdown /
     * crash codes — the set of PDO error codes that mean "the database is
     * unreachable" rather than "the query failed against a live connection".
     * Mirrors the distinction GatewayException::getGatewayError() ===
     * 'connection_failed' draws for gateway mode.
     */
    private const CONNECTION_FAILURE_SQLSTATES = [
        '08000', '08001', '08003', '08004', '08006', '08007', '08P01',
        '57P01', '57P02', '57P03',
    ];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $dbname,
        private readonly string $user,
        private readonly ?string $password,
        private readonly ?DbConnectionPool $pool = null
    ) {
        if (!extension_loaded('pdo_pgsql')) {
            throw new \Exception(
                'DirectTransport requires the pdo_pgsql PHP extension. ' .
                'DB_MODE=direct needs ext-pdo_pgsql installed and enabled.'
            );
        }
    }

    public function callFunction(string $function_name, array $params): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $function_name)) {
            throw new \Exception("DirectTransport: invalid database function name '{$function_name}'");
        }

        // Normalize associative params to positional order — same convention
        // GatewayClient::callFunction() already applies (see GatewayClient.php).
        $positional = array_is_list($params) ? $params : array_values($params);
        $placeholders = implode(', ', array_fill(0, count($positional), '?'));

        $sql = "SELECT * FROM {$function_name}({$placeholders})";

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($positional);
            // PDO::ATTR_ERRMODE_EXCEPTION (set in pdo(), via DbConnectionPool's
            // default options) means fetchAll() throws PDOException on failure
            // rather than returning false, so the result here is always an array.
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->connected = true;

            return $rows;
        } catch (PDOException $e) {
            throw new DbTransportException(
                "Direct database function call failed: " . $e->getMessage(),
                isConnectionFailure: $this->isConnectionFailure($e),
                previous: $e
            );
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    private function pdo(): PDO
    {
        try {
            return $this->pool()->getConnection($this->dbname, [
                'driver' => 'pgsql',
                'host' => $this->host,
                'port' => $this->port,
                'user' => $this->user,
                'password' => $this->password ?? '',
            ]);
        } catch (PDOException $e) {
            throw new DbTransportException(
                "Direct database connection failed: " . $e->getMessage(),
                isConnectionFailure: true,
                previous: $e
            );
        }
    }

    private function pool(): DbConnectionPool
    {
        return $this->pool ?? DbConnectionPool::getInstance();
    }

    private function isConnectionFailure(PDOException $e): bool
    {
        $sqlstate = is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? null) : null;
        $sqlstate ??= $e->getCode();

        return in_array((string) $sqlstate, self::CONNECTION_FAILURE_SQLSTATES, true);
    }
}

<?php

namespace StoneScriptPHP\Database;

/**
 * Interface for database connections.
 *
 * Supports both direct PostgreSQL connections and gateway-based connections
 * for enterprise multi-tenant platforms.
 */
interface ConnectionInterface
{
    /**
     * Call a database function with the given parameters.
     *
     * @param string $function_name The name of the database function to call
     * @param array $params The parameters to pass to the function
     * @return array The result rows from the function call
     */
    public function callFunction(string $function_name, array $params): array;

    /**
     * Check if the connection is established and available.
     *
     * @return bool True if connected, false otherwise
     */
    public function isConnected(): bool;
}

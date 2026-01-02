<?php

namespace StoneScriptPHP\Database;

use Exception;
use StoneScriptPHP\Env;

/**
 * Gateway-based database connection implementation.
 *
 * Uses HTTP POST requests to a stonescriptdb-gateway service for database operations.
 * Designed for enterprise multi-tenant platforms where direct database access is not available.
 */
class GatewayConnection implements ConnectionInterface
{
    private string $gateway_url;
    private ?string $platform;
    private ?string $tenant_id;
    private bool $connected = false;
    private ?string $last_error = null;

    /**
     * Create a new gateway connection.
     *
     * @param string $gateway_url The URL of the gateway service
     * @param string|null $platform The platform identifier
     * @param string|null $tenant_id The tenant identifier
     */
    public function __construct(string $gateway_url, ?string $platform = null, ?string $tenant_id = null)
    {
        $this->gateway_url = rtrim($gateway_url, '/');
        $this->platform = $platform;
        $this->tenant_id = $tenant_id;
    }

    /**
     * Create a GatewayConnection from environment configuration.
     *
     * @return self
     * @throws Exception If gateway URL is not configured
     */
    public static function fromEnv(): self
    {
        $env = Env::get_instance();

        if (empty($env->DB_GATEWAY_URL)) {
            throw new Exception('DB_GATEWAY_URL must be configured for gateway connection mode');
        }

        return new self(
            $env->DB_GATEWAY_URL,
            $env->DB_GATEWAY_PLATFORM,
            $env->DB_GATEWAY_TENANT_ID
        );
    }

    /**
     * {@inheritdoc}
     */
    public function callFunction(string $function_name, array $params): array
    {
        $url = $this->gateway_url . '/call';

        $payload = [
            'platform' => $this->platform,
            'tenant_id' => $this->tenant_id,
            'function' => $function_name,
            'params' => $params
        ];

        $start_time = microtime(true);

        try {
            $response = $this->httpPost($url, $payload);
            $elapsed_time = microtime(true) - $start_time;

            log_debug(__METHOD__ . " Gateway call to $function_name took " . ($elapsed_time * 1000) . "ms");

            if (!isset($response['rows'])) {
                $this->last_error = 'Invalid gateway response: missing rows field';
                log_debug(__METHOD__ . ' ' . $this->last_error);
                return [];
            }

            $this->connected = true;

            // Log execution time from gateway if available
            if (isset($response['execution_time_ms'])) {
                log_debug(__METHOD__ . " Gateway reported execution time: {$response['execution_time_ms']}ms");
            }

            return $response['rows'];
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            log_debug(__METHOD__ . ' Exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get the last error message.
     *
     * @return string|null The last error message or null if no error
     */
    public function getLastError(): ?string
    {
        return $this->last_error;
    }

    /**
     * Set the tenant ID for subsequent requests.
     *
     * @param string|null $tenant_id The tenant identifier
     * @return void
     */
    public function setTenantId(?string $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    /**
     * Get the current tenant ID.
     *
     * @return string|null The current tenant identifier
     */
    public function getTenantId(): ?string
    {
        return $this->tenant_id;
    }

    /**
     * Set the platform identifier for subsequent requests.
     *
     * @param string|null $platform The platform identifier
     * @return void
     */
    public function setPlatform(?string $platform): void
    {
        $this->platform = $platform;
    }

    /**
     * Get the current platform identifier.
     *
     * @return string|null The current platform identifier
     */
    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    /**
     * Perform an HTTP POST request to the gateway.
     *
     * @param string $url The URL to post to
     * @param array $data The data to send as JSON
     * @return array The decoded JSON response
     * @throws Exception If the request fails
     */
    private function httpPost(string $url, array $data): array
    {
        $json_payload = json_encode($data);

        if ($json_payload === false) {
            throw new Exception('Failed to encode request payload as JSON: ' . json_last_error_msg());
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new Exception('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json_payload)
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);

        if ($curl_errno !== 0) {
            throw new Exception("Gateway request failed: $curl_error (errno: $curl_errno)");
        }

        if ($http_code !== 200) {
            $error_message = "Gateway returned HTTP $http_code";

            // Try to extract error message from response
            if ($response !== false && !empty($response)) {
                $error_body = json_decode($response, true);
                if (is_array($error_body) && isset($error_body['error'])) {
                    $error_message .= ': ' . $error_body['error'];
                }
            }

            throw new Exception($error_message);
        }

        if ($response === false || $response === '') {
            throw new Exception('Empty response from gateway');
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode gateway response: ' . json_last_error_msg());
        }

        return $decoded;
    }
}

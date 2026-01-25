<?php

namespace StoneScriptPHP\Auth\Client;

/**
 * Base HTTP client for ProGalaxy Auth Service
 *
 * Provides common HTTP methods for interacting with the authentication service.
 * Used by MembershipClient and InvitationClient.
 *
 * @package StoneScriptPHP\Auth\Client
 */
abstract class AuthServiceClient
{
    protected string $authServiceUrl;
    protected int $timeout = 30;
    protected int $connectTimeout = 10;

    /**
     * Create a new auth service client
     *
     * @param string|null $authServiceUrl Auth service URL (defaults to config)
     */
    public function __construct(?string $authServiceUrl = null)
    {
        $this->authServiceUrl = rtrim(
            $authServiceUrl ?? $this->getDefaultAuthServiceUrl(),
            '/'
        );

        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Auth service client requires the curl extension');
        }
    }

    /**
     * Get default auth service URL from config
     */
    protected function getDefaultAuthServiceUrl(): string
    {
        // Try to load from config
        $configFile = defined('ROOT_PATH') ? ROOT_PATH . 'config/auth.php' : null;

        if ($configFile && file_exists($configFile)) {
            $config = require $configFile;
            return $config['auth_service_url'] ?? 'http://localhost:3139';
        }

        return 'http://localhost:3139';
    }

    /**
     * Set request timeout in seconds
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set connection timeout in seconds
     */
    public function setConnectTimeout(int $timeout): self
    {
        $this->connectTimeout = $timeout;
        return $this;
    }

    /**
     * Perform HTTP GET request
     *
     * @param string $endpoint API endpoint (e.g., '/memberships')
     * @param array $headers Additional headers
     * @return array Decoded JSON response
     * @throws AuthServiceException
     */
    protected function get(string $endpoint, array $headers = []): array
    {
        $url = $this->authServiceUrl . $endpoint;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new AuthServiceException('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout
        ]);

        return $this->executeCurl($ch);
    }

    /**
     * Perform HTTP POST request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @param array $headers Additional headers
     * @return array Decoded JSON response
     * @throws AuthServiceException
     */
    protected function post(string $endpoint, array $data, array $headers = []): array
    {
        $url = $this->authServiceUrl . $endpoint;
        $jsonPayload = json_encode($data);

        if ($jsonPayload === false) {
            throw new AuthServiceException('Failed to encode request as JSON: ' . json_last_error_msg());
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new AuthServiceException('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout
        ]);

        return $this->executeCurl($ch);
    }

    /**
     * Perform HTTP PUT request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @param array $headers Additional headers
     * @return array Decoded JSON response
     * @throws AuthServiceException
     */
    protected function put(string $endpoint, array $data, array $headers = []): array
    {
        $url = $this->authServiceUrl . $endpoint;
        $jsonPayload = json_encode($data);

        if ($jsonPayload === false) {
            throw new AuthServiceException('Failed to encode request as JSON: ' . json_last_error_msg());
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new AuthServiceException('Failed to initialize curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout
        ]);

        return $this->executeCurl($ch);
    }

    /**
     * Execute curl request and handle response
     *
     * @param resource $ch cURL handle
     * @return array Decoded JSON response
     * @throws AuthServiceException
     */
    private function executeCurl($ch): array
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new AuthServiceException(
                "Auth service request failed: $curlError (errno: $curlErrno)"
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = "Auth service returned HTTP $httpCode";

            if ($response !== false && !empty($response)) {
                $errorBody = json_decode($response, true);
                if (is_array($errorBody) && isset($errorBody['error'])) {
                    $errorMessage .= ': ' . $errorBody['error'];
                } elseif (is_array($errorBody) && isset($errorBody['message'])) {
                    $errorMessage .= ': ' . $errorBody['message'];
                }
            }

            throw new AuthServiceException($errorMessage, $httpCode);
        }

        if ($response === false || $response === '') {
            throw new AuthServiceException('Empty response from auth service');
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AuthServiceException('Failed to decode auth service response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Build authorization header from JWT token
     *
     * @param string|null $token JWT token
     * @return array Authorization header array
     */
    protected function buildAuthHeader(?string $token): array
    {
        if ($token === null) {
            return [];
        }

        return ['Authorization: Bearer ' . $token];
    }
}

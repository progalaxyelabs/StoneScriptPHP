<?php

namespace App\Services;

/**
 * Service Client
 *
 * HTTP client for inter-service communication
 */
class ServiceClient
{
    private string $serviceName;
    private string $baseUrl;
    private int $timeout;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
        $this->baseUrl = $this->getServiceUrl($serviceName);
        $this->timeout = 5000; // 5 seconds
    }

    /**
     * Make GET request to another service
     */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * Make POST request to another service
     */
    public function post(string $path, array $data = [], array $headers = []): array
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /**
     * Make PUT request to another service
     */
    public function put(string $path, array $data = [], array $headers = []): array
    {
        return $this->request('PUT', $path, $data, $headers);
    }

    /**
     * Make DELETE request to another service
     */
    public function delete(string $path, array $headers = []): array
    {
        return $this->request('DELETE', $path, null, $headers);
    }

    /**
     * Execute HTTP request
     */
    private function request(string $method, string $path, ?array $data = null, array $headers = []): array
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
        ]);

        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new \Exception("Service call failed: {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new \Exception(
                "Service returned error: " . ($decoded['message'] ?? 'Unknown error'),
                $httpCode
            );
        }

        return $decoded ?? [];
    }

    /**
     * Build HTTP headers
     */
    private function buildHeaders(array $customHeaders = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Service-Name: ' . env('SERVICE_NAME', 'microservice'),
        ];

        foreach ($customHeaders as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        return $headers;
    }

    /**
     * Get service URL from configuration
     */
    private function getServiceUrl(string $serviceName): string
    {
        // Load service registry from config
        $services = require __DIR__ . '/../Config/services.php';

        if (!isset($services[$serviceName])) {
            throw new \Exception("Service '{$serviceName}' not found in registry");
        }

        return $services[$serviceName]['url'];
    }
}

<?php

namespace Framework\Routing\Middleware;

use Framework\Routing\MiddlewareInterface;
use Framework\ApiResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $windowSeconds;
    private string $storageFile;
    private array $excludedPaths;

    /**
     * @param int $maxRequests Maximum number of requests allowed in the time window
     * @param int $windowSeconds Time window in seconds (default: 60)
     * @param string|null $storageFile File path to store rate limit data (default: temp file)
     * @param array $excludedPaths Paths excluded from rate limiting
     */
    public function __construct(
        int $maxRequests = 60,
        int $windowSeconds = 60,
        ?string $storageFile = null,
        array $excludedPaths = []
    ) {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storageFile = $storageFile ?? sys_get_temp_dir() . '/stonescript_ratelimit.json';
        $this->excludedPaths = $excludedPaths;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // Check if path is excluded from rate limiting
        foreach ($this->excludedPaths as $excludedPath) {
            if ($this->matchesPath($path, $excludedPath)) {
                return $next($request);
            }
        }

        $identifier = $this->getIdentifier();
        $currentTime = time();

        // Load rate limit data
        $data = $this->loadData();

        // Initialize client data if not exists
        if (!isset($data[$identifier])) {
            $data[$identifier] = [
                'requests' => [],
                'blocked_until' => 0
            ];
        }

        // Check if client is currently blocked
        if ($data[$identifier]['blocked_until'] > $currentTime) {
            $remainingTime = $data[$identifier]['blocked_until'] - $currentTime;
            log_debug("Rate limit: Client $identifier is blocked for $remainingTime more seconds");
            http_response_code(429);

            header('Retry-After: ' . $remainingTime);
            header('X-RateLimit-Limit: ' . $this->maxRequests);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $data[$identifier]['blocked_until']);

            return new ApiResponse('error', 'Too many requests. Please try again later.');
        }

        // Remove old requests outside the time window
        $windowStart = $currentTime - $this->windowSeconds;
        $data[$identifier]['requests'] = array_filter(
            $data[$identifier]['requests'],
            fn($timestamp) => $timestamp > $windowStart
        );

        // Count requests in current window
        $requestCount = count($data[$identifier]['requests']);

        // Check if limit exceeded
        if ($requestCount >= $this->maxRequests) {
            // Block for the window duration
            $data[$identifier]['blocked_until'] = $currentTime + $this->windowSeconds;
            $this->saveData($data);

            log_debug("Rate limit exceeded for client $identifier");
            http_response_code(429);

            header('Retry-After: ' . $this->windowSeconds);
            header('X-RateLimit-Limit: ' . $this->maxRequests);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . ($currentTime + $this->windowSeconds));

            return new ApiResponse('error', 'Rate limit exceeded. Please try again later.');
        }

        // Add current request
        $data[$identifier]['requests'][] = $currentTime;
        $this->saveData($data);

        // Add rate limit headers
        $remaining = $this->maxRequests - ($requestCount + 1);
        header('X-RateLimit-Limit: ' . $this->maxRequests);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . ($currentTime + $this->windowSeconds));

        // Continue to next middleware
        return $next($request);
    }

    /**
     * Get unique identifier for the client (IP address)
     */
    private function getIdentifier(): string
    {
        // Try to get real IP (considering proxies)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ??
              $_SERVER['HTTP_X_REAL_IP'] ??
              $_SERVER['REMOTE_ADDR'] ??
              'unknown';

        // If multiple IPs, take the first one
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }

        return trim($ip);
    }

    /**
     * Load rate limit data from storage
     */
    private function loadData(): array
    {
        if (!file_exists($this->storageFile)) {
            return [];
        }

        $content = file_get_contents($this->storageFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save rate limit data to storage
     */
    private function saveData(array $data): void
    {
        // Clean up old entries (more than 2x window time)
        $cleanupTime = time() - ($this->windowSeconds * 2);
        foreach ($data as $identifier => $clientData) {
            if (empty($clientData['requests']) && $clientData['blocked_until'] < $cleanupTime) {
                unset($data[$identifier]);
            }
        }

        file_put_contents($this->storageFile, json_encode($data));
    }

    /**
     * Check if a path matches a pattern (supports wildcards)
     */
    private function matchesPath(string $path, string $pattern): bool
    {
        if ($path === $pattern) {
            return true;
        }

        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $path);
    }
}

<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

class LoggingMiddleware implements MiddlewareInterface
{
    private bool $logRequests;
    private bool $logResponses;
    private bool $logTiming;

    /**
     * @param bool $logRequests Whether to log incoming requests (default: true)
     * @param bool $logResponses Whether to log responses (default: false)
     * @param bool $logTiming Whether to log request timing (default: true)
     */
    public function __construct(
        bool $logRequests = true,
        bool $logResponses = false,
        bool $logTiming = true
    ) {
        $this->logRequests = $logRequests;
        $this->logResponses = $logResponses;
        $this->logTiming = $logTiming;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $startTime = microtime(true);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $path = $_SERVER['REQUEST_URI'] ?? '/';

        if ($this->logRequests) {
            $logMessage = "Request: $method $path";
            if (!empty($request['input'])) {
                $logMessage .= ' | Body: ' . json_encode($request['input']);
            }
            log_debug($logMessage);
        }

        // Continue to next middleware
        $response = $next($request);

        // Log after processing
        $duration = microtime(true) - $startTime;

        if ($this->logTiming) {
            log_debug("Request completed in " . round($duration * 1000, 2) . "ms");
        }

        if ($this->logResponses && $response instanceof ApiResponse) {
            log_debug("Response: status={$response->status}, message={$response->message}");
        }

        return $response;
    }
}

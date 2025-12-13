<?php

namespace Framework\Http\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    private bool $allowCredentials;
    private int $maxAge;

    /**
     * @param array $allowedOrigins Array of allowed origins (e.g., ['https://example.com'])
     * @param array $allowedMethods Array of allowed HTTP methods (default: ['GET', 'POST', 'OPTIONS'])
     * @param array $allowedHeaders Array of allowed headers (default: common headers)
     * @param bool $allowCredentials Whether to allow credentials (default: true)
     * @param int $maxAge Max age for preflight cache in seconds (default: 900)
     */
    public function __construct(
        array $allowedOrigins = [],
        array $allowedMethods = ['GET', 'POST', 'OPTIONS'],
        array $allowedHeaders = ['Alt-Used', 'Content-Type', 'Authorization'],
        bool $allowCredentials = true,
        int $maxAge = 900
    ) {
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
        $this->allowCredentials = $allowCredentials;
        $this->maxAge = $maxAge;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $origin = strtolower($_SERVER['HTTP_ORIGIN'] ?? '');

        // Add CORS headers
        if (!empty($origin) && in_array($origin, $this->allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));

        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Max-Age: ' . $this->maxAge);
        header('Vary: Origin');

        // Handle preflight OPTIONS request
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            return new ApiResponse('ok', 'Preflight OK', []);
        }

        // Continue to next middleware
        return $next($request);
    }
}

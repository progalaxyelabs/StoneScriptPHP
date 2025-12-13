<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private array $headers;

    /**
     * @param array $customHeaders Custom security headers to add
     */
    public function __construct(array $customHeaders = [])
    {
        // Default security headers
        $defaultHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];

        $this->headers = array_merge($defaultHeaders, $customHeaders);
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Add security headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Continue to next middleware
        return $next($request);
    }
}

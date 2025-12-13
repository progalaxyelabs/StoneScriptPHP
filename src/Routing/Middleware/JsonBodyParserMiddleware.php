<?php

namespace Framework\Routing\Middleware;

use Framework\Routing\MiddlewareInterface;
use Framework\ApiResponse;

class JsonBodyParserMiddleware implements MiddlewareInterface
{
    private bool $strict;

    /**
     * @param bool $strict If true, only accept application/json content type (default: false)
     */
    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        // Only parse body for POST, PUT, PATCH requests
        if (!in_array($method, ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $mediaType = explode(';', $contentType)[0];
        $mediaType = trim($mediaType);

        // Check content type if strict mode
        if ($this->strict && $mediaType !== 'application/json') {
            log_debug("JsonBodyParser: Unsupported Content-Type: $contentType");
            http_response_code(415);
            return new ApiResponse('error', 'Content-Type must be application/json');
        }

        // Parse JSON body
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody)) {
            // Empty body is acceptable
            $request['body'] = [];
            $request['raw_body'] = '';
            return $next($request);
        }

        $parsedBody = json_decode($rawBody, true);

        if ($parsedBody === null && json_last_error() !== JSON_ERROR_NONE) {
            log_debug('JsonBodyParser: Invalid JSON - ' . json_last_error_msg());
            http_response_code(400);
            return new ApiResponse('error', 'Invalid JSON: ' . json_last_error_msg());
        }

        // Add parsed body to request
        $request['body'] = $parsedBody ?? [];
        $request['raw_body'] = $rawBody;

        log_debug('JsonBodyParser: Successfully parsed JSON body');

        return $next($request);
    }
}

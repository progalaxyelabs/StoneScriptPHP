<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

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
        array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Alt-Used', 'Content-Type', 'Authorization'],
        bool $allowCredentials = true,
        int $maxAge = 900
    ) {
        // Deliberately NOT a wildcard-match-all: '*' is stripped (with a loud
        // warning, not a silent no-op) rather than special-cased to mean "match
        // any origin". Access-Control-Allow-Credentials is always sent true
        // below, and Access-Control-Allow-Origin: * combined with credentials is
        // both rejected by browsers and unsafe (any site could make credentialed
        // requests). If a caller configures '*', that's almost certainly someone
        // trying to restore the old broken-fallback behavior, not an intentional
        // "allow every origin, no exceptions" design — hence the warning instead
        // of quietly dropping it.
        if (in_array('*', $allowedOrigins, true)) {
            log_warning(
                "CorsMiddleware: '*' found in allowedOrigins — ignored. Wildcard " .
                'origins are not supported (Access-Control-Allow-Credentials is ' .
                'always sent true here, and Origin:* + credentials is invalid/unsafe). ' .
                'List explicit origins instead.'
            );
            $allowedOrigins = array_values(array_filter($allowedOrigins, fn($o) => $o !== '*'));
        }

        // Lowercase for case-insensitive matching against $origin below, which is
        // also lowercased — a configured 'https://MyApp.com' must still match an
        // incoming 'Origin: https://myapp.com'.
        $this->allowedOrigins = array_map('strtolower', $allowedOrigins);
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
        $this->allowCredentials = $allowCredentials;
        $this->maxAge = $maxAge;
    }

    /**
     * Pure decision logic, deliberately separated from handle() so it's
     * testable without depending on xdebug_get_headers() (not reliably
     * available — routinely disabled in prod for performance) or any other
     * mechanism for intercepting PHP's global header() calls.
     *
     * @return string|null The exact origin string to echo back in
     *   Access-Control-Allow-Origin, or null if $origin should not be
     *   granted the header at all (fail-closed default: not in the list).
     */
    public function resolveAllowedOrigin(string $origin): ?string
    {
        $origin = strtolower($origin);
        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            return null;
        }
        return $origin;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $allowOrigin = $this->resolveAllowedOrigin($_SERVER['HTTP_ORIGIN'] ?? '');

        if ($allowOrigin !== null) {
            header("Access-Control-Allow-Origin: $allowOrigin");
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

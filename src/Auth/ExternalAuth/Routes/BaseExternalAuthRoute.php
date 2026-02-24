<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\Client\AuthServiceException;

/**
 * Abstract base class for all ExternalAuth route handlers
 *
 * Provides shared infrastructure: client access, hook invocation,
 * auth header extraction, and standardized error handling.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
abstract class BaseExternalAuthRoute implements IRouteHandler
{
    protected ExternalAuthServiceClient $client;
    protected array $hooks;
    protected ExternalAuthConfig $config;

    /**
     * @param ExternalAuthServiceClient $client HTTP client for auth service
     * @param array $hooks Lifecycle hooks from config
     * @param ExternalAuthConfig $config Full config object
     */
    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config
    ) {
        $this->client = $client;
        $this->hooks = $hooks;
        $this->config = $config;
    }

    /**
     * Wrap an auth service call with error handling and optional hook invocation
     *
     * @param callable $call Function that calls the auth service client
     * @param string|null $hookName Hook to invoke on success (e.g., 'after_login')
     * @param array|null $input Original input data passed to the hook
     * @return ApiResponse
     */
    protected function proxyCall(callable $call, ?string $hookName = null, ?array $input = null): ApiResponse
    {
        try {
            $result = $call();

            // Invoke hook on success (hook exceptions are caught and logged, never affect response)
            if ($hookName !== null && isset($this->hooks[$hookName]) && is_callable($this->hooks[$hookName])) {
                try {
                    ($this->hooks[$hookName])($result, $input);
                } catch (\Throwable $e) {
                    log_error("ExternalAuth hook '$hookName' failed: " . $e->getMessage());
                }
            }

            return res_ok($result);
        } catch (AuthServiceException $e) {
            $httpCode = $e->getCode();
            if ($httpCode >= 400 && $httpCode < 600) {
                http_response_code($httpCode);
            } else {
                http_response_code(502);
            }
            return res_error($e->getMessage());
        } catch (\Throwable $e) {
            log_error('ExternalAuth proxy error: ' . $e->getMessage());
            http_response_code(502);
            return res_error('Authentication service unavailable');
        }
    }

    /**
     * Extract the Authorization header from the current request
     *
     * @return string|null The raw Authorization header value, or null
     */
    protected function getAuthHeader(): ?string
    {
        // Try getallheaders() first (Apache/FPM)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // Headers are case-insensitive per HTTP spec
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return $value;
                }
            }
        }

        // Fallback to $_SERVER
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        // Apache mod_rewrite fallback
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return null;
    }
}

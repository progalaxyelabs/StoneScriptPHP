<?php

namespace StoneScriptPHP\Routing;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Binding\TypedArray;

class MiddlewarePipeline
{
    private array $middleware = [];
    private int $index = 0;

    /**
     * Add middleware to the pipeline
     *
     * @param MiddlewareInterface $middleware
     * @return self
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Add multiple middleware to the pipeline
     *
     * @param array $middlewares Array of MiddlewareInterface instances
     * @return self
     */
    public function pipes(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->pipe($middleware);
        }
        return $this;
    }

    /**
     * The installed middleware instances, in pipe order.
     *
     * Read-only introspection — used by boot-time wiring assertions (e.g.
     * AuthMiddlewareRegistrar::assertFullyWired) to verify the pipeline enforces
     * every declared route access model.
     *
     * @return TypedArray<MiddlewareInterface>
     */
    public function getMiddleware(): TypedArray
    {
        return new TypedArray(MiddlewareInterface::class, $this->middleware);
    }

    /**
     * Execute the middleware pipeline
     *
     * @param array $request The request data
     * @param callable $finalHandler The final handler to call if all middleware passes
     * @return ApiResponse
     */
    public function process(array $request, callable $finalHandler): ApiResponse
    {
        $this->index = 0;
        return $this->next($request, $finalHandler);
    }

    /**
     * Execute the next middleware in the pipeline
     *
     * @param array $request
     * @param callable $finalHandler
     * @return ApiResponse
     */
    private function next(array $request, callable $finalHandler): ApiResponse
    {
        // If we've processed all middleware, call the final handler
        if ($this->index >= count($this->middleware)) {
            return $finalHandler($request);
        }

        // Get the current middleware and increment index
        $middleware = $this->middleware[$this->index];
        $this->index++;

        // Call the middleware with a closure that calls the next middleware
        $response = $middleware->handle($request, function($request) use ($finalHandler) {
            return $this->next($request, $finalHandler);
        });

        // A middleware that returned nothing is a server bug: answer 500, log it at error level, and record it for
        // request_logs (it used to be an error body with NO status, i.e. shipped as HTTP 200).
        if ($response === null) {
            $detail = 'Middleware did not return a response: ' . get_class($middleware);
            \StoneScriptPHP\RequestLogging\RequestContext::captureException(new \LogicException($detail));
            log_error($detail);
            http_response_code(500);
            return new ApiResponse('error', 'Middleware did not return a response', null, 500);
        }

        // Keep the response OBJECT truthful for every outer layer: code that only called http_response_code(4xx/5xx)
        // left the object's status null, which platform guards read as "no status".
        if ($response->httpStatusCode === null) {
            $process = http_response_code();
            if (is_int($process) && $process >= 400) {
                $response->httpStatusCode = $process;
            }
        }

        return $response;
    }

    /**
     * Get the number of middleware in the pipeline
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->middleware);
    }
}

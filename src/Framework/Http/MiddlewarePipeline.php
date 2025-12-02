<?php

namespace Framework\Http;

use Framework\ApiResponse;

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

        // If middleware returns a response, return it (short-circuit)
        // If it returns null, the middleware called $next() and we return that result
        return $response ?? new ApiResponse('error', 'Middleware did not return a response');
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

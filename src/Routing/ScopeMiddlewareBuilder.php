<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

/**
 * Builder for adding middleware to a scope.
 *
 * Passed to the callback in Router::scope() to provide a clean API
 * for adding middleware to a specific scope.
 *
 * Usage:
 *   $router->scope('portal', function($r) {
 *       $r->use(new GatewayTenantMiddleware());
 *       $r->use(new SubscriptionMiddleware());
 *   });
 */
class ScopeMiddlewareBuilder
{
    public function __construct(
        private MiddlewarePipeline $pipeline
    ) {
    }

    /**
     * Add a middleware to this scope
     *
     * @param MiddlewareInterface $middleware
     * @return self
     */
    public function use(MiddlewareInterface $middleware): self
    {
        $this->pipeline->pipe($middleware);
        return $this;
    }
}

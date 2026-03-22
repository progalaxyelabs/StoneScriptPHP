<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

/**
 * Represents a route entry with metadata about the handler, scope, and alias status.
 *
 * Used internally by the Router to store normalized route configuration
 * regardless of whether the old (string handler) or new (array with scope) format is used.
 */
class RouteEntry
{
    public function __construct(
        /** The handler class name or pre-instantiated handler object */
        public readonly string|object $handler,
        /** The scope this route belongs to (e.g., 'portal', 'admin', 'shared') */
        public readonly string $scope = 'shared',
        /** Whether this route is an alias (routable but excluded from client generation) */
        public readonly bool $isAlias = false,
    ) {
    }

    /**
     * Get the handler class name as a string.
     */
    public function getHandlerClass(): string
    {
        return is_object($this->handler) ? get_class($this->handler) : $this->handler;
    }
}

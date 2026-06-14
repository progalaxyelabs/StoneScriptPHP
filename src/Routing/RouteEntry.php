<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

/**
 * Represents a route entry with metadata about the handler, service, group, and generation hints.
 *
 * Used internally by the Router to store normalized route configuration.
 *
 * ## v4.0 fields (CLIENT-SDK-SPEC §0 A2)
 *
 * - `service`   — the first URL segment partition key (`portal`, `admin`, `public`).
 *                 Reserved values `infra` and `webhook` cause the route to be excluded
 *                 from all generated client packages (A3).
 * - `group`     — the domain-concept grouping for the generated client (`inventory`,
 *                 `billing`, `routes`, `workspaces`). MANDATORY on includable routes.
 *                 Missing `group` on an includable route = hard error in the generator.
 * - `action`    — optional explicit method name override (kebab→camelCase). When absent,
 *                 the generator derives the action from the last non-id URL segment.
 * - `streaming` — when true, the route is excluded from client generation entirely (A1).
 *                 A comment listing the skipped route is emitted in the generated package.
 * - `param`     — optional documentation label for the tail `:id` path parameter. Does not
 *                 change generated TypeScript signature (always `id: string | number`). (A5)
 * - `isAlias`   — routable but excluded from client generation (legacy backward-compat flag).
 */
class RouteEntry
{
    public function __construct(
        /** The handler class name or pre-instantiated handler object */
        public readonly string|object $handler,

        /**
         * Service partition key (v4.0).
         * `portal`, `admin`, `public`, etc. → included in corresponding package.
         * `infra`, `webhook` → excluded from all generated packages (A3).
         */
        public readonly string $service = 'shared',

        /** Whether this route is an alias (routable but excluded from client generation) */
        public readonly bool $isAlias = false,

        /**
         * Domain-concept group for the generated client (A2).
         * MANDATORY on includable routes (service != 'infra'|'webhook' and !streaming).
         * The generator emits a hard error when this is null on an includable route.
         */
        public readonly ?string $group = null,

        /**
         * Explicit action name override (kebab→camelCase by generator) (A2).
         * When null, the generator derives the action from the last non-id URL segment.
         */
        public readonly ?string $action = null,

        /**
         * When true, the route is a streaming endpoint (SSE / chunked) (A1).
         * Generator skips it entirely and emits a notice in the generated package.
         */
        public readonly bool $streaming = false,

        /**
         * Documentation label for the tail `:id` path parameter (A5).
         * Does NOT change the generated TypeScript signature — always `id: string | number`.
         * Purely informational for the PHP route handler developer.
         */
        public readonly ?string $param = null,
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

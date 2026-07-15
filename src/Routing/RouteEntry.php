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

        /**
         * Response DTO class name for typed-return generation (CLIENT-SDK-SPEC §10).
         * When set to a DTO FQCN (e.g. `App\Models\Warehouse::class`), the generator
         * reflects the DTO's public typed properties into a TypeScript interface and
         * types the generated method `Promise<Dto>` (or `Promise<Dto[]>` with
         * `collection: true`). When null, the method falls back to `Promise<ApiResponse>`
         * (= `unknown`) — the incremental-safe default.
         */
        public readonly ?string $response = null,

        /**
         * When true (and `response` is set), the endpoint returns a bare JSON array of
         * the response DTO — the generated method is typed `Promise<Dto[]>`. When false,
         * it returns a single DTO object — `Promise<Dto>`. Ignored when `response` is null.
         */
        public readonly bool $collection = false,

        /**
         * When true, the route is registered public (no JWT required) — same effect
         * as declaring it under routes.php's 'public' section in Format 1. Only
         * meaningful in Format 2 (flat format), where every route previously had no
         * way to opt out of the default protected registration (loadRoutes() always
         * passed isPublic=false for flat-format routes, with no per-route override).
         */
        public readonly bool $isPublic = false,

        /**
         * Route access model (v6.2.0) — supersedes the `$isPublic` boolean.
         * One of RouteAccess::PUBLIC | AUTHENTICATION | AUTHORIZATION.
         *
         * When null, it is DERIVED for backward compatibility: `$isPublic === true`
         * ⇒ `public`, otherwise `authorization` (the historical "protected" default —
         * a card-token business route). Declare it explicitly to opt a route into the
         * typed access/refresh middleware.
         */
        public readonly ?string $access = null,

        /**
         * Which credential this route consumes (v6.2.0):
         * RouteAccess::TOKEN_ACCESS (Bearer access token, stateless) or
         * RouteAccess::TOKEN_REFRESH (body refresh token, stateful DB-gated).
         */
        public readonly string $tokenType = RouteAccess::TOKEN_ACCESS,
    ) {
        if ($access !== null && !RouteAccess::isValidAccess($access)) {
            throw new \InvalidArgumentException(
                "RouteEntry \$access must be one of "
                . implode('|', RouteAccess::ACCESS_VALUES) . ", got '$access'."
            );
        }
        if (!RouteAccess::isValidTokenType($tokenType)) {
            throw new \InvalidArgumentException(
                "RouteEntry \$tokenType must be one of "
                . implode('|', RouteAccess::TOKEN_TYPE_VALUES) . ", got '$tokenType'."
            );
        }
    }

    /**
     * The effective access value, deriving from the legacy `$isPublic` flag when
     * `$access` was not declared. Never null.
     */
    public function resolvedAccess(): string
    {
        if ($this->access !== null) {
            return $this->access;
        }
        return $this->isPublic ? RouteAccess::PUBLIC : RouteAccess::AUTHORIZATION;
    }

    /** Whether the route requires no token (public), honouring both dimensions. */
    public function isPublicAccess(): bool
    {
        return $this->resolvedAccess() === RouteAccess::PUBLIC;
    }

    /**
     * Get the handler class name as a string.
     */
    public function getHandlerClass(): string
    {
        return is_object($this->handler) ? get_class($this->handler) : $this->handler;
    }
}

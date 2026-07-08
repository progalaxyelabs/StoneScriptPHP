<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing;

/**
 * Immutable value object for injecting a request into Router::dispatch()
 * without touching PHP superglobals.
 *
 * See TESTABILITY-SPEC.md, requirement T1-1. This is the seam that lets a
 * route-level test exercise the full middleware pipeline + handler dispatch
 * (method matching, header-driven middleware like JwtAuthMiddleware, request
 * body shape, response shape) without mutating $_SERVER/$_GET/$_POST or
 * fighting php://input, and without a live HTTP server.
 *
 * Production call sites never construct this. `Router::dispatch(null)`
 * (the default) reads $_SERVER/$_GET/$_POST/php://input/getallheaders()/
 * $_COOKIE exactly as before — this object only exists to supply an
 * alternative source for those same values.
 */
final class IncomingRequest
{
    /**
     * @param string      $method  HTTP method. Case-insensitive — normalized
     *                             to uppercase by the router to match how
     *                             addRoute()/loadRoutes() store route methods.
     * @param string      $path    Request path (no query string).
     * @param array       $headers Header name => value. Keys are compared
     *                             as-is by consuming middleware — match the
     *                             casing your middleware expects (most
     *                             framework middleware reads via
     *                             case-insensitive helpers, but this object
     *                             does not normalize casing itself).
     * @param array       $query   Query-string parameters, used as the route
     *                             `input` for GET/HEAD-like requests.
     * @param array|null  $body    Pre-parsed request body (as if
     *                             json_decode()'d already), used as the route
     *                             `input` for POST/PUT/PATCH. Null means "no
     *                             body" (equivalent to an empty array).
     * @param array       $cookies Cookie name => value, made available to
     *                             middleware/helpers via the request context's
     *                             'cookies' key instead of $_COOKIE.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly array $cookies = [],
    ) {
    }
}

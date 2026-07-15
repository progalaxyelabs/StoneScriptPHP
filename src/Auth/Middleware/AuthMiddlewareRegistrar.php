<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Auth\RefreshTokenStore;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Routing\RouteAccess;

/**
 * AuthMiddlewareRegistrar — safe-by-construction wiring for the typed-auth pipeline.
 *
 * ## The half-wiring hazard this closes
 *
 * {@see AccessTokenMiddleware} ignores refresh-token routes and {@see RefreshTokenMiddleware}
 * ignores access-token routes — each enforces only its own credential class and passes
 * the other's routes through. That separation is deliberate (owner preference: split by
 * type, no branching mega-class), but it means installing only ONE of them leaves the
 * OTHER credential class completely unauthenticated, with nothing to detect it.
 *
 * This registrar removes the footgun two ways:
 *   1. {@see create()} — the sanctioned install path. It returns BOTH middleware as a
 *      unit; spread it into the pipeline and you cannot get one without the other.
 *   2. {@see assertFullyWired()} — a boot assertion (wired automatically by
 *      `Application::run()`): if the route model declares any access/refresh route but
 *      the pipeline is missing the matching middleware, it throws and refuses to boot.
 *      Fail-closed, loud, at startup — never a silently-open route at request time.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   6.2.0
 */
final class AuthMiddlewareRegistrar
{
    /**
     * Build BOTH typed-auth middleware as a unit.
     *
     * @param TrustedIssuerVerifier $verifier Issuer→key verifier (mandatory iss).
     * @param RefreshTokenStore $store Refresh-token persistence gate.
     * @param array{
     *   access_purpose?: string|null,
     *   refresh_purpose?: string|null,
     *   header_name?: string,
     *   body_field?: string
     * } $options
     * @return array{0: AccessTokenMiddleware, 1: RefreshTokenMiddleware}
     */
    public static function create(
        TrustedIssuerVerifier $verifier,
        RefreshTokenStore $store,
        array $options = []
    ): array {
        return [
            new AccessTokenMiddleware(
                $verifier,
                $options['access_purpose'] ?? null,
                $options['header_name'] ?? 'Authorization'
            ),
            new RefreshTokenMiddleware(
                $verifier,
                $store,
                $options['refresh_purpose'] ?? null,
                $options['body_field'] ?? 'refresh_token'
            ),
        ];
    }

    /**
     * Fail loudly if the route model needs typed auth the pipeline does not enforce.
     *
     * A no-op unless at least one route declares an `access` of `authentication` or
     * `authorization` — so platforms that never adopt the typed model are unaffected.
     *
     * @param iterable<mixed> $pipeline The global middleware instances.
     * @param iterable<array<string,mixed>> $routeMetas `Router::getRouteMeta()` output.
     * @throws \RuntimeException if a declared access/refresh route class is unenforced.
     */
    public static function assertFullyWired(iterable $pipeline, iterable $routeMetas): void
    {
        $hasAccessMw = false;
        $hasRefreshMw = false;
        foreach ($pipeline as $mw) {
            if ($mw instanceof AccessTokenMiddleware) {
                $hasAccessMw = true;
            }
            if ($mw instanceof RefreshTokenMiddleware) {
                $hasRefreshMw = true;
            }
        }

        $needsAccess = false;
        $needsRefresh = false;
        foreach ($routeMetas as $meta) {
            $access = $meta['access'] ?? null;
            if ($access === null || $access === RouteAccess::PUBLIC) {
                continue;
            }
            $tokenType = $meta['token_type'] ?? RouteAccess::TOKEN_ACCESS;
            if ($tokenType === RouteAccess::TOKEN_REFRESH) {
                $needsRefresh = true;
            } else {
                $needsAccess = true;
            }
        }

        $missing = [];
        if ($needsAccess && !$hasAccessMw) {
            $missing[] = 'AccessTokenMiddleware (access-token routes would be UNAUTHENTICATED)';
        }
        if ($needsRefresh && !$hasRefreshMw) {
            $missing[] = 'RefreshTokenMiddleware (refresh routes would be UNAUTHENTICATED)';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Typed-auth wiring incomplete — the route model declares access/refresh routes '
                . 'but the middleware pipeline is missing: ' . implode('; ', $missing)
                . '. Install both together via AuthMiddlewareRegistrar::create(). Refusing to '
                . 'boot with a credential class left silently unprotected (fail-closed).'
            );
        }
    }
}

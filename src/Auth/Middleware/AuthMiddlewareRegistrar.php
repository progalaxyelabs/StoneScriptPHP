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
 *      `Application::run()`): if protected routes exist but the pipeline is missing the
 *      typed-auth middleware, it throws {@see AuthWiringException} and refuses to boot.
 *      Fail-closed, loud, at startup — never a silently-open route at request time.
 *
 * ## 7.0.0 — strict, single-mode
 *
 * The typed access/token_type model IS the model in 7.0.0; there is no pre-7.0
 * fallback. A platform still on the old `is_public` schema (protected routes, no
 * typed-auth middleware wired) is DETECTED at boot and gets a clear, actionable
 * migration error — the message tells the developer exactly what to change.
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
     * Build the standalone single-token pipeline: {@see SingleTokenMiddleware}
     * (access-token routes, no authn/authz purpose gate) + {@see RefreshTokenMiddleware}
     * (refresh routes, unchanged). Use this INSTEAD of {@see create()} on a
     * standalone + no-tenant platform (one principal, one signing key, no
     * exchange step) — see SingleTokenMiddleware for the full rationale.
     *
     * The refresh half is identical to {@see create()}'s: single-token mode only
     * relaxes the access-token PURPOSE check; refresh-token handling (stateful,
     * DB-gated) is unchanged. Because SingleTokenMiddleware extends
     * AccessTokenMiddleware, {@see assertFullyWired()} accepts this pipeline as
     * fully protecting access-token routes.
     *
     * @param TrustedIssuerVerifier $verifier Issuer→key verifier (mandatory iss).
     * @param RefreshTokenStore $store Refresh-token persistence gate.
     * @param array{
     *   refresh_purpose?: string|null,
     *   header_name?: string,
     *   body_field?: string
     * } $options `access_purpose` is intentionally ignored — single-token mode
     *   enforces no purpose. All other options match {@see create()}.
     * @return array{0: SingleTokenMiddleware, 1: RefreshTokenMiddleware}
     */
    public static function createSingleToken(
        TrustedIssuerVerifier $verifier,
        RefreshTokenStore $store,
        array $options = []
    ): array {
        return [
            new SingleTokenMiddleware(
                $verifier,
                // Pass null purpose: SingleTokenMiddleware ignores the purpose gate
                // entirely, so a constructor purpose would be inert (and misleading).
                null,
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
     * Fail fast at boot if protected routes exist but the typed-auth middleware are
     * not (fully) wired. Distinguishes two failure modes with distinct, actionable
     * messages:
     *
     *   - OLD SCHEMA (neither typed middleware wired): the platform has not migrated
     *     to 7.0.0 — emit the full migration guide.
     *   - HALF-WIRE (exactly one wired): a migrated platform installed one credential
     *     class but not the other — name the missing middleware.
     *
     * A genuine no-op only when there are NO protected routes at all (a pure-public
     * service). Any platform with protected routes MUST wire both middleware.
     *
     * @param iterable<mixed> $pipeline The global middleware instances.
     * @param iterable<array<string,mixed>> $routeMetas `Router::getRouteMeta()` output.
     * @throws AuthWiringException if protected routes are not fully enforced.
     */
    public static function assertFullyWired(iterable $pipeline, iterable $routeMetas): void
    {
        $hasAccessMw = false;
        $hasRefreshMw = false;
        foreach ($pipeline as $mw) {
            // SingleTokenMiddleware extends AccessTokenMiddleware, so a standalone
            // single-token pipeline is correctly recognised as protecting
            // access-token routes (it enforces everything but the purpose gate).
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

        // Pure-public service: nothing to enforce.
        if (!$needsAccess && !$needsRefresh) {
            return;
        }

        // OLD SCHEMA: protected routes exist but NEITHER typed middleware is wired.
        // This is the pre-7.0 platform that merely vendored the new version.
        if (!$hasAccessMw && !$hasRefreshMw) {
            throw new AuthWiringException(self::migrationMessage());
        }

        // HALF-WIRE: one credential class installed, the needed other one missing.
        $missing = [];
        if ($needsAccess && !$hasAccessMw) {
            $missing[] = 'AccessTokenMiddleware (access-token routes would be UNAUTHENTICATED)';
        }
        if ($needsRefresh && !$hasRefreshMw) {
            $missing[] = 'RefreshTokenMiddleware (refresh routes would be UNAUTHENTICATED)';
        }
        if ($missing !== []) {
            throw new AuthWiringException(
                'StoneScriptPHP 7.0.0: typed-auth wiring incomplete — cannot boot. '
                . 'The route model declares access/refresh routes but the middleware pipeline '
                . 'is missing: ' . implode('; ', $missing) . '. Install BOTH together via '
                . 'AuthMiddlewareRegistrar::create($verifier, $store) and pass them into '
                . "Application::run(['middleware' => [...\$auth]]). Refusing to boot with a "
                . 'credential class left unprotected (fail-closed). See UPGRADING-7.0.md.'
            );
        }
    }

    /**
     * The actionable "old routing schema detected" migration message. Public so
     * consumers and tests can reference the exact text.
     */
    public static function migrationMessage(): string
    {
        return <<<TXT
StoneScriptPHP 7.0.0: old routing schema detected — cannot boot.

This release replaces the `is_public` route flag with a strict typed access model
and REQUIRES the typed-auth middleware. This platform registered protected routes
(including the framework's own auth routes) but wired NEITHER typed-auth middleware,
so it is still on the pre-7.0 schema. 7.0.0 is single-mode — there is no old-mode
fallback — so it refuses to boot rather than serve routes with no authentication.

To migrate:
  1. Declare access + token_type on every non-public route:
       access     ∈ {public, authentication, authorization}
       token_type ∈ {access, refresh}
     (a protected route with no explicit access is treated as authorization/access.)
  2. Wire BOTH typed-auth middleware via the registrar:
       \$auth = StoneScriptPHP\\Auth\\Middleware\\AuthMiddlewareRegistrar::create(
                   \$trustedIssuerVerifier, \$refreshTokenStore);
       Application::run(['middleware' => [...\$auth], /* ... */]);
  3. Provide a TrustedIssuerVerifier (issuer→key map) and a RefreshTokenStore
     implementation (see InMemoryRefreshTokenStore for the contract).

See UPGRADING-7.0.md for the full migration guide.
TXT;
    }
}

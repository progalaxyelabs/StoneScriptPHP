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
 * the other's routes through. That separation is deliberate (one small middleware per credential
 * class, no branching mega-class), but it means installing only ONE of them leaves the
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
 * ## Strict, single-mode
 *
 * The typed access/token_type model is the only model (since 7.0.0). A pipeline that
 * is missing the middleware a declared route needs is DETECTED at boot and gets an
 * error naming exactly the middleware that is missing and the routes it would leave
 * open.
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
     * Mode-aware access-token wiring: the issuers, keys and purpose rules each AUTH_MODE needs, built from
     * the environment so a platform holds no plumbing.
     *
     * external / hybrid - two issuers are trusted. The auth service (JWKS) issues identity tokens
     *   (purpose=authentication). THIS API (iss = JWT_ISSUER, local public key) signs the API tokens that
     *   POST {prefix}/exchange returns (purpose=authorization), and routes default to
     *   access=authorization, so trusting only the auth service would 401 every call after a successful
     *   exchange. {@see AccessTokenMiddleware} stays strict: an identity token cannot reach an
     *   authorization route nor an API token an authentication route. A token that claims the auth
     *   service issuer but is signed by the local key is rejected (issuer-to-key binding).
     * builtin - one principal, one key, no exchange step; {@see SingleTokenMiddleware} enforces issuer,
     *   signature, expiry and type=access, and skips only the authn/authz purpose gate.
     *
     * Call it from `Application::run()` (the `typed_auth` option does) or after it: the local issuer/key are read from
     * {@see \StoneScriptPHP\Auth\LocalSigningIdentity::current()}, which `run()` sets from the exchange signing config.
     * Called EARLIER (before run()) it captures the environment's identity, which differs when the signing key or issuer
     * is overridden.
     *
     * Fails at boot (RuntimeException naming the variables) when a required AUTH_* / JWT_ISSUER value is
     * empty, the key is missing, or JWT_ISSUER equals AUTH_ISSUER. Returns only the ACCESS middleware: a
     * route with token_type=refresh still needs RefreshTokenMiddleware + a RefreshTokenStore
     * ({@see create()}).
     *
     * @param array{
     *   auth_service_url?: ?string,
     *   auth_issuer?: ?string,
     *   jwks_path?: string,
     *   jwks_keys?: array<string, \Firebase\JWT\Key>,
     *   jwks_url?: ?string,
     *   identity?: \StoneScriptPHP\Auth\LocalSigningIdentity,
     *   local_issuer?: ?string,
     *   local_public_key_path?: ?string,
     *   local_public_key_pem?: ?string
     * } $options explicit overrides (e.g. from src/config/auth.php `server.*`); each defaults to Env.
     *   `jwks_keys` pins JWKS keys without a network fetch (tests).
     * @return list<AccessTokenMiddleware> empty only for an unknown mode (the boot guard then refuses to
     *   start as soon as a protected route exists).
     * @throws \RuntimeException
     */
    public static function forMode(string $mode, \StoneScriptPHP\Env $env, array $options = []): array
    {
        // The local issuer + key are THE signing identity (what RsaJwtHandler / ExchangeRoute sign with and every other
        // verifier uses); explicit options override it for tests and unusual layouts.
        $identity    = $options['identity'] ?? \StoneScriptPHP\Auth\LocalSigningIdentity::current();
        $localIssuer = trim((string) ($options['local_issuer'] ?? $identity->issuer));
        $keyPem      = trim((string) ($options['local_public_key_pem'] ?? $identity->publicKeyPem ?? ''));
        $keyPath     = $keyPem !== '' ? '' : self::projectPath((string) ($options['local_public_key_path'] ?? $identity->publicKeyPath));

        if ($mode === 'builtin') {
            return [new SingleTokenMiddleware(new TrustedIssuerVerifier([
                $localIssuer => self::localIssuerEntry($localIssuer, $keyPath, $keyPem),
            ]))];
        }

        if ($mode !== 'external' && $mode !== 'hybrid') {
            return [];
        }

        $authUrl    = trim((string) ($options['auth_service_url'] ?? $env->AUTH_SERVICE_URL));
        $authIssuer = trim((string) ($options['auth_issuer'] ?? $env->AUTH_ISSUER));
        if ($authUrl === '' || $authIssuer === '') {
            throw new \RuntimeException(
                "AUTH_MODE is '{$mode}' but AUTH_SERVICE_URL and/or AUTH_ISSUER is empty. Set both "
                . "(AUTH_ISSUER is the exact 'iss' the auth service stamps in its tokens)."
            );
        }
        if ($localIssuer !== '' && $localIssuer === $authIssuer) {
            throw new \RuntimeException(
                'JWT_ISSUER must differ from AUTH_ISSUER: this API and the auth service are different token issuers.'
            );
        }

        $jwks = ['kind' => 'jwks', 'jwks_url' => ($options['jwks_url'] ?? null) ?: rtrim($authUrl, '/') . ($options['jwks_path'] ?? '/api/auth/jwks')];
        if (isset($options['jwks_keys'])) {
            $jwks['jwks_keys'] = $options['jwks_keys'];
        }

        return [new AccessTokenMiddleware(new TrustedIssuerVerifier([
            $authIssuer  => $jwks,
            $localIssuer => self::localIssuerEntry($localIssuer, $keyPath, $keyPem),
        ]))];
    }

    /** @return array<string, string> */
    private static function localIssuerEntry(string $issuer, string $publicKeyPath, string $publicKeyPem = ''): array
    {
        if ($issuer === '') {
            throw new \RuntimeException(
                "JWT_ISSUER is not set. Set it to this API's own public URL: it is the issuer of every token "
                . 'this API signs and the only issuer it trusts for them.'
            );
        }
        if ($publicKeyPem !== '') {
            return ['kind' => 'local', 'public_key' => $publicKeyPem];
        }
        if ($publicKeyPath === '' || !is_file($publicKeyPath)) {
            throw new \RuntimeException(
                "JWT public key not found at '{$publicKeyPath}' (JWT_PUBLIC_KEY_PATH). Run `php stone generate jwt` or mount the key."
            );
        }
        return ['kind' => 'local', 'public_key_path' => $publicKeyPath];
    }

    /** Key paths are written relative to the project root ('./keys/...'): never resolve against the cwd. */
    private static function projectPath(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }
        if (!defined('ROOT_PATH')) {
            // Never guess from the working directory (public/ under the built-in server, different again under FPM).
            throw new \RuntimeException("Cannot resolve the relative key path '{$path}': ROOT_PATH is not defined. Define ROOT_PATH or use an absolute path.");
        }
        $root = (string) ROOT_PATH;
        return $root . preg_replace('#^\./#', '', $path);
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
     * not (fully) wired. The error names the middleware actually missing and (up to
     * three of) the routes it would leave unauthenticated.
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
        $accessRoutes = [];
        $refreshRoutes = [];
        foreach ($routeMetas as $meta) {
            $access = $meta['access'] ?? null;
            if ($access === null || $access === RouteAccess::PUBLIC) {
                continue;
            }
            $tokenType = $meta['token_type'] ?? RouteAccess::TOKEN_ACCESS;
            $label = trim(((string) ($meta['method'] ?? '')) . ' ' . ((string) ($meta['path'] ?? '')));
            if ($tokenType === RouteAccess::TOKEN_REFRESH) {
                $needsRefresh = true;
                $refreshRoutes[] = $label;
            } else {
                $needsAccess = true;
                $accessRoutes[] = $label;
            }
        }

        // Pure-public service: nothing to enforce.
        if (!$needsAccess && !$needsRefresh) {
            return;
        }

        $missing = [];
        if ($needsAccess && !$hasAccessMw) {
            $missing[] = 'AccessTokenMiddleware (protects ' . self::describeRoutes($accessRoutes) . ')';
        }
        if ($needsRefresh && !$hasRefreshMw) {
            $missing[] = 'RefreshTokenMiddleware (protects ' . self::describeRoutes($refreshRoutes) . ')';
        }
        if ($missing !== []) {
            throw new AuthWiringException(
                'StoneScriptPHP: typed-auth wiring incomplete - cannot boot. '
                . 'Routes declare an `access` level other than public, but the middleware pipeline '
                . 'is missing: ' . implode('; ', $missing) . '. Those routes would be served '
                . 'UNAUTHENTICATED, so the application refuses to boot (fail-closed). '
                . 'Install both middleware together via AuthMiddlewareRegistrar::create($verifier, $store) '
                . "and pass them into Application::run(['middleware' => [...\$auth]]), "
                . 'or mark the routes `access => public` if they are meant to be open.'
            );
        }
    }

    /**
     * @param list<string> $routes
     */
    private static function describeRoutes(array $routes): string
    {
        $shown = array_slice($routes, 0, 3);
        $text = implode(', ', $shown);
        $extra = count($routes) - count($shown);
        return $extra > 0 ? $text . ' and ' . $extra . ' more' : $text;
    }

    /**
     * Setup guidance for wiring the typed-auth middleware (the shape of the data you
     * must supply). Kept as a public helper for tooling and docs; the boot error
     * itself is built from the ACTUAL failure by {@see assertFullyWired()}.
     */
    public static function migrationMessage(): string
    {
        return <<<TXT
Typed-auth wiring guide.

Routes that declare `access` = authentication|authorization are enforced by two
middleware, one per credential class (token_type access / refresh). Both must be wired:
  \$auth = StoneScriptPHP\\Auth\\Middleware\\AuthMiddlewareRegistrar::create(
              \$trustedIssuerVerifier, \$refreshTokenStore);
  Application::run(['middleware' => [...\$auth], /* ... */]);
A TrustedIssuerVerifier (issuer->key map) and a RefreshTokenStore implementation are
required (see InMemoryRefreshTokenStore for the contract). Background: UPGRADING-7.0.md.
TXT;
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\RefreshTokenStore;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Env;

/**
 * The ONE place that mints, persists, rotates and revokes refresh tokens.
 *
 * Every token-minting path (builtin OAuth callback, a platform's password/OTP login, token
 * exchange, refresh) calls this instead of `generateToken()` + a hand-written `store()`,
 * so a refresh token can never exist without its row, and the row can never hold plaintext.
 *
 *  - {@see issueSession()}: new session. Mints access + refresh, persists the refresh HASH.
 *    Fails CLOSED: if the row cannot be written an exception is thrown and no refresh token
 *    leaves this method (a token with no row can never refresh, so handing it out would be a
 *    silent time bomb).
 *  - {@see refresh()}: verifies a presented refresh token, then either mints a new access token
 *    (rotation off) or rotates (rotation on): new pair, same session family, old token spent.
 *    Replay of a spent token revokes the whole family.
 *  - {@see revokeSession()}: logout.
 *
 * Rotation is OFF by default (`rotate: false`) because the stock body-mode client keeps its
 * first refresh token and does not read a rotated one from the refresh response; turning
 * rotation on for such a client logs the user out at the second refresh. Cookie-mode and any
 * client that stores the returned `refresh_token` can enable it.
 */
final class RefreshTokenIssuer
{
    private static ?self $configured = null;

    /** @var (callable(array<string,mixed>): (array<string,mixed>|null))|null */
    private $claimsProvider;

    /**
     * @param (callable(array<string,mixed>): (array<string,mixed>|null))|null $claimsProvider
     *   Called on refresh with the verified refresh-token claims; returns the claims for the new
     *   tokens (reload the user, pick up role changes) or null when the subject no longer exists
     *   (refresh is then rejected). Default: reuse the verified claims.
     */
    public function __construct(
        private readonly JwtHandlerInterface $jwt,
        private readonly RefreshTokenStore $store,
        private readonly int $accessTtl = 900,
        private readonly int $refreshTtl = 15552000,
        private readonly bool $rotate = false,
        ?callable $claimsProvider = null,
    ) {
        if ($rotate && !($store instanceof RotatingRefreshTokenStore)) {
            throw new \LogicException('Refresh token rotation requires a RotatingRefreshTokenStore (e.g. PostgresRefreshTokenStore).');
        }
        $this->claimsProvider = $claimsProvider;
    }

    // ---- process-wide instance (config-built by Application::run) -----------------------------

    public static function configure(?self $issuer): void
    {
        self::$configured = $issuer;
    }

    public static function configured(): ?self
    {
        return self::$configured;
    }

    /**
     * Build the issuer from `Application::run()` config / env. Returns null (and registers none)
     * when persistence is not configured, which is the 12.x default.
     *
     * Config: `auth.refresh_tokens` = ['store' => 'postgres'|RefreshTokenStore|'none', 'rotate' => bool,
     * 'reuse_grace_seconds' => int, 'session_max_seconds' => int, 'claims_provider' => callable]. Env fallbacks:
     * REFRESH_TOKEN_STORE, REFRESH_TOKEN_ROTATE, REFRESH_TOKEN_REUSE_GRACE_SECONDS, REFRESH_TOKEN_SESSION_MAX_SECONDS
     * (the absolute session cap, default 180 days, never slides).
     *
     * @param array<string, mixed> $authConfig the `auth` section
     */
    public static function bootstrap(array $authConfig, JwtHandlerInterface $jwt, Env $env): ?self
    {
        $cfg = $authConfig['refresh_tokens'] ?? [];
        $store = $cfg['store'] ?? $env->REFRESH_TOKEN_STORE;
        $rotate = (bool) ($cfg['rotate'] ?? $env->REFRESH_TOKEN_ROTATE);
        $grace = (int) ($cfg['reuse_grace_seconds'] ?? $env->REFRESH_TOKEN_REUSE_GRACE_SECONDS);
        $sessionMax = (int) ($cfg['session_max_seconds'] ?? $env->REFRESH_TOKEN_SESSION_MAX_SECONDS);

        if ($store instanceof RefreshTokenStore) {
            $instance = $store;
        } elseif ($store === 'postgres') {
            $instance = new PostgresRefreshTokenStore($grace, $sessionMax);
        } elseif ($store === '' || $store === 'none') {
            self::$configured = null;
            return null;
        } else {
            throw new \InvalidArgumentException("Unknown refresh token store '" . (is_scalar($store) ? $store : get_debug_type($store)) . "' (expected postgres|none or a RefreshTokenStore).");
        }

        $issuer = new self(
            $jwt,
            $instance,
            $env->JWT_ACCESS_TOKEN_EXPIRY,
            $env->JWT_REFRESH_TOKEN_EXPIRY,
            $rotate,
            $cfg['claims_provider'] ?? null,
        );
        self::$configured = $issuer;
        return $issuer;
    }

    public function store(): RefreshTokenStore
    {
        return $this->store;
    }

    public function refreshTtl(): int
    {
        return $this->refreshTtl;
    }

    public function rotates(): bool
    {
        return $this->rotate;
    }

    // ---- issue --------------------------------------------------------------------------------

    /**
     * Start a session: mint access + refresh and persist the refresh hash (new family).
     *
     * @param array<string, mixed> $claims Claims for both tokens.
     * @param array<string, mixed>|null $metadata Stored with the row; default a truncated network prefix + truncated user agent.
     * @param string|null $subject Explicit session subject (build it with {@see qualifiedSubject()}). Default:
     *   {@see subjectOf()} - `identity_id` when a real global identity exists, else `{tenant_id}#{user_id}`.
     * @throws \RuntimeException When the refresh token cannot be persisted (nothing is returned).
     * @throws \InvalidArgumentException When the claims carry no identifier.
     */
    public function issueSession(array $claims, string $purpose = TokenClaims::PURPOSE_AUTHENTICATION, ?array $metadata = null, ?string $subject = null): IssuedTokens
    {
        $subject = $subject ?? self::subjectOf($claims);
        $access = $this->sign($claims, $this->accessTtl, TokenClaims::TYPE_ACCESS, $purpose);
        $refresh = $this->mintRefresh($claims, $purpose);

        try {
            $this->store->store(
                hash('sha256', $refresh),
                $subject,
                $purpose,
                time() + $this->refreshTtl,
                $metadata ?? self::requestMetadata()
            );
        } catch (\Throwable $e) {
            log_error('RefreshTokenIssuer: could not persist refresh token - session NOT issued: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            throw new \RuntimeException('Could not persist the refresh token; sign-in aborted.', 0, $e);
        }

        return new IssuedTokens($access, $refresh, $this->accessTtl, $claims);
    }

    // ---- refresh ------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $metadata
     * @throws RefreshRejectedException
     */
    public function refresh(string $refreshToken, ?array $metadata = null, ?bool $rotate = null): IssuedTokens
    {
        $rotate ??= $this->rotate;
        if ($rotate && !($this->store instanceof RotatingRefreshTokenStore)) {
            throw new \LogicException('Refresh token rotation requires a RotatingRefreshTokenStore.');
        }
        $verified = $this->jwt->verifyToken($refreshToken);
        if ($verified === false) {
            throw new RefreshRejectedException(RefreshRejectedException::INVALID_TOKEN);
        }
        if (($verified[TokenClaims::CLAIM_TYPE] ?? null) !== TokenClaims::TYPE_REFRESH) {
            throw new RefreshRejectedException(RefreshRejectedException::WRONG_TYPE);
        }
        $purpose = (string) ($verified[TokenClaims::CLAIM_PURPOSE] ?? TokenClaims::PURPOSE_AUTHENTICATION);
        if (!TokenClaims::isValidPurpose($purpose)) {
            throw new RefreshRejectedException(RefreshRejectedException::INVALID_TOKEN);
        }
        $oldHash = hash('sha256', $refreshToken);

        // Gate (before any claims lookup): usable? replay => revoke family.
        if ($this->store instanceof RotatingRefreshTokenStore) {
            $inspection = $this->store->inspect($oldHash);
            if ($inspection->status === TokenInspection::REUSED) {
                $this->onReuse($inspection->familyId, $inspection->subject);
                throw new RefreshRejectedException(RefreshRejectedException::REUSE_DETECTED);
            }
            if (!$inspection->usable()) {
                throw new RefreshRejectedException(
                    $inspection->status === TokenInspection::EXPIRED ? RefreshRejectedException::EXPIRED : RefreshRejectedException::UNKNOWN
                );
            }
        } elseif (!$this->store->exists($oldHash)) {
            throw new RefreshRejectedException(RefreshRejectedException::UNKNOWN);
        }

        $claims = self::stripReserved($verified);
        if ($this->claimsProvider !== null) {
            $fresh = ($this->claimsProvider)($claims);
            if ($fresh === null) {
                throw new RefreshRejectedException(RefreshRejectedException::SUBJECT_GONE);
            }
            $claims = $fresh;
        }

        $access = $this->sign($claims, $this->accessTtl, TokenClaims::TYPE_ACCESS, $purpose);

        if (!$rotate) {
            return new IssuedTokens($access, null, $this->accessTtl, $claims);
        }

        /** @var RotatingRefreshTokenStore $store */
        $store = $this->store;
        $newRefresh = $this->mintRefresh($claims, $purpose);
        $result = $store->rotate($oldHash, hash('sha256', $newRefresh), time() + $this->refreshTtl, $metadata ?? self::requestMetadata());

        if (!$result->ok()) {
            if ($result->status === RotationResult::REUSED) {
                $this->onReuse($result->familyId, $result->subject, false);
                throw new RefreshRejectedException(RefreshRejectedException::REUSE_DETECTED);
            }
            throw new RefreshRejectedException(
                $result->status === RotationResult::EXPIRED ? RefreshRejectedException::EXPIRED : RefreshRejectedException::UNKNOWN
            );
        }

        return new IssuedTokens($access, $newRefresh, $this->accessTtl, $claims);
    }

    // ---- revoke -------------------------------------------------------------------------------

    /** Logout: end the session that owns this refresh token. Unknown token = 0, never an error. */
    public function revokeSession(string $refreshToken): int
    {
        $hash = hash('sha256', $refreshToken);
        if ($this->store instanceof RotatingRefreshTokenStore) {
            return $this->store->revokeSession($hash);
        }
        $existed = $this->store->exists($hash);
        $this->store->revoke($hash);
        return $existed ? 1 : 0;
    }

    /**
     * Logout everywhere / password change / breach response for an already-qualified subject
     * (see {@see qualifiedSubject()}). Prefer {@see revokeAllForUser()}.
     */
    public function revokeAll(string $subject, ?string $purpose = null): int
    {
        return $this->store->revokeAllForSubject($subject, $purpose);
    }

    /**
     * Revoke every session of ONE user in ONE tenant. The user id of a multi-tenant platform is only unique
     * inside its tenant, so the tenant is part of the stored subject: revoking user "5" of tenant A never
     * touches user "5" of tenant B. Call this on password reset/change, role change and identity deletion
     * (the table has no foreign key to your users table, so deleting a user does NOT remove the rows by itself).
     */
    public function revokeAllForUser(string|int $userId, ?string $tenantId = null, ?string $purpose = null, bool $expectSessions = false): int
    {
        return $this->revokeAllForSubjectLogged(self::qualifiedSubject($userId, $tenantId), $purpose, $expectSessions);
    }

    /**
     * Revoke every session of the user described by a claims array, using the SAME subject builder as issuing
     * ({@see subjectOf()}), so what issue stored is exactly what revoke looks up.
     *
     * @param array<string, mixed> $claims
     */
    public function revokeAllForClaims(array $claims, ?string $purpose = null, bool $expectSessions = false): int
    {
        return $this->revokeAllForSubjectLogged(self::subjectOf($claims), $purpose, $expectSessions);
    }

    private function revokeAllForSubjectLogged(string $subject, ?string $purpose, bool $expectSessions): int
    {
        $n = $this->store->revokeAllForSubject($subject, $purpose);
        if ($n === 0 && $expectSessions) {
            // Either the user had no session, or the subject being revoked does not match the one that was
            // stored at issue time (the dangerous case: a "logout everywhere" that silently did nothing).
            log_warning('RefreshTokenIssuer: revoke-all affected 0 sessions where at least one was expected - check that issue and revoke use the same subject', ['subject' => $subject]);
        }
        return $n;
    }

    /**
     * The stored subject for a user: `{tenantId}#{userId}` inside a tenant, the bare id for a tenant-less platform.
     */
    public static function qualifiedSubject(string|int $userId, ?string $tenantId = null): string
    {
        $user = (string) $userId;
        if ($user === '') {
            throw new \InvalidArgumentException('Refresh token subject must not be empty.');
        }
        return ($tenantId !== null && $tenantId !== '') ? $tenantId . '#' . $user : $user;
    }

    /**
     * Cheap readiness check for a /health route or deploy script: is the store usable (schema applied)?
     *
     * @return array{ok: bool, error: ?string}
     */
    public function healthCheck(): array
    {
        try {
            if ($this->store instanceof PostgresRefreshTokenStore) {
                $this->store->ensureSchema();
            }
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => \StoneScriptPHP\Persistence\LogSanitizer::sanitize($e->getMessage())];
        }
    }

    // ---- internals ----------------------------------------------------------------------------

    private function onReuse(?string $familyId, ?string $subject, bool $revokeHere = true): void
    {
        if ($revokeHere && $familyId !== null && $this->store instanceof RotatingRefreshTokenStore) {
            $this->store->revokeFamily($familyId);
        }
        // An attack signal: never silent. Subject is an opaque id, not PII.
        log_alert('RefreshTokenIssuer: refresh token REUSE detected - session family revoked', [
            'family_id' => $familyId,
            'subject'   => $subject,
        ]);
    }

    /** @param array<string, mixed> $claims */
    private function mintRefresh(array $claims, string $purpose): string
    {
        // jti makes every refresh token unique even when minted in the same second with the same
        // claims; without it a rotation could hash to its own predecessor.
        return $this->sign($claims + ['jti' => bin2hex(random_bytes(16))], $this->refreshTtl, TokenClaims::TYPE_REFRESH, $purpose);
    }

    /**
     * JwtHandlerInterface still declares the pre-6.2 two-parameter signature; every real handler
     * (RsaJwtHandler, HybridApiTokenJwtHandler) takes type and purpose as well.
     *
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims, int $ttl, string $type, string $purpose): string
    {
        // @phpstan-ignore arguments.count
        return $this->jwt->generateToken($claims, $ttl, $type, $purpose);
    }

    /**
     * The ONE subject builder for a claims array (used by issue AND by every revoke path).
     *
     * `identity_id` (a real, global identity) is used as is. Otherwise the per-tenant `user_id`/`sub` is used, and
     * whenever a `tenant_id` is present it is qualified as `{tenant}#{user}`: user ids are only unique inside a
     * tenant, so a bare id would let a revoke in one tenant log the same-numbered user out of every other tenant.
     *
     * @param array<string, mixed> $claims
     * @throws \InvalidArgumentException when no identifier is present
     */
    public static function subjectOf(array $claims): string
    {
        $has = static fn (string $k): bool => isset($claims[$k]) && (is_string($claims[$k]) || is_int($claims[$k])) && (string) $claims[$k] !== '';
        $tenant = isset($claims['tenant_id']) && (is_string($claims['tenant_id']) || is_int($claims['tenant_id'])) && (string) $claims['tenant_id'] !== '' ? (string) $claims['tenant_id'] : null;

        if ($has('identity_id')) {
            return (string) $claims['identity_id']; // globally unique by definition
        }
        foreach (['user_id', 'sub'] as $key) {
            if ($has($key)) {
                return self::qualifiedSubject((string) $claims[$key], $tenant);
            }
        }
        throw new \InvalidArgumentException('Refresh token claims need a subject (identity_id, user_id or sub).');
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private static function stripReserved(array $claims): array
    {
        unset(
            $claims[TokenClaims::CLAIM_TYPE],
            $claims[TokenClaims::CLAIM_PURPOSE],
            $claims['jti'], $claims['iss'], $claims['iat'], $claims['exp'], $claims['nbf'], $claims['aud']
        );
        return $claims;
    }

    /** @return array<string, mixed> */
    private static function requestMetadata(): array
    {
        $meta = [];
        if (function_exists('client_ip')) {
            // Privacy: keep only the network prefix (IPv4 /24, IPv6 /64), enough for a "new location" hint,
            // never the full address.
            $prefix = \StoneScriptPHP\Http\ClientIp::networkPrefix(client_ip());
            if ($prefix !== 'unknown') {
                $meta['network'] = $prefix;
            }
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (is_string($ua) && $ua !== '') {
            $meta['user_agent'] = substr($ua, 0, 255);
        }
        return $meta;
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Database;

/**
 * PostgreSQL-backed {@see RotatingRefreshTokenStore}. All access goes through the framework's
 * vendor schema (`src/Auth/RefreshTokens/Schema`: table `auth_refresh_tokens`, functions
 * `auth_rt_*`), staged by `composer install` and activated with
 * `php stone gateway:migrate-vendor-main`.
 *
 * Refresh tokens are identity-level, not tenant-level, so every call is routed to the MAIN
 * database: a tenant context set earlier in the request is cleared for the call and restored
 * afterwards (gateway mode; direct/pgandroid have one database already).
 *
 * Only SHA-256 hashes are accepted; a raw token (or anything that is not a 64-char hex digest)
 * is rejected before it can reach the database or its logs.
 */
final class PostgresRefreshTokenStore implements RotatingRefreshTokenStore
{
    /** @var array<string, true> schema verified once per process (see ensureSchema()) */
    private static array $verified = [];

    /**
     * @param int  $reuseGraceSeconds Window after a rotation in which the spent token may be exchanged ONCE more.
     * @param int  $sessionMaxSeconds Absolute cap on a session family, fixed at login and never slid by rotation.
     * @param bool $verifySchema      Probe once per process that the vendor schema is applied; fail loudly if not.
     */
    public function __construct(
        private readonly int $reuseGraceSeconds = 10,
        private readonly int $sessionMaxSeconds = 15552000,
        private readonly bool $verifySchema = true,
    ) {
        if ($reuseGraceSeconds < 0 || $sessionMaxSeconds < 1) {
            throw new \InvalidArgumentException('reuseGraceSeconds must be >= 0 and sessionMaxSeconds >= 1');
        }
    }

    /**
     * Loud, actionable failure when the vendor schema (table auth_refresh_tokens + auth_rt_* functions) has not
     * been applied - instead of every sign-in dying with an opaque database error. Probes once per process.
     * Also run by `php stone auth:check-refresh-store`. Run `php stone gateway:migrate-vendor-main` BEFORE
     * enabling REFRESH_TOKEN_STORE=postgres.
     *
     * @throws \RuntimeException
     */
    public function ensureSchema(): void
    {
        $key = 'main';
        if (isset(self::$verified[$key])) {
            return;
        }
        try {
            self::call('auth_rt_inspect', [str_repeat('0', 64), 0]);
        } catch (\Throwable $e) {
            if (preg_match('/does not exist|undefined (function|table)|no such (function|table)/i', $e->getMessage()) !== 1) {
                // a database outage is not a missing schema: do not mislead the operator, and do not cache a verdict
                throw new \RuntimeException('Refresh-token store unavailable: ' . \StoneScriptPHP\Persistence\LogSanitizer::sanitize($e->getMessage()), 0, $e);
            }
            $msg = 'Refresh-token schema is missing or unusable (table auth_refresh_tokens / functions auth_rt_*). '
                . 'Run `php stone gateway:migrate-vendor-main` BEFORE enabling REFRESH_TOKEN_STORE=postgres. Cause: '
                . \StoneScriptPHP\Persistence\LogSanitizer::sanitize($e->getMessage());
            try {
                log_critical($msg);
            } catch (\Throwable) {
            }
            throw new \RuntimeException($msg, 0, $e);
        }
        self::$verified[$key] = true;
    }

    /** Test seam. */
    public static function resetVerification(): void
    {
        self::$verified = [];
    }

    public function store(string $tokenHash, string $subject, string $purpose, int $expiresAt, array $metadata = []): void
    {
        self::assertHash($tokenHash);
        self::assertPurpose($purpose);
        if ($subject === '') {
            throw new \InvalidArgumentException('Refresh token subject must not be empty.');
        }
        if ($this->verifySchema) {
            $this->ensureSchema();
        }
        self::call('auth_rt_store', [
            $tokenHash,
            $subject,
            $purpose,
            self::ts($expiresAt),
            self::encode($metadata),
            null,
            self::ts(time() + $this->sessionMaxSeconds),
        ]);
    }

    public function exists(string $tokenHash): bool
    {
        return $this->inspect($tokenHash)->usable();
    }

    public function inspect(string $tokenHash): TokenInspection
    {
        self::assertHash($tokenHash);
        $row = self::call('auth_rt_inspect', [$tokenHash, $this->reuseGraceSeconds])[0] ?? null;
        if ($row === null) {
            return new TokenInspection(TokenInspection::UNKNOWN);
        }
        return new TokenInspection(
            (string) self::col($row, 'status'),
            self::col($row, 'family_id') === null ? null : (string) self::col($row, 'family_id'),
            self::col($row, 'subject') === null ? null : (string) self::col($row, 'subject'),
            self::col($row, 'purpose') === null ? null : (string) self::col($row, 'purpose'),
        );
    }

    public function rotate(string $oldHash, string $newHash, int $newExpiresAt, array $metadata = []): RotationResult
    {
        self::assertHash($oldHash);
        self::assertHash($newHash);
        if ($oldHash === $newHash) {
            throw new \InvalidArgumentException('rotate(): successor hash equals the old hash (refresh tokens must carry a unique jti).');
        }
        $row = self::call('auth_rt_rotate', [
            $oldHash,
            $newHash,
            self::ts($newExpiresAt),
            self::encode($metadata),
            $this->reuseGraceSeconds,
        ])[0] ?? null;
        if ($row === null) {
            return new RotationResult(RotationResult::UNKNOWN);
        }
        $status = (string) self::col($row, 'status');
        if (!in_array($status, [RotationResult::OK, RotationResult::REUSED, RotationResult::EXPIRED, RotationResult::UNKNOWN], true)) {
            throw new \UnexpectedValueException("auth_rt_rotate returned unknown status '$status'");
        }
        return new RotationResult(
            $status,
            self::col($row, 'family_id') === null ? null : (string) self::col($row, 'family_id'),
            self::col($row, 'subject') === null ? null : (string) self::col($row, 'subject'),
            self::col($row, 'purpose') === null ? null : (string) self::col($row, 'purpose'),
        );
    }

    public function revoke(string $tokenHash): void
    {
        self::assertHash($tokenHash);
        self::call('auth_rt_revoke', [$tokenHash]);
    }

    public function revokeSession(string $tokenHash): int
    {
        self::assertHash($tokenHash);
        return self::count(self::call('auth_rt_revoke_session', [$tokenHash]));
    }

    public function revokeFamily(string $familyId): int
    {
        return self::count(self::call('auth_rt_revoke_family', [$familyId]));
    }

    public function revokeAllForSubject(string $subject, ?string $purpose = null): int
    {
        if ($purpose !== null) {
            self::assertPurpose($purpose);
        }
        return self::count(self::call('auth_rt_revoke_all_for_subject', [$subject, $purpose]));
    }

    public function purgeExpired(int $batch = 1000, int $olderThanSeconds = 0): int
    {
        return self::count(self::call('auth_rt_purge_expired', [max(1, $batch), max(0, $olderThanSeconds)]));
    }

    // ------------------------------------------------------------------

    /** @param array<int, mixed> $params @return array<int, array<string, mixed>> */
    private static function call(string $function, array $params): array
    {
        if (!Database::isGatewayMode()) {
            return Database::fn($function, $params);
        }
        $gw = Database::getGatewayClient();
        $previous = $gw->getTenantId();
        $gw->setTenantId(null); // always the main database
        try {
            return Database::fn($function, $params);
        } finally {
            $gw->setTenantId($previous);
        }
    }

    /** @param array<string, mixed>|object $row */
    private static function col(array|object $row, string $name): mixed
    {
        $row = (array) $row;
        return array_key_exists('o_' . $name, $row) ? $row['o_' . $name] : ($row[$name] ?? null);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function count(array $rows): int
    {
        $row = $rows[0] ?? null;
        return $row === null ? 0 : (int) (self::col($row, 'deleted') ?? 0);
    }

    private static function assertHash(string $hash): void
    {
        if (strlen($hash) !== 64 || !ctype_xdigit($hash)) {
            throw new \InvalidArgumentException(
                'Refresh token stores accept only a SHA-256 hex digest (64 hex chars), never the raw token; got a '
                . strlen($hash) . '-char value.'
            );
        }
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!TokenClaims::isValidPurpose($purpose)) {
            throw new \InvalidArgumentException("'$purpose' is not a valid token purpose (authentication|authorization).");
        }
    }

    private static function ts(int $unix): string
    {
        return (new \DateTimeImmutable('@' . $unix))->format(DATE_ATOM);
    }

    /** @param array<string, mixed> $metadata */
    private static function encode(array $metadata): string
    {
        return $metadata === [] ? '{}' : json_encode($metadata, JSON_THROW_ON_ERROR);
    }
}

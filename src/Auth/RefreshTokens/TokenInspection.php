<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

/** Read-only result of {@see RotatingRefreshTokenStore::inspect()}. */
final class TokenInspection
{
    /** Row present, not rotated, not expired. */
    public const VALID = 'valid';
    /** Rotated a moment ago: a concurrent/retried refresh, not an attack. */
    public const GRACE = 'grace';
    /** Rotated longer ago: replay of a spent token. The caller must revoke the family. */
    public const REUSED = 'reused';
    public const EXPIRED = 'expired';
    /** Never issued, revoked, or purged. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly ?string $familyId = null,
        public readonly ?string $subject = null,
        public readonly ?string $purpose = null,
    ) {
    }

    /** May this token be presented for a refresh right now? */
    public function usable(): bool
    {
        return $this->status === self::VALID || $this->status === self::GRACE;
    }
}

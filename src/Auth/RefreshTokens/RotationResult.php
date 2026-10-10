<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

/** Outcome of {@see RotatingRefreshTokenStore::rotate()}. */
final class RotationResult
{
    /** Old token exchanged; successor stored in the same family. */
    public const OK = 'ok';
    /** Old token was already spent: the whole family has been revoked, nothing stored. */
    public const REUSED = 'reused';
    public const EXPIRED = 'expired';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly ?string $familyId = null,
        public readonly ?string $subject = null,
        public readonly ?string $purpose = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}

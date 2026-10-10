<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

/** An access token plus (when one was minted) its refresh token. */
final class IssuedTokens
{
    /**
     * @param array<string, mixed> $claims The claims the tokens carry.
     * @param string|null $refreshToken Null when a refresh did not rotate (the client keeps its current one).
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly array $claims,
    ) {
    }
}

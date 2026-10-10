<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Fixtures;

use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\TokenClaims;

/** Deterministic JWT stand-in: header.payload.sig with the payload as base64 JSON; iat fixed so same-second collisions are real. */
final class FakeJwt implements JwtHandlerInterface
{
    public function generateToken(array $payload, ?int $expirySeconds = null, string $tokenType = TokenClaims::TYPE_ACCESS, string $purpose = TokenClaims::PURPOSE_AUTHENTICATION): string
    {
        $data = $payload + [TokenClaims::CLAIM_TYPE => $tokenType, TokenClaims::CLAIM_PURPOSE => $purpose, 'iat' => 1000, 'exp' => 2000];
        // payload spread wins for type/purpose only when explicitly given, like RsaJwtHandler
        $data[TokenClaims::CLAIM_TYPE] = $tokenType;
        $data[TokenClaims::CLAIM_PURPOSE] = $purpose;
        return 'h.' . rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=') . '.s';
    }

    public function verifyToken(string $token, bool $verifyIssuer = true): array|false
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'h') {
            return false;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($claims)) {
            return false;
        }
        unset($claims['iat'], $claims['exp']);
        return $claims;
    }
}


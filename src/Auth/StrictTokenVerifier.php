<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * Optional companion to {@see JwtHandlerInterface}: distinguishes "this token is bad" from "I cannot verify tokens right now".
 *
 * verifyTokenStrict() returns the claims on success and false for a genuine rejection (bad signature, expired,
 * malformed, wrong issuer). It THROWS {@see TokenVerifierUnavailableException} when it could not decide (key unreadable,
 * JWKS unreachable). Handlers that do not implement it keep the old behaviour (every failure is `false`).
 */
interface StrictTokenVerifier
{
    /**
     * @return array<string, mixed>|false
     * @throws TokenVerifierUnavailableException
     */
    public function verifyTokenStrict(string $jwt): array|false;
}

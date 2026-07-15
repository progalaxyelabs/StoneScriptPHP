<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\ApiResponse;

/**
 * SingleTokenMiddleware — standalone + no-tenant single-token access verification.
 *
 * Identical to {@see AccessTokenMiddleware} in EVERY security check — signature,
 * `iss`→key selection, `exp`, and `type == access` (a refresh token can never
 * satisfy an access route) — EXCEPT it does NOT enforce the strict authn/authz
 * `purpose` equality gate.
 *
 * ## Why the purpose gate is dropped here (and only here)
 *
 * The two-token model exists to SEPARATE two credentials: an identity/passport
 * token (`purpose=authentication`) proving who you are, and a card/API token
 * (`purpose=authorization`) authorising a tenant-scoped request. That separation
 * is meaningful when the two are signed by DIFFERENT authorities (external auth
 * server signs the passport, the platform signs the card) and/or scoped to
 * DIFFERENT tenants.
 *
 * In the **standalone + no-tenant** cell of the auth matrix there is exactly ONE
 * principal and ONE signing key. The login-issued token IS both the identity and
 * the API bearer — there is no exchange step, no second authority, no tenant to
 * scope into. So gating a request on whether the token's `purpose` claim equals
 * the route's declared access requirement only rejects a token that is otherwise
 * fully valid for this single-key platform (forcing a redundant same-key
 * self-exchange purely to flip `purpose` from authentication to authorization).
 *
 * This middleware therefore accepts a valid, in-date, correctly-signed access
 * token from the trusted issuer REGARDLESS of its `purpose` claim. Every other
 * guarantee AccessTokenMiddleware makes is retained; only the authn/authz
 * separation — which does not exist for a one-principal/one-key platform — is
 * dropped. The identity-token-as-API-bearer allowance is a deliberate exception
 * scoped precisely to the standalone + no-tenant cell (auth-lifecycle-redesign
 * spec §"Single-token purpose gate").
 *
 * ## Wiring
 *
 * Install via {@see AuthMiddlewareRegistrar::createSingleToken()} (returns this
 * plus {@see RefreshTokenMiddleware}, so refresh routes stay protected too).
 * Because it extends AccessTokenMiddleware, {@see AuthMiddlewareRegistrar::assertFullyWired()}
 * recognises it as an access-token enforcer — a single-token platform's pipeline
 * boots as fully protected, not half-wired.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   7.1.0
 */
class SingleTokenMiddleware extends AccessTokenMiddleware
{
    /**
     * Accept ANY valid purpose (authentication OR authorization). One principal /
     * one key → no authn/authz boundary to enforce. The base class has already
     * verified the token is a genuine, in-date, correctly-signed access token
     * from a trusted issuer before this hook is reached, so there is nothing
     * left to check.
     *
     * @param array<string,mixed> $claims Verified token claims.
     */
    protected function assertPurpose(array $claims, string $requiredPurpose): ?ApiResponse
    {
        return null;
    }
}

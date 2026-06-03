<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\TokenExchangeService;
use StoneScriptPHP\Auth\TokenExchangeException;

/**
 * POST {prefix}/exchange  (PUBLIC — see note on auth)
 *
 * Exchanges an identity token (issued by the external auth service) for a
 * platform token whose `roles` claim is resolved from the platform's own
 * tenant database.
 *
 * Why this route is registered as PUBLIC (excluded from JwtAuthMiddleware):
 * the inbound token in the Authorization header is an *identity* token signed
 * by the auth service — NOT a platform token. JwtAuthMiddleware validates
 * platform tokens (different issuer/key) and would reject it. This route does
 * its own validation against the auth service JWKS via TokenExchangeService.
 *
 * Flow:
 *   1. Extract identity JWT from Authorization: Bearer header
 *   2. Validate against auth service JWKS (TokenExchangeService::validateIdentityToken)
 *   3. Resolve platform roles via the injected roles_resolver closure
 *      (platform-specific — reads the tenant DB). No resolver => 501, never guess.
 *   4. Sign a platform token (TokenExchangeService::exchange) using the platform's
 *      EXISTING JWT keypair (JWT_PRIVATE_KEY_PATH / JWT_ISSUER) so the token is
 *      accepted by this platform's own JwtAuthMiddleware — no second keypair.
 *   5. Return the platform token envelope (mirrors the legacy hand-rolled route).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ExchangeRoute extends BaseExternalAuthRoute
{
    /**
     * Platform-specific role resolver.
     *
     * Signature: fn(array $identityClaims): array  — returns a list of role
     * strings for the identity in the current tenant (e.g. ['owner']).
     *
     * @var callable|null
     */
    protected $rolesResolver;

    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config,
        ?callable $rolesResolver = null
    ) {
        parent::__construct($client, $hooks, $config);
        $this->rolesResolver = $rolesResolver;
    }

    /**
     * {@inheritdoc}
     *
     * No body fields — the identity token is read from the Authorization header.
     */
    public function validation_rules(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // 1. Extract identity token from the Authorization header.
        $identityToken = $this->extractIdentityToken();
        if ($identityToken === null || $identityToken === '') {
            // AUTH-SPEC §4c: machine-readable code in data object.
            return new ApiResponse(
                'error',
                'Authorization header with Bearer token required',
                ['error' => 'invalid_identity_token'],
                401
            );
        }

        // 2. Validate the identity token against the auth service JWKS.
        try {
            $claims = $this->validateIdentity($identityToken);
        } catch (TokenExchangeException $e) {
            log_error('ExchangeRoute: identity token validation failed: ' . $e->getMessage());
            return new ApiResponse(
                'error',
                'Invalid or expired identity token',
                ['error' => 'invalid_identity_token'],
                401
            );
        }

        $identityId = $claims['identity_id'] ?? $claims['sub'] ?? null;
        if (!$identityId) {
            return new ApiResponse(
                'error',
                'Token missing identity_id claim',
                ['error' => 'invalid_identity_token'],
                401
            );
        }

        // 3. Resolve platform roles. A missing resolver is a configuration error —
        //    never guess roles.
        if ($this->rolesResolver === null) {
            log_error('ExchangeRoute: no roles_resolver configured — cannot issue platform token');
            return res_error(
                'Token exchange is not configured on this platform (missing roles_resolver)',
                501
            );
        }

        try {
            $roles = ($this->rolesResolver)($claims);
        } catch (\Throwable $e) {
            log_error('ExchangeRoute: roles_resolver threw: ' . $e->getMessage());
            return res_error('Failed to resolve platform roles', 500);
        }

        if (!is_array($roles)) {
            log_error('ExchangeRoute: roles_resolver must return an array, got ' . gettype($roles));
            return res_error('Failed to resolve platform roles', 500);
        }
        // Normalize to a list of strings.
        $roles = array_values(array_map('strval', $roles));

        // 4. Sign the platform token using the platform's EXISTING JWT keypair.
        try {
            $platformToken = $this->signPlatformToken($claims, $roles);
        } catch (TokenExchangeException $e) {
            log_error('ExchangeRoute: platform token signing failed: ' . $e->getMessage());
            return res_error('Failed to issue platform token', 500);
        }

        // 5. Return the platform token envelope (matches the legacy route shape
        //    so platforms can drop the hand-rolled version with no client change).
        return res_ok([
            'access_token' => $platformToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->config->exchangeTtl,
            'roles'        => $roles,
        ], 'Token exchanged successfully');
    }

    // ── Seams (overridable for unit testing without real JWKS / keys) ───────────

    /**
     * Extract the raw identity JWT from the Authorization header.
     */
    protected function extractIdentityToken(): ?string
    {
        return $this->getBearerToken();
    }

    /**
     * Validate the identity token against the auth service JWKS.
     *
     * @throws TokenExchangeException on any validation failure
     * @return array Decoded identity claims
     */
    protected function validateIdentity(string $identityToken): array
    {
        $service = new TokenExchangeService();
        return $service->validateIdentityToken(
            $identityToken,
            $this->config->jwksUrl,
            $this->config->authIssuer
        );
    }

    /**
     * Sign a platform token from identity claims + resolved roles.
     *
     * Signing config defaults entirely from the platform's existing JWT
     * configuration (JWT_PRIVATE_KEY_PATH / JWT_ISSUER), so the issued token
     * is signed with the very key this platform's JwtAuthMiddleware validates
     * against — no second keypair to keep in sync.
     *
     * @throws TokenExchangeException on signing failure
     */
    protected function signPlatformToken(array $claims, array $roles): string
    {
        $service = new TokenExchangeService();

        $platformCode = $claims['platform_code'] ?? $this->config->platformCode;

        return $service->exchange($claims, $roles, [
            'private_key_path'       => $this->config->signingPrivateKeyPath,
            'private_key_passphrase' => $this->config->signingPrivateKeyPassphrase,
            'issuer'                 => $this->config->signingIssuer,
            'ttl'                    => $this->config->exchangeTtl,
            'custom_claims'          => [
                'platform_code' => $platformCode,
            ],
        ]);
    }
}

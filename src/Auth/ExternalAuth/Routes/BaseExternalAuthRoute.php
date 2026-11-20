<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\BearerToken;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\Client\AuthServiceException;
use StoneScriptPHP\Auth\PlatformCodeGuard;

/**
 * Abstract base class for all ExternalAuth route handlers
 *
 * Provides shared infrastructure: client access, hook invocation,
 * auth header extraction, and standardized error handling.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
abstract class BaseExternalAuthRoute implements IRouteHandler
{
    protected ExternalAuthServiceClient $client;
    protected array $hooks;
    protected ExternalAuthConfig $config;

    /**
     * @param ExternalAuthServiceClient $client HTTP client for auth service
     * @param array $hooks Lifecycle hooks from config
     * @param ExternalAuthConfig $config Full config object
     */
    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config
    ) {
        $this->client = $client;
        $this->hooks = $hooks;
        $this->config = $config;
    }

    /**
     * Wrap an auth service call with error handling and optional hook invocation
     *
     * @param callable $call Function that calls the auth service client
     * @param string|null $hookName Hook to invoke on success (e.g., 'after_login')
     * @param array|null $input Original input data passed to the hook
     * @return ApiResponse
     */
    protected function proxyCall(callable $call, ?string $hookName = null, ?array $input = null): ApiResponse
    {
        try {
            $result = $call();

            // Invoke hook on success (hook exceptions are caught and logged, never affect response)
            if ($hookName !== null && isset($this->hooks[$hookName]) && is_callable($this->hooks[$hookName])) {
                try {
                    ($this->hooks[$hookName])($result, $input);
                } catch (\Throwable $e) {
                    log_error("ExternalAuth hook '$hookName' failed: " . $e->getMessage());
                }
            }

            return res_ok($result);
        } catch (AuthServiceException $e) {
            $httpCode = $e->getCode();
            if ($httpCode >= 400 && $httpCode < 600) {
                http_response_code($httpCode);
            } else {
                http_response_code(502);
            }
            if ($e instanceof \StoneScriptPHP\Exceptions\PublicMessage) {
                return res_error($e->getMessage()); // the auth service's own structured public_message
            }
            // Upstream free text is not deliberately public: generic sentence + correlation id, detail to the log.
            $correlationId = bin2hex(random_bytes(6));
            log_error("ExternalAuth upstream error [correlation_id=$correlationId, HTTP " . (int) $httpCode . ']: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            $generic = ($httpCode >= 400 && $httpCode < 500)
                ? \StoneScriptPHP\Persistence\DbErrorMapper::genericMessage((int) $httpCode)
                : 'Authentication service unavailable';
            return new \StoneScriptPHP\ApiResponse('error', $generic, ['correlation_id' => $correlationId], null);
        } catch (\Throwable $e) {
            log_error('ExternalAuth proxy error: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            http_response_code(502);
            return res_error('Authentication service unavailable');
        }
    }

    /**
     * Extract the raw JWT token from the Authorization header of the current request
     *
     * Strips the "Bearer " prefix so the raw token can be passed directly to
     * client methods — buildAuthHeader() will re-add the prefix when making
     * outbound requests, avoiding the double-Bearer bug.
     *
     * @return string|null The raw JWT token (without "Bearer " prefix), or null
     */
    protected function getBearerToken(): ?string
    {
        $headerValue = null;

        // Try getallheaders() first (Apache/FPM)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // Headers are case-insensitive per HTTP spec
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $headerValue = $value;
                    break;
                }
            }
        }

        // Fallback to $_SERVER
        if ($headerValue === null && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headerValue = $_SERVER['HTTP_AUTHORIZATION'];
        }

        // Apache mod_rewrite fallback
        if ($headerValue === null && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headerValue = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ($headerValue === null) {
            return null;
        }

        // Strip "Bearer " prefix via the canonical BearerToken utility (v5.5.5) —
        // buildAuthHeader() adds it back when making outbound requests. Using the
        // same shared utility here and in AuthServiceClient::buildAuthHeader() keeps
        // both ends of the "inbound header -> outbound header" round trip consistent
        // by construction, instead of two independently-maintained copies of the
        // same regex.
        return BearerToken::strip($headerValue);
    }

    /**
     * LEGACY_TENANCY switch (default TRUE: the legacy 14.x flow). False selects the current flow. Protected so a platform
     * or a test can decide it differently.
     */
    protected function legacyTenancy(): bool
    {
        try {
            return \StoneScriptPHP\Tenancy\Provisioning\TenancyMode::legacy();
        } catch (\Throwable) {
            return true; // no resolvable environment: keep the legacy flow
        }
    }

    /** The MembershipAuthority for the current tenancy flow (AUTH_MODE decides which). Protected: tests and platforms override it. */
    protected function membershipAuthority(): \StoneScriptPHP\Tenancy\Provisioning\MembershipAuthority
    {
        return \StoneScriptPHP\Tenancy\Provisioning\TenancyMode::authority(
            \StoneScriptPHP\Env::get_instance(),
            new \StoneScriptPHP\Tenancy\Provisioning\DatabaseMainTenantStore()
        );
    }

    /**
     * Every known role of the identity in the tenant database, and the union of those roles' privileges (current
     * tenancy flow). STRICT: a failed tenant-database read throws (the caller answers 502); it is never turned into "no
     * roles". Protected: platforms and tests may override.
     *
     * @return array{roles:list<string>,privileges:list<string>}
     * @throws \Throwable
     */
    protected function tenantAccess(string $tenantId, string $identityId): array
    {
        return \StoneScriptPHP\Tenancy\Provisioning\TenantDbScope::run($tenantId, static function () use ($identityId): array {
            return (new \StoneScriptPHP\Auth\TenantRoles\TenantRoleResolver(new \StoneScriptPHP\Auth\TenantRoles\DatabaseTenantRoleStore()))->strictAccess($identityId);
        });
    }

    /**
     * Platform API token for a tenant carrying ALL the user's known roles (roles[], no role_id) and bound to this
     * platform (platform_code). Same shape for provision-tenant, select-tenant and exchange in the current tenancy flow.
     *
     * @param list<string> $roles
     */
    protected function mintTenantToken(string $identityId, string $tenantId, array $roles, string $email, string $displayName): ?string
    {
        if (empty($this->config->signingPrivateKeyPath)) {
            return null;
        }
        try {
            return (new \StoneScriptPHP\Auth\TokenExchangeService())->exchangeApiTokenWithRoles([
                'sub' => $identityId,
                'identity_id' => $identityId,
                'tenant_id' => $tenantId,
                'email' => $email !== '' ? $email : null,
                'display_name' => $displayName !== '' ? $displayName : null,
            ], $roles, [
                'private_key_path' => $this->config->signingPrivateKeyPath,
                'private_key_passphrase' => $this->config->signingPrivateKeyPassphrase,
                'issuer' => $this->config->signingIssuer,
                'ttl' => $this->config->exchangeTtl,
                // platform_code is not an identity claim the minter copies; carry it so the token is bound to this platform.
                'custom_claims' => ['platform_code' => $this->config->platformCode],
            ]);
        } catch (\StoneScriptPHP\Auth\TokenExchangeException $e) {
            log_error(static::class . ': API token minting failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The one response every current-flow entry point (provision-tenant, select-tenant, exchange) gives once a
     * membership is established: roles read STRICTLY from the tenant database, all known roles on the token,
     * and the privilege union in the body. A failed role read is a 502 (never mistaken for "no role"); no known
     * role is a 403 `no_tenant_role` and no token; a token that cannot be signed is a 500.
     *
     * @param array{membership_id:string,tenant_id:string,tenant_name:string,tenant_slug:?string,tenant_db_name:string} $membership
     * @param array{email?:?string,display_name?:?string} $profile
     */
    protected function tenantSessionResponse(string $identityId, array $membership, array $profile, string $message, int $status = 200): ApiResponse
    {
        try {
            $access = $this->tenantAccess($membership['tenant_id'], $identityId);
        } catch (\Throwable $e) {
            log_error(static::class . ': tenant role lookup failed: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return res_error('Could not read roles for this tenant', 502);
        }
        $roles = $access['roles'];
        if ($roles === []) {
            log_warning(static::class . ': member has no known role in the tenant database; refusing');
            return new ApiResponse('error', 'No role in this tenant', ['error' => 'no_tenant_role'], 403);
        }

        $token = $this->mintTenantToken($identityId, $membership['tenant_id'], $roles, (string) ($profile['email'] ?? ''), (string) ($profile['display_name'] ?? ''));
        if ($token === null) {
            return res_error('Could not issue a platform token for this tenant', 500);
        }

        $activeTenant = [
            'id' => $membership['tenant_id'],
            'name' => $membership['tenant_name'],
            'slug' => $membership['tenant_slug'],
            'db_name' => $membership['tenant_db_name'],
        ];
        return new ApiResponse('ok', $message, [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->exchangeTtl,
            'active_tenant' => $activeTenant,
            'available_tenants' => [$activeTenant],
            'roles' => $roles,
            'privileges' => $access['privileges'],
            'membership' => ['id' => $membership['membership_id'], 'tenant_id' => $membership['tenant_id']],
        ], $status);
    }

    /**
     * SECURITY — cross-platform token guard. Call this at the top of
     * process() (after extracting whatever `platform_code` claim the
     * verified token carries) on every route that acts on an
     * identity/API-token-authenticated request. See {@see PlatformCodeGuard}
     * for the full rationale and decision table.
     *
     * @param string|null $tokenPlatformCode The `platform_code` claim off the
     *   verified token (e.g. `auth()->platform_code`, or a raw JWKS-validated
     *   claims array's `platform_code` for routes that decode the token
     *   themselves rather than going through JwtAuthMiddleware, like
     *   {@see \StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute}).
     * @return ApiResponse|null A 403 `wrong_platform` denial when the token
     *   is inadmissible for this platform, or null to proceed.
     */
    protected function guardPlatformCode(?string $tokenPlatformCode): ?ApiResponse
    {
        $reason = PlatformCodeGuard::check($tokenPlatformCode, $this->config->platformCode);

        if ($reason === PlatformCodeGuard::UNCONFIGURED) {
            // FAIL CLOSED (15.0.0): a server that does not know which platform it is admits no token.
            log_error(
                static::class . ': refusing the request — this server has no PLATFORM_CODE configured, ' .
                'so it cannot tell whether the token was minted for this platform. Set PLATFORM_CODE.'
            );
            return new ApiResponse(
                'error',
                'This platform is not configured to accept tokens',
                ['error' => 'platform_not_configured'],
                403
            );
        }

        if (PlatformCodeGuard::isRejection($reason)) {
            log_warning(
                static::class . ": rejecting request — platform_code guard failed ($reason); " .
                'token platform_code=' . ($tokenPlatformCode !== null && $tokenPlatformCode !== '' ? $tokenPlatformCode : '(none)') .
                ', this platform=' . $this->config->platformCode
            );
            return new ApiResponse(
                'error',
                'This token is not valid for this platform',
                ['error' => 'wrong_platform'],
                403
            );
        }

        return null;
    }
}

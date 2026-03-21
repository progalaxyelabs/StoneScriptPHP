<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Tenancy\TenantProvisioner;

/**
 * POST {prefix}/provision-tenant (PROTECTED)
 *
 * Creates a tenant for a logged-in user who has an identity but no tenant.
 *
 * Flow:
 *   1. Decode JWT — extract identity_id
 *   2. Generate tenant_id, slug, db_schema
 *   3. Call provisioner->provision($data) — sequential platform steps:
 *        a. createTenantRecord()  — write tenant to main DB
 *        b. createDatabase()      — provision tenant DB via gateway
 *        c. runMigrations()       — run migrations (default no-op)
 *        d. seedData()            — platform-specific seeding (default no-op)
 *   4. Call auth's POST /api/internal/create-membership
 *   5. Return tenant_id, tenant_slug, tenant_name
 *
 * Falls back to legacy before_provision / after_provision hooks when no
 * provisioner instance is provided (backward compatibility).
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ProvisionTenantRoute extends BaseExternalAuthRoute
{
    public string $store_name = '';
    public string $display_name = '';
    public string $email = '';
    public string $phone_number = '';

    private ?TenantProvisioner $provisioner;

    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config,
        ?TenantProvisioner $provisioner = null
    ) {
        parent::__construct($client, $hooks, $config);
        $this->provisioner = $provisioner;
    }

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return array_merge([
            'store_name'   => 'required|string|max:255',
            'display_name' => 'optional|string|max:255',
            'email'        => 'optional|string|max:255',
            'phone_number' => 'optional|string|max:50',
        ], $this->config->extraValidation);
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // 1. Get identity_id from JWT
        $authHeader = $this->getAuthHeader();
        if (!$authHeader) {
            return res_error('Authorization token required', 401);
        }

        $token = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : $authHeader;

        $claims = $this->decodeJwt($token);
        if ($claims === null) {
            return res_error('Invalid token', 401);
        }

        $identityId = $claims['identity_id'] ?? null;
        if (!$identityId) {
            return res_error('Token missing identity_id', 401);
        }

        // 2. Generate tenant identifiers
        $tenantId      = $this->generateUuid();
        $tenantSlug    = $this->slugify($this->store_name);
        $tenantDbSchema = $this->config->platformCode . '_' . str_replace('-', '_', $tenantId);

        $data = [
            'identity_id'      => $identityId,
            'tenant_id'        => $tenantId,
            'platform_code'    => $this->config->platformCode,
            'tenant_name'      => $this->store_name,
            'tenant_slug'      => $tenantSlug,
            'tenant_db_schema' => $tenantDbSchema,
            'display_name'     => $this->display_name ?: ($claims['display_name'] ?? ''),
            'email'            => $this->email ?: ($claims['email'] ?? ''),
            'phone_number'     => $this->phone_number,
            'role'             => 'owner',
        ];

        // 3. Run provisioning — prefer class-based provisioner over legacy hooks
        if ($this->provisioner !== null) {
            try {
                $data = $this->provisioner->provision($data);
            } catch (\Throwable $e) {
                log_error("TenantProvisioner::provision failed: " . $e->getMessage());
                return res_error('Tenant provisioning failed: ' . $e->getMessage());
            }
        } elseif (isset($this->hooks['before_provision']) && is_callable($this->hooks['before_provision'])) {
            // Legacy hook path (backward compatibility)
            try {
                $hookResult = ($this->hooks['before_provision'])($data);
                if ($hookResult === false) {
                    return res_error('Tenant provisioning failed');
                }
                if (is_array($hookResult)) {
                    $data = array_merge($data, $hookResult);
                }
            } catch (\Throwable $e) {
                log_error("before_provision hook failed: " . $e->getMessage());
                return res_error('Tenant provisioning failed: ' . $e->getMessage());
            }
        }

        // 4. Call auth to create membership (server-to-server)
        $platformSecret = $this->config->platformSecret;
        if (!$platformSecret) {
            log_error("provision-tenant: EXTERNAL_AUTH_CLIENT_SECRET not configured (required for auth service calls)");
            return res_error('Server configuration error', 500);
        }

        try {
            $result = $this->client->createMembership($data, $platformSecret);
        } catch (\Throwable $e) {
            log_error("create-membership failed: " . $e->getMessage());
            return res_error('Failed to create organization membership');
        }

        // 5. Call after_provision hook (legacy — no-op when using provisioner)
        if (isset($this->hooks['after_provision']) && is_callable($this->hooks['after_provision'])) {
            try {
                ($this->hooks['after_provision'])($result, $data);
            } catch (\Throwable $e) {
                log_error("after_provision hook failed: " . $e->getMessage());
            }
        }

        return res_ok([
            'tenant_id'   => $data['tenant_id'],
            'tenant_slug' => $tenantSlug,
            'tenant_name' => $this->store_name,
        ]);
    }

    /**
     * Decode JWT payload without verification (client-side check only).
     * Auth service validates the token — this just reads claims for identity_id.
     */
    private function decodeJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * Generate a URL-safe slug from a store name.
     */
    private function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'store-' . substr($this->generateUuid(), 0, 8);
    }

    /**
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

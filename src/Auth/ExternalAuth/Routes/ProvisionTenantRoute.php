<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/provision-tenant (PROTECTED)
 *
 * Provisions a tenant for an OAuth user who already has an identity + tokens
 * but no tenant. Takes store_name and calls the auth service's register-tenant
 * endpoint with the user's JWT as oauth_token.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ProvisionTenantRoute extends BaseExternalAuthRoute
{
    public string $store_name = '';
    public string $country_code = '';
    public string $display_name = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return array_merge([
            'store_name' => 'required|string|max:255',
            'country_code' => 'optional|string|max:5',
            'display_name' => 'optional|string|max:255',
        ], $this->config->extraValidation);
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // Get the current JWT token from Authorization header
        $authHeader = $this->getAuthHeader();
        if (!$authHeader) {
            return res_error('Authorization token required', 401);
        }

        // Extract the raw token (remove "Bearer " prefix)
        $token = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : $authHeader;

        $data = [
            'tenant_id' => $this->generateUuid(),
            'platform' => $this->config->platformCode,
            'tenant_name' => $this->store_name,
            'country_code' => $this->country_code ?: 'IN',
            'display_name' => $this->display_name,
            'provider' => 'google',
            'oauth_token' => $token,
        ];

        // Invoke before_provision hook (can modify data)
        if (isset($this->hooks['before_provision']) && is_callable($this->hooks['before_provision'])) {
            try {
                $modified = ($this->hooks['before_provision'])($data);
                if (is_array($modified)) {
                    $data = $modified;
                }
            } catch (\Throwable $e) {
                log_error("ExternalAuth hook 'before_provision' failed: " . $e->getMessage());
            }
        }

        return $this->proxyCall(
            fn() => $this->client->registerTenant($data),
            'after_provision',
            $data
        );
    }

    /**
     * Generate a UUID v4 string.
     * Platform API owns tenant_id generation — auth stores it, not generates it.
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

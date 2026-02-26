<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;

/**
 * POST {prefix}/register
 *
 * Proxies registration to the auth service.
 * If mode='tenant' (default), calls register-tenant which provisions identity + tenant + DB.
 * If mode='identity', calls register which creates identity only.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class RegisterRoute extends BaseExternalAuthRoute
{
    public string $email = '';
    public string $password = '';
    public string $tenant_name = '';
    public string $country_code = '';
    public string $display_name = '';

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        $rules = [
            'email' => 'required|string',
            'password' => 'required|string',
        ];

        if ($this->config->registrationMode === 'tenant') {
            // tenant_name and country_code are optional — auth generates slug/schema regardless
            $rules['tenant_name'] = 'optional|string';
            $rules['country_code'] = 'optional|string';
            $rules['display_name'] = 'optional|string';
        }

        // Merge extra validation from config
        return array_merge($rules, $this->config->extraValidation);
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        $data = [
            'email' => $this->email,
            'password' => $this->password,
        ];

        if ($this->config->registrationMode === 'tenant') {
            // Platform API generates tenant_id — auth service accepts and stores it,
            // creating a new tenant record or adding a membership to an existing tenant.
            $data['tenant_id'] = $this->generateUuid();

            if ($this->tenant_name !== '') {
                $data['tenant_name'] = $this->tenant_name;
            }
            if ($this->country_code !== '') {
                $data['country_code'] = $this->country_code;
            }
            if ($this->display_name !== '') {
                $data['display_name'] = $this->display_name;
            }
        }

        // Collect extra fields from request body (not typed properties)
        foreach ($this->config->extraFields as $field) {
            $value = $_POST[$field] ?? null;
            if ($value === null) {
                // Check JSON input
                $jsonInput = json_decode(file_get_contents('php://input') ?: '{}', true);
                $value = $jsonInput[$field] ?? null;
            }
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        // Run before_register hook (can modify data)
        if (isset($this->hooks['before_register']) && is_callable($this->hooks['before_register'])) {
            try {
                $modified = ($this->hooks['before_register'])($data);
                if (is_array($modified)) {
                    $data = $modified;
                }
            } catch (\Throwable $e) {
                log_error("ExternalAuth hook 'before_register' failed: " . $e->getMessage());
            }
        }

        return $this->proxyCall(
            fn() => $this->config->registrationMode === 'tenant'
                ? $this->client->registerTenant($data)
                : $this->client->register($data),
            'after_register',
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

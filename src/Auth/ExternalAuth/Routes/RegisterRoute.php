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
            $rules['tenant_name'] = 'required|string';
            $rules['country_code'] = 'required|string';
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
            $data['tenant_name'] = $this->tenant_name;
            $data['country_code'] = $this->country_code;
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
}

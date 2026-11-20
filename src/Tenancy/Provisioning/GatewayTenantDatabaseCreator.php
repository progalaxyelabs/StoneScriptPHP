<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Tenancy\TenantIdRule;

/**
 * Creates the tenant database through the gateway (`POST /admin/database/create`, uuid = platform
 * tenant id) with the platform's runtime token, and returns the database name the gateway reports.
 *
 * 2xx: the body's `database_name` is returned (the sequence refuses a name that differs from the
 * reserved tenant_db_name). 409 `database_already_exists`: an identity-verified existing database,
 * success (retry); the name is returned when the body carries it. Name collision and every unknown
 * 409/non-2xx fail closed. The response body is never logged.
 */
final class GatewayTenantDatabaseCreator implements TenantDatabaseCreator
{
    /** @var callable(string,string,string):array{0:int,1:string|false,2:string} */
    private $transport;

    /**
     * @param callable(string,string,string):array{0:int,1:string|false,2:string}|null $transport
     *        (path, json payload, bearer) -> [http status, body, curl error]. Default: curl.
     */
    public function __construct(
        private readonly string $platformSlug,
        private readonly string $gatewayUrl,
        #[\SensitiveParameter] private readonly string $platformToken,
        private readonly string $tenantSchema = 'tenant',
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->curl(...);
    }

    public function create(string $platformTenantId): ?string
    {
        $step = ProvisioningException::STEP_DATABASE;
        if ($this->platformToken === '') {
            throw new ProvisioningException($step, 'DB_GATEWAY_PLATFORM_TOKEN is not configured');
        }
        $why = TenantIdRule::violation($platformTenantId, $this->platformSlug, $this->tenantSchema);
        if ($why !== null) {
            throw new ProvisioningException($step, 'Tenant id is not safe to map to a database name: ' . $why);
        }

        $payload = json_encode(['platform' => $this->platformSlug, 'schema_name' => $this->tenantSchema, 'uuid' => $platformTenantId], JSON_THROW_ON_ERROR);
        [$status, $body, $curlError] = ($this->transport)('/admin/database/create', $payload, $this->platformToken);
        if ($body === false) {
            throw new ProvisioningException($step, 'Gateway unreachable while creating the tenant database');
        }
        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $payloadData = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $code = is_string($decoded['error'] ?? null) && preg_match('/^[a-z][a-z0-9_]*$/', $decoded['error']) === 1 ? $decoded['error'] : null;
        // The gateway names the database `database_name`; a 409 body may carry it as `database`. Read both.
        $name = null;
        foreach ([$payloadData, $decoded] as $source) {
            foreach (['database_name', 'database'] as $key) {
                if ($name === null && is_string($source[$key] ?? null) && $source[$key] !== '') {
                    $name = $source[$key];
                }
            }
        }

        if ($code === 'database_name_collision') {
            throw new ProvisioningException($step, "Tenant database name collides with a different tenant (HTTP $status)");
        }
        if ($status === 409) {
            if ($code === 'database_already_exists') {
                return $name;
            }
            throw new ProvisioningException($step, "Gateway refused the tenant database (HTTP 409" . ($code !== null ? " $code" : '') . ')');
        }
        if ($status < 200 || $status >= 300) {
            throw new ProvisioningException($step, "Gateway failed to create the tenant database (HTTP $status" . ($code !== null ? " $code" : '') . ')');
        }
        if ($name === null) {
            throw new ProvisioningException($step, 'Gateway did not report the created database name');
        }
        return $name;
    }

    /** @return array{0:int,1:string|false,2:string} */
    private function curl(string $path, string $payload, string $bearer): array
    {
        $ch = curl_init(rtrim($this->gatewayUrl, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $bearer],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $response, curl_error($ch)];
    }
}

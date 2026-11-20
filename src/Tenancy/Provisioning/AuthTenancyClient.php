<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * The ONLY place that speaks the auth service's tenancy HTTP contract. If the auth side differs
 * in a detail (a path, a header name, a field name), this is the single class to align. The shapes
 * below are pinned by tests/Unit/Tenancy/AuthTenancyClientContractTest.php.
 *
 * Every route is under /api/internal. Two kinds of caller:
 *
 *   OPERATOR (header X-Operator-Token)
 *     POST /operator/platforms                {name, slug, idempotency_key}
 *          -> {platform_id, slug, name, client_id, client_secret?, credential_issued, idempotent_replay}
 *             (client_secret only in the response that issued it; a replay returns none)
 *
 *   PLATFORM (headers X-Platform-Client-Id + X-Platform-Client-Secret). Where a person is involved the
 *   request ALSO carries their identity access token (Authorization: Bearer), whose platform_code claim
 *   must equal the calling platform's slug:
 *     POST /tenants                           {tenant_name, idempotency_key, tenant_slug?}   + identity token
 *          -> {platform_tenant_id, tenant_db_name, platform_tenant_membership_id,
 *              tenant_provisioning_status, is_new_tenant, idempotent_replay, resumed}   (201 new, 200 replay)
 *     POST /tenants/{id}/provisioned          no body
 *     POST /tenants/{id}/provisioning-failed  no body
 *     POST /tenants/{id}/memberships          {identity_id, idempotency_key, invited_by_identity_id?}
 *          -> {platform_tenant_membership_id, platform_tenant_id, status, is_new_membership, idempotent_replay}
 *             (an existing membership comes back with its CURRENT status; never silently reactivated)
 *     PUT  /memberships/{id}/status           {status: active|suspended|removed} -> {id, status, updated_at}
 *     POST /operator/platforms/{platform_id}/credentials/rotate   {idempotency_key}
 *          -> {platform_id, client_id, status:"next", credential_issued, idempotent_replay, client_secret?}
 *     GET  /memberships                       identity token
 *          -> {success, memberships:[{id, platform_id, platform_slug, tenant_id, tenant_slug, tenant_name,
 *                                      tenant_db_name, is_tenant_owner (bool, required), status, joined_at}]}
 *             (active, provisioned tenants only)
 *     POST /memberships/verify                {tenant_id, tenant_slug?} + identity token
 *          -> {exists:false} | {exists:true, membership_id, tenant_id, tenant_slug, tenant_name, tenant_db_name, status}
 *
 * Errors are `{error: <code>}` with these statuses: 400 invalid_*, 401 (every authentication failure,
 * identical), 403 platform_not_active / wrong audience, 404 *_not_found, 409 invalid_state, slug_taken,
 * slug_overlap, tenant_slug_taken, tenant_not_provisioned, tenant_removed, cannot_suspend_tenant_owner,
 * 422 tenant_db_name_too_long. The code is carried into the exception message.
 *
 * A platform slug is at most 19 characters so that {slug}_tenant_{uuid_} fits 63 bytes.
 *
 * Secrets (operator token, client secret, user token) are only ever placed in request headers and
 * never appear in an exception message or a log line. A response may carry its fields at the top
 * level or under "data".
 */
final class AuthTenancyClient
{
    /** Longest platform slug: {slug}_tenant_{36-char uuid with underscores} must fit 63 bytes. */
    public const MAX_SLUG_LENGTH = 19;

    private const BASE = '/api/internal';

    /** @var callable(string,string,list<string>,?string):array{0:int,1:string} */
    private $transport;

    /**
     * @param callable(string,string,list<string>,?string):array{0:int,1:string}|null $transport
     *        [http status, body]; throws on a network failure. Default: curl.
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly ?string $clientId = null,
        #[\SensitiveParameter] private readonly ?string $clientSecret = null,
        ?callable $transport = null,
    ) {
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            throw new \InvalidArgumentException('Auth service URL must be an http(s) URL');
        }
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /** True when $slug satisfies the platform slug rules (shared by the CLI, the generator and the tables). */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/\A[a-z0-9]+(_[a-z0-9]+)*\z/', $slug) === 1 && strlen($slug) <= self::MAX_SLUG_LENGTH;
    }

    /**
     * Register a platform (operator action). Idempotent on (slug, idempotency key).
     *
     * @return array{platform_id:string,client_id:string,client_secret:?string}
     */
    public function registerPlatform(#[\SensitiveParameter] string $operatorToken, string $name, string $slug, string $idempotencyKey): array
    {
        $this->requireSecureForOperator();
        $r = $this->request('POST', self::BASE . '/operator/platforms', [
            'X-Operator-Token: ' . $operatorToken,
        ], ['name' => $name, 'slug' => $slug, 'idempotency_key' => $idempotencyKey]);

        $platformId = (string) ($r['platform_id'] ?? '');
        $clientId = (string) ($r['client_id'] ?? '');
        if (!Uuid::isValid($platformId) || $clientId === '') {
            throw new ProvisioningException('register_platform', 'Auth returned an incomplete platform registration');
        }
        $secret = $r['client_secret'] ?? null;
        return [
            'platform_id' => $platformId,
            'client_id' => $clientId,
            'client_secret' => is_string($secret) && $secret !== '' ? $secret : null,
        ];
    }

    /**
     * Add a `next` credential for a platform (operator action). The secret is returned only by the call that
     * issued it; an idempotent replay returns none.
     *
     * @return array{platform_id:string,client_id:string,client_secret:?string}
     */
    public function rotateCredential(#[\SensitiveParameter] string $operatorToken, string $platformId, string $idempotencyKey): array
    {
        $this->requireSecureForOperator();
        if (!Uuid::isValid($platformId)) {
            throw new ProvisioningException('rotate_credential', 'platform id must be a uuid');
        }
        $r = $this->request('POST', self::BASE . '/operator/platforms/' . $platformId . '/credentials/rotate', [
            'X-Operator-Token: ' . $operatorToken,
        ], ['idempotency_key' => $idempotencyKey]);
        $clientId = (string) ($r['client_id'] ?? '');
        if ($clientId === '') {
            throw new ProvisioningException('rotate_credential', 'Auth returned an incomplete credential');
        }
        $secret = $r['client_secret'] ?? null;
        return ['platform_id' => $platformId, 'client_id' => $clientId, 'client_secret' => is_string($secret) && $secret !== '' ? $secret : null];
    }

    /** Operator calls carry the most powerful token: https only (plain http is allowed for a local auth service). */
    private function requireSecureForOperator(): void
    {
        $parts = parse_url($this->baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' && !$local) {
            throw new ProvisioningException('operator_transport', 'Operator calls need an https auth URL (plain http is allowed only for localhost)');
        }
    }

    /** @return array{platform_tenant_id:string,tenant_db_name:string,platform_tenant_membership_id:string,idempotent_replay:bool} */
    public function createTenant(string $tenantName, string $idempotencyKey, #[\SensitiveParameter] string $userToken, ?string $tenantSlug = null): array
    {
        if ($userToken === '') {
            throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'The caller identity token is required to reserve a tenant');
        }
        $body = ['tenant_name' => $tenantName, 'idempotency_key' => $idempotencyKey];
        if ($tenantSlug !== null && $tenantSlug !== '') {
            $body['tenant_slug'] = $tenantSlug;
        }
        $r = $this->request('POST', self::BASE . '/tenants', array_merge($this->credentialHeaders(), [
            'Authorization: Bearer ' . $userToken,
        ]), $body);

        return [
            'platform_tenant_id' => (string) ($r['platform_tenant_id'] ?? ''),
            'tenant_db_name' => (string) ($r['tenant_db_name'] ?? ''),
            'platform_tenant_membership_id' => (string) ($r['platform_tenant_membership_id'] ?? ''),
            'idempotent_replay' => ($r['idempotent_replay'] ?? false) === true,
        ];
    }

    public function markProvisioned(string $tenantId): void
    {
        $this->request('POST', self::BASE . '/tenants/' . rawurlencode($tenantId) . '/provisioned', $this->credentialHeaders(), null);
    }

    /** The auth endpoint takes no body: the reason stays in the platform's own log and status. */
    public function markProvisioningFailed(string $tenantId): void
    {
        $this->request('POST', self::BASE . '/tenants/' . rawurlencode($tenantId) . '/provisioning-failed', $this->credentialHeaders(), null);
    }

    /**
     * Add a member. An existing membership is returned with its CURRENT status (auth never silently reactivates).
     *
     * @return array{id:string,status:string}
     */
    public function addMembership(string $tenantId, string $identityId, string $idempotencyKey, ?string $invitedByIdentityId = null): array
    {
        $body = ['identity_id' => $identityId, 'idempotency_key' => $idempotencyKey];
        if ($invitedByIdentityId !== null && $invitedByIdentityId !== '') {
            $body['invited_by_identity_id'] = $invitedByIdentityId;
        }
        $r = $this->request('POST', self::BASE . '/tenants/' . rawurlencode($tenantId) . '/memberships', $this->credentialHeaders(), $body);
        return ['id' => (string) ($r['platform_tenant_membership_id'] ?? ''), 'status' => (string) ($r['status'] ?? '')];
    }

    /**
     * PUT /memberships/{id}/status {status: active|suspended|removed} -> {id, status, updated_at}.
     * Errors: invalid_status 400, membership_not_found 404, cannot_suspend_tenant_owner 409 (last active owner).
     */
    public function setMembershipStatus(string $membershipId, string $status): void
    {
        $r = $this->request('PUT', self::BASE . '/memberships/' . rawurlencode($membershipId) . '/status', $this->credentialHeaders(), ['status' => $status]);
        if (($r['status'] ?? null) !== $status) {
            throw new ProvisioningException('set_membership_status', 'Auth did not confirm the new membership status');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listMemberships(#[\SensitiveParameter] string $userToken): array
    {
        $r = $this->request('GET', self::BASE . '/memberships', array_merge($this->credentialHeaders(), ['Authorization: Bearer ' . $userToken]), null);
        $rows = $r['memberships'] ?? null;
        if (!is_array($rows)) {
            throw new ProvisioningException('list_memberships', 'Auth returned a malformed membership list');
        }
        $rows = array_values(array_filter($rows, 'is_array'));
        foreach ($rows as $row) {
            // Owner status is part of the list contract: never guessed. A row without it is a contract break.
            if (!array_key_exists('is_tenant_owner', $row) || !is_bool($row['is_tenant_owner'])) {
                throw new ProvisioningException('list_memberships', 'Auth returned a membership row without is_tenant_owner');
            }
        }
        return $rows;
    }

    /**
     * Does the token's identity hold an active membership on this platform's provisioned tenant?
     *
     * @return array<string,mixed>|null the membership row, or null when none
     */
    public function verifyMembership(#[\SensitiveParameter] string $userToken, string $tenantId, ?string $tenantSlug = null): ?array
    {
        $body = ['tenant_id' => $tenantId];
        if ($tenantSlug !== null && $tenantSlug !== '') {
            $body['tenant_slug'] = $tenantSlug;
        }
        $r = $this->request('POST', self::BASE . '/memberships/verify', array_merge($this->credentialHeaders(), ['Authorization: Bearer ' . $userToken]), $body);
        return ($r['exists'] ?? false) === true ? $r : null;
    }

    /** @return list<string> */
    private function credentialHeaders(): array
    {
        if ($this->clientId === null || $this->clientId === '' || $this->clientSecret === null || $this->clientSecret === '') {
            throw new ProvisioningException('auth_credential', 'Platform credential is not configured (PLATFORM_CLIENT_ID / PLATFORM_CLIENT_SECRET); run platform:register');
        }
        return ['X-Platform-Client-Id: ' . $this->clientId, 'X-Platform-Client-Secret: ' . $this->clientSecret];
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $headers, ?array $body): array
    {
        $headers[] = 'Accept: application/json';
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        }
        try {
            [$status, $raw] = ($this->transport)($method, rtrim($this->baseUrl, '/') . $path, $headers, $payload);
        } catch (ProvisioningException $e) {
            throw $e;
        } catch (\Throwable) {
            // The transport error text can carry the URL or headers: report only that the call failed.
            throw new ProvisioningException('auth_request', "Auth service request failed ($method $path)");
        }

        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            $code = is_string($decoded['error'] ?? null) && preg_match('/^[a-z][a-z0-9_]*$/', $decoded['error']) === 1 ? ' ' . $decoded['error'] : '';
            throw new ProvisioningException('auth_request', "Auth service returned HTTP $status$code ($method $path)");
        }
        $data = $decoded['data'] ?? null;
        return is_array($data) ? $data : $decoded;
    }

    /** @param list<string> $headers @return array{0:int,1:string} */
    private static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            throw new \RuntimeException('transport failure');
        }
        return [$status, (string) $response];
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/** MembershipAuthority backed by the external auth service (v2 endpoints). AUTH_MODE=external|hybrid. */
final class ExternalMembershipAuthority implements MembershipAuthority
{
    /**
     * @param MainTenantStore|null $main when given, a status change confirmed by auth is mirrored into the platform's
     *        own tenant_memberships row (the two must not disagree)
     */
    public function __construct(private readonly AuthV2Client $client, private readonly ?MainTenantStore $main = null)
    {
    }

    public function reserveTenant(string $tenantName, string $idempotencyKey, string $identityId, #[\SensitiveParameter] ?string $userToken, ?string $tenantSlug = null): TenantReservation
    {
        $r = $this->step(ProvisioningException::STEP_RESERVE, fn () => $this->client->createTenant($tenantName, $idempotencyKey, (string) $userToken, $tenantSlug));
        try {
            return new TenantReservation($r['platform_tenant_id'], $r['tenant_db_name'], $r['platform_tenant_membership_id'], $r['idempotent_replay']);
        } catch (\InvalidArgumentException $e) {
            throw new ProvisioningException(ProvisioningException::STEP_RESERVE, 'Auth returned an invalid tenant reservation: ' . $e->getMessage(), $e);
        }
    }

    public function markProvisioned(string $tenantId): void
    {
        $this->step(ProvisioningException::STEP_AUTH_PROVISIONED, fn () => $this->client->markProvisioned($tenantId));
    }

    public function markProvisioningFailed(string $tenantId, string $reason): void
    {
        $this->step(ProvisioningException::STEP_AUTH_PROVISIONED, fn () => $this->client->markProvisioningFailed($tenantId));
    }

    public function addMember(string $tenantId, string $identityId, string $idempotencyKey, ?string $invitedByIdentityId = null): string
    {
        $m = $this->step(ProvisioningException::STEP_ADD_MEMBER, fn () => $this->client->addMembership($tenantId, $identityId, $idempotencyKey, $invitedByIdentityId));
        if (!Uuid::isValid($m['id'])) {
            throw new ProvisioningException(ProvisioningException::STEP_ADD_MEMBER, 'Auth returned an invalid membership id');
        }
        // Auth returns an existing membership with its CURRENT status and never reactivates it: a re-invite of a
        // suspended or removed member is refused here, not written locally as if it were active.
        if ($m['status'] !== 'active') {
            throw new ProvisioningException(ProvisioningException::STEP_ADD_MEMBER, 'The membership already exists with status "' . $m['status'] . '"; reactivate it explicitly instead of inviting again');
        }
        return $m['id'];
    }

    public function setMembershipStatus(string $membershipId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'removed'], true) || !Uuid::isValid($membershipId)) {
            throw new ProvisioningException('set_membership_status', 'status must be active, suspended or removed and the membership id a uuid');
        }
        $this->step('set_membership_status', fn () => $this->client->setMembershipStatus($membershipId, $status));

        // Auth confirmed, and auth is the source of truth: the change HAPPENED. Mirror it into the platform row WITHOUT
        // the local last-owner rule (auth already enforced it). If the mirror write fails the call still succeeds; the
        // drift is logged (ids only) and recorded for `php stone tenancy:preflight` to report.
        if ($this->main !== null) {
            try {
                $this->main->mirrorMembershipStatus($membershipId, $status);
                $this->main->clearMirrorDrift($membershipId);
            } catch (\Throwable) {
                if (function_exists('log_error')) {
                    log_error("ExternalMembershipAuthority: mirror drift: auth confirmed membership=$membershipId status=$status but the platform row was not updated");
                }
                try {
                    $this->main->recordMirrorDrift($membershipId, $status);
                } catch (\Throwable) {
                    // the log line above is then the only record
                }
            }
        }
    }

    public function listMemberships(string $identityId, #[\SensitiveParameter] ?string $userToken): array
    {
        if ($userToken === null || $userToken === '') {
            throw new ProvisioningException('list_memberships', 'The caller identity token is required to list memberships');
        }
        $out = [];
        foreach ($this->step('list_memberships', fn () => $this->client->listMemberships($userToken)) as $row) {
            $normalized = self::normalize($row);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    public function verifyMembership(string $identityId, #[\SensitiveParameter] ?string $userToken, string $tenantId, ?string $tenantSlug = null): ?array
    {
        if ($userToken === null || $userToken === '') {
            throw new ProvisioningException('verify_membership', 'The caller identity token is required to verify a membership');
        }
        $row = $this->step('verify_membership', fn () => $this->client->verifyMembership($userToken, $tenantId, $tenantSlug));
        if ($row === null) {
            return null;
        }
        $normalized = self::normalize($row);
        // A positive answer that does not name the same tenant is never trusted.
        return $normalized !== null && $normalized['tenant_id'] === $tenantId ? $normalized : null;
    }

    /** @param array<string,mixed> $row @return array{membership_id:string,tenant_id:string,tenant_name:string,tenant_slug:?string,tenant_db_name:string,is_tenant_owner:bool}|null */
    private static function normalize(array $row): ?array
    {
        $tenantId = (string) ($row['tenant_id'] ?? $row['platform_tenant_id'] ?? '');
        $membershipId = (string) ($row['membership_id'] ?? $row['id'] ?? $row['platform_tenant_membership_id'] ?? '');
        if ($tenantId === '' || $membershipId === '') {
            return null; // a row without ids cannot be selected; never invent them
        }
        $slug = $row['tenant_slug'] ?? $row['slug'] ?? null;
        return [
            'membership_id' => $membershipId,
            'tenant_id' => $tenantId,
            'tenant_name' => (string) ($row['tenant_name'] ?? $row['name'] ?? ''),
            'tenant_slug' => is_string($slug) && $slug !== '' ? $slug : null,
            'tenant_db_name' => (string) ($row['tenant_db_name'] ?? ''),
            'is_tenant_owner' => (bool) ($row['is_tenant_owner'] ?? false),
        ];
    }

    /**
     * @template T
     * @param callable():T $call
     * @return T
     */
    private function step(string $step, callable $call): mixed
    {
        try {
            return $call();
        } catch (ProvisioningException $e) {
            throw new ProvisioningException($step, $e->getMessage(), $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use StoneScriptPHP\Tenancy\Provisioning\BuiltinRequestStore;
use StoneScriptPHP\Tenancy\Provisioning\MainTenantStore;
use StoneScriptPHP\Tenancy\Provisioning\MembershipAuthority;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;
use StoneScriptPHP\Tenancy\Provisioning\TenantDatabaseCreator;
use StoneScriptPHP\Tenancy\Provisioning\TenantDbSeeder;
use StoneScriptPHP\Tenancy\Provisioning\TenantReservation;
use StoneScriptPHP\Tenancy\TenantIdRule;

/** Shared in-memory fakes for the tenancy tests. A single call log lets tests assert the step ORDER. */
final class CallLog
{
    /** @var list<string> */
    public array $calls = [];

    public function add(string $call): void
    {
        $this->calls[] = $call;
    }

    public function count(string $prefix): int
    {
        return count(array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, $prefix)));
    }
}

/** Authority that behaves like auth: ids are generated once per idempotency key. */
class FakeAuthority implements MembershipAuthority
{
    /** @var array<string,TenantReservation> */
    public array $byKey = [];
    /** @var array<string,string> tenantId => status */
    public array $status = [];
    public ?string $failAt = null;
    public int $uuidCounter = 0;
    public string $slug = 'acme';
    /** @var list<array{string,string,string}> */
    public array $members = [];

    public function __construct(private readonly CallLog $log)
    {
    }

    private function uuid(): string
    {
        $this->uuidCounter++;
        return sprintf('00000000-0000-4000-8000-%012d', $this->uuidCounter);
    }

    private function maybeFail(string $point): void
    {
        if ($this->failAt === $point) {
            throw new ProvisioningException($point, "forced failure at $point");
        }
    }

    public ?string $lastSlug = null;
    /** @var list<array<string,mixed>> */
    public array $verifyRows = [];
    public ?string $lastInvitedBy = null;

    public function verifyMembership(string $identityId, ?string $userToken, string $tenantId, ?string $tenantSlug = null): ?array
    {
        foreach ($this->verifyRows as $row) {
            if ($row['tenant_id'] === $tenantId) {
                return $row;
            }
        }
        return null;
    }

    public function reserveTenant(string $tenantName, string $idempotencyKey, string $identityId, ?string $userToken, ?string $tenantSlug = null): TenantReservation
    {
        $this->log->add('authority.reserve');
        $this->lastSlug = $tenantSlug;
        $this->lastUserToken = $userToken;
        $this->lastIdentity = $identityId;
        $this->maybeFail('reserve');
        if (isset($this->byKey[$idempotencyKey])) {
            $r = $this->byKey[$idempotencyKey];
            return new TenantReservation($r->platformTenantId, $r->tenantDbName, $r->membershipId, true);
        }
        $tenantId = $this->uuid();
        $r = new TenantReservation($tenantId, TenantIdRule::databaseName($this->slug, 'tenant', $tenantId), $this->uuid());
        $this->byKey[$idempotencyKey] = $r;
        $this->status[$tenantId] = 'provisioning';
        return $r;
    }

    public function markProvisioned(string $tenantId): void
    {
        $this->log->add('authority.provisioned');
        $this->maybeFail('auth_provisioned');
        $this->status[$tenantId] = 'provisioned';
    }

    public function markProvisioningFailed(string $tenantId, string $reason): void
    {
        $this->log->add('authority.failed:' . $reason);
        $this->maybeFail('auth_failed_report');
        $this->status[$tenantId] = 'provisioning_failed';
    }

    public function addMember(string $tenantId, string $identityId, string $idempotencyKey, ?string $invitedByIdentityId = null): string
    {
        $this->log->add('authority.addMember');
        $this->lastInvitedBy = $invitedByIdentityId;
        $this->maybeFail('add_member');
        $this->members[] = [$tenantId, $identityId, $idempotencyKey];
        return $this->uuid();
    }

    public ?string $lastUserToken = null;
    public ?string $lastIdentity = null;
    /** @var list<array<string,mixed>> */
    public array $listRows = [];
    public bool $listThrows = false;

    public function listMemberships(string $identityId, ?string $userToken): array
    {
        if ($this->listThrows) {
            throw new ProvisioningException('list_memberships', 'auth down');
        }
        return $this->listRows;
    }

    /** @var list<array{string,string}> */
    public array $statusChanges = [];

    public function setMembershipStatus(string $membershipId, string $status): void
    {
        $this->log->add('authority.setStatus:' . $status);
        $this->statusChanges[] = [$membershipId, $status];
    }
}

final class FakeMainStore implements MainTenantStore, BuiltinRequestStore
{
    /** @var array<string,array<string,mixed>> */
    public array $tenants = [];
    /** @var list<array<string,mixed>> */
    public array $memberships = [];
    /** @var array<string,array{identity_id:string,tenant_id:string,membership_id:string,tenant_db_name:string}> */
    public array $requests = [];
    public ?string $failAt = null;

    public function __construct(private readonly CallLog $log)
    {
    }

    private function maybeFail(string $point): void
    {
        if ($this->failAt === $point) {
            throw new \RuntimeException("forced failure at $point");
        }
    }

    public function getTenantStatus(string $tenantId): ?string
    {
        return $this->tenants[$tenantId]['status'] ?? null;
    }

    public function upsertTenant(string $tenantId, string $name, ?string $slug, string $tenantDbName, string $createdByIdentityId): void
    {
        $this->log->add('main.upsertTenant');
        $this->maybeFail('main_tenant_row');
        $existing = $this->tenants[$tenantId] ?? null;
        $status = $existing['status'] ?? 'provisioning';
        if ($status === 'provisioning_failed') {
            $status = 'provisioning';
        }
        $this->tenants[$tenantId] = ['name' => $name, 'slug' => $slug, 'db' => $tenantDbName, 'status' => $status, 'by' => $createdByIdentityId];
    }

    public function setTenantStatus(string $tenantId, string $status): void
    {
        $this->log->add('main.status:' . $status);
        if ($status === 'provisioned') {
            $this->maybeFail('main_provisioned');
        }
        $this->tenants[$tenantId]['status'] = $status;
    }

    public function createMembership(string $membershipId, string $tenantId, string $identityId, bool $isTenantOwner, ?string $invitedByIdentityId): void
    {
        $this->log->add('main.membership');
        $this->maybeFail('main_membership');
        foreach ($this->memberships as $m) {
            if ($m['identity'] === $identityId && $m['tenant'] === $tenantId) {
                return; // idempotent
            }
        }
        $this->memberships[] = ['id' => $membershipId, 'tenant' => $tenantId, 'identity' => $identityId, 'owner' => $isTenantOwner, 'invited_by' => $invitedByIdentityId];
    }

    public function listIdentityMemberships(string $identityId): array
    {
        $out = [];
        foreach ($this->memberships as $m) {
            if ($m['identity'] === $identityId && ($this->tenants[$m['tenant']]['status'] ?? null) === 'provisioned') {
                $t = $this->tenants[$m['tenant']];
                $out[] = ['membership_id' => $m['id'], 'tenant_id' => $m['tenant'], 'tenant_name' => $t['name'], 'tenant_slug' => $t['slug'], 'tenant_db_name' => $t['db'], 'is_tenant_owner' => $m['owner']];
            }
        }
        return $out;
    }

    public function find(string $idempotencyKey): ?array
    {
        return $this->requests[$idempotencyKey] ?? null;
    }

    public function save(string $idempotencyKey, string $identityId, string $tenantId, string $membershipId, string $tenantDbName): array
    {
        $this->requests[$idempotencyKey] ??= ['identity_id' => $identityId, 'tenant_id' => $tenantId, 'membership_id' => $membershipId, 'tenant_db_name' => $tenantDbName];
        return $this->requests[$idempotencyKey];
    }

    /** @var array<string,string> membership id => status (tests may pre-set a non-active status) */
    public array $membershipStatus = [];

    public function findMembership(string $tenantId, string $identityId): ?array
    {
        foreach ($this->memberships as $m) {
            if ($m['tenant'] === $tenantId && $m['identity'] === $identityId) {
                return ['id' => $m['id'], 'status' => $this->membershipStatus[$m['id']] ?? 'active'];
            }
        }
        return null;
    }

    public bool $failStatusChange = false;
    public bool $failMirror = false;
    public bool $failDriftRecord = false;
    /** @var array<string,string> membership id => status auth confirmed but was not mirrored */
    public array $drift = [];

    public function mirrorMembershipStatus(string $membershipId, string $status): void
    {
        if ($this->failMirror) {
            throw new \RuntimeException('main database down');
        }
        $this->log->add('main.mirror:' . $status);
        $this->membershipStatus[$membershipId] = $status;
    }

    public function recordMirrorDrift(string $membershipId, string $authStatus): void
    {
        if ($this->failDriftRecord) {
            throw new \RuntimeException('main database down');
        }
        $this->log->add('main.drift:' . $authStatus);
        $this->drift[$membershipId] = $authStatus;
    }

    public function clearMirrorDrift(string $membershipId): void
    {
        $this->log->add('main.driftCleared');
        unset($this->drift[$membershipId]);
    }

    public function setMembershipStatus(string $membershipId, string $status): void
    {
        if ($this->failStatusChange) {
            throw new \RuntimeException('main database down');
        }
        $this->log->add('main.membershipStatus:' . $status);
        $this->membershipStatus[$membershipId] = $status;
    }
}

final class FakeDatabases implements TenantDatabaseCreator
{
    /** @var list<string> */
    public array $created = [];
    public ?string $failAt = null;
    /** name to report; null = report the derived name, '' = report nothing (null) */
    public ?string $reportName = null;

    public function __construct(private readonly CallLog $log, private readonly string $slug = 'acme')
    {
    }

    public function create(string $platformTenantId): ?string
    {
        $this->log->add('db.create');
        if ($this->failAt === 'create_database') {
            throw new ProvisioningException('create_database', 'forced failure at create_database');
        }
        $this->created[] = $platformTenantId;
        if ($this->reportName === '') {
            return null;
        }
        return $this->reportName ?? TenantIdRule::databaseName($this->slug, 'tenant', $platformTenantId);
    }
}

final class FakeSeeder implements TenantDbSeeder
{
    /** @var list<array{string,string,string,?string}> */
    public array $users = [];
    public ?string $failAt = null;
    /** @var list<array{string,array<string,list<string>>,string}> */
    public array $privilegeSeeds = [];
    /** what seedPrivileges reports for the owner role (0 = the owner has no privileges) */
    public int $ownerPrivileges = 1;

    public function __construct(private readonly CallLog $log)
    {
    }

    public function seedPrivileges(string $platformTenantId, array $grants, string $checkRole): int
    {
        $this->log->add('tenantdb.privileges');
        if ($this->failAt === 'seed_privileges') {
            throw new \RuntimeException('forced failure at seed_privileges');
        }
        $this->privilegeSeeds[] = [$platformTenantId, $grants, $checkRole];
        return $this->ownerPrivileges;
    }

    public function ensureUserWithRole(string $platformTenantId, string $identityId, ?string $displayName, string $roleCode, ?string $assignedByIdentityId): void
    {
        $this->log->add('tenantdb.user:' . $roleCode);
        if ($this->failAt === 'owner_user') {
            throw new \RuntimeException('forced failure at owner_user');
        }
        $this->users[] = [$platformTenantId, $identityId, $roleCode, $assignedByIdentityId];
    }
}

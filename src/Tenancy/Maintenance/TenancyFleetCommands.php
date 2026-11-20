<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Maintenance;

use StoneScriptPHP\Tenancy\Provisioning\Uuid;

/**
 * The two fleet-wide tenancy commands (behaviour lives here, the CLI files only wire it):
 *
 *   php stone tenancy:seed-privileges [--tenant=<uuid>] [--dry-run]
 *       Applies config/tenant-privileges.php to EVERY provisioned tenant database (or one), idempotently, and reports
 *       per tenant. --dry-run changes nothing and lists what would be added. A tenant that fails is reported and the
 *       run goes on; the exit status is 1 if any tenant failed.
 *
 *   php stone tenancy:preflight
 *       MANDATORY before TENANCY_V2 is switched on for a platform: exits 1 if (a) any provisioned tenant has a role that
 *       users hold but that has ZERO privileges, (b) any ACTIVE membership has no active tenant-DB user holding a role
 *       with privileges (such a user would be refused at select-tenant; this also catches an empty user_roles before a
 *       platform's one-off role move), or (c) a membership status confirmed by auth was never mirrored (drift).
 *       Read-only; reports counts and ids only.
 */
final class TenancyFleetCommands
{
    /** @var callable(string):void */
    private $stdout;
    /** @var callable(string):void */
    private $stderr;

    /**
     * @param callable(string):void $stdout
     * @param callable(string):void $stderr
     */
    public function __construct(callable $stdout, callable $stderr, private readonly TenantFleet $fleet)
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    /**
     * @param list<string> $args
     * @param array<string,list<string>> $grants the platform privilege seed
     */
    public function seedPrivileges(array $args, array $grants): int
    {
        $tenant = null;
        $dryRun = false;
        foreach ($args as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif (preg_match('/\A--tenant=(.+)\z/s', (string) $arg, $m) === 1) {
                $tenant = $m[1];
            } else {
                return $this->fail('Usage: php stone tenancy:seed-privileges [--tenant=<uuid>] [--dry-run]');
            }
        }
        if ($tenant !== null && !Uuid::isValid($tenant)) {
            return $this->fail('--tenant must be a tenant uuid');
        }
        if ($grants === [] || count(array_filter($grants)) === 0) {
            return $this->fail('config/tenant-privileges.php defines no privileges: nothing to apply');
        }

        try {
            $tenants = $this->fleet->provisionedTenants();
        } catch (\Throwable) {
            return $this->fail('Could not list the provisioned tenants (is the tenancy schema migrated?)');
        }
        if ($tenant !== null) {
            $tenants = array_values(array_filter($tenants, static fn (array $t): bool => $t['id'] === $tenant));
            if ($tenants === []) {
                return $this->fail("Tenant $tenant is not a provisioned tenant of this platform");
            }
        }

        $failed = 0;
        $changed = 0;
        foreach ($tenants as $t) {
            $label = $t['id'] . ' (' . $t['db_name'] . ')';
            try {
                $missing = $this->fleet->missingPrivileges($t['id'], $grants);
                if ($missing === []) {
                    $this->out("ok      $label: nothing to add");
                    continue;
                }
                $changed++;
                if ($dryRun) {
                    $this->out("would   $label: add " . count($missing) . ' privilege(s): ' . $this->describe($missing));
                    continue;
                }
                $this->fleet->applyPrivileges($t['id'], $grants);
                $this->out("seeded  $label: added " . count($missing) . ' privilege(s): ' . $this->describe($missing));
            } catch (\Throwable) {
                $failed++;
                ($this->stderr)("FAILED  $label: could not read or write the tenant database\n");
            }
        }

        $this->out(sprintf('%d tenant(s) visited, %d %s, %d failed%s', count($tenants), $changed, $dryRun ? 'would change' : 'changed', $failed, $dryRun ? ' (dry run: nothing written)' : ''));
        return $failed === 0 ? 0 : 1;
    }

    public function preflight(): int
    {
        try {
            $tenants = $this->fleet->provisionedTenants();
        } catch (\Throwable) {
            return $this->fail('Could not list the provisioned tenants (is the tenancy schema migrated?)');
        }

        $problems = 0;
        foreach ($tenants as $t) {
            $label = $t['id'] . ' (' . $t['db_name'] . ')';
            try {
                $counts = $this->fleet->roleCounts($t['id']);
            } catch (\Throwable) {
                $problems++;
                ($this->stderr)("FAIL $label: could not read the tenant database\n");
                continue;
            }
            foreach ($counts as $c) {
                if ($c['users'] > 0 && $c['privileges'] === 0) {
                    $problems++;
                    ($this->stderr)("FAIL $label: role '{$c['role']}' is held by {$c['users']} user(s) but has no privileges\n");
                }
            }

            // Every ACTIVE member must be able to select the tenant: an active tenant-DB user holding a role with
            // privileges. (Covers empty user_roles before a platform's one-off role move.) Counts and ids only.
            try {
                $members = $this->fleet->activeMemberIdentities($t['id']);
                $withAccess = array_flip($this->fleet->identitiesWithAccess($t['id']));
            } catch (\Throwable) {
                $problems++;
                ($this->stderr)("FAIL $label: could not compare the active memberships with the tenant database\n");
                continue;
            }
            $without = array_values(array_filter($members, static fn (string $id): bool => !isset($withAccess[$id])));
            if ($without !== []) {
                $problems++;
                ($this->stderr)(sprintf("FAIL %s: %d of %d active member(s) have no active tenant user with a role that has privileges; identities: %s\n", $label, count($without), count($members), implode(', ', $without)));
            }
        }

        // Membership status changes that auth confirmed but that were not mirrored into this platform's rows.
        try {
            $drift = $this->fleet->mirrorDrift();
        } catch (\Throwable) {
            $problems++;
            ($this->stderr)("FAIL could not read the membership mirror drift list\n");
            $drift = [];
        }
        if ($drift !== []) {
            $problems++;
            ($this->stderr)(sprintf("FAIL mirror drift: %d membership(s) have an auth-confirmed status that was not mirrored: %s\n", count($drift), implode(', ', array_map(static fn (array $d): string => $d['membership_id'] . '=' . $d['status'], $drift))));
        }

        if ($problems > 0) {
            ($this->stderr)("\nPre-flight FAILED ($problems problem(s) in " . count($tenants) . " tenant(s)). Do not set TENANCY_V2=true. Fix with: php stone tenancy:seed-privileges\n");
            return 1;
        }
        $this->out('Pre-flight passed: ' . count($tenants) . ' provisioned tenant(s), every held role has privileges.');
        return 0;
    }

    /** @param list<array{role:string,privilege:string}> $missing */
    private function describe(array $missing): string
    {
        $by = [];
        foreach ($missing as $m) {
            $by[$m['role']][] = $m['privilege'];
        }
        $parts = [];
        foreach ($by as $role => $privileges) {
            $parts[] = $role . ' [' . implode(', ', $privileges) . ']';
        }
        return implode('; ', $parts);
    }

    private function out(string $line): void
    {
        ($this->stdout)($line . "\n");
    }

    private function fail(string $message): int
    {
        ($this->stderr)('ERROR: ' . $message . "\n");
        return 1;
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware;
use StoneScriptPHP\Auth\TenantRoles\TenantRoleResolver;
use StoneScriptPHP\Auth\TenantRoles\TenantRoleStore;
use StoneScriptPHP\Tenancy\Provisioning\DatabaseTenantDbSeeder;
use StoneScriptPHP\Tenancy\Provisioning\TenantPrivilegeSeed;

/**
 * The platform privilege seed (validation, loading, the SQL boundary), the strict role resolver, and the token
 * shapes (roles[] only on a v2 'card' token without role_id; legacy tokens unchanged).
 *
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenantPrivilegeSeed
 * @covers \StoneScriptPHP\Tenancy\Provisioning\DatabaseTenantDbSeeder
 * @covers \StoneScriptPHP\Auth\TenantRoles\TenantRoleResolver
 * @covers \StoneScriptPHP\Auth\AuthenticatedUser
 * @covers \StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware
 */
final class PrivilegeSeedAndRolesTest extends TestCase
{
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';

    public function test_the_seed_is_validated_deduplicated_and_sorted(): void
    {
        $seed = TenantPrivilegeSeed::fromArray(['owner' => ['b.manage', 'a.create', 'a.create'], 'cashier' => []]);

        $this->assertSame(['owner' => ['a.create', 'b.manage'], 'cashier' => []], $seed);
    }

    public function test_a_malformed_role_or_privilege_code_is_refused(): void
    {
        foreach ([['Owner' => ['x']], ['own er' => ['x']], [0 => ['x']], ['owner' => 'x'], ['owner' => ['X.create']], ['owner' => ['a b']], ['owner' => [1]], ['owner' => ["a'; drop"]]] as $bad) {
            try {
                TenantPrivilegeSeed::fromArray($bad);
                $this->fail('accepted: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_seed_is_loaded_from_the_config_file_and_a_missing_file_is_an_empty_seed(): void
    {
        $dir = sys_get_temp_dir() . '/ssp-seed-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/tenant-privileges.php', "<?php\nreturn ['owner' => ['tenant.manage']];\n");
        file_put_contents($dir . '/bad.php', "<?php\nreturn 'nope';\n");
        try {
            $this->assertSame(['owner' => ['tenant.manage']], TenantPrivilegeSeed::fromFile($dir . '/tenant-privileges.php'));
            $this->assertSame([], TenantPrivilegeSeed::fromFile($dir . '/missing.php'));
            $this->expectException(\InvalidArgumentException::class);
            TenantPrivilegeSeed::fromFile($dir . '/bad.php');
        } finally {
            unlink($dir . '/tenant-privileges.php');
            unlink($dir . '/bad.php');
            rmdir($dir);
        }
    }

    public function test_the_stub_the_generator_writes_is_a_valid_seed_with_a_filled_in_owner(): void
    {
        $seed = TenantPrivilegeSeed::fromFile(__DIR__ . '/../../../src/Templates/Tenancy/config/tenant-privileges.php.template');

        $this->assertNotSame([], $seed['owner'] ?? [], 'the stub must not ship an owner role that fails the provisioning check');
    }

    public function test_the_seeder_calls_the_generated_function_inside_the_tenant_scope_and_returns_the_count(): void
    {
        $calls = [];
        $routes = [];
        $seeder = new DatabaseTenantDbSeeder(function (string $fn, array $params) use (&$calls): array {
            $calls[] = [$fn, $params];
            return [['o_privilege_count' => 2]];
        }, function (?string $id) use (&$routes): ?string {
            $routes[] = $id;
            return null;
        });

        $count = $seeder->seedPrivileges(self::TENANT, ['owner' => ['a.b', 'c.d']], 'owner');

        $this->assertSame(2, $count);
        $this->assertSame([self::TENANT, null], $routes);
        $this->assertSame('tenant_seed_privileges', $calls[0][0]);
        $this->assertSame(['owner' => ['a.b', 'c.d']], json_decode($calls[0][1][0], true));
        $this->assertSame('owner', $calls[0][1][1]);
    }

    public function test_an_empty_seed_is_sent_as_a_json_object_not_an_array(): void
    {
        $sent = null;
        $seeder = new DatabaseTenantDbSeeder(function (string $fn, array $params) use (&$sent): array {
            $sent = $params[0];
            return [['o_privilege_count' => 0]];
        }, fn (?string $id): ?string => null);

        $seeder->seedPrivileges(self::TENANT, [], 'owner');

        $this->assertSame('{}', $sent, 'the SQL function expects a JSON object');
    }

    public function test_a_count_that_does_not_come_back_is_an_error(): void
    {
        $seeder = new DatabaseTenantDbSeeder(fn (): array => [], fn (?string $id): ?string => null);

        $this->expectException(\RuntimeException::class);
        $seeder->seedPrivileges(self::TENANT, [], 'owner');
    }

    // ---- W1: strict resolver ----

    private function failingStore(): TenantRoleStore
    {
        return new class implements TenantRoleStore {
            public function roleGrants(string $identityId): array
            {
                throw new \RuntimeException('down');
            }
        };
    }

    public function test_strict_variants_throw_where_the_lenient_ones_grant_nothing(): void
    {
        $resolver = new TenantRoleResolver($this->failingStore());

        $this->assertSame([], $resolver->rolesFor(self::IDENTITY));
        $this->assertSame([], $resolver->privilegesFor(self::IDENTITY));
        foreach (['strictRolesFor', 'strictPrivilegesFor', 'strictAccess'] as $method) {
            try {
                $resolver->$method(self::IDENTITY);
                $this->fail("$method must throw when the store fails");
            } catch (\RuntimeException $e) {
                $this->assertSame('down', $e->getMessage());
            }
        }
    }

    public function test_strict_access_returns_known_roles_and_the_privilege_union_from_one_read(): void
    {
        $counter = new \stdClass();
        $counter->reads = 0;
        $resolver = new TenantRoleResolver(new class ($counter) implements TenantRoleStore {
            public function __construct(private \stdClass $counter)
            {
            }

            public function roleGrants(string $identityId): array
            {
                $this->counter->reads++;
                return ['owner' => ['b', 'a'], 'cashier' => ['a'], 'ghost' => []];
            }
        });

        $this->assertSame(['roles' => ['cashier', 'owner'], 'privileges' => ['a', 'b']], $resolver->strictAccess(self::IDENTITY));
        $this->assertSame(1, $counter->reads);
        $this->assertSame(['roles' => [], 'privileges' => []], $resolver->strictAccess(''), 'no identity is no roles, not an error');
    }

    // ---- W6: token shapes ----

    public function test_roles_are_read_only_from_a_v2_card_token_without_role_id(): void
    {
        $v2 = AuthenticatedUser::fromPayload(['sub' => self::IDENTITY, 'token_type' => 'card', 'roles' => ['owner', 'cashier']]);
        $this->assertSame(['owner', 'cashier'], $v2->roles);
        $this->assertNull($v2->role_id, 'a v2 token has no primary role');
        $this->assertTrue($v2->hasRole('cashier'));
        $this->assertTrue($v2->hasAnyRole(['admin', 'owner']));
        $this->assertFalse($v2->hasAnyRole(['admin']));

        // A card token that also carries role_id keeps the single-role meaning.
        $single = AuthenticatedUser::fromPayload(['sub' => self::IDENTITY, 'token_type' => 'card', 'role_id' => 'owner', 'roles' => ['x', 'y']]);
        $this->assertSame([], $single->roles);
        $this->assertSame('owner', $single->role_id);
        $this->assertFalse($single->hasRole('x'));

        // A legacy platform token: roles[0] is still its user_role/role_id, roles stays empty.
        $legacy = AuthenticatedUser::fromPayload(['sub' => self::IDENTITY, 'token_type' => 'platform', 'roles' => ['admin', 'member']]);
        $this->assertSame([], $legacy->roles);
        $this->assertSame('admin', $legacy->user_role);
        $this->assertSame('admin', $legacy->role_id);

        // No token_type at all: legacy behaviour.
        $bare = AuthenticatedUser::fromPayload(['sub' => self::IDENTITY, 'roles' => ['admin']]);
        $this->assertSame([], $bare->roles);
        $this->assertSame('admin', $bare->user_role);
    }

    public function test_the_role_middleware_uses_roles_only_for_v2_card_tokens(): void
    {
        $next = static fn (array $r) => null;
        $admits = static fn (array $claims, array $allowed): bool => (new RequireRoleMiddleware($allowed))->handle(['jwt_claims' => $claims], $next) === null;

        $this->assertTrue($admits(['token_type' => 'card', 'roles' => ['cashier', 'owner']], ['owner']));
        $this->assertFalse($admits(['token_type' => 'card', 'roles' => ['cashier']], ['owner']));
        // Legacy shapes keep their behaviour: a roles[] array without role_id/user_role is not consulted.
        $this->assertFalse($admits(['token_type' => 'platform', 'roles' => ['owner']], ['owner']), 'legacy platform token: unchanged (refused)');
        $this->assertFalse($admits(['roles' => ['owner']], ['owner']));
        // role_id wins on any token, as before.
        $this->assertTrue($admits(['token_type' => 'card', 'role_id' => 'owner', 'roles' => ['x']], ['owner']));
        $this->assertFalse($admits(['token_type' => 'card', 'role_id' => 'cashier', 'roles' => ['owner']], ['owner']), 'role_id present: roles[] is not used');
    }
}

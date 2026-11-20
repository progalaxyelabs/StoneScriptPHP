<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute;
use StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\Tenancy\Provisioning\MembershipAuthority;

require_once __DIR__ . '/TenancyFakes.php';

/** Only the two protected seams a platform or test may set: the flag and the authority. Everything else is real. */
final class RealSelectTenantRoute extends SelectTenantRoute
{
    public bool $v2 = true;
    public ?MembershipAuthority $authority = null;

    protected function tenancyV2Enabled(): bool
    {
        return $this->v2;
    }

    protected function membershipAuthority(): MembershipAuthority
    {
        return $this->authority ?? throw new \LogicException('no authority');
    }
}

/**
 * select-tenant under TENANCY_V2: the REAL tenant-DB role read (TenantRoleResolver + DatabaseTenantRoleStore
 * over Database::fake(), the framework's gateway test double) and the REAL RS256 signing. The token carries
 * ALL known roles and no primary role; privileges are the union of the roles' privileges.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute
 * @covers \StoneScriptPHP\Auth\TokenExchangeService
 * @covers \StoneScriptPHP\Auth\AuthenticatedUser
 * @covers \StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenantDbScope
 */
final class SelectTenantV2Test extends TestCase
{
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const MEMBERSHIP = '33333333-3333-4333-8333-333333333333';

    /** @var list<string> */
    private array $touched = [];
    private string $keyDir;
    private string $publicKey;
    /** @var list<array{string,array}> */
    private array $dbCalls = [];

    protected function setUp(): void
    {
        foreach (['DB_GATEWAY_URL' => 'http://gateway.invalid:9000', 'DB_GATEWAY_PLATFORM' => 'acme', 'AUTH_SERVICE_URL' => 'http://auth.invalid:3139', 'AUTH_ISSUER' => 'http://auth.invalid:3139'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $this->touched[] = $k;
        }
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer identity-token';

        $this->keyDir = sys_get_temp_dir() . '/ssp-select-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->keyDir . '/private.pem', $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
    }

    protected function tearDown(): void
    {
        Database::clearFakeMode();
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
        AuthContext::clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        foreach ($this->touched as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        @unlink($this->keyDir . '/private.pem');
        @rmdir($this->keyDir);
    }

    /** @param list<array{role:string,privilege:?string}>|\Closure $rows */
    private function fakeTenantDb(array|\Closure $rows): void
    {
        Database::fake(['tenant_get_identity_role_privileges' => function (array $params) use ($rows): array {
            $this->dbCalls[] = ['tenant_get_identity_role_privileges', $params];
            if ($rows instanceof \Closure) {
                return $rows();
            }
            return array_map(static fn (array $r): array => ['o_role_code' => $r['role'], 'o_privilege_code' => $r['privilege']], $rows);
        }]);
    }

    private function route(?FakeAuthority $authority = null, bool $withKey = true): RealSelectTenantRoute
    {
        AuthContext::setUser(new AuthenticatedUser(user_id: self::IDENTITY, email: 'o@example.com', platform_code: 'acme'));
        $options = ['platform_secret' => 'unused', 'platform_code' => 'acme', 'signing_issuer' => 'https://api.acme.test', 'exchange_ttl' => 600];
        if ($withKey) {
            $options['signing_private_key_path'] = $this->keyDir . '/private.pem';
        }
        $config = new ExternalAuthConfig($options);
        $route = new RealSelectTenantRoute(new ExternalAuthServiceClient('http://auth.invalid:3139', 'acme'), $config->hooks, $config);
        $authority ??= new FakeAuthority(new CallLog());
        $authority->verifyRows ??= [];
        $route->authority = $authority;
        $route->tenant_id = self::TENANT;
        return $route;
    }

    private function member(): FakeAuthority
    {
        $authority = new FakeAuthority(new CallLog());
        $authority->verifyRows = [['membership_id' => self::MEMBERSHIP, 'tenant_id' => self::TENANT, 'tenant_name' => 'Acme', 'tenant_slug' => null, 'tenant_db_name' => 'acme_tenant_x', 'is_tenant_owner' => false]];
        return $authority;
    }

    /** @return array<string,mixed> */
    private function decode(string $jwt): array
    {
        return (array) JWT::decode($jwt, new Key($this->publicKey, 'RS256'));
    }

    public function test_the_token_carries_all_known_roles_and_no_primary_role_and_privileges_are_the_union(): void
    {
        $this->fakeTenantDb([
            ['role' => 'owner', 'privilege' => 'staff.invite'],
            ['role' => 'owner', 'privilege' => 'sales.create'],
            ['role' => 'cashier', 'privilege' => 'sales.create'],
            ['role' => 'ghost', 'privilege' => null],
        ]);

        $res = $this->route($this->member())->process();

        $this->assertSame('ok', $res->status);
        $this->assertSame([self::IDENTITY], $this->dbCalls[0][1], 'the real role read asks the tenant database for this identity');
        $this->assertSame(['cashier', 'owner'], $res->data['roles'], 'every known role, the unknown one is dropped');
        $this->assertSame(['sales.create', 'staff.invite'], $res->data['privileges'], 'union of the roles privileges');
        $this->assertArrayNotHasKey('active_role', $res->data);
        $this->assertArrayNotHasKey('available_roles', $res->data);
        $this->assertArrayNotHasKey('role', $res->data['membership']);

        $claims = $this->decode($res->data['access_token']);
        $this->assertSame(['cashier', 'owner'], (array) $claims['roles']);
        $this->assertArrayNotHasKey('role_id', $claims, 'no single or primary role on the token');
        $this->assertSame(self::TENANT, $claims['tenant_id']);
        $this->assertSame(self::IDENTITY, $claims['identity_id']);
        $this->assertSame('acme', $claims['platform_code']);
        $this->assertSame('https://api.acme.test', $claims['iss']);
        $this->assertSame('card', $claims['token_type']);
        $this->assertSame(600, $claims['exp'] - $claims['iat']);
    }

    public function test_a_signature_made_with_another_key_does_not_verify(): void
    {
        $this->fakeTenantDb([['role' => 'owner', 'privilege' => 'x']]);
        $res = $this->route($this->member())->process();

        $other = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['key'];

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        JWT::decode($res->data['access_token'], new Key($other, 'RS256'));
    }

    public function test_the_authenticated_user_and_the_role_middleware_use_roles_not_a_primary_role(): void
    {
        $this->fakeTenantDb([['role' => 'cashier', 'privilege' => 'a'], ['role' => 'auditor', 'privilege' => 'b']]);
        $claims = $this->decode($this->route($this->member())->process()->data['access_token']);

        $user = AuthenticatedUser::fromPayload($claims);
        $this->assertSame(['auditor', 'cashier'], $user->roles);
        $this->assertNull($user->role_id, 'a roles[] API token has no role_id');
        $this->assertTrue($user->hasRole('auditor'));
        $this->assertTrue($user->hasRole('cashier'));
        $this->assertFalse($user->hasRole('owner'));

        $reached = false;
        $next = function () use (&$reached) {
            $reached = true;
            return null;
        };
        $this->assertNull((new RequireRoleMiddleware(['owner', 'auditor']))->handle(['jwt_claims' => $claims], $next));
        $this->assertTrue($reached, 'any held role on the allowed list admits');

        $reached = false;
        $denied = (new RequireRoleMiddleware(['owner']))->handle(['jwt_claims' => $claims], $next);
        $this->assertSame(403, $denied->httpStatusCode);
        $this->assertFalse($reached, 'no held role on the allowed list refuses');
    }

    public function test_a_member_with_only_unknown_roles_or_none_is_refused_and_nothing_is_signed(): void
    {
        foreach ([[['role' => 'authenticated', 'privilege' => null]], []] as $rows) {
            $this->fakeTenantDb($rows);
            $res = $this->route($this->member())->process();

            $this->assertSame(403, $res->httpStatusCode);
            $this->assertSame('no_tenant_role', $res->data['error']);
            $this->assertArrayNotHasKey('access_token', (array) $res->data);
        }
    }

    public function test_no_membership_is_403_and_the_tenant_database_is_never_read(): void
    {
        $this->fakeTenantDb([['role' => 'owner', 'privilege' => 'x']]);

        $res = $this->route(new FakeAuthority(new CallLog()))->process();

        $this->assertSame(403, $res->httpStatusCode);
        $this->assertSame('tenant_access_denied', $res->data['error']);
        $this->assertSame([], $this->dbCalls);
    }

    public function test_a_failing_tenant_role_read_is_a_502_never_mistaken_for_no_role_and_signs_nothing(): void
    {
        $this->fakeTenantDb(static function (): array {
            throw new \RuntimeException('tenant database unreachable');
        });

        $res = $this->route($this->member())->process();

        $this->assertSame(502, $res->httpStatusCode, 'a store error is not "this user has no role"');
        $this->assertNotSame('no_tenant_role', $res->data['error'] ?? null);
        $this->assertArrayNotHasKey('access_token', (array) $res->data);
    }

    public function test_without_a_signing_key_no_token_is_issued(): void
    {
        $this->fakeTenantDb([['role' => 'owner', 'privilege' => 'x']]);

        $res = $this->route($this->member(), false)->process();

        $this->assertSame('error', $res->status);
        $this->assertSame(500, $res->httpStatusCode);
    }

    public function test_selection_token_is_optional_under_v2_and_still_required_in_14x(): void
    {
        $route = $this->route();
        $route->v2 = true;
        $this->assertStringContainsString('optional', $route->validation_rules()['selection_token']);
        $route->v2 = false;
        $this->assertStringContainsString('required', $route->validation_rules()['selection_token']);
    }
}

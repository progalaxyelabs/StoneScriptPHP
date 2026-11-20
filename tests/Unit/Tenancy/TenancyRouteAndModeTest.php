<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Env;
use StoneScriptPHP\Tenancy\Provisioning\BuiltinMembershipAuthority;
use StoneScriptPHP\Tenancy\Provisioning\DatabaseMainTenantStore;
use StoneScriptPHP\Tenancy\Provisioning\ExternalMembershipAuthority;
use StoneScriptPHP\Tenancy\Provisioning\TenancyMode;
use StoneScriptPHP\Tenancy\Provisioning\TenantProvisioningSequence;

require_once __DIR__ . '/TenancyFakes.php';

/** Route that lets the test choose the flag and the sequence (the two protected seams a platform also uses). */
final class V2ProvisionRoute extends ProvisionTenantRoute
{
    public bool $v2 = true;
    public ?TenantProvisioningSequence $sequence = null;

    protected function tenancyV2Enabled(): bool
    {
        return $this->v2;
    }

    protected function buildTenancySequence(): TenantProvisioningSequence
    {
        return $this->sequence ?? throw new \LogicException('no sequence for this test');
    }
}

final class V2MembershipsRoute extends \StoneScriptPHP\Auth\ExternalAuth\Routes\MembershipsRoute
{
    public bool $v2 = true;
    public ?\StoneScriptPHP\Tenancy\Provisioning\MembershipAuthority $authority = null;

    protected function tenancyV2Enabled(): bool
    {
        return $this->v2;
    }

    protected function membershipAuthority(): \StoneScriptPHP\Tenancy\Provisioning\MembershipAuthority
    {
        return $this->authority ?? throw new \LogicException('no authority');
    }
}

/**
 * TENANCY_V2 switch, AUTH_MODE authority selection, and the provision-tenant route running the v2
 * sequence (success, failure step reported, 14.x flow untouched when the flag is off).
 *
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenancyMode
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute
 */
final class TenancyRouteAndModeTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    protected function setUp(): void
    {
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.invalid:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'acme');
        $this->setEnv('AUTH_SERVICE_URL', 'http://auth.invalid:3139');
        $this->setEnv('AUTH_ISSUER', 'http://auth.invalid:3139');
        $this->resetEnv();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer user-token';
    }

    protected function tearDown(): void
    {
        $this->resetEnv();
        AuthContext::clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        foreach ($this->touched as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->touched = [];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $this->touched[] = $name;
    }

    private function unsetEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name]);
    }

    private function resetEnv(): void
    {
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
    }

    public function test_tenancy_v2_is_off_by_default_and_on_only_when_set(): void
    {
        $this->unsetEnv('TENANCY_V2');
        $this->resetEnv();
        $this->assertFalse(TenancyMode::v2Enabled(Env::get_instance()), 'platforms upgrading to 15.0.0 keep the old flow until they switch');

        $this->setEnv('TENANCY_V2', 'true');
        $this->resetEnv();
        $this->assertTrue(TenancyMode::v2Enabled(Env::get_instance()));

        $this->setEnv('TENANCY_V2', 'false');
        $this->resetEnv();
        $this->assertFalse(TenancyMode::v2Enabled(Env::get_instance()));
    }

    public function test_auth_mode_selects_the_authority(): void
    {
        $store = new DatabaseMainTenantStore(fn (): array => []);

        $this->setEnv('AUTH_MODE', 'external');
        $this->setEnv('AUTH_V2_CLIENT_ID', 'client-1');
        $this->setEnv('AUTH_V2_CLIENT_SECRET', 'secret-1');
        $this->resetEnv();
        $this->assertInstanceOf(ExternalMembershipAuthority::class, TenancyMode::authority(Env::get_instance(), $store));

        $this->setEnv('AUTH_MODE', 'hybrid');
        $this->resetEnv();
        $this->assertInstanceOf(ExternalMembershipAuthority::class, TenancyMode::authority(Env::get_instance(), $store));

        $this->setEnv('AUTH_MODE', 'builtin');
        $this->resetEnv();
        $this->assertInstanceOf(BuiltinMembershipAuthority::class, TenancyMode::authority(Env::get_instance(), $store));
    }

    private function route(bool $v2, ?TenantProvisioningSequence $sequence): V2ProvisionRoute
    {
        AuthContext::setUser(new AuthenticatedUser(user_id: '11111111-1111-4111-8111-111111111111', email: 'owner@example.com', platform_code: 'acme'));
        $config = new ExternalAuthConfig(['provision_tenant' => true, 'platform_secret' => 'unused', 'platform_code' => 'acme']);
        $route = new V2ProvisionRoute(new ExternalAuthServiceClient('http://auth.invalid:3139', 'acme'), $config->hooks, $config, null);
        $route->v2 = $v2;
        $route->sequence = $sequence;
        $route->tenant_name = 'Acme Shop';
        $route->idempotency_key = 'idem-1';
        return $route;
    }

    // ---- 4.4: memberships list and select-tenant read the v2 authority ----

    private function listRoute(FakeAuthority $authority, bool $v2 = true): V2MembershipsRoute
    {
        AuthContext::setUser(new AuthenticatedUser(user_id: '11111111-1111-4111-8111-111111111111', email: 'o@example.com', platform_code: 'acme'));
        $config = new ExternalAuthConfig(['platform_secret' => 'unused', 'platform_code' => 'acme']);
        $route = new V2MembershipsRoute(new ExternalAuthServiceClient('http://auth.invalid:3139', 'acme'), $config->hooks, $config);
        $route->v2 = $v2;
        $route->authority = $authority;
        return $route;
    }

    public function test_memberships_list_uses_the_authority_when_v2_is_on_and_slug_may_be_null(): void
    {
        $authority = new class (new CallLog()) extends FakeAuthority {
            public function listMemberships(string $identityId, ?string $userToken): array
            {
                return [['membership_id' => 'm-1', 'tenant_id' => 't-1', 'tenant_name' => 'Acme', 'tenant_slug' => null, 'tenant_db_name' => 'acme_tenant_x', 'is_tenant_owner' => true]];
            }
        };

        $res = $this->listRoute($authority)->process();

        $this->assertSame('ok', $res->status);
        $this->assertTrue($res->data['success']);
        $this->assertSame('t-1', $res->data['memberships'][0]['tenant_id']);
        $this->assertNull($res->data['memberships'][0]['tenant_slug']);
        $this->assertSame('m-1', $res->data['memberships'][0]['id']);
    }

    public function test_memberships_list_failure_is_a_502_not_an_empty_list(): void
    {
        $authority = new class (new CallLog()) extends FakeAuthority {
            public function listMemberships(string $identityId, ?string $userToken): array
            {
                throw new \StoneScriptPHP\Tenancy\Provisioning\ProvisioningException('list_memberships', 'down');
            }
        };

        $res = $this->listRoute($authority)->process();

        $this->assertSame('error', $res->status);
        $this->assertSame(502, $res->httpStatusCode);
    }

    public function test_provision_tenant_does_not_touch_the_v2_sequence_when_the_flag_is_off(): void
    {
        // buildTenancySequence() would throw LogicException if it were used: with the flag off the 14.x flow
        // runs instead (here it stops at the missing platform secret / provisioner, not in the sequence).
        $route = $this->route(false, null);

        try {
            $route->process();
        } catch (\LogicException $e) {
            $this->fail('the v2 sequence must not be built when TENANCY_V2 is off');
        } catch (\Throwable) {
            // any other outcome of the legacy flow is fine for this assertion
        }
        $this->addToAssertionCount(1);
    }
}

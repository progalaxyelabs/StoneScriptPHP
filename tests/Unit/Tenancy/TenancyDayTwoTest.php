<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\Client\AuthServiceException;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\Tenancy\Provisioning\MembershipAuthority;
use StoneScriptPHP\Tenancy\Provisioning\TenantProvisioningSequence;

require_once __DIR__ . '/TenancyFakes.php';
require_once __DIR__ . '/InMemoryTenantDbs.php';

final class PromotingClient extends ExternalAuthServiceClient
{
    /** @var array<string,mixed>|\Throwable */
    public array|\Throwable $promoteResult = [];

    public function __construct(private readonly CallLog $log)
    {
        parent::__construct('http://auth.invalid:3139', 'acme');
    }

    public function promoteOAuthConnection(string $oauthState, string $authToken): array
    {
        $this->log->add('client.promote');
        if ($this->promoteResult instanceof \Throwable) {
            throw $this->promoteResult;
        }
        return $this->promoteResult;
    }
}

/** Real route code; only the seams a platform also has (flag, authority, sequence pieces, seed) are set. */
final class DayTwoProvisionRoute extends ProvisionTenantRoute
{
    public ?MembershipAuthority $authority = null;
    public ?FakeMainStore $main = null;
    public ?FakeDatabases $databases = null;
    public ?InMemoryTenantDbs $tenantDbs = null;
    /** @var array<string,list<string>> */
    public array $grants = [];

    protected function legacyTenancy(): bool
    {
        return false;
    }

    protected function membershipAuthority(): MembershipAuthority
    {
        return $this->authority;
    }

    protected function tenantPrivilegeSeed(): array
    {
        return $this->grants;
    }

    public bool $promotedTokenInvalid = false;
    /** @var list<string> tokens the JWKS validator was asked about */
    public array $validated = [];

    /** The test tokens are unsigned: stand in for the JWKS validator with "valid" / "invalid" and the payload as claims. */
    protected function validatePromotedToken(string $token): array
    {
        $this->validated[] = $token;
        if ($this->promotedTokenInvalid) {
            throw new \RuntimeException('signature invalid');
        }
        return (array) json_decode((string) base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);
    }

    protected function buildTenancySequence(): TenantProvisioningSequence
    {
        return new TenantProvisioningSequence($this->authority, $this->main, $this->databases, $this->tenantDbs, [], 'owner', 'acme', 'tenant', $this->tenantPrivilegeSeed());
    }
}

final class DayTwoSelectRoute extends SelectTenantRoute
{
    public ?MembershipAuthority $authority = null;

    protected function legacyTenancy(): bool
    {
        return false;
    }

    protected function membershipAuthority(): MembershipAuthority
    {
        return $this->authority;
    }
}

final class DayTwoExchangeRoute extends ExchangeRoute
{
    public ?MembershipAuthority $authority = null;

    protected function legacyTenancy(): bool
    {
        return false;
    }

    protected function membershipAuthority(): MembershipAuthority
    {
        return $this->authority;
    }

    protected function extractIdentityToken(): ?string
    {
        return 'identity.jwt';
    }

    protected function validateIdentity(string $authToken): array
    {
        return ['sub' => '11111111-1111-4111-8111-111111111111', 'platform_code' => 'acme', 'email' => 'o@example.com'];
    }
}

/**
 * The whole tenancy journey over shared in-memory state, with the REAL route code, the REAL tenant-DB role read
 * (Database::fake) and the REAL RS256 signing: provision (incl. Google sign-up), then come back and select-tenant /
 * exchange. Nothing between "provisioned today" and "logs in tomorrow" is stubbed.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\BaseExternalAuthRoute
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenantProvisioningSequence
 */
final class TenancyDayTwoTest extends TestCase
{
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';
    private const GOOGLE_IDENTITY = '99999999-9999-4999-8999-999999999999';
    private const FIRST_TENANT = '00000000-0000-4000-8000-000000000001';

    /** @var list<string> */
    private array $touched = [];
    private string $keyDir;
    private string $publicKey;
    private CallLog $log;
    private FakeAuthority $authority;
    private FakeMainStore $main;
    private FakeDatabases $databases;
    private InMemoryTenantDbs $tenantDbs;

    protected function setUp(): void
    {
        foreach (['DB_GATEWAY_URL' => 'http://gateway.invalid:9000', 'DB_GATEWAY_PLATFORM' => 'acme', 'AUTH_SERVICE_URL' => 'http://auth.invalid:3139', 'AUTH_ISSUER' => 'http://auth.invalid:3139'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $this->touched[] = $k;
        }
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer identity-token';

        $this->keyDir = sys_get_temp_dir() . '/ssp-daytwo-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->keyDir . '/private.pem', $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        $this->log = new CallLog();
        $this->authority = new FakeAuthority($this->log);
        $this->main = new FakeMainStore($this->log);
        $this->databases = new FakeDatabases($this->log);
        $this->tenantDbs = new InMemoryTenantDbs();
        $this->tenantDbs->installDatabaseFake();
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

    private function config(): ExternalAuthConfig
    {
        return new ExternalAuthConfig([
            'provision_tenant' => true, 'platform_secret' => 'unused', 'platform_code' => 'acme',
            'signing_private_key_path' => $this->keyDir . '/private.pem', 'signing_issuer' => 'https://api.acme.test', 'exchange_ttl' => 600,
        ]);
    }

    private function provisionRoute(string $name = 'Acme Shop', string $key = 'idem-1', array $grants = ['owner' => ['tenant.manage', 'sales.create'], 'cashier' => ['sales.create']], ?PromotingClient $client = null): DayTwoProvisionRoute
    {
        AuthContext::setUser(new AuthenticatedUser(user_id: self::IDENTITY, email: 'o@example.com', platform_code: 'acme'));
        $config = $this->config();
        $route = new DayTwoProvisionRoute($client ?? new PromotingClient($this->log), $config->hooks, $config, null);
        $route->authority = $this->authority;
        $route->main = $this->main;
        $route->databases = $this->databases;
        $route->tenantDbs = $this->tenantDbs;
        $route->grants = $grants;
        $route->tenant_name = $name;
        $route->idempotency_key = $key;
        return $route;
    }

    /** @return array<string,mixed> */
    private function decode(string $jwt): array
    {
        return (array) JWT::decode($jwt, new Key($this->publicKey, 'RS256'));
    }

    private function jwtWithIdentity(string $identity): string
    {
        $b64 = static fn (array $a): string => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');
        return $b64(['alg' => 'none']) . '.' . $b64(['identity_id' => $identity, 'sub' => $identity]) . '.sig';
    }

    // ---- C4: seeded privileges, and the owner can come back tomorrow ----

    public function test_a_fresh_owner_can_select_the_tenant_again_the_next_day(): void
    {
        $today = $this->provisionRoute()->process();

        $this->assertSame('ok', $today->status);
        $this->assertSame(201, $today->httpStatusCode);
        $this->assertSame(['owner'], $today->data['roles']);
        $this->assertSame(['sales.create', 'tenant.manage'], $today->data['privileges'], 'the seeded privileges of the owner role');
        $claims = $this->decode($today->data['access_token']);
        $this->assertSame(['owner'], (array) $claims['roles']);
        $this->assertArrayNotHasKey('role_id', $claims);
        $this->assertSame('acme', $claims['platform_code'], 'O1: the provision token has the select-tenant shape');
        $this->assertArrayNotHasKey('active_role', $today->data);

        // The next day: a brand new request, the membership now listed by the authority, nothing carried over.
        $tenant = $today->data['active_tenant'];
        $this->authority->verifyRows = [['membership_id' => $today->data['membership']['id'], 'tenant_id' => $tenant['id'], 'tenant_name' => $tenant['name'], 'tenant_slug' => null, 'tenant_db_name' => $tenant['db_name'], 'is_tenant_owner' => true]];
        AuthContext::setUser(new AuthenticatedUser(user_id: self::IDENTITY, email: 'o@example.com', platform_code: 'acme'));
        $select = new DayTwoSelectRoute(new PromotingClient($this->log), $this->config()->hooks, $this->config());
        $select->authority = $this->authority;
        $select->tenant_id = $tenant['id'];

        $tomorrow = $select->process();

        $this->assertSame('ok', $tomorrow->status, json_encode($tomorrow->data));
        $this->assertSame(['owner'], $tomorrow->data['roles']);
        $this->assertSame(['sales.create', 'tenant.manage'], $tomorrow->data['privileges']);
        $this->assertSame(['owner'], (array) $this->decode($tomorrow->data['access_token'])['roles']);
    }

    public function test_the_privilege_seed_is_written_into_the_tenant_database_before_the_owner_user(): void
    {
        $this->provisionRoute()->process();

        $this->assertEqualsCanonicalizing(['sales.create' => true, 'tenant.manage' => true], $this->tenantDbs->dbs[self::FIRST_TENANT]['priv']['owner']);
        $this->assertSame(['sales.create' => true], $this->tenantDbs->dbs[self::FIRST_TENANT]['priv']['cashier'], 'the whole platform-wide seed, not just the owner');
        $this->assertSame(['owner' => true], $this->tenantDbs->dbs[self::FIRST_TENANT]['roles'][self::IDENTITY]);
    }

    public function test_an_owner_role_with_no_privileges_fails_provisioning_and_issues_no_token(): void
    {
        $res = $this->provisionRoute('Acme Shop', 'idem-empty', ['owner' => []])->process();

        $this->assertSame('error', $res->status);
        $this->assertSame('seed_privileges', $res->data['step']);
        $this->assertArrayNotHasKey('access_token', $res->data);
        $this->assertSame('provisioning_failed', $this->main->tenants[self::FIRST_TENANT]['status']);
        $this->assertSame('provisioning_failed', $this->authority->status[self::FIRST_TENANT]);
        $this->assertSame([], $this->main->memberships, 'no membership for a tenant its owner could not use');
        $this->assertArrayNotHasKey(self::IDENTITY, $this->tenantDbs->dbs[self::FIRST_TENANT]['roles'] ?? [], 'the owner user is not created for a tenant that failed the privilege check');
    }

    public function test_fixing_the_seed_and_retrying_with_the_same_key_resumes_the_same_tenant(): void
    {
        $this->provisionRoute('Acme Shop', 'idem-fix', [])->process();
        $this->assertSame('provisioning_failed', $this->main->tenants[self::FIRST_TENANT]['status']);

        $retry = $this->provisionRoute('Acme Shop', 'idem-fix')->process();

        $this->assertSame('ok', $retry->status);
        $this->assertSame(self::FIRST_TENANT, $retry->data['active_tenant']['id'], 'the same tenant, not a new one');
        $this->assertSame(200, $retry->httpStatusCode, 'a resume is a replay');
        $this->assertCount(1, $this->main->tenants);
    }

    public function test_privileges_already_in_the_tenant_schema_satisfy_the_owner_check(): void
    {
        $this->tenantDbs->preSeed(self::FIRST_TENANT, ['owner' => ['tenant.manage']]);

        $res = $this->provisionRoute('Acme Shop', 'idem-pre', [])->process();

        $this->assertSame('ok', $res->status, json_encode($res->data));
        $this->assertSame(['tenant.manage'], $res->data['privileges']);
    }

    // ---- C3: Google sign-up under LEGACY_TENANCY=false ----

    public function test_google_signup_promotes_first_then_provisions_with_the_promoted_token_and_identity(): void
    {
        $promoted = $this->jwtWithIdentity(self::GOOGLE_IDENTITY);
        $client = new PromotingClient($this->log);
        $client->promoteResult = ['access_token' => $promoted, 'token_type' => 'Bearer'];
        $route = $this->provisionRoute('Acme Shop', 'idem-g', ['owner' => ['tenant.manage']], $client);
        $route->oauth_state = 'oauth-state-abc';

        $res = $route->process();

        $this->assertSame('ok', $res->status, json_encode($res->data));
        $calls = $this->log->calls;
        $this->assertSame('client.promote', $calls[0], 'the OAuth promote runs before anything else');
        $this->assertSame('authority.reserve', $calls[1]);
        $this->assertSame($promoted, $this->authority->lastUserToken, 'the authority gets the promoted access token, not the pending one');
        $this->assertNotSame('identity-token', $this->authority->lastUserToken);
        $this->assertSame(self::GOOGLE_IDENTITY, $this->authority->lastIdentity, 'the real identity named by the promoted token');
        $this->assertSame(['owner' => true], $this->tenantDbs->dbs[self::FIRST_TENANT]['roles'][self::GOOGLE_IDENTITY]);
        $this->assertSame(self::GOOGLE_IDENTITY, $this->decode($res->data['access_token'])['identity_id']);
    }

    public function test_only_auths_already_used_error_means_oauth_session_used(): void
    {
        $cases = [
            ['Auth service returned HTTP 400: OAuth session not found or already used', 409, 'oauth_session_used'],
            ['Auth service returned HTTP 400: OAuth session expired. Please sign in with Google again.', 400, 'oauth_promote_failed'],
            ['Auth service returned HTTP 400: Invalid request body', 400, 'oauth_promote_failed'],
        ];
        foreach ($cases as [$message, $status, $code]) {
            $this->setUp();
            $client = new PromotingClient($this->log);
            $client->promoteResult = new AuthServiceException($message, 400);
            $route = $this->provisionRoute('Acme Shop', 'idem-g2', ['owner' => ['tenant.manage']], $client);
            $route->oauth_state = 'state';

            $res = $route->process();

            $this->assertSame($status, $res->httpStatusCode, $message);
            $this->assertSame($code, $res->data['error'], $message);
            $this->assertSame(['client.promote'], $this->log->calls, 'no tenant was reserved');
        }

        $this->setUp();
        $client = new PromotingClient($this->log);
        $client->promoteResult = new AuthServiceException('Auth service returned HTTP 500', 500);
        $route = $this->provisionRoute('Acme Shop', 'idem-g2', ['owner' => ['tenant.manage']], $client);
        $route->oauth_state = 'state';
        $this->assertSame(502, $route->process()->httpStatusCode);
    }

    public function test_the_promoted_token_is_verified_by_the_jwks_validator_before_its_identity_is_trusted(): void
    {
        $promoted = $this->jwtWithIdentity(self::GOOGLE_IDENTITY);
        $client = new PromotingClient($this->log);
        $client->promoteResult = ['access_token' => $promoted];
        $route = $this->provisionRoute('Acme Shop', 'idem-v', ['owner' => ['tenant.manage']], $client);
        $route->oauth_state = 'state';
        $route->promotedTokenInvalid = true;

        $res = $route->process();

        $this->assertSame(502, $res->httpStatusCode);
        $this->assertSame([$promoted], $route->validated, 'the validator was asked about the promoted token');
        $this->assertSame(['client.promote'], $this->log->calls, 'a token that does not validate provisions nothing');
    }

    public function test_oauth_state_is_refused_on_a_standalone_platform_and_nothing_is_promoted(): void
    {
        $client = new PromotingClient($this->log);
        $route = $this->provisionRoute('Acme Shop', 'idem-b', ['owner' => ['tenant.manage']], $client);
        $route->authority = new \StoneScriptPHP\Tenancy\Provisioning\BuiltinMembershipAuthority('acme', $this->main, $this->main);
        $route->oauth_state = 'state';

        $res = $route->process();

        $this->assertSame(400, $res->httpStatusCode);
        $this->assertSame('oauth_not_supported', $res->data['error']);
        $this->assertSame([], $this->log->calls);
    }

    public function test_a_promote_without_a_usable_token_stops_before_any_tenant(): void
    {
        foreach ([[], ['success' => false, 'access_token' => 'x'], ['access_token' => 'not-a-jwt']] as $result) {
            $this->setUp();
            $client = new PromotingClient($this->log);
            $client->promoteResult = $result;
            $route = $this->provisionRoute('Acme Shop', 'idem-g3', ['owner' => ['tenant.manage']], $client);
            $route->oauth_state = 'state';

            $res = $route->process();

            $this->assertSame(502, $res->httpStatusCode, json_encode($result));
            $this->assertSame(['client.promote'], $this->log->calls);
        }
    }

    // ---- W3: the existing-tenant guard, through the authority ----

    private function ownedRow(string $name): array
    {
        return ['membership_id' => '33333333-3333-4333-8333-333333333333', 'tenant_id' => '22222222-2222-4222-8222-222222222222', 'tenant_name' => $name, 'tenant_slug' => null, 'tenant_db_name' => 'acme_tenant_x', 'is_tenant_owner' => true];
    }

    public function test_an_owner_asking_for_the_same_tenant_again_gets_it_back_without_provisioning(): void
    {
        $this->tenantDbs->preSeed('22222222-2222-4222-8222-222222222222', ['owner' => ['tenant.manage']]);
        $this->tenantDbs->ensureUserWithRole('22222222-2222-4222-8222-222222222222', self::IDENTITY, null, 'owner', null);
        $this->authority->listRows = [$this->ownedRow('Acme Shop')];

        $res = $this->provisionRoute('  acme SHOP ')->process();

        $this->assertSame('ok', $res->status, json_encode($res->data));
        $this->assertSame(200, $res->httpStatusCode);
        $this->assertSame('Tenant already provisioned', $res->message);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $res->data['active_tenant']['id']);
        $this->assertSame([], $this->log->calls, 'no reservation, no database, nothing provisioned');
    }

    public function test_an_owner_asking_for_a_differently_named_tenant_gets_409_unless_additional_is_allowed(): void
    {
        $this->authority->listRows = [$this->ownedRow('Acme Shop')];

        $denied = $this->provisionRoute('Another Business')->process();
        $this->assertSame(409, $denied->httpStatusCode);
        $this->assertSame('tenant_already_exists', $denied->data['error']);
        $this->assertSame([], $this->log->calls);

        $route = $this->provisionRoute('Another Business', 'idem-add');
        $route->allow_additional_tenant = true;
        $this->assertSame('ok', $route->process()->status);
        $this->assertSame(1, $this->log->count('authority.reserve'));
    }

    public function test_any_owned_tenant_whose_name_matches_is_replayed_and_409_only_when_none_matches(): void
    {
        $second = ['membership_id' => '55555555-5555-4555-8555-555555555555', 'tenant_id' => '66666666-6666-4666-8666-666666666666', 'tenant_name' => 'Second Shop', 'tenant_slug' => null, 'tenant_db_name' => 'acme_tenant_y', 'is_tenant_owner' => true];
        foreach (['22222222-2222-4222-8222-222222222222', '66666666-6666-4666-8666-666666666666'] as $tenant) {
            $this->tenantDbs->preSeed($tenant, ['owner' => ['tenant.manage']]);
            $this->tenantDbs->ensureUserWithRole($tenant, self::IDENTITY, null, 'owner', null);
        }
        $this->authority->listRows = [$this->ownedRow('Acme Shop'), $second];

        $res = $this->provisionRoute('second shop')->process();

        $this->assertSame(200, $res->httpStatusCode);
        $this->assertSame('66666666-6666-4666-8666-666666666666', $res->data['active_tenant']['id'], 'the SECOND owned tenant matched by name');
        $this->assertSame([], $this->log->calls);

        $none = $this->provisionRoute('Neither')->process();
        $this->assertSame(409, $none->httpStatusCode);
    }

    public function test_a_non_owner_membership_does_not_block_creating_a_tenant_and_a_failed_lookup_never_blocks(): void
    {
        $staff = $this->ownedRow('Someone Elses');
        $staff['is_tenant_owner'] = false;
        $this->authority->listRows = [$staff];
        $this->assertSame('ok', $this->provisionRoute('Acme Shop', 'idem-staff')->process()->status);

        $this->setUp();
        $this->authority->listThrows = true;
        $this->assertSame('ok', $this->provisionRoute('Acme Shop', 'idem-down')->process()->status);
    }

    public function test_a_second_identical_request_after_success_is_a_200_for_the_same_tenant(): void
    {
        $first = $this->provisionRoute()->process();
        $second = $this->provisionRoute()->process();

        $this->assertSame(201, $first->httpStatusCode);
        $this->assertSame(200, $second->httpStatusCode);
        $this->assertSame($first->data['active_tenant']['id'], $second->data['active_tenant']['id']);
        $this->assertSame('Tenant already provisioned', $second->message);
    }

    // ---- W1 / W2 ----

    public function test_a_failing_tenant_database_read_during_login_is_a_502(): void
    {
        $today = $this->provisionRoute()->process();
        $tenant = $today->data['active_tenant'];
        $this->authority->verifyRows = [['membership_id' => $today->data['membership']['id'], 'tenant_id' => $tenant['id'], 'tenant_name' => 'Acme Shop', 'tenant_slug' => null, 'tenant_db_name' => $tenant['db_name'], 'is_tenant_owner' => true]];
        $this->tenantDbs->failReads = true;
        AuthContext::setUser(new AuthenticatedUser(user_id: self::IDENTITY, email: 'o@example.com', platform_code: 'acme'));
        $select = new DayTwoSelectRoute(new PromotingClient($this->log), $this->config()->hooks, $this->config());
        $select->authority = $this->authority;
        $select->tenant_id = $tenant['id'];

        $res = $select->process();

        $this->assertSame(502, $res->httpStatusCode);
        $this->assertArrayNotHasKey('access_token', (array) $res->data);
    }

    public function test_exchange_in_the_current_flow_uses_the_authority_and_the_tenant_database_never_the_platform_resolvers(): void
    {
        $today = $this->provisionRoute()->process();
        $tenant = $today->data['active_tenant'];
        $this->authority->verifyRows = [['membership_id' => $today->data['membership']['id'], 'tenant_id' => $tenant['id'], 'tenant_name' => 'Acme Shop', 'tenant_slug' => null, 'tenant_db_name' => $tenant['db_name'], 'is_tenant_owner' => true]];

        $resolverCalls = 0;
        $boom = function () use (&$resolverCalls): array {
            $resolverCalls++;
            return ['owner'];
        };
        $config = $this->config();
        $route = new DayTwoExchangeRoute(new PromotingClient($this->log), $config->hooks, $config, $boom, $boom);
        $route->authority = $this->authority;
        $route->tenant_id = $tenant['id'];

        $res = $route->process();

        $this->assertSame('ok', $res->status, json_encode($res->data));
        $this->assertSame(0, $resolverCalls, 'roles_resolver / tenants_resolver are never consulted in the current flow');
        $this->assertSame(['owner'], $res->data['roles']);
        $this->assertSame(['owner'], (array) $this->decode($res->data['access_token'])['roles']);

        $other = new DayTwoExchangeRoute(new PromotingClient($this->log), $config->hooks, $config, $boom, $boom);
        $other->authority = $this->authority;
        $other->tenant_id = '88888888-8888-4888-8888-888888888888';
        $denied = $other->process();
        $this->assertSame(403, $denied->httpStatusCode);
        $this->assertSame('tenant_access_denied', $denied->data['error']);
    }
}

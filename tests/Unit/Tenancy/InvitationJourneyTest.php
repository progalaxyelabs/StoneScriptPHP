<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\Invitations\InvitationCompletionService;
use StoneScriptPHP\Auth\Invitations\InvitationException;
use StoneScriptPHP\Auth\Invitations\InvitationRecord;
use StoneScriptPHP\Auth\Invitations\InvitationRepositoryInterface;
use StoneScriptPHP\Auth\TokenExchangeService;
use StoneScriptPHP\Database;
use StoneScriptPHP\Tenancy\Provisioning\TenantDbScope;
use StoneScriptPHP\Tenancy\Provisioning\TenantStaffInvite;

require_once __DIR__ . '/TenancyFakes.php';
require_once __DIR__ . '/InMemoryTenantDbs.php';

/**
 * accept-invite under LEGACY_TENANCY=false: the invitation's role is written to the tenant database through TenantStaffInvite,
 * the member is added at the authority, and the token carries the member's roles. An unknown role refuses the invite
 * before anything is written. Whole journey over the shared fakes with real RS256 signing.
 *
 * @covers \StoneScriptPHP\Auth\Invitations\InvitationCompletionService
 * @covers \StoneScriptPHP\Tenancy\Provisioning\TenantStaffInvite
 */
final class InvitationJourneyTest extends TestCase
{
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const OWNER = '11111111-1111-4111-8111-111111111111';
    private const INVITEE = '44444444-4444-4444-8444-444444444444';
    private const GRANTS = ['owner' => ['tenant.manage'], 'cashier' => ['sales.create'], 'empty_role' => []];

    private string $keyDir;
    private string $publicKey;
    private CallLog $log;
    private FakeAuthority $authority;
    private FakeMainStore $main;
    private InMemoryTenantDbs $tenantDbs;

    protected function setUp(): void
    {
        $this->keyDir = sys_get_temp_dir() . '/ssp-invite-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->keyDir . '/private.pem', $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        $this->log = new CallLog();
        $this->authority = new FakeAuthority($this->log);
        $this->main = new FakeMainStore($this->log);
        $this->tenantDbs = new InMemoryTenantDbs();
        $this->tenantDbs->installDatabaseFake();
        $this->tenantDbs->preSeed(self::TENANT, self::GRANTS);
        $this->tenantDbs->ensureUserWithRole(self::TENANT, self::OWNER, null, 'owner', null);
    }

    protected function tearDown(): void
    {
        Database::clearFakeMode();
        @unlink($this->keyDir . '/private.pem');
        @rmdir($this->keyDir);
    }

    private function invitation(string $role = 'cashier', ?string $tenantId = self::TENANT): InvitationRecord
    {
        return new InvitationRecord('inv-1', 'invitee@example.com', $role, 'pending', '2099-01-01T00:00:00Z', self::OWNER, $tenantId);
    }

    /** @return array{InvitationCompletionService, InvitationRepositoryInterface} */
    private function service(InvitationRecord $invitation, bool $expectAccepted): array
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($invitation);
        $expectAccepted
            ? $repository->expects($this->once())->method('markAccepted')->with('inv-1', self::INVITEE)
            : $repository->expects($this->never())->method('markAccepted');

        $tokens = $this->getMockBuilder(TokenExchangeService::class)->onlyMethods(['validateIdentityToken'])->getMock();
        $tokens->method('validateIdentityToken')->willReturn(['sub' => self::INVITEE, 'email' => 'invitee@example.com', 'platform_code' => 'acme', 'display_name' => 'New Cashier']);

        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        $authClient->expects($this->never())->method('createMembershipForInvite');

        return [new InvitationCompletionService($tokens, $authClient, new TenantStaffInvite($this->authority, $this->main, $this->tenantDbs)), $repository];
    }

    private function accept(InvitationCompletionService $service, InvitationRepositoryInterface $repository, array $config = [], ?bool &$writerCalled = null): array
    {
        $writerCalled = false;
        return $service->complete(
            'raw-token',
            'identity.jwt',
            $repository,
            fn (InvitationRecord $i): string => $i->role,
            function () use (&$writerCalled): array {
                $writerCalled = true;
                return ['tenant_id' => self::TENANT];
            },
            ['jwks_url' => 'https://auth.example.test/jwks', 'issuer' => 'https://auth.example.test'],
            $config + [
                'platform_code' => 'acme', 'legacy_tenancy' => false, 'tenant_privileges' => self::GRANTS,
                'signing_private_key_path' => $this->keyDir . '/private.pem', 'signing_issuer' => 'https://api.acme.test', 'ttl' => 600,
            ]
        );
    }

    public function test_the_invited_member_gets_the_invitations_role_in_the_tenant_database_and_a_token_with_it(): void
    {
        [$service, $repository] = $this->service($this->invitation('cashier'), true);

        $result = $this->accept($service, $repository, [], $writerCalled);

        $this->assertSame('card', $result['mode']);
        $this->assertSame(['cashier'], $result['roles']);
        $this->assertSame(['sales.create'], $result['privileges']);
        $this->assertSame(['cashier' => true], $this->tenantDbs->dbs[self::TENANT]['roles'][self::INVITEE], 'the role is written to the tenant database');
        $this->assertSame([[self::TENANT, self::INVITEE, 'invite:inv-1']], $this->authority->members, 'added at the authority, idempotent on the invitation id');
        $this->assertSame(self::OWNER, $this->authority->lastInvitedBy);
        $this->assertCount(1, $this->main->memberships);
        $this->assertFalse($this->main->memberships[0]['owner'], 'an invited member is never an owner');
        $this->assertSame($result['membership']['id'], $this->main->memberships[0]['id']);
        $this->assertFalse($writerCalled, 'the framework writes the membership, not the platform closure');

        $claims = (array) JWT::decode($result['access_token'], new Key($this->publicKey, 'RS256'));
        $this->assertSame(['cashier'], (array) $claims['roles']);
        $this->assertArrayNotHasKey('role_id', $claims);
        $this->assertSame(self::TENANT, $claims['tenant_id']);
        $this->assertSame('acme', $claims['platform_code']);
    }

    public function test_a_member_who_already_holds_roles_gets_them_all_on_the_token(): void
    {
        $this->tenantDbs->ensureUserWithRole(self::TENANT, self::INVITEE, null, 'owner', null);
        [$service, $repository] = $this->service($this->invitation('cashier'), true);

        $result = $this->accept($service, $repository);

        $this->assertSame(['cashier', 'owner'], $result['roles']);
    }

    public function test_an_unknown_or_missing_role_refuses_the_invite_and_nothing_is_written(): void
    {
        foreach (['wizard', '', 'empty_role', 'Owner'] as $role) {
            $this->setUp();
            [$service, $repository] = $this->service($this->invitation($role), false);

            try {
                $this->accept($service, $repository);
                $this->fail("role '$role' must refuse the invite");
            } catch (InvitationException $e) {
                $this->assertSame(InvitationException::INVALID_ROLE, $e->getErrorCode(), "role '$role'");
                $this->assertSame(422, $e->getHttpStatusCode());
            }
            $this->assertSame([], $this->log->calls, "nothing reached the authority or the main database for '$role'");
            $this->assertArrayNotHasKey(self::INVITEE, $this->tenantDbs->dbs[self::TENANT]['roles'], 'nothing in the tenant database');
        }
    }

    public function test_an_invitation_without_a_tenant_is_a_config_error_and_writes_nothing(): void
    {
        [$service, $repository] = $this->service($this->invitation('cashier', null), false);

        try {
            $this->accept($service, $repository);
            $this->fail('expected an InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::CONFIG_ERROR, $e->getErrorCode());
        }
        $this->assertSame([], $this->log->calls);
    }

    public function test_an_invitation_without_a_tenant_uses_the_tenant_the_request_is_routed_to(): void
    {
        // (1) the running TenantDbScope names the tenant
        [$service, $repository] = $this->service($this->invitation('cashier', null), true);
        $result = TenantDbScope::run(self::TENANT, fn (): array => $this->accept($service, $repository));

        $this->assertSame(self::TENANT, $result['membership']['tenant_id']);
        $this->assertSame(['cashier'], $result['roles']);
        $this->assertSame(['cashier' => true], $this->tenantDbs->dbs[self::TENANT]['roles'][self::INVITEE]);
    }

    public function test_the_gateway_clients_tenant_id_is_the_other_source_of_the_routed_tenant(): void
    {
        $this->setUp();
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->invitation('cashier', null));
        $repository->expects($this->once())->method('markAccepted');
        $tokens = $this->getMockBuilder(TokenExchangeService::class)->onlyMethods(['validateIdentityToken'])->getMock();
        $tokens->method('validateIdentityToken')->willReturn(['sub' => self::INVITEE, 'email' => 'invitee@example.com', 'platform_code' => 'acme']);
        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        // The gateway client's tenant id (set by the routing middleware) stands behind routedTenantId().
        $service = new class ($tokens, $authClient, new TenantStaffInvite($this->authority, $this->main, $this->tenantDbs)) extends InvitationCompletionService {
            protected function routedTenantId(): ?string
            {
                return '22222222-2222-4222-8222-222222222222';
            }
        };

        $result = $this->accept($service, $repository);

        $this->assertSame(self::TENANT, $result['membership']['tenant_id']);
    }

    public function test_an_invitation_with_no_tenant_and_no_routed_tenant_is_refused_and_nothing_is_written(): void
    {
        [$service, $repository] = $this->service($this->invitation('cashier', null), false);

        try {
            $this->accept($service, $repository);
            $this->fail('expected an InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::CONFIG_ERROR, $e->getErrorCode());
        }
        $this->assertSame([], $this->log->calls);
    }

    public function test_the_routed_tenant_lookup_reads_the_scope_and_is_null_outside_any_scope(): void
    {
        $this->assertNull(TenantDbScope::routedTenantId(), 'faked database, no scope: nothing routed');
        $this->assertSame(self::TENANT, TenantDbScope::run(self::TENANT, fn (): ?string => TenantDbScope::routedTenantId()));
    }

    public function test_an_invitation_without_an_inviter_is_refused_with_a_422_before_anything_is_written(): void
    {
        foreach ([null, '', '  '] as $inviter) {
            $this->setUp();
            $invitation = new InvitationRecord('inv-1', 'invitee@example.com', 'cashier', 'pending', '2099-01-01T00:00:00Z', $inviter, self::TENANT);
            [$service, $repository] = $this->service($invitation, false);

            try {
                $this->accept($service, $repository);
                $this->fail('a missing inviter must refuse the invite');
            } catch (InvitationException $e) {
                $this->assertSame(InvitationException::MISSING_INVITER, $e->getErrorCode());
                $this->assertSame(422, $e->getHttpStatusCode());
            }
            $this->assertSame([], $this->log->calls, 'nothing reached the authority');
            $this->assertArrayNotHasKey(self::INVITEE, $this->tenantDbs->dbs[self::TENANT]['roles']);
        }
    }

    public function test_a_refusal_by_the_authority_leaves_the_invitation_pending_and_retryable(): void
    {
        $this->authority->failAt = 'add_member';
        [$service, $repository] = $this->service($this->invitation('cashier'), false);

        try {
            $this->accept($service, $repository);
            $this->fail('expected an InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::MEMBERSHIP_FAILED, $e->getErrorCode());
            $this->assertSame(502, $e->getHttpStatusCode());
        }
        $this->assertSame([], $this->main->memberships);
    }

    public function test_passthrough_mode_returns_the_identity_token_and_tenant(): void
    {
        [$service, $repository] = $this->service($this->invitation('cashier'), true);

        $result = $this->accept($service, $repository, ['card' => false]);

        $this->assertSame('passthrough', $result['mode']);
        $this->assertSame('identity.jwt', $result['identity_token']);
        $this->assertSame(self::TENANT, $result['tenant_id']);
    }

    public function test_a_retried_accept_after_a_failure_resumes_without_duplicates(): void
    {
        $this->main->failAt = 'main_membership';
        [$service, $repository] = $this->service($this->invitation('cashier'), false);
        try {
            $this->accept($service, $repository);
            $this->fail('the main write fails once');
        } catch (InvitationException) {
        }

        $this->main->failAt = null;
        [$service, $repository] = $this->service($this->invitation('cashier'), true);
        $result = $this->accept($service, $repository);

        $this->assertSame(['cashier'], $result['roles']);
        $this->assertCount(1, $this->main->memberships);
        $this->assertSame(['cashier' => true], $this->tenantDbs->dbs[self::TENANT]['roles'][self::INVITEE]);
    }
}

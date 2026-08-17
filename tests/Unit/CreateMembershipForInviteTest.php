<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;

/**
 * Unit test for ExternalAuthServiceClient::createMembershipForInvite() —
 * the deliberate "never send role" wrapper around
 * createMembership(). No network I/O: createMembership() (the only method
 * that would actually reach the network via AuthServiceClient::post()) is
 * stubbed via a partial mock, isolating exactly the behavior this method
 * is responsible for — stripping role/roles before delegating.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient::createMembershipForInvite
 */
class CreateMembershipForInviteTest extends TestCase
{
    private function client(): ExternalAuthServiceClient&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->getMockBuilder(ExternalAuthServiceClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createMembership'])
            ->getMock();
    }

    public function test_strips_role_key_before_delegating(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('createMembership')
            ->with(
                $this->callback(function (array $data) {
                    $this->assertArrayNotHasKey('role', $data);
                    $this->assertSame('identity-1', $data['identity_id']);
                    $this->assertSame('tenant-1', $data['tenant_id']);
                    return true;
                }),
                'platform-secret'
            )
            ->willReturn(['membership_id' => 'mem-1']);

        $result = $client->createMembershipForInvite([
            'identity_id' => 'identity-1',
            'tenant_id'   => 'tenant-1',
            'role'        => 'owner', // deliberately included — must be stripped
        ], 'platform-secret');

        $this->assertSame(['membership_id' => 'mem-1'], $result);
    }

    public function test_strips_roles_array_key_before_delegating(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('createMembership')
            ->with(
                $this->callback(function (array $data) {
                    $this->assertArrayNotHasKey('roles', $data);
                    return true;
                }),
                'secret'
            )
            ->willReturn([]);

        $client->createMembershipForInvite([
            'identity_id' => 'identity-1',
            'tenant_id'   => 'tenant-1',
            'roles'       => ['owner', 'admin'],
        ], 'secret');
    }

    public function test_forces_is_tenant_owner_false_even_if_caller_supplies_true(): void
    {
        // An invite-accepted membership is never the
        // tenant's owner by construction — this method must never let an
        // is_tenant_owner=true sneak through, even if a caller supplies it.
        $client = $this->client();
        $client->expects($this->once())
            ->method('createMembership')
            ->with(
                $this->callback(function (array $data) {
                    $this->assertArrayHasKey('is_tenant_owner', $data);
                    $this->assertFalse($data['is_tenant_owner']);
                    return true;
                }),
                'secret'
            )
            ->willReturn([]);

        $client->createMembershipForInvite([
            'identity_id'     => 'identity-1',
            'tenant_id'       => 'tenant-1',
            'is_tenant_owner' => true, // deliberately included — must be forced false
        ], 'secret');
    }

    /**
     * BUGFIX (2026-08-17): every create-membership call must carry an
     * EXPLICIT boolean, never an implicit auth-side default — this pins the
     * invite path's half of that contract (the creator path's half is
     * ProvisionTenantRouteBugFixesTest / ProvisionTenantRoutePlatformCodeGuardTest).
     */
    public function test_sends_explicit_is_tenant_owner_false_when_caller_omits_it(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('createMembership')
            ->with(
                $this->callback(function (array $data) {
                    $this->assertArrayHasKey('is_tenant_owner', $data);
                    $this->assertFalse($data['is_tenant_owner']);
                    return true;
                }),
                'secret'
            )
            ->willReturn([]);

        $client->createMembershipForInvite([
            'identity_id' => 'identity-1',
            'tenant_id'   => 'tenant-1',
            // is_tenant_owner deliberately omitted — must not be left implicit.
        ], 'secret');
    }

    public function test_passes_through_other_fields_unchanged(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('createMembership')
            ->with(
                $this->callback(function (array $data) {
                    $this->assertSame('identity-1', $data['identity_id']);
                    $this->assertSame('tenant-1', $data['tenant_id']);
                    $this->assertSame('invitee@example.com', $data['email']);
                    $this->assertSame('schema_x', $data['tenant_db_schema']);
                    return true;
                }),
                'secret'
            )
            ->willReturn([]);

        $client->createMembershipForInvite([
            'identity_id'      => 'identity-1',
            'tenant_id'        => 'tenant-1',
            'email'            => 'invitee@example.com',
            'tenant_db_schema' => 'schema_x',
        ], 'secret');
    }
}

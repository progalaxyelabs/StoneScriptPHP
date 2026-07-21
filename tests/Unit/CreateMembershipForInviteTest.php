<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;

/**
 * Unit test for ExternalAuthServiceClient::createMembershipForInvite() —
 * design doc §3.4.1/§4.2's deliberate "never send role" wrapper around
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

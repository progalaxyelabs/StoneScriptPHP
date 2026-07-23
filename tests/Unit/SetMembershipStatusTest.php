<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;

/**
 * Unit test for ExternalAuthServiceClient::setMembershipStatus() —
 * AUTH-IDENTITY.md §3.3/§5. No network I/O: the protected `put()` method
 * (the only one that would actually reach the network via curl) is stubbed
 * via a partial mock, isolating exactly what this method is responsible for
 * — building the right endpoint/body/header shape.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient::setMembershipStatus
 */
class SetMembershipStatusTest extends TestCase
{
    /** @return ExternalAuthServiceClient&\PHPUnit\Framework\MockObject\MockObject */
    private function client()
    {
        return $this->getMockBuilder(ExternalAuthServiceClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['put'])
            ->getMock();
    }

    public function test_calls_membership_status_endpoint_with_expected_body(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('put')
            ->with(
                '/api/internal/membership-status',
                ['membership_id' => 'mem-1', 'status' => 'suspended'],
                ['X-Platform-Secret: secret-value']
            )
            ->willReturn(['id' => 'mem-1', 'status' => 'suspended', 'updated_at' => '2026-07-23T00:00:00Z']);

        $result = $client->setMembershipStatus('mem-1', 'suspended', 'secret-value');

        $this->assertSame('mem-1', $result['id']);
        $this->assertSame('suspended', $result['status']);
    }

    public function test_reactivate_status_passes_through(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(fn (array $data) => $data['status'] === 'active'),
                $this->anything()
            )
            ->willReturn(['id' => 'mem-1', 'status' => 'active', 'updated_at' => 'now']);

        $result = $client->setMembershipStatus('mem-1', 'active', 'secret-value');

        $this->assertSame('active', $result['status']);
    }

    public function test_propagates_auth_error_response_body_without_throwing(): void
    {
        // AUTH-IDENTITY.md §3.5: auth refuses to suspend the tenant owner by
        // returning a typed {error: ...} body, not a 5xx — this method must
        // pass that straight through, never swallow or rethrow it as an
        // exception, since it's a normal (if unsuccessful) response shape.
        $client = $this->client();
        $client->expects($this->once())
            ->method('put')
            ->willReturn(['error' => 'cannot_suspend_tenant_owner']);

        $result = $client->setMembershipStatus('owner-membership-id', 'suspended', 'secret-value');

        $this->assertSame('cannot_suspend_tenant_owner', $result['error']);
    }

    public function test_secret_is_sent_as_platform_secret_header_never_in_body(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(function (array $data) {
                    $this->assertArrayNotHasKey('platform_secret', $data);
                    $this->assertArrayNotHasKey('X-Platform-Secret', $data);
                    return true;
                }),
                ['X-Platform-Secret: super-secret']
            )
            ->willReturn([]);

        $client->setMembershipStatus('mem-1', 'active', 'super-secret');
    }
}

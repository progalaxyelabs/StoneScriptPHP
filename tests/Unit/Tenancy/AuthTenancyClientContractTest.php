<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;

/**
 * Pins every request and response shape of the auth service's tenancy handlers (paths, headers, bodies,
 * fields, error codes) as implemented by the auth side. If the auth handlers change, this file is what
 * must be changed together with AuthTenancyClient.
 *
 * @covers \StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient
 */
final class AuthTenancyClientContractTest extends TestCase
{
    private const PLATFORM = '12121212-1212-4212-8212-121212121212';
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const MEMBERSHIP = '33333333-3333-4333-8333-333333333333';
    private const IDENTITY = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{string,string,list<string>,?string}> */
    private array $seen = [];

    private function client(int $status, array $body, ?string $id = 'pc_0123456789abcdef', ?string $secret = 'S3cretS3cretS3cretS3cretS3cretS3cret'): AuthTenancyClient
    {
        return new AuthTenancyClient('https://auth.example.test', $id, $secret, function (string $m, string $u, array $h, ?string $b) use ($status, $body): array {
            $this->seen[] = [$m, $u, $h, $b];
            return [$status, json_encode($body)];
        });
    }

    /** @return list<string> header names (before the colon), sorted */
    private function headerNames(): array
    {
        $names = array_map(static fn (string $h): string => strtolower(explode(':', $h, 2)[0]), $this->seen[0][2]);
        sort($names);
        return $names;
    }

    public function test_register_platform_shape(): void
    {
        $client = $this->client(201, ['platform_id' => self::PLATFORM, 'slug' => 'acme', 'name' => 'Acme', 'client_id' => 'pc_0123456789abcdef', 'client_secret' => 'sec', 'credential_issued' => true, 'idempotent_replay' => false], null, null);

        $r = $client->registerPlatform('op-token', 'Acme', 'acme', 'register:acme');

        [$method, $url, $headers, $body] = $this->seen[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/operator/platforms', $url);
        $this->assertContains('X-Operator-Token: op-token', $headers);
        $this->assertSame(['name' => 'Acme', 'slug' => 'acme', 'idempotency_key' => 'register:acme'], json_decode((string) $body, true));
        $this->assertSame(['platform_id' => self::PLATFORM, 'client_id' => 'pc_0123456789abcdef', 'client_secret' => 'sec'], $r);
        $this->assertNotContains('x-platform-client-secret', $this->headerNames(), 'operator calls carry no platform credential');
    }

    public function test_register_platform_replay_has_no_secret(): void
    {
        $r = $this->client(200, ['platform_id' => self::PLATFORM, 'client_id' => 'pc_0123456789abcdef', 'credential_issued' => false, 'idempotent_replay' => true], null, null)
            ->registerPlatform('t', 'Acme', 'acme', 'k');

        $this->assertNull($r['client_secret']);
    }

    public function test_create_tenant_shape(): void
    {
        $client = $this->client(201, ['platform_tenant_id' => self::TENANT, 'tenant_db_name' => 'acme_tenant_x', 'platform_tenant_membership_id' => self::MEMBERSHIP, 'tenant_provisioning_status' => 'provisioning', 'is_new_tenant' => true, 'idempotent_replay' => false, 'resumed' => false]);

        $r = $client->createTenant('Acme Shop', 'idem-1', 'user.jwt', 'acme-shop');

        [$method, $url, $headers, $body] = $this->seen[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/tenants', $url);
        $this->assertContains('X-Platform-Client-Id: pc_0123456789abcdef', $headers);
        $this->assertContains('X-Platform-Client-Secret: S3cretS3cretS3cretS3cretS3cretS3cret', $headers);
        $this->assertContains('Authorization: Bearer user.jwt', $headers);
        $this->assertSame(['tenant_name' => 'Acme Shop', 'idempotency_key' => 'idem-1', 'tenant_slug' => 'acme-shop'], json_decode((string) $body, true));
        $this->assertSame(['platform_tenant_id' => self::TENANT, 'tenant_db_name' => 'acme_tenant_x', 'platform_tenant_membership_id' => self::MEMBERSHIP, 'idempotent_replay' => false], $r);
    }

    public function test_set_membership_status_shape(): void
    {
        $client = $this->client(200, ['id' => self::MEMBERSHIP, 'status' => 'removed', 'updated_at' => '2026-11-21T00:00:00Z']);

        $client->setMembershipStatus(self::MEMBERSHIP, 'removed');

        [$method, $url, $headers, $body] = $this->seen[0];
        $this->assertSame('PUT', $method);
        $this->assertSame('https://auth.example.test/api/internal/memberships/' . self::MEMBERSHIP . '/status', $url);
        $this->assertContains('X-Platform-Client-Id: pc_0123456789abcdef', $headers);
        $this->assertSame(['status' => 'removed'], json_decode((string) $body, true));

        $this->expectException(ProvisioningException::class);
        $this->client(200, ['id' => self::MEMBERSHIP, 'status' => 'active'])->setMembershipStatus(self::MEMBERSHIP, 'removed');
    }

    public function test_rotate_credential_shape_and_replay_without_a_secret(): void
    {
        $issued = $this->client(201, ['platform_id' => self::PLATFORM, 'client_id' => 'pc_next0123456789', 'status' => 'next', 'credential_issued' => true, 'idempotent_replay' => false, 'client_secret' => 'sec'], null, null);
        $r = $issued->rotateCredential('op-token', self::PLATFORM, 'rot-1');

        [$method, $url, $headers, $body] = $this->seen[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/operator/platforms/' . self::PLATFORM . '/credentials/rotate', $url);
        $this->assertContains('X-Operator-Token: op-token', $headers);
        $this->assertSame(['idempotency_key' => 'rot-1'], json_decode((string) $body, true));
        $this->assertSame(['platform_id' => self::PLATFORM, 'client_id' => 'pc_next0123456789', 'client_secret' => 'sec'], $r);

        $replay = $this->client(200, ['platform_id' => self::PLATFORM, 'client_id' => 'pc_next0123456789', 'status' => 'next', 'credential_issued' => false, 'idempotent_replay' => true], null, null);
        $this->assertNull($replay->rotateCredential('op-token', self::PLATFORM, 'rot-1')['client_secret']);
    }

    public function test_operator_calls_refuse_plain_http_except_localhost(): void
    {
        $sent = [];
        $make = function (string $url) use (&$sent): AuthTenancyClient {
            return new AuthTenancyClient($url, null, null, function () use (&$sent): array {
                $sent[] = 1;
                return [201, json_encode(['platform_id' => self::PLATFORM, 'client_id' => 'pc_0123456789abcdef'])];
            });
        };

        foreach (['http://auth.example.test', 'http://10.0.0.5:3139'] as $url) {
            try {
                $make($url)->registerPlatform('t', 'A', 'a', 'k');
                $this->fail("$url must be refused");
            } catch (ProvisioningException $e) {
                $this->assertSame('operator_transport', $e->step);
            }
            try {
                $make($url)->rotateCredential('t', self::PLATFORM, 'k');
                $this->fail("$url must be refused");
            } catch (ProvisioningException $e) {
                $this->assertSame('operator_transport', $e->step);
            }
        }
        $this->assertSame([], $sent);

        foreach (['http://localhost:3139', 'http://127.0.0.1', 'https://auth.example.test'] as $url) {
            $this->assertSame(self::PLATFORM, $make($url)->registerPlatform('t', 'A', 'a', 'k')['platform_id']);
        }
    }

    public function test_create_tenant_without_a_slug_omits_the_field(): void
    {
        $this->client(200, ['platform_tenant_id' => self::TENANT])->createTenant('Acme', 'k', 'u');

        $this->assertSame(['tenant_name' => 'Acme', 'idempotency_key' => 'k'], json_decode((string) $this->seen[0][3], true));
    }

    public function test_mark_provisioned_and_failed_send_no_body(): void
    {
        $client = $this->client(200, ['status' => 'ok']);

        $client->markProvisioned(self::TENANT);
        $client->markProvisioningFailed(self::TENANT);

        $this->assertSame('https://auth.example.test/api/internal/tenants/' . self::TENANT . '/provisioned', $this->seen[0][1]);
        $this->assertSame('https://auth.example.test/api/internal/tenants/' . self::TENANT . '/provisioning-failed', $this->seen[1][1]);
        $this->assertNull($this->seen[0][3]);
        $this->assertNull($this->seen[1][3]);
        $this->assertNotContains('authorization', $this->headerNames(), 'these calls need only the platform credential');
    }

    public function test_add_membership_shape_with_and_without_inviter(): void
    {
        $client = $this->client(201, ['platform_tenant_membership_id' => self::MEMBERSHIP, 'platform_tenant_id' => self::TENANT, 'status' => 'active', 'is_new_membership' => true, 'idempotent_replay' => false]);

        $id = $client->addMembership(self::TENANT, self::IDENTITY, 'inv-1', '44444444-4444-4444-8444-444444444444');
        $client->addMembership(self::TENANT, self::IDENTITY, 'inv-2');

        $this->assertSame(['id' => self::MEMBERSHIP, 'status' => 'active'], $id);
        $this->assertSame(['identity_id' => self::IDENTITY, 'idempotency_key' => 'inv-1', 'invited_by_identity_id' => '44444444-4444-4444-8444-444444444444'], json_decode((string) $this->seen[0][3], true));
        $this->assertSame(['identity_id' => self::IDENTITY, 'idempotency_key' => 'inv-2'], json_decode((string) $this->seen[1][3], true));
        $this->assertSame('https://auth.example.test/api/internal/tenants/' . self::TENANT . '/memberships', $this->seen[0][1]);
    }

    public function test_list_memberships_shape(): void
    {
        $row = ['id' => self::MEMBERSHIP, 'platform_id' => self::PLATFORM, 'platform_slug' => 'acme', 'tenant_id' => self::TENANT, 'tenant_slug' => null, 'tenant_name' => 'Acme', 'tenant_db_name' => 'acme_tenant_x', 'is_tenant_owner' => true, 'status' => 'active', 'joined_at' => '2026-11-20T00:00:00Z'];
        $client = $this->client(200, ['success' => true, 'memberships' => [$row]]);

        $rows = $client->listMemberships('user.jwt');

        [$method, $url, $headers, $body] = $this->seen[0];
        $this->assertSame('GET', $method);
        $this->assertSame('https://auth.example.test/api/internal/memberships', $url);
        $this->assertNull($body);
        $this->assertContains('Authorization: Bearer user.jwt', $headers);
        $this->assertContains('X-Platform-Client-Id: pc_0123456789abcdef', $headers);
        $this->assertSame([$row], $rows);
    }

    public function test_a_list_row_without_is_tenant_owner_breaks_the_contract(): void
    {
        $base = ['id' => self::MEMBERSHIP, 'tenant_id' => self::TENANT, 'tenant_slug' => null, 'tenant_name' => 'Acme', 'tenant_db_name' => 'x', 'status' => 'active'];
        foreach ([$base, $base + ['is_tenant_owner' => null], $base + ['is_tenant_owner' => 'true'], $base + ['is_tenant_owner' => 1]] as $row) {
            try {
                $this->client(200, ['success' => true, 'memberships' => [$row]])->listMemberships('u');
                $this->fail('a row without a boolean is_tenant_owner must be refused');
            } catch (ProvisioningException $e) {
                $this->assertStringContainsString('is_tenant_owner', $e->getMessage());
            }
        }
    }

    public function test_is_tenant_owner_is_read_from_the_response_for_true_and_false(): void
    {
        $base = ['id' => self::MEMBERSHIP, 'tenant_id' => self::TENANT, 'tenant_slug' => null, 'tenant_name' => 'Acme', 'tenant_db_name' => 'x', 'status' => 'active'];
        $authority = new \StoneScriptPHP\Tenancy\Provisioning\ExternalMembershipAuthority(
            $this->client(200, ['success' => true, 'memberships' => [$base + ['is_tenant_owner' => true], ['id' => self::IDENTITY, 'tenant_id' => self::PLATFORM, 'is_tenant_owner' => false] + $base]])
        );

        $rows = $authority->listMemberships(self::IDENTITY, 'u');

        $this->assertTrue($rows[0]['is_tenant_owner']);
        $this->assertFalse($rows[1]['is_tenant_owner']);
    }

    public function test_a_membership_list_without_the_array_is_an_error_not_an_empty_list(): void
    {
        $this->expectException(ProvisioningException::class);
        $this->client(200, ['success' => true])->listMemberships('u');
    }

    public function test_verify_membership_shape_for_exists_true_and_false(): void
    {
        $yes = $this->client(200, ['exists' => true, 'membership_id' => self::MEMBERSHIP, 'tenant_id' => self::TENANT, 'tenant_slug' => null, 'tenant_name' => 'Acme', 'tenant_db_name' => 'x', 'status' => 'active']);
        $row = $yes->verifyMembership('user.jwt', self::TENANT);

        [$method, $url, , $body] = $this->seen[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/memberships/verify', $url);
        $this->assertSame(['tenant_id' => self::TENANT], json_decode((string) $body, true));
        $this->assertSame(self::MEMBERSHIP, $row['membership_id']);

        $this->seen = [];
        $this->assertNull($this->client(200, ['exists' => false])->verifyMembership('user.jwt', self::TENANT, 'some-slug'));
        $this->assertSame(['tenant_id' => self::TENANT, 'tenant_slug' => 'some-slug'], json_decode((string) $this->seen[0][3], true));
    }

    public function test_every_auth_error_status_carries_its_code_and_nothing_else(): void
    {
        $cases = [
            [400, 'invalid_slug'], [401, 'unauthorized'], [403, 'platform_not_active'], [404, 'tenant_not_found'],
            [409, 'tenant_not_provisioned'], [409, 'slug_taken'], [409, 'invalid_state'], [422, 'tenant_db_name_too_long'],
        ];
        foreach ($cases as [$status, $code]) {
            try {
                $this->client($status, ['error' => $code, 'detail' => 'S3cretS3cretS3cret'])->createTenant('A', 'k', 'u');
                $this->fail("HTTP $status must fail");
            } catch (ProvisioningException $e) {
                $this->assertStringContainsString("HTTP $status $code", $e->getMessage());
                $this->assertStringNotContainsString('S3cret', $e->getMessage());
            }
        }
    }

    public function test_slug_rule_is_at_most_19_characters(): void
    {
        $this->assertTrue(AuthTenancyClient::isValidSlug('acme'));
        $this->assertTrue(AuthTenancyClient::isValidSlug('acme_shop_2'));
        $this->assertTrue(AuthTenancyClient::isValidSlug(str_repeat('a', 19)));
        $this->assertFalse(AuthTenancyClient::isValidSlug(str_repeat('a', 20)));
        foreach (['', 'Acme', 'acme-shop', 'acme__shop', '_acme', 'acme_', 'acme shop'] as $bad) {
            $this->assertFalse(AuthTenancyClient::isValidSlug($bad), $bad);
        }
        // {slug}_tenant_{uuid with underscores} must fit a 63-byte database name.
        $this->assertLessThanOrEqual(63, strlen(str_repeat('a', 19) . '_tenant_' . str_replace('-', '_', '22222222-2222-4222-8222-222222222222')));
    }
}

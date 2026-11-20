<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Provisioning\GatewayTenantDatabaseCreator;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;

/**
 * @covers \StoneScriptPHP\Tenancy\Provisioning\GatewayTenantDatabaseCreator
 */
final class GatewayTenantDatabaseCreatorTest extends TestCase
{
    private const TENANT = '22222222-2222-4222-8222-222222222222';
    private const TOKEN = 'ssdb_pt_secret_token';

    /** @var list<array{string,string,string}> */
    private array $calls = [];

    /** @param array{0:int,1:string|false,2:string} $response */
    private function creator(array $response, string $token = self::TOKEN): GatewayTenantDatabaseCreator
    {
        return new GatewayTenantDatabaseCreator('acme', 'http://gateway.invalid', $token, 'tenant', function (string $path, string $payload, string $bearer) use ($response): array {
            $this->calls[] = [$path, $payload, $bearer];
            return $response;
        });
    }

    public function test_creates_with_uuid_platform_and_tenant_schema_and_returns_the_reported_name(): void
    {
        $name = $this->creator([201, json_encode(['database_name' => 'acme_tenant_22222222_2222_4222_8222_222222222222']), ''])->create(self::TENANT);

        $this->assertSame('acme_tenant_22222222_2222_4222_8222_222222222222', $name);
        $this->assertSame('/admin/database/create', $this->calls[0][0]);
        $this->assertSame(['platform' => 'acme', 'schema_name' => 'tenant', 'uuid' => self::TENANT], json_decode($this->calls[0][1], true));
        $this->assertSame(self::TOKEN, $this->calls[0][2]);
    }

    public function test_accepts_a_name_wrapped_in_data(): void
    {
        $name = $this->creator([200, json_encode(['data' => ['database_name' => 'n']]), ''])->create(self::TENANT);

        $this->assertSame('n', $name);
    }

    public function test_a_409_body_that_names_the_database_as_database_is_read_too(): void
    {
        $this->assertSame('acme_tenant_x', $this->creator([409, json_encode(['error' => 'database_already_exists', 'database' => 'acme_tenant_x']), ''])->create(self::TENANT));
        $this->assertSame('acme_tenant_y', $this->creator([201, json_encode(['database' => 'acme_tenant_y']), ''])->create(self::TENANT));
    }

    public function test_a_success_that_does_not_name_the_database_is_refused(): void
    {
        $this->expectException(ProvisioningException::class);
        $this->creator([201, json_encode(['status' => 'ok']), ''])->create(self::TENANT);
    }

    public function test_existing_verified_database_is_success_on_retry_with_or_without_a_name(): void
    {
        $this->assertNull($this->creator([409, json_encode(['error' => 'database_already_exists']), ''])->create(self::TENANT));
        $this->assertSame('x', $this->creator([409, json_encode(['error' => 'database_already_exists', 'database_name' => 'x']), ''])->create(self::TENANT));
    }

    public function test_name_collision_unknown_409_server_error_and_unreachable_all_fail_closed(): void
    {
        $cases = [
            [409, json_encode(['error' => 'database_name_collision'])],
            [422, json_encode(['error' => 'database_name_collision'])],
            [409, json_encode(['error' => 'something_new'])],
            [409, 'database already exists'],
            [500, json_encode(['error' => 'database_incomplete_x'])],
            [403, ''],
        ];
        foreach ($cases as [$status, $body]) {
            try {
                $this->creator([$status, $body, ''])->create(self::TENANT);
                $this->fail("HTTP $status $body must fail");
            } catch (ProvisioningException $e) {
                $this->assertSame('create_database', $e->step);
                $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }

        $this->expectException(ProvisioningException::class);
        $this->creator([0, false, 'could not connect'])->create(self::TENANT);
    }

    public function test_missing_token_and_unsafe_tenant_ids_are_refused_before_any_request(): void
    {
        foreach ([fn () => $this->creator([201, '{}', ''], '')->create(self::TENANT), fn () => $this->creator([201, '{}', ''])->create('Not_A_Safe-ID')] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a ProvisioningException');
            } catch (ProvisioningException) {
            }
        }
        $this->assertSame([], $this->calls);
    }
}

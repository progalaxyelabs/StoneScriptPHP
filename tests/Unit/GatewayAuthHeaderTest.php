<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gateway admin-auth header tests (#2915).
 *
 * Companion to gateway #2913, which placed POST /v2/migrate and /v2/migrate-all
 * behind admin_auth_middleware (shared admin bearer + IP allowlist). The migrate
 * CLI helpers must present `Authorization: Bearer <token>` when an admin token is
 * configured, and MUST omit it when none is set (back-compat for local/ungated
 * gateways). gatewayJsonHeaders() is the single source of that header logic, used
 * by stepCreateDatabase, stepMigrateDatabase and stepMigrateAllDatabases.
 */
class GatewayAuthHeaderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/gateway-common.php';
    }

    public function test_includes_bearer_header_when_token_set(): void
    {
        $headers = gatewayJsonHeaders('{"x":1}', 'secret-token-123');

        $this->assertContains('Authorization: Bearer secret-token-123', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function test_omits_bearer_header_when_token_null(): void
    {
        $headers = gatewayJsonHeaders('{"x":1}', null);

        foreach ($headers as $h) {
            $this->assertStringStartsNotWith('Authorization:', $h, 'No Authorization header when token is null');
        }
        // Base headers still present.
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function test_omits_bearer_header_when_token_empty_string(): void
    {
        // getenv() returns '' (falsy) when an env var is set-but-empty; must not emit a
        // bare "Authorization: Bearer " header, which the gateway would reject.
        $headers = gatewayJsonHeaders('{"x":1}', '');

        foreach ($headers as $h) {
            $this->assertStringStartsNotWith('Authorization:', $h);
        }
    }

    public function test_token_defaults_to_omitted(): void
    {
        $headers = gatewayJsonHeaders('{"x":1}');

        foreach ($headers as $h) {
            $this->assertStringStartsNotWith('Authorization:', $h);
        }
    }

    public function test_content_length_matches_payload_bytes(): void
    {
        $payload = '{"platform":"myapp","schema_name":"v1"}';
        $headers = gatewayJsonHeaders($payload, 'tok');

        $this->assertContains('Content-Length: ' . strlen($payload), $headers);
    }

    public function test_migrate_step_signatures_accept_admin_token(): void
    {
        // Guard against accidental signature drift: the admin token param must exist
        // and be nullable on both migrate step functions (the seam this task closes).
        $migrate = new \ReflectionFunction('stepMigrateDatabase');
        $this->assertSame('adminToken', $migrate->getParameters()[4]->getName());
        $this->assertTrue($migrate->getParameters()[4]->allowsNull());

        $migrateAll = new \ReflectionFunction('stepMigrateAllDatabases');
        $this->assertSame('adminToken', $migrateAll->getParameters()[3]->getName());
        $this->assertTrue($migrateAll->getParameters()[3]->allowsNull());
    }
}

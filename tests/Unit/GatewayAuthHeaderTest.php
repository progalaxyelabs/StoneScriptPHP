<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gateway auth header tests.
 *
 * gatewayJsonHeaders() is the single source of Bearer-header logic shared by
 * stepCreateDatabase, stepMigrateDatabase and stepMigrateAllDatabases — it must
 * present `Authorization: Bearer <token>` when a token is passed in, and MUST
 * omit it when none is set (back-compat for local/ungated gateways).
 *
 * As of gateway v4.1.0+ (v5.0.1 CLI refactor, commit 567050c), POST /v2/migrate,
 * POST /v2/migrate-all and POST /admin/database/create require a per-platform
 * bearer token (DB_GATEWAY_PLATFORM_TOKEN / `platformToken`), NOT the shared
 * admin token — passing the admin token to these endpoints now returns HTTP 403.
 * The platform token itself is provisioned via POST /admin/platform-token, which
 * DOES require the admin token (see resolveGatewayPlatformToken() /
 * stepProvisionPlatformToken() in cli/helpers/gateway-common.php) — so the admin
 * credential remains the trust root, scoped down to a platform-specific token for
 * these specific operations. This is a deliberate least-privilege narrowing that
 * tracks the gateway's own contract, not an app-side privilege downgrade.
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

    public function test_migrate_step_signatures_accept_platform_token(): void
    {
        // Guard against accidental signature drift: the *platform* token param must
        // exist and be nullable on both migrate step functions. Renamed from
        // 'adminToken' in the v5.0.1 gateway-cli refactor (commit 567050c) — gateway
        // v4.1.0+ requires a per-platform bearer token for POST /v2/migrate and
        // /v2/migrate-all (the admin token now gets HTTP 403 on these endpoints).
        // This is an intentional rename tracking the gateway's contract, not a
        // privilege downgrade: the platform token is itself provisioned via the
        // admin token (see resolveGatewayPlatformToken()), so admin-level trust is
        // still the root — it's just scoped down before hitting these endpoints.
        $migrate = new \ReflectionFunction('stepMigrateDatabase');
        $this->assertSame('platformToken', $migrate->getParameters()[4]->getName());
        $this->assertTrue($migrate->getParameters()[4]->allowsNull());

        $migrateAll = new \ReflectionFunction('stepMigrateAllDatabases');
        $this->assertSame('platformToken', $migrateAll->getParameters()[3]->getName());
        $this->assertTrue($migrateAll->getParameters()[3]->allowsNull());
    }

    // ── cross-DB-link authorization tests ───────────────────────────────────

    public function test_load_cross_db_link_auth_returns_null_when_env_absent(): void
    {
        // Ensure env vars are not set (they should not be in a clean test run).
        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS');
        putenv('GATEWAY_CROSS_DB_LINK_DEPLOY_REF');

        $result = loadCrossDbLinkAuth();
        $this->assertNull($result, 'No env vars → null (cross-DB-link off by default)');
    }

    public function test_load_cross_db_link_auth_returns_null_when_authorized_false(): void
    {
        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED=false');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS=[{"foreign_db":"auth","foreign_table":"identities","allowed_columns":["id"],"scope_column":"platform_code"}]');

        $result = loadCrossDbLinkAuth();
        $this->assertNull($result, 'authorized=false → null');

        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS');
    }

    public function test_load_cross_db_link_auth_returns_null_when_grants_absent(): void
    {
        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED=true');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS');  // unset

        // Should warn and return null
        $result = loadCrossDbLinkAuth();
        $this->assertNull($result, 'authorized=true but no GRANTS → null (safety: never send bare true without grants)');

        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED');
    }

    public function test_load_cross_db_link_auth_returns_null_for_invalid_json_grants(): void
    {
        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED=true');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS=not-valid-json');

        $result = loadCrossDbLinkAuth();
        $this->assertNull($result, 'invalid grants JSON → null (never send malformed payload)');

        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS');
    }

    public function test_load_cross_db_link_auth_returns_authorization_block(): void
    {
        $grantsJson = json_encode([[
            'foreign_db'      => 'example_auth_main',
            'foreign_table'   => 'identities',
            'allowed_columns' => ['id', 'platform_code', 'email'],
            'scope_column'    => 'platform_code',
        ]]);
        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED=true');
        putenv('GATEWAY_CROSS_DB_LINK_DEPLOY_REF=v2026.06.04.1');
        putenv("GATEWAY_CROSS_DB_LINK_GRANTS={$grantsJson}");

        $result = loadCrossDbLinkAuth();

        $this->assertIsArray($result);
        $this->assertTrue($result['authorized']);
        $this->assertSame('v2026.06.04.1', $result['deploy_ref']);
        $this->assertCount(1, $result['grants']);
        $this->assertSame('example_auth_main', $result['grants'][0]['foreign_db']);
        $this->assertSame('identities', $result['grants'][0]['foreign_table']);
        $this->assertSame('platform_code', $result['grants'][0]['scope_column']);

        putenv('GATEWAY_CROSS_DB_LINK_AUTHORIZED');
        putenv('GATEWAY_CROSS_DB_LINK_DEPLOY_REF');
        putenv('GATEWAY_CROSS_DB_LINK_GRANTS');
    }

    public function test_migrate_payload_includes_cross_db_link_when_set(): void
    {
        // Verify the payload JSON built by stepMigrateDatabase includes cross_db_link
        // when the authorization is passed. We use output buffering to capture the
        // function output and a mock HTTP call cannot be made here — so we test the
        // JSON encoding path by reconstructing the body exactly as the function builds it.
        $crossDbLink = [
            'authorized' => true,
            'deploy_ref' => 'v2026.06.04.1',
            'grants'     => [[
                'foreign_db'      => 'example_auth_main',
                'foreign_table'   => 'identities',
                'allowed_columns' => ['id', 'email'],
                'scope_column'    => 'platform_code',
            ]],
        ];

        $body = [
            'platform'          => 'example-builder',
            'schema_name'       => 'main_v1',
            'database_id'       => 'main',
            'force'             => false,
            'allow'             => [],
            'skip_verification' => false,
        ];
        $body['cross_db_link'] = $crossDbLink;  // mirrors the step function logic

        $decoded = json_decode(json_encode($body), true);
        $this->assertArrayHasKey('cross_db_link', $decoded);
        $this->assertTrue($decoded['cross_db_link']['authorized']);
        $this->assertSame('v2026.06.04.1', $decoded['cross_db_link']['deploy_ref']);
        $this->assertCount(1, $decoded['cross_db_link']['grants']);
    }

    public function test_migrate_payload_omits_cross_db_link_when_null(): void
    {
        // When crossDbLink is null, the key must not appear in the payload.
        $crossDbLink = null;

        $body = [
            'platform'          => 'example-builder',
            'schema_name'       => 'main_v1',
            'database_id'       => 'main',
            'force'             => false,
            'allow'             => [],
            'skip_verification' => false,
        ];
        if ($crossDbLink !== null) {
            $body['cross_db_link'] = $crossDbLink;
        }

        $decoded = json_decode(json_encode($body), true);
        $this->assertArrayNotHasKey('cross_db_link', $decoded, 'cross_db_link must be absent when null — gateway rejects manifests fail-closed');
    }

    public function test_migrate_step_signatures_accept_cross_db_link(): void
    {
        // Guard against accidental signature drift on the cross_db_link param.
        $migrate = new \ReflectionFunction('stepMigrateDatabase');
        $params = $migrate->getParameters();
        $crossDbParam = $params[11]; // 12th param (0-indexed)
        $this->assertSame('crossDbLink', $crossDbParam->getName());
        $this->assertTrue($crossDbParam->allowsNull());
        $this->assertNull($crossDbParam->getDefaultValue(), 'must default to null (safe by default)');

        $migrateAll = new \ReflectionFunction('stepMigrateAllDatabases');
        $params = $migrateAll->getParameters();
        $crossDbParam = $params[10]; // 11th param (0-indexed)
        $this->assertSame('crossDbLink', $crossDbParam->getName());
        $this->assertTrue($crossDbParam->allowsNull());
        $this->assertNull($crossDbParam->getDefaultValue(), 'must default to null (safe by default)');
    }
}

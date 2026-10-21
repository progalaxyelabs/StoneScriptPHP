<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\E2E;

/**
 * Seed and migration failures through the framework CLI against the REAL gateway: terminal output (stdout+stderr)
 * must carry identifiers only.
 */
final class RealGatewayCliTest extends RealGatewayTestCase
{
    private string $lastPlatform = '';

    /** @return array{0:int,1:array<string,mixed>} */
    private function post(string $path, array $json, string $token): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
            'content' => (string) json_encode($json),
        ]]);
        $body = (string) @file_get_contents(self::$url . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $mm) === 1) {
                $status = (int) $mm[1];
            }
        }
        return [$status, (array) json_decode($body, true)];
    }

    public function testSeedFailureThroughRegisterMain(): void
    {
        $project = $this->makeProject([
            'seeders/users.pgsql' => "INSERT INTO users (id, email, name) VALUES\n"
                . "  ('11111111-1111-1111-1111-111111111111', 'seed.sentinel@e2e.test', 'Seed One'),\n"
                . "  ('22222222-2222-2222-2222-222222222222', 'seed.sentinel@e2e.test', 'Seed Two');\n",
        ]);
        $this->lastPlatform = $this->newPlatformName('e2eseed');
        [$out, $err, $code] = $this->runCli('gateway-register-main.php', $project, $this->lastPlatform, ['--retry=1', '--delay=0']);
        $this->assertNotSame(0, $code, 'a failing seed must fail the command');
        $this->assertClean($out, 'seed failure stdout');
        $this->assertClean($err, 'seed failure stderr');
        $both = $out . $err;
        $this->assertStringContainsString('error=query_failed', $both);
        $this->assertStringContainsString('sqlstate=23505', $both);
        $this->assertStringContainsString('constraint=users_email_key', $both);
        $this->assertStringContainsString('error details withheld', $both);

        // PINNED FORMAT: a failed seeder cause joins message and detail with ' - ' (not the ' | DETAIL: ' of /call)
        $this->assertSame(1, preg_match('/DB_GATEWAY_PLATFORM_TOKEN=(\S+)/', $out, $m), 'token printed at step 3');
        $platform = $this->lastPlatform;
        [$status, $body] = $this->post('/admin/database/create', ['platform' => $platform, 'schema_name' => 'main'], $m[1]);
        $this->assertSame(500, $status);
        $cause = (string) ($body['cause'] ?? '');
        $this->assertStringStartsWith('duplicate key value violates unique constraint "users_email_key" - Key (email)=(', $cause);
        $this->assertStringNotContainsString(' | DETAIL:', $cause);
    }

    public function testMigrationFailureThroughMigrateMain(): void
    {
        $platform = $this->newPlatformName('e2emig');
        $project = $this->makeProject();
        [$out, $err, $code] = $this->runCli('gateway-register-main.php', $project, $platform, ['--retry=1', '--delay=0']);
        $this->assertSame(0, $code, "registration must succeed first:\n$out\n$err");
        $this->assertSame(1, preg_match('/DB_GATEWAY_PLATFORM_TOKEN=(\S+)/', $out, $m));
        $token = $m[1];

        file_put_contents(
            $project . '/src/postgresql/main/postgresql/migrations/20261021_000001_bad_data.pgsql',
            "INSERT INTO users (email, name) VALUES ('mig.sentinel@e2e.test', 'A'), ('mig.sentinel@e2e.test', 'B');\n"
        );
        [$out, $err, $code] = $this->runCli('gateway-migrate-main.php', $project, $platform, ['--retry=1', '--delay=0'], ['DB_GATEWAY_PLATFORM_TOKEN' => $token]);
        $this->assertNotSame(0, $code, 'a failing migration must fail the command');
        $this->assertClean($out, 'migration failure stdout');
        $this->assertClean($err, 'migration failure stderr');
        $both = $out . $err;
        $this->assertStringContainsString('error=migration_failed', $both);
        $this->assertStringContainsString('item=20261021_000001_bad_data.pgsql', $both);
        $this->assertStringContainsString('constraint=users_email_key', $both);

        // PINNED FORMAT: a failed migration cause is '<message> - <detail> (HINT: ...)' and names the file in `message`
        [$status, $body] = $this->post('/v2/migrate', ['platform' => $platform, 'schema_name' => 'main'], $token);
        $this->assertSame(500, $status);
        $this->assertSame('Migration 20261021_000001_bad_data.pgsql failed', $body['message'] ?? null);
        $cause = (string) ($body['cause'] ?? '');
        $this->assertStringStartsWith('duplicate key value violates unique constraint "users_email_key" - Key (email)=(', $cause);
        $this->assertStringNotContainsString(' | DETAIL:', $cause);
    }

    public function testGatewayLogsStayCleanAfterCliFailures(): void
    {
        $this->assertNotSame('', $this->gatewayLogs(), 'gateway log not found');
        $this->assertClean($this->gatewayLogs(), 'gateway logs');
    }
}

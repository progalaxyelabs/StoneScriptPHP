<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\E2E;

/**
 * Seed and migration failures through the framework CLI against the REAL gateway: terminal output (stdout+stderr)
 * must carry identifiers only.
 */
final class RealGatewayCliTest extends RealGatewayTestCase
{
    public function testSeedFailureThroughRegisterMain(): void
    {
        $project = $this->makeProject([
            'seeders/users.pgsql' => "INSERT INTO users (id, email, name) VALUES\n"
                . "  ('11111111-1111-1111-1111-111111111111', 'seed.sentinel@e2e.test', 'Seed One'),\n"
                . "  ('22222222-2222-2222-2222-222222222222', 'seed.sentinel@e2e.test', 'Seed Two');\n",
        ]);
        [$out, $err, $code] = $this->runCli('gateway-register-main.php', $project, $this->newPlatformName('e2eseed'), ['--retry=1', '--delay=0']);
        $this->assertNotSame(0, $code, 'a failing seed must fail the command');
        $this->assertClean($out, 'seed failure stdout');
        $this->assertClean($err, 'seed failure stderr');
        $both = $out . $err;
        $this->assertStringContainsString('error=query_failed', $both);
        $this->assertStringContainsString('sqlstate=23505', $both);
        $this->assertStringContainsString('constraint=users_email_key', $both);
        $this->assertStringContainsString('error details withheld', $both);
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
    }

    public function testGatewayLogsStayCleanAfterCliFailures(): void
    {
        $this->assertNotSame('', $this->gatewayLogs(), 'gateway log not found');
        $this->assertClean($this->gatewayLogs(), 'gateway logs');
    }
}

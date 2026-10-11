<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CliProjectHarness.php';

/** The stone entry point: exit status reaches the caller; `migrate` no longer points at a removed script. */
final class StoneCliBehaviourTest extends TestCase
{
    private CliProjectHarness $project;

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->cleanup();
        }
    }

    public function test_script_that_reports_failure_without_exiting_makes_stone_exit_non_zero(): void
    {
        $this->project = new CliProjectHarness(stubFramework: true);
        $this->project->write(
            'vendor/progalaxyelabs/stonescriptphp/cli/gateway-register-main.php',
            "<?php\necho \"partial\\n\";\n\$stoneExitCode = 3;\n"
        );
        [$code, $out] = $this->project->stone(['gateway:register-main']);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('partial', $out);
    }

    public function test_script_that_succeeds_exits_zero(): void
    {
        $this->project = new CliProjectHarness(stubFramework: true);
        $this->project->write('vendor/progalaxyelabs/stonescriptphp/cli/gateway-register-main.php', "<?php\necho \"fine\\n\";\n");
        [$code] = $this->project->stone(['gateway:register-main']);
        $this->assertSame(0, $code);
    }

    public function test_script_exit_code_is_preserved(): void
    {
        $this->project = new CliProjectHarness(stubFramework: true);
        $this->project->write('vendor/progalaxyelabs/stonescriptphp/cli/gateway-register-main.php', "<?php\nexit(7);\n");
        [$code] = $this->project->stone(['gateway:register-main']);
        $this->assertSame(7, $code);
    }

    public function test_unknown_command_exits_non_zero(): void
    {
        $this->project = new CliProjectHarness();
        [$code] = $this->project->stone(['no-such-command']);
        $this->assertSame(1, $code);
    }

    public function test_test_command_propagates_the_phpunit_exit_status(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('vendor/bin/phpunit', "#!/bin/sh\nexit 4\n");
        chmod($this->project->dir . '/vendor/bin/phpunit', 0755);
        [$code] = $this->project->stone(['test']);
        $this->assertSame(4, $code);
    }

    public function test_migrate_without_argument_shows_help_and_does_not_mention_composer_install(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        [$code, $out] = $this->project->stone(['migrate']);
        $this->assertSame(2, $code, 'help is printed but a bare `migrate` is not a success');
        $this->assertStringContainsString('gateway:migrate-main', $out);
        $this->assertStringNotContainsString('composer install', $out);
        $this->assertStringNotContainsString('gateway-register.php', $out);
    }

    public function test_migrate_unknown_subcommand_prints_help_and_fails_but_explicit_help_succeeds(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        [$code, $out] = $this->project->stone(['migrate', 'bogus']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('gateway:migrate-main', $out);
        [$code] = $this->project->stone(['migrate', 'help']);
        $this->assertSame(0, $code);
    }

    public function test_migrate_register_is_refused_with_a_pointer_to_the_gateway_commands(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        [$code, $out] = $this->project->stone(['migrate', 'register']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('gateway:register-main', $out);
        $this->assertStringNotContainsString('composer install', $out);
    }

    public function test_the_tracked_stone_file_carries_no_dated_internal_narrative(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/stone');
        $this->assertDoesNotMatchRegularExpression('/\b20\d\d-\d\d-\d\d\b/', $src);
        $this->assertStringNotContainsString('confirmed live', strtolower($src));
    }

    public function test_env_check_lists_public_routes_under_the_tenant_param_once(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        $this->project->write('src/config/routes.php', "<?php\nreturn ['GET' => ["
            . "'/portal/tenant/{tenantId}/invitations/{token}' => ['handler' => 'H', 'service' => 'portal', 'group' => 'inv', 'action' => 'preview', 'access' => 'public'],"
            . "'/portal/tenant/{tenantId}/orders' => ['handler' => 'H', 'service' => 'portal', 'group' => 'orders', 'action' => 'list', 'access' => 'authorization'],"
            . "]];\n");
        [$code, $out] = $this->project->stone(['env:check']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(1, substr_count($out, 'these PUBLIC routes carry'));
        $this->assertStringContainsString('GET /portal/tenant/{tenantId}/invitations/{token}', $out);
        $this->assertStringNotContainsString('/orders', $out);
    }

    public function test_env_check_prints_no_public_route_warning_when_there_is_none(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        [$code, $out] = $this->project->stone(['env:check']);
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('these PUBLIC routes carry', $out);
    }
}

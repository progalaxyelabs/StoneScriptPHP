<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CliProjectHarness.php';

/**
 * Gateway CLI commands read configuration through the framework's Env, so values that live in
 * `.env` (loaded without putenv) are seen, with the application's variable names supported.
 */
final class GatewayCliConfigTest extends TestCase
{
    private CliProjectHarness $project;

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->cleanup();
        }
    }

    /** @return array<string,mixed> */
    private function resolve(string $dotenv, array $env = [], array $options = []): array
    {
        $this->project = new CliProjectHarness();
        if ($dotenv !== '') {
            $this->project->write('.env', $dotenv);
        }
        $opts = var_export($options + [
            'database_id' => null, 'schema_name' => null, 'main_schema_name' => null, 'tenant_schema_name' => null,
        ], true);
        $code = 'define("ROOT_PATH", ' . var_export($this->project->dir . '/', true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/cli/helpers/gateway-common.php', true) . ';'
            . 'echo json_encode(loadGatewayEnv(' . $opts . '));';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project->dir,
            ['PATH' => (string) getenv('PATH')] + $env
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, "exit $status, stdout: $out, stderr: $err");
        return $decoded;
    }

    private const SKELETON_ENV = "DB_GATEWAY_URL=http://gw.invalid:9000\nDB_GATEWAY_PLATFORM=shop\n"
        . "DB_GATEWAY_SCHEMA_NAME=main\nDB_GATEWAY_ADMIN_TOKEN=adm\n";

    public function test_values_in_dotenv_under_the_application_names_are_read(): void
    {
        $c = $this->resolve(self::SKELETON_ENV . "DB_GATEWAY_TENANT_SCHEMA_NAME=tenant\n");
        $this->assertSame('http://gw.invalid:9000', $c['gateway_url']);
        $this->assertSame('shop', $c['platform_id']);
        $this->assertSame('main', $c['main_schema_name']);
        $this->assertSame('tenant', $c['tenant_schema_name']);
        $this->assertSame('adm', $c['admin_token']);
        $this->assertSame('main', $c['database_id']);
    }

    public function test_legacy_and_app_names_with_equal_values_resolve_fine(): void
    {
        $c = $this->resolve(self::SKELETON_ENV . "PLATFORM_ID=shop\nMAIN_SCHEMA_NAME=main\nADMIN_TOKEN=adm\nDATABASE_ID=db1\n");
        $this->assertSame('shop', $c['platform_id']);
        $this->assertSame('main', $c['main_schema_name']);
        $this->assertSame('adm', $c['admin_token']);
        $this->assertSame('db1', $c['database_id']);
    }

    /** @return array{0:int,1:string} */
    private function stoneWith(string $dotenv, array $args, array $env = []): array
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', $dotenv);
        mkdir($this->project->dir . '/src/postgresql/main/postgresql/tables', 0700, true);
        mkdir($this->project->dir . '/src/postgresql/tenant/postgresql/tables', 0700, true);
        return $this->project->stone($args, $env);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("conflicts")]
    public function test_conflicting_legacy_and_app_values_stop_the_command_naming_both_keys_not_values(string $legacy, string $app): void
    {
        [$code, $out] = $this->stoneWith(
            "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=shop\nDB_GATEWAY_SCHEMA_NAME=main\n"
            . "DB_GATEWAY_TENANT_SCHEMA_NAME=tenant\nDB_GATEWAY_ADMIN_TOKEN=secret-app-value\n"
            . "$legacy=secret-legacy-value\n",
            ['gateway:register-main', '--retry=1', '--delay=0']
        );
        $this->assertSame(1, $code);
        $this->assertStringContainsString("$legacy and $app", $out);
        $this->assertStringNotContainsString('secret-legacy-value', $out);
        $this->assertStringNotContainsString('secret-app-value', $out);
    }

    public static function conflicts(): array
    {
        return [
            'platform' => ['PLATFORM_ID', 'DB_GATEWAY_PLATFORM'],
            'main schema' => ['MAIN_SCHEMA_NAME', 'DB_GATEWAY_SCHEMA_NAME'],
            'tenant schema' => ['TENANT_SCHEMA_NAME', 'DB_GATEWAY_TENANT_SCHEMA_NAME'],
            'admin token' => ['ADMIN_TOKEN', 'DB_GATEWAY_ADMIN_TOKEN'],
        ];
    }

    public function test_tenants_check_ids_also_stops_on_a_platform_conflict(): void
    {
        [$code, $out] = $this->stoneWith(
            "DB_GATEWAY_PLATFORM=shop\nPLATFORM_ID=other\nDB_GATEWAY_TENANT_SCHEMA_NAME=tenant\n",
            ['tenants:check-ids', '--sample-generator=1']
        );
        $this->assertSame(2, $code, 'a configuration conflict is a usage error here; 1 means violations found');
        $this->assertStringContainsString('PLATFORM_ID and DB_GATEWAY_PLATFORM', $out);
    }

    public function test_tenants_check_ids_tenant_schema_does_not_fall_back_to_schema_name(): void
    {
        [$code, $out] = $this->stoneWith(
            "DB_GATEWAY_PLATFORM=shop\nSCHEMA_NAME=v1_0\nDB_GATEWAY_SCHEMA_NAME=main\n",
            ['tenants:check-ids', '--sample-generator=1']
        );
        $this->assertSame(2, $code, 'usage error: no tenant schema configured');
        $this->assertStringContainsString('--schema', $out);
    }

    public function test_register_tenant_never_uses_the_main_schema_name(): void
    {
        // Only the MAIN schema is configured: register-tenant must refuse, not upload under "main".
        [$code, $out] = $this->stoneWith(
            "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=shop\nDB_GATEWAY_SCHEMA_NAME=main\nDB_GATEWAY_ADMIN_TOKEN=adm\n",
            ['gateway:register-tenant']
        );
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no tenant schema name', $out);
        $this->assertStringNotContainsString('Schema:      main', $out);
    }

    public function test_register_tenant_resolution_order(): void
    {
        $base = "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=shop\nDB_GATEWAY_SCHEMA_NAME=main\n";
        [, $out] = $this->stoneWith($base . "DB_GATEWAY_TENANT_SCHEMA_NAME=tenant_a\nSCHEMA_NAME=generic\n", ['gateway:register-tenant']);
        $this->assertStringContainsString('Schema:      tenant_a', $out);
        [, $out] = $this->stoneWith($base . "SCHEMA_NAME=generic\n", ['gateway:register-tenant']);
        $this->assertStringContainsString('Schema:      generic', $out);
        [, $out] = $this->stoneWith($base . "DB_GATEWAY_TENANT_SCHEMA_NAME=tenant_a\n", ['gateway:register-tenant', '--schema-name=cli_t']);
        $this->assertStringContainsString('Schema:      cli_t', $out);
    }

    public function test_cross_db_link_opt_in_is_read_from_the_process_environment_only(): void
    {
        $dotenv = "DB_GATEWAY_URL=http://gw.invalid:9000\nDB_GATEWAY_PLATFORM=shop\nDB_GATEWAY_SCHEMA_NAME=main\n"
            . "GATEWAY_CROSS_DB_LINK_AUTHORIZED=true\nGATEWAY_CROSS_DB_LINK_GRANTS=[]\n";
        $c = $this->resolve($dotenv);
        $this->assertNull($c['cross_db_link'], '.env must not enable the cross-DB-link opt-in');

        $c = $this->resolve($dotenv, ['GATEWAY_CROSS_DB_LINK_AUTHORIZED' => 'true', 'GATEWAY_CROSS_DB_LINK_GRANTS' => '[]']);
        $this->assertIsArray($c['cross_db_link']);
        $this->assertTrue($c['cross_db_link']['authorized']);
    }

    public function test_legacy_only_environment_is_unchanged(): void
    {
        $c = $this->resolve(
            "DB_GATEWAY_URL=http://gw.invalid:9000\n",
            ['PLATFORM_ID' => 'p-legacy', 'MAIN_SCHEMA_NAME' => 'm-legacy', 'ADMIN_TOKEN' => 'old-token']
        );
        $this->assertSame('p-legacy', $c['platform_id']);
        $this->assertSame('m-legacy', $c['main_schema_name']);
        $this->assertSame('old-token', $c['admin_token']);
    }

    public function test_real_environment_beats_dotenv_and_cli_option_beats_both(): void
    {
        $c = $this->resolve(self::SKELETON_ENV, ['DB_GATEWAY_ADMIN_TOKEN' => 'from-process', 'DB_GATEWAY_SCHEMA_NAME' => 'proc_main']);
        $this->assertSame('from-process', $c['admin_token']);
        $this->assertSame('proc_main', $c['main_schema_name']);

        $c = $this->resolve(self::SKELETON_ENV, [], ['main_schema_name' => 'opt_main', 'database_id' => 'opt_db']);
        $this->assertSame('opt_main', $c['main_schema_name']);
        $this->assertSame('opt_db', $c['database_id']);
    }

    public function test_register_main_with_only_the_skeleton_dotenv_gets_past_configuration(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=shop\n"
            . "DB_GATEWAY_SCHEMA_NAME=main\nDB_GATEWAY_ADMIN_TOKEN=adm\n");
        mkdir($this->project->dir . '/src/postgresql/main/postgresql/tables', 0700, true);
        [$code, $out] = $this->project->stone(['gateway:register-main', '--retry=1', '--delay=0']);
        $this->assertStringNotContainsString('is required', $out);
        $this->assertStringContainsString('Platform:    shop', $out);
        $this->assertNotSame(0, $code, 'the unreachable gateway must fail the command');
    }

    public function test_cli_scripts_do_not_read_configuration_through_bare_getenv(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/cli/{gateway-*.php,helpers/gateway-common.php,tenants-check-ids.php,validate-schema-contract.php}', GLOB_BRACE) as $file) {
            // The cross-DB-link opt-in is deliberately process-environment-only.
            $code = preg_replace("/getenv\\('GATEWAY_CROSS_DB_LINK_[A-Z_]+'\\)/", '', (string) file_get_contents($file));
            if (preg_match('/\bgetenv\s*\(/', (string) $code)) {
                $offenders[] = basename($file);
            }
        }
        $this->assertSame([], $offenders);
    }

    private const REG = "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=shop\nDB_GATEWAY_ADMIN_TOKEN=adm\n";

    public function test_main_and_tenant_schema_names_must_differ(): void
    {
        [$code, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\nDB_GATEWAY_TENANT_SCHEMA_NAME=main\n", ['gateway:register-main']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('resolve to the same value', $out);
        $this->assertStringContainsString('DB_GATEWAY_SCHEMA_NAME', $out);
        $this->assertStringContainsString('DB_GATEWAY_TENANT_SCHEMA_NAME', $out);
    }

    public function test_register_main_with_the_tenant_name_as_main_override_is_refused(): void
    {
        [$code, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\nDB_GATEWAY_TENANT_SCHEMA_NAME=tenant\n", ['gateway:register-main', '--main-schema-name=tenant']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('resolve to the same value', $out);
    }

    public function test_register_tenant_falling_back_to_schema_name_equal_to_main_is_refused(): void
    {
        [$code, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\nSCHEMA_NAME=main\n", ['gateway:register-tenant']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('same value as the main schema name', $out);
        $this->assertStringNotContainsString('Step 1', $out, 'nothing may be sent to the gateway');
    }

    public function test_register_tenant_override_equal_to_main_is_refused(): void
    {
        [$code, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\n", ['gateway:register-tenant', '--schema-name=main']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('same value as the main schema name', $out);
    }

    public function test_unknown_option_is_rejected_with_exit_2_naming_only_the_option(): void
    {
        foreach (['gateway:register-main', 'gateway:migrate-main', 'gateway:list-schemas', 'gateway:provision-token'] as $cmd) {
            [$code, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\n", [$cmd, '--bogus=secret-value']);
            $this->assertSame(2, $code, $cmd);
            $this->assertStringContainsString("unknown option '--bogus'", $out);
            $this->assertStringNotContainsString('secret-value', $out);
        }
    }

    public function test_schema_name_option_a_command_ignores_is_rejected_not_silently_dropped(): void
    {
        foreach ([['gateway:register-main', '--schema-name=x'], ['gateway:migrate-main', '--schema-name=x'],
                  ['gateway:migrate-tenant', '--schema-name=x'], ['gateway:migrate-all-tenants', '--main-schema-name=x'],
                  ['gateway:list-schemas', '--tenant-schema-name=x']] as $args) {
            [$code, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\nDB_GATEWAY_TENANT_SCHEMA_NAME=tenant\n", $args);
            $this->assertSame(2, $code, implode(' ', $args));
            $this->assertStringContainsString('has no effect on this command', $out);
        }
    }

    public function test_options_a_command_honours_are_still_accepted(): void
    {
        [, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\n", ['gateway:register-tenant', '--schema-name=t1', '--quiet']);
        $this->assertStringNotContainsString('unknown option', $out);
        $this->assertStringNotContainsString('has no effect', $out);
        [, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\n", ['gateway:list-databases', '--schema=x']);
        $this->assertStringNotContainsString('unknown option', $out);
        [, $out] = $this->stoneWith(self::REG . "DB_GATEWAY_SCHEMA_NAME=main\n", ['gateway:register-main', '--retry=1', '--delay=0', '--force', '--main-schema-name=m2']);
        $this->assertStringNotContainsString('unknown option', $out);
    }

    public function test_env_example_documents_the_application_names_only(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');
        $this->assertDoesNotMatchRegularExpression('/^PLATFORM_ID=/m', $example);
        $this->assertDoesNotMatchRegularExpression('/^SCHEMA_NAME=/m', $example);
        $this->assertMatchesRegularExpression('/^DB_GATEWAY_SCHEMA_NAME=main$/m', $example);
    }
}

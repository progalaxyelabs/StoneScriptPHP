<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A broken .env must fail loudly and keep failing: Env must never hand back a
 * half-built singleton of class defaults (builtin auth, empty config), and no response or
 * check output may disclose the file's contents or location.
 *
 * Each case runs in a child PHP process because ROOT_PATH is a process-wide constant.
 */
final class EnvBrokenEnvFileTest extends TestCase
{
    private const GW = ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => 'main'];

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_env_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $env = $this->dir . DIRECTORY_SEPARATOR . '.env';
        if (is_dir($env)) {
            rmdir($env);
        } elseif (is_file($env)) {
            unlink($env);
        }
        rmdir($this->dir);
    }

    /** @param array<string,string> $envVars @return array{0:string,1:int,2:string} stdout, exit code, stderr */
    private function php(string $code, array $envVars = []): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $full = 'define("ROOT_PATH", ' . var_export($this->dir . DIRECTORY_SEPARATOR, true) . ');'
            . 'require ' . var_export($autoload, true) . ';' . $code;
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $full],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')] + $envVars
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$out, proc_close($proc), $err];
    }

    private function attempts(array $envVars, string $tail = ''): string
    {
        return $this->php(
            'for ($i = 1; $i <= 2; $i++) {'
            . '  try { $e = StoneScriptPHP\Env::get_instance(); echo "ATTEMPT$i:OK:" . $e->AUTH_MODE . "\n"; }'
            . '  catch (Throwable $t) { echo "ATTEMPT$i:THROW:" . get_class($t) . ":" . $t->getMessage() . "\n"; }'
            . '}' . $tail,
            $envVars
        )[0];
    }

    /** @return array{0:string,1:string} response body (stdout), server error log (stderr) */
    private function application(array $envVars): array
    {
        $r = $this->php(
            '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/anything";'
            . 'StoneScriptPHP\Application::run(["routes" => []]);',
            $envVars
        );
        return [$r[0], $r[2]];
    }

    /** @return array{0:string,1:int} stdout+stderr combined, exit code */
    private function envCheck(array $envVars): array
    {
        [$o, $c, $e] = $this->php(
            'require ' . var_export(dirname(__DIR__, 2) . '/cli/env-check.php', true) . ';',
            $envVars
        );
        return [$o . $e, $c];
    }

    private function json(string $out): array
    {
        $json = json_decode((string) strstr($out, '{'), true);
        $this->assertIsArray($json, 'output must be clean JSON: ' . $out);
        return $json;
    }

    // ---- Env: load failures -------------------------------------------------------------

    public function test_semicolon_comment_env_throws_every_time(): void
    {
        file_put_contents($this->dir . '/.env', "; semicolon comment\n");
        $out = $this->attempts(self::GW);

        $this->assertStringContainsString('ATTEMPT1:THROW:StoneScriptPHP\Exceptions\ConfigurationException', $out);
        $this->assertStringContainsString('ATTEMPT2:THROW:StoneScriptPHP\Exceptions\ConfigurationException', $out);
        $this->assertStringNotContainsString(':OK:', $out, 'must not fall back to a defaults-only instance');
        $this->assertStringNotContainsString('semicolon', $out, 'dotenv text must never be forwarded');
    }

    public function test_invalid_secret_line_is_never_leaked_by_the_exception_or_the_response(): void
    {
        // Line 1 is valid; the secret is on the INVALID line 2 (this is the case phpdotenv quotes back).
        file_put_contents($this->dir . '/.env', "DB_GATEWAY_URL=http://g:9000\nSECRET_TOKEN=hunter2 x\n");

        $out = $this->attempts(self::GW);
        $this->assertStringContainsString('ATTEMPT1:THROW:StoneScriptPHP\Exceptions\ConfigurationException', $out);
        $this->assertStringContainsString('not valid dotenv syntax', $out);
        $this->assertStringNotContainsString('hunter2', $out);

        [$body, $log] = $this->application(self::GW);
        $this->assertStringNotContainsString('hunter2', $body . $log);
        $this->assertStringNotContainsString($this->dir, $body);

        [$check, $code] = $this->envCheck(self::GW);
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('hunter2', $check);
    }

    public function test_env_that_is_a_directory_is_treated_as_missing(): void
    {
        mkdir($this->dir . '/.env'); // Docker bind-mount of a host file that did not exist
        $out = $this->attempts(self::GW);

        $this->assertStringContainsString('ATTEMPT1:OK:builtin', $out);
        $this->assertStringContainsString('ATTEMPT2:OK:builtin', $out);
    }

    public function test_unreadable_env_file_throws(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read a mode-000 file');
        }
        file_put_contents($this->dir . '/.env', "FOO=bar\n");
        chmod($this->dir . '/.env', 0000);

        $out = $this->attempts(self::GW);
        $this->assertStringContainsString('ATTEMPT1:THROW:StoneScriptPHP\Exceptions\ConfigurationException', $out);
        $this->assertStringContainsString('Cannot read the environment file', $out);
        $this->assertStringContainsString('ATTEMPT2:THROW', $out);
    }

    public function test_missing_env_file_with_required_settings_in_real_env_boots(): void
    {
        $out = $this->attempts(self::GW);
        $this->assertStringContainsString('ATTEMPT1:OK:builtin', $out);
        $this->assertStringContainsString('ATTEMPT2:OK:builtin', $out);
    }

    public function test_missing_required_settings_are_remembered_not_forgotten(): void
    {
        $out = $this->attempts([], 'echo "FAILURE:" . (StoneScriptPHP\Env::bootFailure()?->getMessage() ?? "none");');

        // composer's autoload already constructed Env once (bootstrap.php swallows that failure for CLI
        // tools), so get_instance() hands back the fully loaded instance - but the failure is not forgotten.
        $this->assertStringContainsString('ATTEMPT1:OK:builtin', $out);
        $this->assertStringContainsString("FAILURE:Required configuration 'DB_GATEWAY_URL'", $out);
    }

    public function test_valid_env_file_loads(): void
    {
        file_put_contents($this->dir . '/.env', "# comment\nDB_GATEWAY_URL=http://g:9000\nDB_GATEWAY_PLATFORM=p\nAUTH_MODE=hybrid\n");
        $this->assertStringContainsString('ATTEMPT1:OK:hybrid', $this->attempts([]));
    }

    // ---- Application::run ---------------------------------------------------------------

    public function test_application_run_returns_generic_500_for_a_broken_env_without_path_or_dotenv_text(): void
    {
        file_put_contents($this->dir . '/.env', "; not a dotenv comment\n");
        [$out, $log] = $this->application(self::GW);

        $json = $this->json($out);
        $this->assertSame('error', $json['status']);
        $this->assertSame('env_configuration_invalid', $json['data']['error']);
        $this->assertStringContainsString('not configured correctly', $json['message']);
        $this->assertStringNotContainsString($this->dir, $out, 'filesystem path must stay out of the response');
        $this->assertStringNotContainsString('not a dotenv', $out . $log);
        $this->assertStringContainsString($this->dir, $log, 'the path belongs in the server error log');
    }

    public function test_application_run_refuses_to_serve_when_a_required_setting_is_missing(): void
    {
        $json = $this->json($this->application([])[0]);
        $this->assertSame('env_configuration_invalid', $json['data']['error']);
        $this->assertStringNotContainsString('DB_GATEWAY_URL', $json['message']);
    }

    public function test_application_run_gets_past_the_env_check_with_env_only_configuration(): void
    {
        $this->assertStringNotContainsString('env_configuration_invalid', $this->application(self::GW)[0]);
    }

    public function test_application_run_refuses_empty_gateway_settings_and_missing_schema(): void
    {
        foreach ([
            ['DB_GATEWAY_URL' => '', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => 'main'],
            ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => '', 'DB_GATEWAY_SCHEMA_NAME' => 'main'],
            ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => ''],
            ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p'],
        ] as $vars) {
            $json = $this->json($this->application($vars)[0]);
            $this->assertSame('env_configuration_invalid', $json['data']['error'], json_encode($vars));
        }
    }

    // ---- tenancy needs DB_GATEWAY_TENANT_SCHEMA_NAME -------------------------------------

    private function applicationWith(string $configPhp, array $envVars): string
    {
        return $this->php(
            '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/anything";'
            . 'StoneScriptPHP\Application::run(' . $configPhp . ');',
            $envVars
        )[0];
    }

    public function test_declared_tenancy_without_tenant_schema_fails_at_boot(): void
    {
        $out = $this->applicationWith('["routes" => [], "tenant_url_match" => ["enabled" => true]]', self::GW);
        $this->assertSame('env_configuration_invalid', $this->json($out)['data']['error']);
    }

    public function test_declared_tenancy_with_tenant_schema_boots_past_the_env_check(): void
    {
        $out = $this->applicationWith(
            '["routes" => [], "tenant_url_match" => ["enabled" => true]]',
            self::GW + ['DB_GATEWAY_TENANT_SCHEMA_NAME' => 'tenant']
        );
        $this->assertStringNotContainsString('env_configuration_invalid', $out);
    }

    public function test_no_declared_tenancy_does_not_require_a_tenant_schema(): void
    {
        $out = $this->applicationWith('["routes" => []]', self::GW);
        $this->assertStringNotContainsString('env_configuration_invalid', $out);
    }

    private const NULL_STRATEGY = 'new class implements StoneScriptPHP\\Tenancy\\TenancyStrategyInterface {'
        . ' public function resolveTenantId(array $request, ?StoneScriptPHP\\Auth\\AuthenticatedUser $user): ?string { return null; } }';

    public function test_a_custom_no_op_strategy_boots_without_a_tenant_schema(): void
    {
        $out = $this->applicationWith('["routes" => [], "tenancy" => ["strategy" => ' . self::NULL_STRATEGY . ']]', self::GW);
        $this->assertStringNotContainsString('env_configuration_invalid', $out);
    }

    public function test_a_strategy_that_declares_tenant_routing_requires_the_tenant_schema(): void
    {
        $strategy = 'new class implements StoneScriptPHP\\Tenancy\\TenancyStrategyInterface, StoneScriptPHP\\Tenancy\\DeclaresTenantRouting {'
            . ' public function resolveTenantId(array $request, ?StoneScriptPHP\\Auth\\AuthenticatedUser $user): ?string { return "t"; }'
            . ' public function routesTenants(): bool { return true; } }';
        $cfg = '["routes" => [], "tenancy" => ["strategy" => ' . $strategy . ']]';
        $this->assertSame('env_configuration_invalid', $this->json($this->applicationWith($cfg, self::GW))['data']['error']);
        $this->assertStringNotContainsString(
            'env_configuration_invalid',
            $this->applicationWith($cfg, self::GW + ['DB_GATEWAY_TENANT_SCHEMA_NAME' => 'tenant'])
        );
    }

    public function test_request_time_tenant_id_without_tenant_schema_answers_a_clear_500_not_an_opaque_failure(): void
    {
        $code = 'StoneScriptPHP\Env::resetForTesting();'
            . '$mw = new StoneScriptPHP\Routing\Middleware\GatewayTenantMiddleware(new class implements StoneScriptPHP\Tenancy\TenancyStrategyInterface {'
            . '  public function resolveTenantId(array $request, ?StoneScriptPHP\Auth\AuthenticatedUser $user): ?string { return "t1"; } });'
            . '$r = $mw->handle([], static fn(array $q) => new StoneScriptPHP\ApiResponse("ok", "reached"));'
            . 'echo "R:" . $r->status . ":" . $r->httpStatusCode . ":" . $r->message;';
        [$out] = $this->php($code, self::GW);
        $this->assertStringContainsString('R:error:500:Tenant routing is not configured on this server (DB_GATEWAY_TENANT_SCHEMA_NAME)', $out);

        [$out2] = $this->php($code, self::GW + ['DB_GATEWAY_TENANT_SCHEMA_NAME' => 'tenant']);
        $this->assertStringContainsString('R:ok:', $out2);
    }

    public function test_reset_for_testing_makes_env_reread_the_environment(): void
    {
        [$out] = $this->php(
            'putenv("APP_NAME=First"); StoneScriptPHP\Env::resetForTesting(); $a = StoneScriptPHP\Env::get_instance()->APP_NAME;'
            . 'putenv("APP_NAME=Second"); $same = StoneScriptPHP\Env::get_instance()->APP_NAME;'
            . 'StoneScriptPHP\Env::resetForTesting(); $b = StoneScriptPHP\Env::get_instance()->APP_NAME;'
            . 'echo "$a|$same|$b";',
            self::GW
        );
        $this->assertSame('First|First|Second', $out);
    }

    // ---- php stone env:check ------------------------------------------------------------

    public function test_env_check_detects_the_unparseable_case_that_bootFailure_cannot(): void
    {
        file_put_contents($this->dir . '/.env', "; nope\n");
        [$out, $code] = $this->envCheck(self::GW);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('FAIL', $out);
        $this->assertStringContainsString('not valid dotenv syntax', $out);
    }

    public function test_env_check_fails_on_missing_required_settings(): void
    {
        [$out, $code] = $this->envCheck([]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString("Required configuration 'DB_GATEWAY_URL'", $out);
    }

    public function test_env_check_and_run_agree_on_every_env_level_refusal(): void
    {
        $cases = [
            'empty url'      => ['DB_GATEWAY_URL' => '', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => 'main'],
            'empty platform' => ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => '', 'DB_GATEWAY_SCHEMA_NAME' => 'main'],
            'empty schema'   => ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => ''],
            'no schema'      => ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p'],
            'blank schema'   => ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => '  '],
            'bad db mode'    => self::GW + ['DB_MODE' => 'typo'],
            'good'           => self::GW,
        ];
        foreach ($cases as $name => $vars) {
            $runRefuses = str_contains($this->application($vars)[0], 'env_configuration_invalid');
            $checkFails = $this->envCheck($vars)[1] === 1;
            $this->assertSame($runRefuses, $checkFails, "run()/env:check disagree for: $name");
        }
        $this->assertTrue($runRefuses === false && $checkFails === false); // the last case is the good one
    }

    public function test_env_check_warns_but_passes_when_only_app_config_dependent_checks_would_fail(): void
    {
        [$out, $code] = $this->envCheck(self::GW + ['AUTH_MODE' => 'external']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('WARN: typed_auth wiring', $out);
        $this->assertStringContainsString('WARN: Tenancy is in use but', $out);
    }

    public function test_env_check_passes_for_a_good_env(): void
    {
        file_put_contents($this->dir . '/.env', "DB_GATEWAY_URL=http://g:9000\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        [$out, $code] = $this->envCheck([]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK: environment loads cleanly', $out);
    }

    public function test_env_check_passes_for_env_only_deployment(): void
    {
        [$out, $code] = $this->envCheck(self::GW);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('absent', $out);
    }
}

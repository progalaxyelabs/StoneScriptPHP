<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The deploy CLI against a stand-in gateway that records every request: which
 * credential each step sends, in which order, and what is never done
 * (no automatic token replacement, no redirects, no token in --quiet output).
 */
final class GatewayCliEnforcingFlowTest extends TestCase
{
    private static string $dir;
    private static string $log;
    private static string $sinkLog;
    private static $srv;
    private static $sink;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/ssphp-cliflow-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0775, true);
        self::$log = self::$dir . '/requests.jsonl';
        self::$sinkLog = self::$dir . '/sink.log';
        file_put_contents(self::$dir . '/gw.php', '<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$body = file_get_contents("php://input");
file_put_contents(getenv("GW_LOG"), json_encode([
  "method" => $_SERVER["REQUEST_METHOD"], "path" => $path,
  "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
  "admin" => $_SERVER["HTTP_X_GATEWAY_ADMIN_TOKEN"] ?? null,
  "body" => json_decode($body, true),
]) . "\n", FILE_APPEND);
header("Content-Type: application/json");
if (str_starts_with($path, "/redirect/")) { http_response_code(302); header("Location: " . getenv("GW_SINK") . "/landed"); return; }
$platform = json_decode($body, true)["platform"] ?? "";
switch (true) {
  case $path === "/platform/register": http_response_code(201); echo "{}"; break;
  case $path === "/admin/platform-token":
    if ($platform === "haspt") { echo json_encode(["platform"=>$platform,"token_exists"=>true]); }
    else { http_response_code(201); echo json_encode(["platform"=>$platform,"token"=>"ssdb_pt_fresh","token_exists"=>false]); }
    break;
  case $path === "/admin/platform-token/rotate": echo json_encode(["platform"=>$platform,"token"=>"ssdb_pt_rotated","token_exists"=>true]); break;
  case str_ends_with($path, "/schema"): echo json_encode(["has_tables"=>true,"has_functions"=>true,"has_migrations"=>false]); break;
  case $path === "/admin/database/create": echo json_encode(["status"=>"ok"]); break;
  case $path === "/v2/migrate": echo json_encode(["status"=>"ok","databases_updated"=>[],"migrations_applied"=>0,"functions_updated"=>0,"execution_time_ms"=>1]); break;
  default: http_response_code(404); echo "{}";
}');
        file_put_contents(self::$dir . '/sink.php', '<?php file_put_contents(getenv("SINK_LOG"), "hit\n", FILE_APPEND); echo "{}";');
        file_put_contents(self::$dir . '/archive.tar.gz', 'x');
        $sinkPort = random_int(20000, 29999);
        self::$port = random_int(30000, 40000);
        $env = array_merge(getenv(), ['GW_LOG' => self::$log, 'GW_SINK' => "http://127.0.0.1:$sinkPort", 'SINK_LOG' => self::$sinkLog]);
        $null = ['file', '/dev/null', 'w'];
        self::$sink = proc_open([PHP_BINARY, '-S', "127.0.0.1:$sinkPort", self::$dir . '/sink.php'], [1 => $null, 2 => $null], $p, self::$dir, $env);
        self::$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$dir . '/gw.php'], [1 => $null, 2 => $null], $p, self::$dir, $env);
        usleep(600000);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$srv, self::$sink] as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        foreach (glob(self::$dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        @unlink(self::$log);
        @unlink(self::$sinkLog);
    }

    /** @return array{0:string,1:string,2:int} stdout, stderr, exit code */
    private function php(string $body): array
    {
        $code = 'define("ROOT_PATH", ' . var_export(self::$dir . '/', true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/cli/helpers/gateway-common.php', true) . ';'
            . '$url = "http://127.0.0.1:' . self::$port . '";' . $body;
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$dir);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$out, $err, proc_close($proc)];
    }

    /** @return list<array<string,mixed>> */
    private function requests(): array
    {
        if (!is_file(self::$log)) {
            return [];
        }
        return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents(self::$log))));
    }

    public function test_new_platform_order_register_token_upload_create_with_the_right_credentials(): void
    {
        [$out, $err, $code] = $this->php(<<<'PHP'
$env = ['gateway_url' => $url, 'platform_id' => 'newplat', 'admin_token' => 'ADM', 'deploy_token' => null];
stepRegisterPlatform($url, 'newplat', true, 'ADM', null);
$tok = resolveGatewayDeployToken($env, false);
stepUploadSchema($url, 'newplat', 'main', ROOT_PATH . 'archive.tar.gz', true, $tok);
stepCreateDatabase($url, 'newplat', 'main', null, $tok, 1, 0, true);
PHP);
        $this->assertSame(0, $code, $out . $err);
        $r = $this->requests();
        $this->assertSame(
            ['/platform/register', '/admin/platform-token', '/platform/newplat/schema', '/admin/database/create'],
            array_column($r, 'path')
        );
        $this->assertSame('Bearer ADM', $r[0]['auth']);
        $this->assertSame('Bearer ADM', $r[1]['auth']);
        $this->assertSame('deploy', $r[1]['body']['scope'], 'the CLI issues a deploy-scope token for its own steps');
        $this->assertSame('Bearer ssdb_pt_fresh', $r[2]['auth']);
        $this->assertSame('Bearer ssdb_pt_fresh', $r[3]['auth']);
        $this->assertNull($r[2]['admin']);
        $this->assertNull($r[3]['admin']);
    }

    public function test_an_existing_token_is_never_replaced_automatically(): void
    {
        [$out, $err, $code] = $this->php(<<<'PHP'
resolveGatewayDeployToken(['gateway_url' => $url, 'platform_id' => 'haspt', 'admin_token' => 'ADM', 'deploy_token' => null], false);
PHP);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--rotate', $err);
        $this->assertStringContainsString('DB_GATEWAY_DEPLOY_TOKEN', $err);
        $this->assertStringContainsString('--scope=deploy', $err);
        $this->assertSame(['/admin/platform-token'], array_column($this->requests(), 'path'), 'no rotate request may be made');
    }

    public function test_rotate_is_used_only_when_asked_for(): void
    {
        [$out, $err, $code] = $this->php('echo stepProvisionPlatformToken($url, "haspt", "ADM", true, true);');
        $this->assertSame(0, $code, $err);
        $this->assertSame('ssdb_pt_rotated', trim($out));
        $this->assertSame(['/admin/platform-token/rotate'], array_column($this->requests(), 'path'));
    }

    public function test_quiet_never_issues_a_token_it_would_have_to_discard(): void
    {
        [$out, $err, $code] = $this->php(<<<'PHP'
resolveGatewayDeployToken(['gateway_url' => $url, 'platform_id' => 'newplat', 'admin_token' => 'ADM', 'deploy_token' => null], true);
PHP);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('gateway:provision-token --scope=deploy --quiet', $err);
        $this->assertSame([], $this->requests(), 'nothing may be issued when the token would be lost');
    }

    public function test_provision_token_sends_the_requested_scope(): void
    {
        [, $err, $code] = $this->php('stepProvisionPlatformToken($url, "newplat", "ADM", true);'
            . 'stepProvisionPlatformToken($url, "newplat", "ADM", true, false, "deploy");');
        $this->assertSame(0, $code, $err);
        $r = $this->requests();
        $this->assertSame('runtime', $r[0]['body']['scope']);
        $this->assertSame('deploy', $r[1]['body']['scope']);
    }

    public function test_quiet_provision_prints_only_the_token(): void
    {
        [$out, $err, $code] = $this->php('echo stepProvisionPlatformToken($url, "newplat", "ADM", true) . "\n";');
        $this->assertSame(0, $code, $err);
        $this->assertSame("ssdb_pt_fresh\n", $out);
    }

    public function test_force_and_allow_send_the_admin_header_plain_migrate_does_not(): void
    {
        [$out, $err, $code] = $this->php(<<<'PHP'
stepMigrateDatabase($url, 'p', 'main', null, 'PT', false, 1, 0, true, [], false, null, 'ADM');
stepMigrateDatabase($url, 'p', 'main', null, 'PT', true, 1, 0, true, [], false, null, 'ADM');
stepMigrateDatabase($url, 'p', 'main', null, 'PT', false, 1, 0, true, ['drop_column'], false, null, 'ADM');
PHP);
        $this->assertSame(0, $code, $out . $err);
        $r = $this->requests();
        $this->assertCount(3, $r);
        $this->assertSame('Bearer PT', $r[0]['auth']);
        $this->assertNull($r[0]['admin']);
        $this->assertSame('ADM', $r[1]['admin']);
        $this->assertSame('ADM', $r[2]['admin']);
        $this->assertSame('Bearer PT', $r[1]['auth']);
    }

    public function test_redirects_are_not_followed(): void
    {
        [$out, $err, $code] = $this->php('stepRegisterPlatform($url . "/redirect", "p", true, "ADM", null);');
        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist(self::$sinkLog, 'the redirect target must never be contacted');
    }

    public function test_the_output_of_a_failed_provision_does_not_contain_credentials(): void
    {
        [$out, $err, $code] = $this->php('stepProvisionPlatformToken($url . "/redirect", "p", "SECRET-ADMIN-TOKEN", true);');
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('SECRET-ADMIN-TOKEN', $out . $err);
    }
}

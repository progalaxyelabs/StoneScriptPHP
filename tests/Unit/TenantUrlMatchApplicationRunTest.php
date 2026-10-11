<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Application::run(['tenant_url_match' => ['enabled' => true]]) end to end: a public route
 * under /tenant/{tenantId}/ is reachable without a tenant token, a protected one is not,
 * and a refused request never reaches its handler.
 */
final class TenantUrlMatchApplicationRunTest extends TestCase
{
    private string $dir = '';
    private string $privatePem = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-tum-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privatePem);
        file_put_contents($this->dir . '/private.pem', $this->privatePem);
        file_put_contents($this->dir . '/public.pem', openssl_pkey_get_details($key)['key']);
    }

    protected function tearDown(): void
    {
        foreach (['private.pem', 'public.pem', 'effect.log'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir);
    }

    private function token(?string $tenantId): string
    {
        $claims = [
            'iss' => 'https://api.test.invalid', 'sub' => 'u1', 'iat' => time(), 'exp' => time() + 600,
            'type' => 'access', 'purpose' => 'authorization',
        ];
        if ($tenantId !== null) {
            $claims['tenant_id'] = $tenantId;
        }
        return \Firebase\JWT\JWT::encode($claims, $this->privatePem, 'RS256');
    }

    /** @return array{0:string,1:bool} body, whether the handler ran */
    private function call(string $uri, ?string $token, string $guard = '"tenant_url_match" => ["enabled" => true]', array $extraEnv = [], int $runs = 1, bool $resetBetween = false): array
    {
        $effect = $this->dir . '/effect.log';
        @unlink($effect);
        $handlers = 'namespace Probe;'
            . 'class H implements \StoneScriptPHP\IRouteHandler { public function validation_rules(): array { return []; }'
            . ' public function process(): \StoneScriptPHP\ApiResponse {'
            . ' file_put_contents(' . var_export($effect, true) . ', "ran");'
            . ' return new \StoneScriptPHP\ApiResponse("ok", "HANDLER-REACHED"); } }';
        $routes = '["GET" => ['
            . '"/portal/tenant/{tenantId}/invitations/{token}" => ["handler" => \Probe\H::class, "service" => "portal", "group" => "inv", "action" => "preview", "access" => "public"],'
            . '"/portal/tenant/{tenantId}/orders" => ["handler" => \Probe\H::class, "service" => "portal", "group" => "orders", "action" => "list", "access" => "authorization"],'
            . ']]';
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $code = 'define("ROOT_PATH", ' . var_export($this->dir . '/', true) . ');'
            . 'require ' . $autoload . ';'
            . 'eval(' . var_export(
                'namespace Probe; ' . substr($handlers, strlen('namespace Probe;')),
                true
            ) . ');'
            . '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]=' . var_export($uri, true) . ';'
            . ($token !== null ? '$_SERVER["HTTP_AUTHORIZATION"]="Bearer ' . $token . '";' : '')
            . 'for ($i = 0; $i < ' . $runs . '; $i++) { StoneScriptPHP\\Application::run(["routes" => ' . $routes . ', "typed_auth" => true, ' . $guard . ']);' . ($resetBetween ? ' StoneScriptPHP\\Application::resetPublicTenantRoutesWarningForTesting();' : '') . ' }';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'DB_GATEWAY_URL' => 'http://127.0.0.1:9', 'DB_GATEWAY_PLATFORM' => 'p',
             'DB_GATEWAY_SCHEMA_NAME' => 'main', 'DB_GATEWAY_TENANT_SCHEMA_NAME' => 'tenant', 'JWT_ISSUER' => 'https://api.test.invalid',
             'JWT_PUBLIC_KEY_PATH' => $this->dir . '/public.pem', 'JWT_PRIVATE_KEY_PATH' => $this->dir . '/private.pem'] + $extraEnv
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [$out, is_file($effect)];
    }

    public function test_public_route_is_reachable_without_any_token(): void
    {
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/invitations/xyz', null);
        $this->assertStringContainsString('HANDLER-REACHED', $out);
        $this->assertTrue($ran);
    }

    public function test_public_route_is_reachable_with_a_tenantless_identity_token(): void
    {
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/invitations/xyz', $this->token(null));
        $this->assertStringContainsString('HANDLER-REACHED', $out);
        $this->assertTrue($ran);
    }

    public function test_protected_route_with_tenantless_token_is_refused_without_side_effect(): void
    {
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/orders', $this->token(null));
        $this->assertStringContainsString('tenant_context_required', $out);
        $this->assertFalse($ran);
    }

    public function test_protected_route_with_mismatching_tenant_is_refused_without_side_effect(): void
    {
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/orders', $this->token('tenant-b'));
        $this->assertStringContainsString('tenant_mismatch', $out);
        $this->assertFalse($ran);
    }

    public function test_protected_route_with_matching_tenant_reaches_the_handler(): void
    {
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/orders', $this->token('tenant-a'));
        $this->assertStringContainsString('HANDLER-REACHED', $out);
        $this->assertTrue($ran);
    }

    private const STORE_ACCESS = '"store_access" => ["enabled" => true, "auth_service_url" => "http://127.0.0.1:9", "platform_code" => "p"]';

    public function test_store_access_lets_a_public_tenant_route_through_without_a_token(): void
    {
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/invitations/xyz', null, self::STORE_ACCESS, ['AUTH_SERVICE_URL' => 'http://127.0.0.1:9']);
        $this->assertStringContainsString('HANDLER-REACHED', $out);
        $this->assertTrue($ran);
    }

    public function test_store_access_still_gates_a_protected_tenant_route_without_running_the_handler(): void
    {
        // The membership lookup cannot succeed (no auth service): the request must fail closed.
        [$out, $ran] = $this->call('/portal/tenant/tenant-a/orders', $this->token('tenant-a'), self::STORE_ACCESS, ['AUTH_SERVICE_URL' => 'http://127.0.0.1:9']);
        $this->assertStringNotContainsString('HANDLER-REACHED', $out);
        $this->assertFalse($ran);
    }

    public function test_boot_warns_naming_every_public_route_that_carries_the_tenant_param(): void
    {
        [$out] = $this->call('/portal/tenant/tenant-a/invitations/xyz', null);
        $this->assertStringContainsString('tenant_url_match', $out);
        $this->assertStringContainsString('GET /portal/tenant/{tenantId}/invitations/{token}', $out);
        $this->assertStringNotContainsString('GET /portal/tenant/{tenantId}/orders,', $out);
        $this->assertStringNotContainsString('orders', explode('each handler must authorise', $out)[1] ?? '');
    }

    public function test_boot_warning_is_logged_once_per_worker_process_not_per_request(): void
    {
        [$out] = $this->call('/portal/tenant/tenant-a/invitations/xyz', null, '"tenant_url_match" => ["enabled" => true]', [], 3);
        $this->assertSame(1, substr_count($out, 'these PUBLIC routes carry'), 'three requests in one worker, one warning');
    }

    public function test_boot_warning_fires_again_after_the_test_reset(): void
    {
        [$out] = $this->call('/portal/tenant/tenant-a/invitations/xyz', null, '"tenant_url_match" => ["enabled" => true]', [], 2, true);
        $this->assertSame(2, substr_count($out, 'these PUBLIC routes carry'));
    }

    public function test_boot_warning_also_fires_when_only_store_access_is_enabled(): void
    {
        [$out] = $this->call('/portal/tenant/tenant-a/invitations/xyz', null, self::STORE_ACCESS, ['AUTH_SERVICE_URL' => 'http://127.0.0.1:9']);
        $this->assertSame(1, substr_count($out, 'these PUBLIC routes carry'));
        $this->assertStringContainsString('GET /portal/tenant/{tenantId}/invitations/{token}', $out);
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Application::run(['typed_auth' => true]) wires AuthMiddlewareRegistrar::forMode() from the environment. */
final class TypedAuthApplicationOptInTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-typed-optin-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->dir . '/private.pem', $pem);
        file_put_contents($this->dir . '/public.pem', openssl_pkey_get_details($key)['key']);
    }

    protected function tearDown(): void
    {
        foreach (['private.pem', 'public.pem'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        rmdir($this->dir);
    }

    private function boot(string $config, array $env): string
    {
        $code = 'define("ROOT_PATH", ' . var_export($this->dir . '/', true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/nothing-here";'
            . 'StoneScriptPHP\Application::run(' . $config . ');';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'DB_GATEWAY_URL' => 'http://127.0.0.1:9', 'DB_GATEWAY_PLATFORM' => 'p',
             'DB_GATEWAY_SCHEMA_NAME' => 'main'] + $env
        );
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private function good(): array
    {
        return ['JWT_ISSUER' => 'https://api.test.invalid', 'JWT_PUBLIC_KEY_PATH' => $this->dir . '/public.pem',
                'JWT_PRIVATE_KEY_PATH' => $this->dir . '/private.pem'];
    }

    public function test_without_the_option_a_bare_app_still_refuses_to_boot_on_the_framework_auth_routes(): void
    {
        $this->assertStringContainsString('auth_wiring_required', $this->boot('["routes" => []]', $this->good()));
    }

    public function test_builtin_boots_with_the_option(): void
    {
        $out = $this->boot('["routes" => [], "typed_auth" => true]', $this->good());
        $this->assertStringNotContainsString('auth_wiring_required', $out);
        $this->assertStringNotContainsString('env_configuration_invalid', $out);
    }

    public function test_external_boots_with_both_issuers_configured(): void
    {
        $out = $this->boot('["routes" => [], "typed_auth" => true]', $this->good() + [
            'AUTH_MODE' => 'external', 'AUTH_SERVICE_URL' => 'http://127.0.0.1:9', 'AUTH_ISSUER' => 'https://auth.test.invalid',
            'PLATFORM_CODE' => 'p',
        ]);
        $this->assertStringNotContainsString('env_configuration_invalid', $out);
    }

    public function test_missing_issuer_stops_the_boot_with_a_generic_body(): void
    {
        $env = $this->good();
        $env['JWT_ISSUER'] = '';
        $out = $this->boot('["routes" => [], "typed_auth" => true]', $env);
        $this->assertStringContainsString('env_configuration_invalid', $out);
        $this->assertStringNotContainsString($this->dir, $out);
    }

    public function test_external_without_auth_service_settings_stops_the_boot(): void
    {
        $out = $this->boot('["routes" => [], "typed_auth" => true]', $this->good() + ['AUTH_MODE' => 'external']);
        $this->assertStringContainsString('env_configuration_invalid', $out);
    }

    // ---- local issuer/key come from what ExchangeRoute signs with --------------------------

    private function request(string $config, array $env, string $token): string
    {
        $code = 'define("ROOT_PATH", ' . var_export($this->dir . '/', true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'class ProbeRoute implements StoneScriptPHP\IRouteHandler { public function validation_rules(): array { return []; }'
            . ' public function process(): StoneScriptPHP\ApiResponse { return new StoneScriptPHP\ApiResponse("ok", "PROBE-REACHED"); } }'
            . '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/probe"; $_SERVER["HTTP_AUTHORIZATION"]="Bearer ' . $token . '";'
            . 'StoneScriptPHP\Application::run(' . $config . ');';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'DB_GATEWAY_URL' => 'http://127.0.0.1:9', 'DB_GATEWAY_PLATFORM' => 'p',
             'DB_GATEWAY_SCHEMA_NAME' => 'main', 'AUTH_MODE' => 'external', 'AUTH_SERVICE_URL' => 'http://127.0.0.1:9',
             'AUTH_ISSUER' => 'https://auth.test.invalid', 'PLATFORM_CODE' => 'p'] + $env
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private function token(string $privatePem): string
    {
        return \Firebase\JWT\JWT::encode([
            'iss' => 'https://api.test.invalid', 'sub' => 'u1', 'iat' => time(), 'exp' => time() + 600,
            'type' => 'access', 'purpose' => 'authorization',
        ], $privatePem, 'RS256');
    }

    public function test_typed_auth_derives_its_local_key_from_the_exchange_signing_config(): void
    {
        // Unit-level: JwtAuthMiddleware (ahead of the typed middleware) still verifies with JWT_PUBLIC_KEY_PATH, so an
        // end-to-end request cannot show this; what matters here is that typed_auth and ExchangeRoute read ONE source.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        file_put_contents($this->dir . '/other.pem', $otherPem);
        $saved = [];
        foreach (['DB_GATEWAY_URL' => 'http://127.0.0.1:9', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => 'main'] as $k => $v) {
            $saved[$k] = getenv($k);
            putenv("$k=$v");
        }
        \StoneScriptPHP\Env::resetForTesting();
        try {
            $opts = new \ReflectionMethod(\StoneScriptPHP\Application::class, 'buildAuthRouteOptions');
            $env = (new \ReflectionClass(\StoneScriptPHP\Env::class))->newInstanceWithoutConstructor();
            $env->AUTH_SERVICE_URL = 'http://auth.test';
            $env->AUTH_ISSUER = 'http://auth.test';
            $cfg = new \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig(
                $opts->invoke(null, ['features' => ['signing_private_key_path' => $this->dir . '/other.pem', 'signing_issuer' => 'https://signer.invalid']], $env)
            );
            $this->assertSame($this->dir . '/other.pem', $cfg->signingPrivateKeyPath);
            $this->assertSame('https://signer.invalid', $cfg->signingIssuer);

            $id = \StoneScriptPHP\Auth\LocalSigningIdentity::fromExternalConfig($cfg, \StoneScriptPHP\Env::get_instance());
            $this->assertSame('https://signer.invalid', $id->issuer);
            $this->assertSame(openssl_pkey_get_details($other)['key'], $id->publicKeyPem);
        } finally {
            unlink($this->dir . '/other.pem');
            foreach ($saved as $k => $old) {
                $old === false ? putenv($k) : putenv("$k=$old");
            }
            \StoneScriptPHP\Env::resetForTesting();
        }
    }

    public function test_double_wiring_is_logged(): void
    {
        $verifier = 'new StoneScriptPHP\\Auth\\TrustedIssuerVerifier(["https://x.invalid" => ["kind" => "local", "public_key" => file_get_contents(' . var_export($this->dir . '/public.pem', true) . ')]])';
        $config = '["routes" => [], "typed_auth" => true, "middleware" => [new StoneScriptPHP\\Auth\\Middleware\\AccessTokenMiddleware(' . $verifier . ')]]';
        $out = $this->request(str_replace('\\\\', '\\', $config), $this->good(), 'x');
        $this->assertStringContainsString('AccessTokenMiddleware is already in', $out);
    }

    // ---- end to end: exchange -> protected route, with and without a signing-key override ----

    private const E2E_SCRIPT = <<<'PHP'
<?php
define('ROOT_PATH', getenv('T_ROOT') . '/');
require getenv('T_AUTOLOAD');
use StoneScriptPHP\Application;
use StoneScriptPHP\Env;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;

class ProbeRoute implements StoneScriptPHP\IRouteHandler {
    public function validation_rules(): array { return []; }
    public function process(): StoneScriptPHP\ApiResponse { return new StoneScriptPHP\ApiResponse('ok', 'PROBE-REACHED'); }
}

$authConfig = (json_decode((string) getenv('T_AUTH_TOP'), true) ?: []) + ['features' => json_decode((string) getenv('T_FEATURES'), true) ?: []];
$options = (new ReflectionMethod(Application::class, 'buildAuthRouteOptions'))->invoke(null, $authConfig, Env::get_instance());
$cfg = new ExternalAuthConfig($options);

// The real ExchangeRoute, only the auth-service round trip stubbed: this is the token a client gets from POST /api/auth/exchange.
$route = new class (new ExternalAuthServiceClient('http://127.0.0.1:9', 'p'), [], $cfg, static fn(array $c): array => ['owner'], static fn(array $c): array => [['id' => 't1']]) extends ExchangeRoute {
    protected function extractIdentityToken(): ?string { return 'identity'; }
    protected function validateIdentity(string $authToken): array { return ['sub' => 'u1', 'identity_id' => 'u1', 'platform_code' => 'p']; }
};
$route->tenant_id = 't1';
$resp = $route->process();
if ($resp->status !== 'ok') { echo 'EXCHANGE-FAILED:' . json_encode($resp->data); exit; }

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/probe';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $resp->data['access_token'];
Application::run([
    'routes' => ['GET' => ['/probe' => ['handler' => ProbeRoute::class, 'access' => 'authorization']]],
    'auth' => $authConfig,
    'typed_auth' => true,
]);
PHP;

    /** @return array{0:string,1:string} stdout, stderr */
    private function e2e(array $features, array $extraEnv = [], array $authTop = []): array
    {
        $script = $this->dir . '/e2e.php';
        file_put_contents($script, self::E2E_SCRIPT);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'T_ROOT' => $this->dir, 'T_AUTOLOAD' => dirname(__DIR__, 2) . '/vendor/autoload.php',
             'T_FEATURES' => json_encode($features), 'T_AUTH_TOP' => json_encode($authTop), 'DB_GATEWAY_URL' => 'http://127.0.0.1:9', 'DB_GATEWAY_PLATFORM' => 'p',
             'DB_GATEWAY_SCHEMA_NAME' => 'main', 'DB_GATEWAY_TENANT_SCHEMA_NAME' => 'tenant', 'AUTH_MODE' => 'external',
             'AUTH_SERVICE_URL' => 'http://127.0.0.1:9', 'AUTH_ISSUER' => 'https://auth.test.invalid', 'PLATFORM_CODE' => 'p']
            + $extraEnv + $this->good()
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unlink($script);
        return [$out, $err];
    }

    public function test_top_level_auth_signing_keys_are_honoured_for_signer_and_verifiers_alike(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        file_put_contents($this->dir . '/other.pem', $otherPem);
        $saved = [];
        foreach (['DB_GATEWAY_URL' => 'http://127.0.0.1:9', 'DB_GATEWAY_PLATFORM' => 'p', 'DB_GATEWAY_SCHEMA_NAME' => 'main'] as $k => $v) {
            $saved[$k] = getenv($k);
            putenv("$k=$v");
        }
        \StoneScriptPHP\Env::resetForTesting();
        try {
            $opts = new \ReflectionMethod(\StoneScriptPHP\Application::class, 'buildAuthRouteOptions');
            $env = (new \ReflectionClass(\StoneScriptPHP\Env::class))->newInstanceWithoutConstructor();
            $env->AUTH_SERVICE_URL = 'http://auth.test';
            $env->AUTH_ISSUER = 'http://auth.test';
            $top = ['signing_issuer' => 'https://top.invalid', 'signing_private_key_path' => $this->dir . '/other.pem'];
            $cfg = new \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig($opts->invoke(null, $top, $env));
            $this->assertSame('https://top.invalid', $cfg->signingIssuer);
            $this->assertSame($this->dir . '/other.pem', $cfg->signingPrivateKeyPath);
            $id = \StoneScriptPHP\Auth\LocalSigningIdentity::fromExternalConfig($cfg, \StoneScriptPHP\Env::get_instance());
            $this->assertSame('https://top.invalid', $id->issuer);
            $this->assertSame(openssl_pkey_get_details($other)['key'], $id->publicKeyPem);

            // auth.features still wins over the top-level key when both are given
            $both = $top + ['features' => ['signing_issuer' => 'https://features.invalid']];
            $cfg2 = new \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig($opts->invoke(null, $both, $env));
            $this->assertSame('https://features.invalid', $cfg2->signingIssuer);
        } finally {
            unlink($this->dir . '/other.pem');
            foreach ($saved as $k => $old) {
                $old === false ? putenv($k) : putenv("$k=$old");
            }
            \StoneScriptPHP\Env::resetForTesting();
        }
    }

    public function test_default_config_exchange_token_reaches_a_protected_route(): void
    {
        [$out, $err] = $this->e2e([]);
        $this->assertStringContainsString('PROBE-REACHED', $out, $out . $err);
    }

    public function test_overridden_signing_key_and_issuer_exchange_token_reaches_a_protected_route(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        file_put_contents($this->dir . '/other.pem', $otherPem);
        try {
            [$out, $err] = $this->e2e(['signing_private_key_path' => $this->dir . '/other.pem', 'signing_issuer' => 'https://signer.invalid']);
            $this->assertStringContainsString('PROBE-REACHED', $out, $out . $err);
        } finally {
            unlink($this->dir . '/other.pem');
        }
    }

    public function test_top_level_auth_signing_override_works_end_to_end(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        file_put_contents($this->dir . '/other.pem', $otherPem);
        try {
            [$out, $err] = $this->e2e([], [], ['signing_private_key_path' => $this->dir . '/other.pem', 'signing_issuer' => 'https://signer.invalid']);
            $this->assertStringContainsString('PROBE-REACHED', $out, $out . $err);
        } finally {
            unlink($this->dir . '/other.pem');
        }
    }
}

<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware;

/**
 * Real end-to-end JWT routing by issuer through the hybrid handler, against a REAL local JWKS endpoint (php -S):
 *  - a foreign-issuer token with a valid JWKS signature is ACCEPTED (and the local-key miss is not an error);
 *  - a token signed by the PLATFORM key but claiming a FOREIGN issuer is REJECTED (the claim only selects the
 *    verifier; the verifier still proves the signature);
 *  - a token without `iss` behaves as before (platform-key path);
 *  - a token that fails every key is rejected and the middleware answers 401.
 */
class JwtRoutingTest extends TestCase
{
    private const PLATFORM_ISS = 'https://api.platform.example';
    private const AUTH_ISS     = 'https://auth.service.example';

    private string $dir;
    /** @var resource|null */
    private $server = null;
    private int $port = 0;
    /** @var array{0:string,1:string} */
    private array $platformKeys;
    /** @var array{0:string,1:string} */
    private array $authKeys;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-jwtroute-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/www', 0755, true);
        putenv('STONESCRIPTPHP_LOG_DIR=' . $this->dir . '/logs');
        mkdir($this->dir . '/logs', 0755, true);
        http_response_code(200);

        $this->platformKeys = $this->keypair();
        $this->authKeys     = $this->keypair();

        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->authKeys[1]));
        $b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        file_put_contents($this->dir . '/www/jwks.json', json_encode(['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'auth-1',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]]));

        $this->port = random_int(20000, 40000);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->dir . '/www'],
            [1 => ['file', $this->dir . '/server.out', 'w'], 2 => ['file', $this->dir . '/server.err', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $en, $es, 0.2);
            if ($c) { fclose($c); break; }
            usleep(100000);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        putenv('STONESCRIPTPHP_LOG_DIR');
        foreach (['/www/jwks.json', '/server.out', '/server.err', '/priv.pem', '/pub.pem'] as $f) { @unlink($this->dir . $f); }
        foreach (glob($this->dir . '/logs/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir . '/www');
        @rmdir($this->dir);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        http_response_code(200);
    }

    /** @return array{0:string,1:string} private, public PEM */
    private function keypair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        return [$priv, openssl_pkey_get_details($res)['key']];
    }

    private function handler(): object
    {
        file_put_contents($this->dir . '/priv.pem', $this->platformKeys[0]);
        file_put_contents($this->dir . '/pub.pem', $this->platformKeys[1]);
        $class = class_exists(\StoneScriptPHP\Auth\HybridApiTokenJwtHandler::class)
            ? \StoneScriptPHP\Auth\HybridApiTokenJwtHandler::class
            : \StoneScriptPHP\Auth\HybridCardJwtHandler::class;
        $url = 'http://127.0.0.1:' . $this->port;

        if (class_exists(\StoneScriptPHP\Auth\LocalSigningIdentity::class)) {
            $identity = new \StoneScriptPHP\Auth\LocalSigningIdentity(
                self::PLATFORM_ISS, $this->dir . '/priv.pem', null, $this->dir . '/pub.pem', $this->platformKeys[1]
            );
            return new $class($url, self::AUTH_ISS, '/jwks.json', $identity);
        }
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        putenv('DB_GATEWAY_URL=http://localhost:9000');
        putenv('DB_GATEWAY_PLATFORM=test-platform');
        $env = Env::get_instance();
        $env->JWT_PRIVATE_KEY_PATH = $this->dir . '/priv.pem';
        $env->JWT_PUBLIC_KEY_PATH  = $this->dir . '/pub.pem';
        $env->JWT_ISSUER           = self::PLATFORM_ISS;
        $env->JWT_PRIVATE_KEY_PASSPHRASE = null;
        return new $class($url, self::AUTH_ISS, '/jwks.json');
    }

    private function token(array $claims, string $privateKey, ?string $kid = null): string
    {
        return JWT::encode($claims + ['sub' => 'u-1', 'iat' => time(), 'exp' => time() + 600], $privateKey, 'RS256', $kid);
    }

    private function log(): string
    {
        $t = '';
        foreach (glob($this->dir . '/logs/*') ?: [] as $f) { $t .= (string) file_get_contents($f); }
        return $t;
    }

    public function test_foreign_issuer_token_with_valid_jwks_signature_is_accepted_without_error_noise(): void
    {
        $jwt = $this->token(['iss' => self::AUTH_ISS], $this->authKeys[0], 'auth-1');
        $claims = $this->handler()->verifyToken($jwt);
        $this->assertIsArray($claims);
        $this->assertSame('u-1', $claims['sub']);
        $this->assertStringNotContainsString('JWT signature invalid', $this->log());
        $this->assertStringNotContainsString(' ERROR ', $this->log());
    }

    public function test_platform_signed_token_claiming_a_foreign_issuer_is_rejected(): void
    {
        $jwt = $this->token(['iss' => self::AUTH_ISS], $this->platformKeys[0], 'auth-1');
        $this->assertFalse($this->handler()->verifyToken($jwt));
    }

    public function test_platform_signed_token_without_iss_still_verifies_on_the_platform_key(): void
    {
        $jwt = $this->token([], $this->platformKeys[0]);
        $claims = $this->handler()->verifyToken($jwt);
        $this->assertIsArray($claims, 'an iss-less token is decided by the platform key exactly as before');
    }

    public function test_platform_issuer_token_verifies_on_the_platform_key(): void
    {
        $this->assertIsArray($this->handler()->verifyToken($this->token(['iss' => self::PLATFORM_ISS], $this->platformKeys[0])));
    }

    public function test_token_failing_every_key_is_rejected_and_the_middleware_answers_401(): void
    {
        $stranger = $this->keypair();
        $jwt = $this->token(['iss' => self::AUTH_ISS], $stranger[0], 'auth-1');
        $handler = $this->handler();
        $this->assertFalse($handler->verifyToken($jwt));

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        $_SERVER['REQUEST_URI'] = '/portal/thing';
        $mw = new JwtAuthMiddleware($handler);
        $res = $mw->handle(['route' => ['is_public' => false, 'pattern' => '/portal/thing']], fn () => new \StoneScriptPHP\ApiResponse('ok', 'reached'));
        $this->assertSame(401, $res->httpStatusCode);
        $this->assertSame('error', $res->status);
    }

    public function test_attacker_controlled_issuer_cannot_forge_a_log_line(): void
    {
        $jwt = $this->token(["iss" => "https://evil.example\n[2026-01-01 00:00:00] ERROR FORGED LINE"], $this->authKeys[0], 'auth-1');
        $this->assertFalse($this->handler()->verifyToken($jwt));
        foreach (explode("\n", $this->log()) as $line) {
            $this->assertFalse(str_starts_with($line, '[2026-01-01'), 'a forged line must not start a log record');
        }
    }
}

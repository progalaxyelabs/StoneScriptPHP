<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\RedirectResponse;
use StoneScriptPHP\HtmlResponse;
use StoneScriptPHP\Env;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthRoutes;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthInitiateRoute;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthCallbackRoute;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthUserResolver;
use StoneScriptPHP\Routing\Router;

/**
 * Unit tests for the builtin (standalone, no central auth service) Google
 * OAuth popup flow: RedirectResponse/HtmlResponse, GoogleOAuthRoutes
 * registration/validation, and the initiate/callback routes' non-network
 * code paths (the actual Google token exchange is not exercised here —
 * that would require mocking Google's HTTP endpoints, out of scope for a
 * unit test; the exchange call itself is a thin pass-through to
 * google/apiclient, which has its own test coverage upstream).
 *
 * @covers \StoneScriptPHP\RedirectResponse
 * @covers \StoneScriptPHP\HtmlResponse
 * @covers \StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthRoutes
 * @covers \StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthInitiateRoute
 * @covers \StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthCallbackRoute
 */
class GoogleOAuthBuiltinTest extends TestCase
{
    private string $testKeysDir;
    private string $testPrivateKey;
    private string $testPublicKey;
    private Env $env;

    /** @var string[] */
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');

        $this->env = Env::get_instance();

        $this->testKeysDir = sys_get_temp_dir() . '/ssp-oauth-test-' . getmypid();
        mkdir($this->testKeysDir, 0755, true);
        $this->testPrivateKey = $this->testKeysDir . '/private.pem';
        $this->testPublicKey  = $this->testKeysDir . '/public.pem';

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKeyPem);
        file_put_contents($this->testPrivateKey, $privateKeyPem);
        $pubKeyDetails = openssl_pkey_get_details($res);
        file_put_contents($this->testPublicKey, $pubKeyDetails['key']);

        $this->env->JWT_PRIVATE_KEY_PATH = $this->testPrivateKey;
        $this->env->JWT_PUBLIC_KEY_PATH  = $this->testPublicKey;
        $this->env->JWT_ISSUER           = 'test.stonescriptphp.com';
        $this->env->JWT_PRIVATE_KEY_PASSPHRASE = null;
        $this->env->JWT_ACCESS_TOKEN_EXPIRY  = 900;
        $this->env->JWT_REFRESH_TOKEN_EXPIRY = 15552000;
    }

    protected function tearDown(): void
    {
        @unlink($this->testPrivateKey);
        @unlink($this->testPublicKey);
        @rmdir($this->testKeysDir);

        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    // ─── RedirectResponse / HtmlResponse ────────────────────────────────────

    public function testRedirectResponseIsAnApiResponse(): void
    {
        $r = new RedirectResponse('https://accounts.google.com/o/oauth2/v2/auth?x=1');
        $this->assertInstanceOf(ApiResponse::class, $r);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth?x=1', $r->location);
        $this->assertSame(302, $r->httpStatusCode);
    }

    public function testHtmlResponseIsAnApiResponse(): void
    {
        $r = new HtmlResponse('<html>hi</html>');
        $this->assertInstanceOf(ApiResponse::class, $r);
        $this->assertSame('<html>hi</html>', $r->html);
        $this->assertSame(200, $r->httpStatusCode);
    }

    // ─── GoogleOAuthRoutes::register() validation ───────────────────────────

    public function testRegisterThrowsWhenClientIdMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoogleOAuthRoutes::register(new Router(), [
            'client_secret' => 'secret',
            'redirect_uri' => 'https://api.example.com/oauth/google/callback',
            'user_resolver' => $this->fakeResolver(),
        ]);
    }

    public function testRegisterThrowsWhenUserResolverMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoogleOAuthRoutes::register(new Router(), [
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://api.example.com/oauth/google/callback',
        ]);
    }

    public function testRegisterThrowsWhenUserResolverWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoogleOAuthRoutes::register(new Router(), [
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://api.example.com/oauth/google/callback',
            'user_resolver' => new \stdClass(),
        ]);
    }

    public function testRegisterSucceedsWithMinimalValidConfig(): void
    {
        $router = new Router();
        GoogleOAuthRoutes::register($router, [
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://api.example.com/oauth/google/callback',
            'user_resolver' => $this->fakeResolver(),
        ]);

        $paths = array_column($router->getRouteMeta(), 'path');
        $this->assertContains('/oauth/google', $paths);
        $this->assertContains('/oauth/google/callback', $paths);
    }

    // ─── GoogleOAuthInitiateRoute ────────────────────────────────────────────

    public function testInitiateReturnsRedirectToGoogleWithExpectedParams(): void
    {
        $route = new GoogleOAuthInitiateRoute(
            'test-client-id.apps.googleusercontent.com',
            'test-client-secret',
            'https://api.example.com/oauth/google/callback',
            new RsaJwtHandler()
        );

        $response = $route->process();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/', $response->location);

        parse_str((string) parse_url($response->location, PHP_URL_QUERY), $params);
        $this->assertSame('test-client-id.apps.googleusercontent.com', $params['client_id']);
        $this->assertSame('https://api.example.com/oauth/google/callback', $params['redirect_uri']);
        $this->assertSame('code', $params['response_type']);
        $this->assertStringContainsString('email', $params['scope']);
        $this->assertNotEmpty($params['state']);

        // state must be a verifiable signed token, not an opaque/random string
        $jwtHandler = new RsaJwtHandler();
        $statePayload = $jwtHandler->verifyToken($params['state']);
        $this->assertIsArray($statePayload);
        $this->assertSame('oauth_state', $statePayload['purpose']);
        $this->assertSame('google', $statePayload['provider']);
    }

    // ─── GoogleOAuthCallbackRoute — non-network error paths ────────────────

    public function testCallbackBridgesOauthErrorWhenGoogleReturnsError(): void
    {
        $route = $this->makeCallbackRoute();
        $route->error = 'access_denied';

        $response = $route->process();

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertStringContainsString('oauth_error', $response->html);
        $this->assertStringContainsString('window.opener.postMessage', $response->html);
    }

    public function testCallbackBridgesOauthErrorWhenCodeOrStateMissing(): void
    {
        $route = $this->makeCallbackRoute();
        // code and state both left empty

        $response = $route->process();

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertStringContainsString('oauth_error', $response->html);
    }

    public function testCallbackBridgesOauthErrorWhenStateInvalid(): void
    {
        $route = $this->makeCallbackRoute();
        $route->code = 'some-code';
        $route->state = 'not-a-valid-jwt';

        $response = $route->process();

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertStringContainsString('oauth_error', $response->html);
        $this->assertStringContainsString('expired or is invalid', $response->html);
    }

    public function testCallbackBridgesOauthErrorWhenStateWrongPurpose(): void
    {
        $jwtHandler = new RsaJwtHandler();
        // A validly-signed token, but not one minted by GoogleOAuthInitiateRoute
        // (wrong 'purpose' claim) — must still be rejected.
        $wrongPurposeState = $jwtHandler->generateToken(['purpose' => 'something_else'], 600, 'oauth_state');

        $route = $this->makeCallbackRoute();
        $route->code = 'some-code';
        $route->state = $wrongPurposeState;

        $response = $route->process();

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertStringContainsString('oauth_error', $response->html);
    }

    private function makeCallbackRoute(): GoogleOAuthCallbackRoute
    {
        return new GoogleOAuthCallbackRoute(
            'test-client-id.apps.googleusercontent.com',
            'test-client-secret',
            'https://api.example.com/oauth/google/callback',
            new RsaJwtHandler(),
            $this->fakeResolver()
        );
    }

    private function fakeResolver(): GoogleOAuthUserResolver
    {
        return new class implements GoogleOAuthUserResolver {
            public function resolve(array $profile): array
            {
                return ['user_id' => 1, 'email' => $profile['email'] ?? null];
            }
        };
    }
}

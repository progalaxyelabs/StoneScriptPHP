<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthCallbackRoute;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthInitiateRoute;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthRoutes;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthUserResolver;
use StoneScriptPHP\Auth\BuiltinOAuth\OpenerOrigins;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Database;
use StoneScriptPHP\Database\HydrationException;
use StoneScriptPHP\ExceptionHandler;
use StoneScriptPHP\Logger;
use StoneScriptPHP\Persistence\LogSanitizer;
use StoneScriptPHP\Persistence\Mutation;
use StoneScriptPHP\Persistence\PersistenceException;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Tests\Fixtures\FakeJwt;

/** 12.0.0 review fixes: origin lock availability, tenant-qualified subject, central log/response sanitising. */
final class Release12HardeningTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/ssp-r12-' . bin2hex(random_bytes(4));
        mkdir($this->logDir);
        Logger::get_instance()->configure(false, true, false, $this->logDir);
        RefreshTokenIssuer::configure(null);
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_SERVER['HTTP_REFERER']);
    }

    protected function tearDown(): void
    {
        Logger::get_instance()->configure(false, true, false, null);
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->logDir);
        RefreshTokenIssuer::configure(null);
        Database::clearFakeMode();
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_SERVER['HTTP_REFERER']);
    }

    private function logText(): string
    {
        $out = '';
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            $out .= (string) file_get_contents($f);
        }
        return $out;
    }

    // ---- C1: opener lock availability ----

    public function test_request_own_origin_is_implicitly_allowed(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame(['https://app.example', 'https://api.example.test'], OpenerOrigins::allowed(['https://app.example']));
        $this->assertSame(['https://api.example.test'], OpenerOrigins::allowed([]));
        $this->assertSame([], OpenerOrigins::configured([]), 'the implicit origin is not "configured"');
    }

    public function test_check_origins_report_fails_when_empty(): void
    {
        $r = OpenerOrigins::report([]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ALLOWED_ORIGINS is empty', $r['message']);
        $ok = OpenerOrigins::report(['https://app.example/', '*']);
        $this->assertTrue($ok['ok']);
        $this->assertSame(['https://app.example'], $ok['origins']);
    }

    public function test_check_origins_cli_exits_one_when_empty_and_zero_when_set(): void
    {
        $script = realpath(__DIR__ . '/../../cli/auth-check-origins.php');
        $run = function (string $env) use ($script): array {
            $out = [];
            exec('ALLOWED_ORIGINS=' . escapeshellarg($env) . ' ' . PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
            return [$code, implode("\n", $out)];
        };
        [$code, $out] = $run('');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('FAIL', $out);
        [$code, $out] = $run('https://app.example');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('allowed  https://app.example', $out);
    }

    public function test_register_logs_an_error_at_boot_when_no_origin_is_configured(): void
    {
        $router = new Router();
        GoogleOAuthRoutes::register($router, [
            'client_id' => 'cid', 'client_secret' => 'sec', 'redirect_uri' => 'https://x/cb',
            'user_resolver' => new class implements GoogleOAuthUserResolver {
                public function resolve(array $profile): array
                {
                    return [];
                }
            },
            'jwt_handler' => new FakeJwt(),
            'allowed_origins' => [],
        ]);
        $this->assertMatchesRegularExpression('/ERROR\s+GoogleOAuthRoutes: ALLOWED_ORIGINS is empty/', $this->logText());
    }

    public function test_initiate_refuses_a_foreign_opener_with_a_clear_page_and_logs_loudly(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_REFERER'] = 'https://spa.example/login';
        $res = (new GoogleOAuthInitiateRoute('cid.apps.googleusercontent.com', 'sec', 'https://x/cb', new FakeJwt(), []))->process();
        $this->assertSame(403, $res->httpStatusCode);
        $this->assertStringContainsString('ALLOWED_ORIGINS', $res->html);
        $this->assertStringContainsString('https://spa.example', $res->html);
        $this->assertStringContainsString('close this window', $res->html);
        $this->assertMatchesRegularExpression('/ERROR\s+GoogleOAuthInitiateRoute: sign-in popup refused/', $this->logText());
    }

    public function test_initiate_accepts_a_same_origin_opener_with_no_configuration(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_REFERER'] = 'https://api.example.test/login';
        $res = (new GoogleOAuthInitiateRoute('cid.apps.googleusercontent.com', 'sec', 'https://x/cb', new FakeJwt(), []))->process();
        $this->assertInstanceOf(\StoneScriptPHP\RedirectResponse::class, $res);
    }

    public function test_callback_for_a_blocked_opener_exchanges_nothing_and_renders_a_visible_message(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
        $jwt = new FakeJwt();
        $state = $jwt->generateToken(['purpose' => 'oauth_state', 'provider' => 'google', 'opener_origin' => 'https://spa.example'], 600, 'oauth_state', 'oauth_state');
        $route = new GoogleOAuthCallbackRoute('cid', 'sec', 'https://x/cb', $jwt, new class implements GoogleOAuthUserResolver {
            public function resolve(array $profile): array
            {
                throw new \LogicException('must not be reached');
            }
        }, null, ['https://other.example']);
        $route->code = 'code';
        $route->state = $state;
        $res = $route->process();
        $this->assertSame(403, $res->httpStatusCode);
        $this->assertStringContainsString('ALLOWED_ORIGINS', $res->html);
        $this->assertStringNotContainsString('access_token', $res->html);
        $this->assertStringNotContainsString('postMessage', $res->html);
    }

    // ---- C2: tenant-qualified subject on the Google path ----

    private function tenantedResolver(string $tenant): GoogleOAuthUserResolver
    {
        return new class($tenant) implements GoogleOAuthUserResolver {
            public function __construct(private string $tenant)
            {
            }

            public function resolve(array $profile): array
            {
                return ['user_id' => '5', 'email' => $profile['email'], 'display_name' => 'Ann', 'extra_claims' => ['tenant_id' => $this->tenant]];
            }
        };
    }

    public function test_google_path_stores_a_tenant_qualified_subject_and_revoke_all_hits_only_that_tenant(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $profile = ['sub' => 'g1', 'email' => 'ann@example.com', 'email_verified' => true, 'name' => 'Ann', 'picture' => null];

        $tokens = [];
        foreach (['tenant-a', 'tenant-b'] as $tenant) {
            $route = new GoogleOAuthCallbackRoute('cid', 'sec', 'https://x/cb', new FakeJwt(), $this->tenantedResolver($tenant), $issuer);
            $html = $route->resolveAndMintTokens($profile)->html;
            $this->assertMatchesRegularExpression('/"refresh_token":"([^"]+)"/', $html);
            preg_match('/"refresh_token":"([^"]+)"/', $html, $m);
            $tokens[$tenant] = $m[1];
            $this->assertArrayNotHasKey('identity_id', (new FakeJwt())->verifyToken($m[1]) ?: [], 'no fabricated global identity');
        }
        $this->assertSame('tenant-a#5', $store->inspect(hash('sha256', $tokens['tenant-a']))->subject);
        $this->assertSame('tenant-b#5', $store->inspect(hash('sha256', $tokens['tenant-b']))->subject);

        // OAuth-session revoke-all, via the SAME subject builder as issuing.
        $claims = (new FakeJwt())->verifyToken($tokens['tenant-a']);
        $this->assertSame(1, $issuer->revokeAllForClaims($claims, null, true));
        $this->assertFalse($store->exists(hash('sha256', $tokens['tenant-a'])));
        $this->assertTrue($store->exists(hash('sha256', $tokens['tenant-b'])), "tenant B's user 5 stays signed in");
        $this->assertStringNotContainsString('revoke-all affected 0', $this->logText());
    }

    public function test_password_reset_revocation_on_a_tenanted_platform(): void
    {
        // The generated login template issues with the qualified subject; the reset template revokes by user + tenant.
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $s = $issuer->issueSession(['user_id' => '9', 'tenant_id' => 'tenant-a'], TokenClaims::PURPOSE_AUTHENTICATION, null, RefreshTokenIssuer::qualifiedSubject('9', 'tenant-a'));
        $other = $issuer->issueSession(['user_id' => '9', 'tenant_id' => 'tenant-b']);
        $this->assertSame(1, $issuer->revokeAllForUser('9', 'tenant-a', null, true));
        $this->assertFalse($store->exists(hash('sha256', $s->refreshToken)));
        $this->assertTrue($store->exists(hash('sha256', $other->refreshToken)));
    }

    public function test_zero_row_revoke_all_logs_a_warning_when_sessions_were_expected(): void
    {
        $issuer = new RefreshTokenIssuer(new FakeJwt(), new InMemoryRefreshTokenStore());
        $this->assertSame(0, $issuer->revokeAllForUser('404', 'tenant-a', null, true));
        $this->assertMatchesRegularExpression('/WARNING\s+RefreshTokenIssuer: revoke-all affected 0 sessions/', $this->logText());
    }

    // ---- C3 / W1: nothing raw reaches logs or responses ----

    public function test_logger_scrubs_message_and_context_centrally(): void
    {
        log_error('write failed: duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(victim@example.com) already exists.', [
            'cause' => 'Key (email)=(victim@example.com) already exists',
            'nested' => ['who' => 'contact: victim@example.com'],
        ]);
        log_info('user victim@example.com signed in');
        $log = $this->logText();
        $this->assertStringNotContainsString('victim@example.com', $log);
        $this->assertStringNotContainsString('Key (email)=(victim', $log);
        $this->assertStringContainsString('users_email_key', $log, 'identifiers stay for diagnosis');
        $this->assertStringContainsString('v***@example.com', $log, 'masked: first char + domain');
    }

    public function test_apostrophes_in_ordinary_log_text_are_not_mangled(): void
    {
        log_info("the user's session didn't start");
        $this->assertStringContainsString("the user's session didn't start", $this->logText());
    }

    public function test_database_gateway_errors_never_carry_raw_values_in_message_or_log(): void
    {
        Database::fake(['f' => function (): never {
            throw new GatewayException('insert failed: Key (email)=(victim@example.com) already exists', 400, null, ['error' => 'query_failed']);
        }]);
        try {
            Database::fn('f', []);
            $this->fail('must throw');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('victim@example.com', $e->getMessage());
            $this->assertStringNotContainsString('Key (email)=(victim', $e->getMessage());
        }
        $this->assertStringNotContainsString('victim@example.com', $this->logText());
    }

    public function test_exception_handler_response_has_no_raw_text_even_in_debug_mode(): void
    {
        $e = new \RuntimeException('insert failed: Key (email)=(victim@example.com) already exists', 0, new \RuntimeException('prev victim@example.com'));
        $m = new \ReflectionMethod(ExceptionHandler::class, 'buildErrorResponse');
        $m->setAccessible(true);
        $handler = ExceptionHandler::getInstance();
        foreach ([true, false] as $debug) {
            $body = $m->invoke($handler, $e, 500, $debug, 'abc123abc123');
            $json = (string) json_encode($body);
            $this->assertStringNotContainsString('victim@example.com', $json);
            $this->assertStringNotContainsString('Key (email)', $json);
            $this->assertSame('abc123abc123', $body['correlation_id']);
        }
        $debugBody = $m->invoke($handler, $e, 500, true, 'abc123abc123');
        $this->assertSame(\RuntimeException::class, $debugBody['debug']['exception']);
        $this->assertArrayNotHasKey('message', $debugBody['debug']);
    }

    public function test_logging_an_exception_goes_through_the_sanitiser(): void
    {
        Logger::get_instance()->log_php_exception(new \RuntimeException('Key (email)=(victim@example.com) already exists'), 'cid-1');
        $log = $this->logText();
        $this->assertStringNotContainsString('victim@example.com', $log);
        $this->assertStringContainsString('cid-1', $log);
    }

    public function test_sanitiser_helpers(): void
    {
        $this->assertSame('a***@x.org and b***@y.io', LogSanitizer::maskEmails('amy@x.org and bob.s+t@y.io'));
        $this->assertStringNotContainsString('s3cret', LogSanitizer::forLog('Key (token)=(s3cret) exists; "s3cret"'));
    }

    // ---- W4: strict hydration after a committed write ----

    public function test_hydration_failure_after_a_committed_write_is_marked_persisted_with_a_correlation_id(): void
    {
        try {
            Mutation::hydrateCommitted('create_thing', static function (): never {
                throw new HydrationException('NULL for non-nullable property note (value victim@example.com)');
            });
            $this->fail('must throw');
        } catch (PersistenceException $e) {
            $this->assertSame(500, $e->httpStatusCode());
            $this->assertSame(PersistenceException::PERSISTED_UNREADABLE, $e->reason());
            $r = \StoneScriptPHP\Persistence\DbErrorMapper::toResponse($e);
            $this->assertSame(500, $r->httpStatusCode);
            $this->assertTrue($r->data['persisted']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $r->data['correlation_id']);
            $this->assertStringContainsString('do not repeat', strtolower($r->message));
            $this->assertStringNotContainsString('victim@example.com', (string) $r->toJson());
            $this->assertStringContainsString($r->data['correlation_id'], $this->logText());
            $this->assertStringNotContainsString('victim@example.com', $this->logText());
        }
    }

    public function test_successful_hydration_is_passed_through(): void
    {
        $this->assertSame(['ok'], Mutation::hydrateCommitted('f', static fn (): array => ['ok']));
    }
}

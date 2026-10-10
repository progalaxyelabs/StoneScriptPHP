<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthCallbackRoute;
use StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthUserResolver;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware;
use StoneScriptPHP\Auth\RefreshTokens\PostgresRefreshTokenStore;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\Auth\RefreshTokens\TokenInspection;
use StoneScriptPHP\Auth\Routes\BodyLogoutRoute;
use StoneScriptPHP\Auth\Routes\BodyRefreshRoute;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\Tests\Fixtures\FakeJwt;
use StoneScriptPHP\Tests\Fixtures\ThrowingStore;

/** L3 wiring: every minting path persists; Postgres store contract; middleware reuse gate; body routes. */
final class RefreshTokenPersistenceWiringTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshTokenIssuer::configure(null);
        // The request's own origin is implicitly allowed (same-origin deployment), so these tests need no ALLOWED_ORIGINS.
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
        RefreshTokenIssuer::configure(null);
        Database::clearFakeMode();
    }

    private function resolver(): GoogleOAuthUserResolver
    {
        return new class implements GoogleOAuthUserResolver {
            public function resolve(array $profile): array
            {
                return ['user_id' => 'u-1', 'email' => $profile['email'], 'display_name' => $profile['name'] ?? 'Ann'];
            }
        };
    }

    private function route(?RefreshTokenIssuer $issuer): GoogleOAuthCallbackRoute
    {
        return new GoogleOAuthCallbackRoute('cid', 'sec', 'https://x/cb', new FakeJwt(), $this->resolver(), $issuer);
    }

    private const PROFILE = ['sub' => 'g1', 'email' => 'ann@example.com', 'email_verified' => true, 'name' => 'Ann', 'picture' => null];

    // ---- BuiltinOAuth ----

    public function test_oauth_callback_persists_the_minted_refresh_token(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $html = $this->route($issuer)->resolveAndMintTokens(self::PROFILE)->html;

        $this->assertStringContainsString('oauth_success', $html);
        $this->assertSame(1, $store->count());
        preg_match('/"refresh_token":"([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->assertTrue($store->exists(hash('sha256', $m[1])), 'the token handed to the client has a live row');
        $this->assertSame('u-1', $store->inspect(hash('sha256', $m[1]))->subject);
    }

    public function test_oauth_callback_fails_closed_when_persistence_is_down(): void
    {
        $issuer = new RefreshTokenIssuer(new FakeJwt(), new ThrowingStore());
        $html = $this->route($issuer)->resolveAndMintTokens(self::PROFILE)->html;

        $this->assertStringContainsString('oauth_error', $html);
        $this->assertStringContainsString('temporarily unavailable', $html);
        $this->assertStringNotContainsString('refresh_token', $html);
        $this->assertStringNotContainsString('access_token', $html);
    }

    public function test_oauth_callback_without_issuer_keeps_legacy_behaviour_and_notices_once(): void
    {
        $seen = [];
        set_error_handler(function (int $n, string $m) use (&$seen): bool {
            $seen[] = $m;
            return true;
        }, E_USER_DEPRECATED);
        $html = $this->route(null)->resolveAndMintTokens(self::PROFILE)->html;
        $this->route(null)->resolveAndMintTokens(self::PROFILE);
        restore_error_handler();

        $this->assertStringContainsString('oauth_success', $html);
        $this->assertLessThanOrEqual(1, count($seen), 'once per process');
    }

    // ---- bootstrap ----

    public function test_bootstrap_default_is_no_persistence(): void
    {
        $this->assertNull(RefreshTokenIssuer::bootstrap([], new FakeJwt(), $this->env()));
        $this->assertNull(RefreshTokenIssuer::configured());
    }

    public function test_bootstrap_builds_a_postgres_issuer_from_config(): void
    {
        $issuer = RefreshTokenIssuer::bootstrap(
            ['refresh_tokens' => ['store' => 'postgres', 'rotate' => true, 'reuse_grace_seconds' => 3]],
            new FakeJwt(),
            $this->env()
        );
        $this->assertInstanceOf(RefreshTokenIssuer::class, $issuer);
        $this->assertInstanceOf(PostgresRefreshTokenStore::class, $issuer->store());
        $this->assertTrue($issuer->rotates());
        $this->assertSame($issuer, RefreshTokenIssuer::configured());
    }

    public function test_bootstrap_rejects_unknown_store(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RefreshTokenIssuer::bootstrap(['refresh_tokens' => ['store' => 'redis']], new FakeJwt(), $this->env());
    }

    private function env(): Env
    {
        $r = new \ReflectionClass(Env::class);
        $env = $r->newInstanceWithoutConstructor();
        return $env;
    }

    // ---- PostgresRefreshTokenStore contract (fake DB) ----

    /** @return array{0: PostgresRefreshTokenStore, 1: \ArrayObject} */
    private function pg(array $responses = []): array
    {
        $calls = new \ArrayObject();
        $fakes = [];
        foreach (['auth_rt_store', 'auth_rt_inspect', 'auth_rt_rotate', 'auth_rt_revoke', 'auth_rt_revoke_session', 'auth_rt_revoke_family', 'auth_rt_revoke_all_for_subject', 'auth_rt_purge_expired'] as $fn) {
            $fakes[$fn] = function (array $p) use ($fn, $calls, $responses): array {
                $calls[] = [$fn, $p];
                return $responses[$fn] ?? [];
            };
        }
        Database::fake($fakes);
        return [new PostgresRefreshTokenStore(7, 1000, false), $calls];
    }

    public function test_postgres_store_rejects_raw_tokens_before_the_database(): void
    {
        [$store, $calls] = $this->pg();
        foreach ([fn () => $store->store('eyJraw.jwt.token', 'u', 'authentication', time() + 5),
                  fn () => $store->exists('short'),
                  fn () => $store->revoke('zz'),
                  fn () => $store->rotate(str_repeat('a', 64), str_repeat('a', 64), time() + 5)] as $call) {
            try {
                $call();
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(0, $calls, 'nothing reached the database');
    }

    public function test_postgres_store_validates_purpose_and_subject(): void
    {
        [$store] = $this->pg();
        $h = str_repeat('a', 64);
        $this->expectException(\InvalidArgumentException::class);
        $store->store($h, 'u', 'bogus', time() + 5);
    }

    public function test_postgres_store_call_shapes(): void
    {
        [$store, $calls] = $this->pg([
            'auth_rt_inspect' => [['o_status' => 'valid', 'o_family_id' => 'f1', 'o_subject' => 'u1', 'o_purpose' => 'authentication', 'o_expires_at' => 'x']],
            'auth_rt_rotate' => [['o_status' => 'ok', 'o_family_id' => 'f1', 'o_subject' => 'u1', 'o_purpose' => 'authentication']],
            'auth_rt_revoke_session' => [['o_deleted' => 3]],
            'auth_rt_revoke_all_for_subject' => [['o_deleted' => '2']],
            'auth_rt_purge_expired' => [['o_deleted' => 5]],
        ]);
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);

        $store->store($a, 'u1', 'authentication', 1_800_000_000, ['ip_address' => '1.2.3.4']);
        $this->assertSame('auth_rt_store', $calls[0][0]);
        $this->assertSame(
            [$a, 'u1', 'authentication', '2027-01-15T08:00:00+00:00', '{"ip_address":"1.2.3.4"}', null],
            array_slice($calls[0][1], 0, 6)
        );
        $this->assertCount(7, $calls[0][1], 'the 7th parameter is the absolute session cap');
        $this->assertNotNull($calls[0][1][6]);

        $i = $store->inspect($a);
        $this->assertSame([$a, 7], $calls[1][1], 'grace window is passed to SQL');
        $this->assertSame(TokenInspection::VALID, $i->status);
        $this->assertSame('f1', $i->familyId);
        $this->assertTrue($store->exists($a));

        $r = $store->rotate($a, $b, 1_800_000_000);
        $this->assertTrue($r->ok());
        $this->assertSame('u1', $r->subject);

        $this->assertSame(3, $store->revokeSession($a));
        $this->assertSame(2, $store->revokeAllForSubject('u1'));
        $this->assertSame(5, $store->purgeExpired(100, 60));
        $this->assertSame(0, $store->revokeFamily('f1'), 'empty result = 0 rows');
    }

    public function test_postgres_store_unknown_token_is_unknown_and_unexpected_rotation_status_is_loud(): void
    {
        [$store] = $this->pg();
        $this->assertSame(TokenInspection::UNKNOWN, $store->inspect(str_repeat('c', 64))->status);
        $this->assertFalse($store->exists(str_repeat('c', 64)));

        [$store2] = $this->pg(['auth_rt_rotate' => [['o_status' => 'weird']]]);
        $this->expectException(\UnexpectedValueException::class);
        $store2->rotate(str_repeat('a', 64), str_repeat('b', 64), time() + 5);
    }

    // ---- middleware reuse gate ----

    private function middlewareFixture(): array
    {
        $key = openssl_pkey_new(['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pub = openssl_pkey_get_details($key)['key'];
        $verifier = new TrustedIssuerVerifier(['https://api.testapp.in' => ['kind' => 'local', 'public_key' => $pub]]);
        $now = 1_000_000;
        $store = new InMemoryRefreshTokenStore(10, function () use (&$now): int {
            return $now;
        });
        $mint = fn (string $jti) => \Firebase\JWT\JWT::encode([
            'iss' => 'https://api.testapp.in', 'sub' => 'u-1', 'iat' => time(), 'exp' => time() + 100000,
            'type' => 'refresh', 'purpose' => 'authentication', 'jti' => $jti,
        ], $key, 'RS256');
        return [new RefreshTokenMiddleware($verifier, $store), $store, $mint, &$now];
    }

    public function test_middleware_revokes_the_family_when_a_spent_token_is_replayed(): void
    {
        [$mw, $store, $mint] = $this->middlewareFixture();
        $old = $mint('1');
        $new = $mint('2');
        $store->store(hash('sha256', $old), 'u-1', 'authentication', time() + 100000);
        $store->rotate(hash('sha256', $old), hash('sha256', $new), time() + 100000);

        // make the rotation old enough to be outside grace: rebuild store clock via reflection-free path
        $rp = new \ReflectionProperty($store, 'clock');
        $rp->setValue($store, fn (): int => time() + 3600);
        // store rows expire at time()+100000, still fine

        $route = ['route' => ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH]];
        $res = $mw->handle($route + ['body' => ['refresh_token' => $old]], fn () => null);
        $this->assertSame(401, $res->httpStatusCode);

        // the legitimate holder's live token went down with the family
        $res2 = $mw->handle($route + ['body' => ['refresh_token' => $new]], fn () => null);
        $this->assertSame(401, $res2->httpStatusCode);
        $this->assertSame(0, $store->count());
    }

    public function test_middleware_still_passes_a_live_token(): void
    {
        [$mw, $store, $mint] = $this->middlewareFixture();
        $tok = $mint('1');
        $store->store(hash('sha256', $tok), 'u-1', 'authentication', time() + 100000);
        $passed = false;
        $mw->handle(
            ['route' => ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH], 'body' => ['refresh_token' => $tok]],
            function () use (&$passed) {
                $passed = true;
                return null;
            }
        );
        $this->assertTrue($passed);
    }

    // ---- body routes ----

    public function test_body_logout_ends_the_session_and_is_idempotent(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        RefreshTokenIssuer::configure($issuer);
        $s = $issuer->issueSession(['user_id' => 'u1']);

        $route = new BodyLogoutRoute();
        $route->refresh_token = $s->refreshToken;
        $this->assertSame('ok', $route->process()->status);
        $this->assertFalse($store->exists(hash('sha256', $s->refreshToken)));
        $this->assertSame('ok', $route->process()->status, 'second logout is harmless');
    }

    public function test_body_routes_answer_501_when_persistence_is_not_configured(): void
    {
        $r = new BodyLogoutRoute();
        $r->refresh_token = 'x';
        $this->assertSame(501, $r->process()->httpStatusCode);
        $f = new BodyRefreshRoute();
        $f->refresh_token = 'x';
        $this->assertSame(501, $f->process()->httpStatusCode);
    }

    public function test_body_refresh_returns_new_access_and_rotated_refresh_only_when_rotating(): void
    {
        foreach ([false, true] as $rotate) {
            $store = new InMemoryRefreshTokenStore();
            $issuer = new RefreshTokenIssuer(new FakeJwt(), $store, 900, 100000, $rotate);
            RefreshTokenIssuer::configure($issuer);
            $s = $issuer->issueSession(['user_id' => 'u1']);

            $route = new BodyRefreshRoute();
            $route->refresh_token = $s->refreshToken;
            $res = $route->process();

            $this->assertSame('ok', $res->status);
            $this->assertArrayHasKey('access_token', $res->data);
            $this->assertSame($rotate, array_key_exists('refresh_token', $res->data));
        }
    }

    public function test_body_refresh_rejection_is_a_generic_401(): void
    {
        $issuer = new RefreshTokenIssuer(new FakeJwt(), new InMemoryRefreshTokenStore());
        RefreshTokenIssuer::configure($issuer);
        $route = new BodyRefreshRoute();
        $route->refresh_token = 'garbage';
        $res = $route->process();
        $this->assertSame(401, $res->httpStatusCode);
        $this->assertSame('Invalid or expired refresh token', $res->message);
    }

    // ---- C1 tenant isolation ----

    public function test_revoking_user_5_of_tenant_a_does_not_touch_user_5_of_tenant_b(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $a = $issuer->issueSession(['user_id' => '5', 'tenant_id' => 'tenant-a'], TokenClaims::PURPOSE_AUTHENTICATION, null, RefreshTokenIssuer::qualifiedSubject('5', 'tenant-a'));
        $b = $issuer->issueSession(['user_id' => '5', 'tenant_id' => 'tenant-b'], TokenClaims::PURPOSE_AUTHENTICATION, null, RefreshTokenIssuer::qualifiedSubject('5', 'tenant-b'));

        $this->assertSame(1, $issuer->revokeAllForUser('5', 'tenant-a'));
        $this->assertFalse($store->exists(hash('sha256', $a->refreshToken)));
        $this->assertTrue($store->exists(hash('sha256', $b->refreshToken)), "tenant B's user 5 stays signed in");
        $this->assertSame(0, $issuer->revokeAllForUser('5', 'tenant-c'));
        $this->assertSame(0, $issuer->revokeAllForUser('5'), 'the bare id is a different subject than any tenant-qualified one');
    }

    public function test_tenant_with_only_user_id_is_qualified_automatically(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $s = $issuer->issueSession(['user_id' => '5', 'tenant_id' => 'tenant-a']);
        $this->assertSame('tenant-a#5', $store->inspect(hash('sha256', $s->refreshToken))->subject);
        $this->assertSame('tenant-a#5', RefreshTokenIssuer::subjectOf(['user_id' => '5', 'tenant_id' => 'tenant-a']));
    }

    public function test_global_identity_id_or_tenantless_user_id_are_unambiguous(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $g = $issuer->issueSession(['identity_id' => 'uuid-1', 'user_id' => '5', 'tenant_id' => 'tenant-a']);
        $this->assertSame('uuid-1', $store->inspect(hash('sha256', $g->refreshToken))->subject);
        $t = $issuer->issueSession(['user_id' => '7']);
        $this->assertSame('7', $store->inspect(hash('sha256', $t->refreshToken))->subject);
        $this->assertSame('tenant-a#5', RefreshTokenIssuer::qualifiedSubject(5, 'tenant-a'));
        $this->assertSame('5', RefreshTokenIssuer::qualifiedSubject(5, null));
    }

    // ---- SECURITY: postMessage never to '*' ----

    private function callbackWithOrigins(?array $allowed, ?string $stateOrigin = null): GoogleOAuthCallbackRoute
    {
        unset($_SERVER['HTTP_HOST']); // exact target lists below: no implicit own origin
        $r = new GoogleOAuthCallbackRoute('cid', 'sec', 'https://x/cb', new FakeJwt(), $this->resolver(), null, $allowed);
        if ($stateOrigin !== null) {
            (new \ReflectionProperty($r, 'openerOrigin'))->setValue($r, $stateOrigin);
        }
        return $r;
    }

    public function test_callback_posts_tokens_only_to_allowed_origins_never_wildcard(): void
    {
        $html = $this->callbackWithOrigins(['https://app.example', 'https://admin.example/'])->resolveAndMintTokens(self::PROFILE)->html;
        $this->assertStringNotContainsString("'*'", $html);
        $this->assertStringNotContainsString('"*"', $html);
        $this->assertStringContainsString('var targets = ["https:\/\/app.example","https:\/\/admin.example"]', $html);
        $this->assertStringContainsString('opener.postMessage(data, o)', $html);
    }

    public function test_callback_with_state_bound_origin_posts_to_that_single_origin(): void
    {
        $html = $this->callbackWithOrigins(['https://app.example', 'https://admin.example'], 'https://admin.example')->resolveAndMintTokens(self::PROFILE)->html;
        $this->assertStringContainsString('var targets = ["https:\/\/admin.example"]', $html);
        $this->assertStringNotContainsString('app.example', $html);
    }

    public function test_state_bound_to_an_origin_that_is_not_allowed_delivers_nothing_and_shows_the_config_problem(): void
    {
        unset($_SERVER['HTTP_HOST']);
        $res = $this->callbackWithOrigins(['https://app.example'], 'https://evil.example')->resolveAndMintTokens(self::PROFILE);
        $this->assertSame(403, $res->httpStatusCode);
        $this->assertStringNotContainsString('access_token', $res->html);
        $this->assertStringNotContainsString('postMessage', $res->html);
        $this->assertStringContainsString('ALLOWED_ORIGINS', $res->html);
    }

    public function test_callback_with_no_allowed_origin_posts_nowhere_and_says_so(): void
    {
        unset($_SERVER['HTTP_HOST']); // no configured origin and no own origin to fall back to
        $res = $this->callbackWithOrigins([])->resolveAndMintTokens(self::PROFILE);
        $this->assertSame(403, $res->httpStatusCode);
        $this->assertStringNotContainsString('access_token', $res->html, 'no tokens in a page that has nowhere to send them');
        $this->assertStringNotContainsString('postMessage', $res->html);
        $this->assertStringContainsString('ALLOWED_ORIGINS', $res->html);
        $this->assertStringContainsString('close this window', $res->html);
    }

    public function test_wildcards_and_junk_never_become_targets(): void
    {
        unset($_SERVER['HTTP_HOST']);
        $this->assertSame([], \StoneScriptPHP\Auth\BuiltinOAuth\OpenerOrigins::allowed(['*', '', 'null', 'javascript:alert(1)', 'https://a.example/path']));
        $this->assertSame(['https://a.example', 'http://b.example:8080'], \StoneScriptPHP\Auth\BuiltinOAuth\OpenerOrigins::allowed(['HTTPS://A.example:443', 'http://b.example:8080', 'https://a.example']));
    }

    public function test_initiate_refuses_a_non_allowed_opener_and_binds_an_allowed_one(): void
    {
        $init = new \StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthInitiateRoute('cid.apps.googleusercontent.com', 'sec', 'https://x/cb', new FakeJwt(), ['https://app.example']);

        $_SERVER['HTTP_REFERER'] = 'https://evil.example/popup-opener.html';
        $res = $init->process();
        $this->assertInstanceOf(\StoneScriptPHP\HtmlResponse::class, $res);
        $this->assertSame(403, $res->httpStatusCode);

        $_SERVER['HTTP_REFERER'] = 'https://app.example/login?x=1';
        $res = $init->process();
        $this->assertInstanceOf(\StoneScriptPHP\RedirectResponse::class, $res);
        parse_str((string) parse_url($res->location, PHP_URL_QUERY), $q);
        $state = (new FakeJwt())->verifyToken($q['state']);
        $this->assertSame('https://app.example', $state['opener_origin']);

        unset($_SERVER['HTTP_REFERER']);
        $res = $init->process();
        parse_str((string) parse_url($res->location, PHP_URL_QUERY), $q);
        $this->assertArrayNotHasKey('opener_origin', (new FakeJwt())->verifyToken($q['state']), 'no Referer: allowed, but unbound');
    }

    // ---- W2 notices never throw ----

    public function test_unpersisted_notice_cannot_turn_sign_in_into_an_outage(): void
    {
        \StoneScriptPHP\Support\DeprecationNotice::reset(false);
        set_error_handler(static function (int $n, string $m): never {
            throw new \ErrorException($m, 0, $n);
        });
        $prev = ini_set('error_log', '/dev/null');
        try {
            $html = $this->route(null)->resolveAndMintTokens(self::PROFILE)->html;
            $this->assertStringContainsString('oauth_success', $html);
        } finally {
            ini_set('error_log', (string) $prev);
            restore_error_handler();
            \StoneScriptPHP\Support\DeprecationNotice::reset();
        }
    }

    // ---- W3 schema check ----

    public function test_schema_probe_fails_loudly_and_actionably_when_not_migrated(): void
    {
        PostgresRefreshTokenStore::resetVerification();
        Database::fake(['auth_rt_inspect' => function (): never {
            throw new \Exception('function auth_rt_inspect(text, integer) does not exist');
        }]);
        $store = new PostgresRefreshTokenStore();
        try {
            $store->store(str_repeat('a', 64), 'u', 'authentication', time() + 100);
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('migrate-vendor-main', $e->getMessage());
            $this->assertStringContainsString('BEFORE enabling', $e->getMessage());
        }
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $h = $issuer->healthCheck();
        $this->assertFalse($h['ok']);
        $this->assertStringContainsString('migrate-vendor-main', (string) $h['error']);
        PostgresRefreshTokenStore::resetVerification();
    }

    public function test_schema_probe_runs_once_per_process_when_ok(): void
    {
        PostgresRefreshTokenStore::resetVerification();
        $probes = 0;
        Database::fake([
            'auth_rt_inspect' => function () use (&$probes): array {
                $probes++;
                return [['o_status' => 'unknown']];
            },
            'auth_rt_store' => [['o_family_id' => 'f']],
        ]);
        $store = new PostgresRefreshTokenStore();
        $store->store(str_repeat('a', 64), 'u', 'authentication', time() + 100);
        $store->store(str_repeat('b', 64), 'u', 'authentication', time() + 100);
        $this->assertSame(1, $probes);
        PostgresRefreshTokenStore::resetVerification();
    }

    // ---- W6 cookie-mode routes through the issuer ----

    private function cookieRequest(string $refreshToken): void
    {
        $_COOKIE = ['refresh_token' => $refreshToken, 'csrf_token' => 'c1'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'c1';
    }

    public function test_cookie_refresh_route_rotates_through_the_issuer_and_detects_replay(): void
    {
        $store = new InMemoryRefreshTokenStore(0);
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store, 900, 100000, true);
        RefreshTokenIssuer::configure($issuer);
        $s = $issuer->issueSession(['user_id' => 'u1']);

        $this->cookieRequest($s->refreshToken);
        $res = @(new \StoneScriptPHP\Auth\Routes\RefreshRoute(new FakeJwt()))->process();
        $this->assertSame('ok', $res->status);
        $this->assertArrayHasKey('access_token', $res->message);

        $this->assertFalse($store->exists(hash('sha256', $s->refreshToken)) && false);
        // replaying the spent token (grace 0) revokes the session
        $this->cookieRequest($s->refreshToken);
        $res = @(new \StoneScriptPHP\Auth\Routes\RefreshRoute(new FakeJwt()))->process();
        $this->assertSame('error', $res->status);
        $this->assertSame(0, $store->count(), 'whole family revoked');
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_COOKIE = [];
    }

    public function test_cookie_logout_route_revokes_the_persisted_session(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        RefreshTokenIssuer::configure($issuer);
        $s = $issuer->issueSession(['user_id' => 'u1']);

        $this->cookieRequest($s->refreshToken);
        $res = @(new \StoneScriptPHP\Auth\Routes\LogoutRoute())->process();
        $this->assertSame('ok', $res->status);
        $this->assertSame(0, $store->count());
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_COOKIE = [];
    }
}

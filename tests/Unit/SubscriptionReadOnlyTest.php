<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Routing\Middleware\CorsMiddleware;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;
use StoneScriptPHP\Subscriptions\SubscriptionState;

/** Header-capturing, provider-injected harness around SubscriptionMiddleware. */
final class CapturingSubscriptionMiddleware extends SubscriptionMiddleware
{
    public array $headers = [];

    protected function sendHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}

final class SubscriptionReadOnlyTest extends TestCase
{
    private const NOW = '2026-10-01T00:00:00Z';

    protected function tearDown(): void
    {
        AuthContext::clear();
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    private function mw(?array $row, array $opts = []): CapturingSubscriptionMiddleware
    {
        $provider = $opts['provider'] ?? fn(string $t): ?array => $row;
        return new CapturingSubscriptionMiddleware(
            expiredMode: $opts['mode'] ?? 'read_only',
            exemptPaths: $opts['exempt'] ?? null,
            writeAllowList: $opts['allow'] ?? [],
            warningDays: 7,
            missingSubscription: $opts['missing'] ?? 'read_only',
            statusProvider: $provider,
            clock: fn() => new \DateTimeImmutable(self::NOW),
        );
    }

    private function call(SubscriptionMiddleware $mw, string $method, string $path, bool $authed = true): ApiResponse
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        AuthContext::clear();
        if ($authed) {
            AuthContext::setUser(new AuthenticatedUser(user_id: 'u1', tenant_id: 't1'));
        }
        return $mw->handle(['method' => $method], fn() => new ApiResponse('ok', 'passed'));
    }

    private static function expiredTrial(): array
    {
        return ['status' => 'trial', 'is_trial' => true, 'is_active' => false, 'expires_at' => '2026-09-10T00:00:00Z'];
    }

    private static function expiredPlan(): array
    {
        return ['status' => 'active', 'is_trial' => false, 'is_active' => false, 'expires_at' => '2026-09-10T00:00:00Z'];
    }

    // ---- read-only mode -------------------------------------------------

    public function test_expired_trial_reads_pass_with_read_only_header(): void
    {
        $mw = $this->mw(self::expiredTrial());
        foreach (['GET', 'HEAD', 'OPTIONS'] as $m) {
            $this->assertSame('passed', $this->call($mw, $m, '/api/bills')->message, $m);
        }
        $this->assertSame('read_only; ended_at=2026-09-10T00:00:00Z; reason=trial_expired', $mw->headers['X-Subscription-State']);
    }

    public function test_expired_trial_write_is_423_with_machine_readable_payload(): void
    {
        $res = $this->call($this->mw(self::expiredTrial()), 'POST', '/api/bills');
        $this->assertSame(423, $res->httpStatusCode);
        $this->assertSame('error', $res->status);
        $this->assertSame('READ_ONLY_TRIAL_EXPIRED', $res->data['error_code']);
        $this->assertSame('read_only', $res->data['subscription_state']);
        $this->assertSame('2026-09-10T00:00:00Z', $res->data['ended_at']);
        $this->assertTrue($res->data['is_trial']);
    }

    public function test_all_write_methods_refused(): void
    {
        $mw = $this->mw(self::expiredTrial());
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            $this->assertSame(423, $this->call($mw, $m, '/bills/1')->httpStatusCode, $m);
        }
    }

    public function test_expired_paid_plan_uses_plan_ended_code(): void
    {
        $res = $this->call($this->mw(self::expiredPlan()), 'POST', '/bills');
        $this->assertSame(423, $res->httpStatusCode);
        $this->assertSame('READ_ONLY_PLAN_ENDED', $res->data['error_code']);
        $this->assertSame('plan_ended', $res->data['reason']);
    }

    public function test_cancelled_with_future_expiry_stays_active_until_period_end(): void
    {
        $row = ['status' => 'cancelled', 'is_trial' => false, 'is_active' => false, 'expires_at' => '2027-01-01T00:00:00Z'];
        $mw = $this->mw($row);
        $this->assertSame('passed', $this->call($mw, 'POST', '/bills')->message);
        $this->assertSame('ok', $mw->headers['X-Subscription-State']);

        $soon = ['status' => 'cancelled', 'is_trial' => false, 'expires_at' => '2026-10-04T00:00:00Z'];
        $mw2 = $this->mw($soon);
        $this->assertSame('passed', $this->call($mw2, 'POST', '/bills')->message);
        $this->assertSame('plan_ending; ends_at=2026-10-04T00:00:00Z; days=3', $mw2->headers['X-Subscription-State']);
    }

    public function test_cancelled_with_past_expiry_is_read_only_and_ended_at_is_never_in_the_future(): void
    {
        $row = ['status' => 'cancelled', 'is_trial' => false, 'expires_at' => '2026-09-30T00:00:00Z'];
        $res = $this->call($this->mw($row), 'POST', '/bills');
        $this->assertSame(423, $res->httpStatusCode);
        $this->assertLessThanOrEqual(strtotime(self::NOW), strtotime($res->data['ended_at']));
        // A future date can never be reported as an ended_at, whatever is_active says.
        $st = SubscriptionState::fromRow(['status' => 'trial', 'is_active' => false, 'expires_at' => '2030-01-01T00:00:00Z'], new \DateTimeImmutable(self::NOW), 7);
        $this->assertFalse($st->isReadOnly());
        $this->assertSame('ok', $st->state);
    }

    public function test_no_row_is_read_only_by_default(): void
    {
        $mw = $this->mw(null);
        $this->assertSame('passed', $this->call($mw, 'GET', '/bills')->message);
        $res = $this->call($mw, 'POST', '/bills');
        $this->assertSame(423, $res->httpStatusCode);
        $this->assertSame('READ_ONLY_NO_SUBSCRIPTION', $res->data['error_code']);
        $this->assertSame('read_only; reason=no_subscription', $mw->headers['X-Subscription-State']);
    }

    public function test_no_row_allow_option_passes_without_header(): void
    {
        $mw = $this->mw(null, ['missing' => 'allow']);
        $this->assertSame('passed', $this->call($mw, 'POST', '/bills')->message);
        $this->assertArrayNotHasKey('X-Subscription-State', $mw->headers);
    }

    // ---- allow-list -----------------------------------------------------

    public function test_default_allow_list_passes_writes_on_expired_tenant(): void
    {
        $mw = $this->mw(self::expiredTrial());
        foreach (['/account/subscription/checkout', '/subscription/checkout', '/api/subscription/admin/activate', '/export/csv', '/internal/sync',
                  '/account/delete', '/api/account/cancel-deletion', '/account/password', '/account/password-reset/request'] as $p) {
            $this->assertSame('passed', $this->call($mw, 'POST', $p)->message, "POST $p");
        }
        $this->assertSame('passed', $this->call($mw, 'DELETE', '/account')->message, 'DPDP erasure: DELETE /account');
    }

    public function test_auth_paths_always_pass_even_without_lookup(): void
    {
        $calls = 0;
        $mw = $this->mw(null, ['provider' => function () use (&$calls) { $calls++; return self::expiredTrial(); }]);
        $this->assertSame('passed', $this->call($mw, 'POST', '/auth/refresh')->message);
        $this->assertSame('passed', $this->call($mw, 'POST', '/api/auth/logout')->message);
        $this->assertSame(0, $calls, '/auth/ is exempt: no lookup');
    }

    public function test_other_account_writes_are_no_longer_exempt_and_scoped_entries_are_exact(): void
    {
        $mw = $this->mw(self::expiredTrial());
        foreach ([['POST', '/account/profile'], ['PUT', '/account'], ['POST', '/account'], ['DELETE', '/account/bills/1'],
                  ['GET', '/account'], ['POST', '/account/delete/extra'], ['PUT', '/account/delete']] as [$m, $p]) {
            if ($m === 'GET') {
                $this->assertSame('passed', $this->call($mw, $m, $p)->message);
                continue;
            }
            $this->assertSame(423, $this->call($mw, $m, $p)->httpStatusCode, "$m $p");
        }
    }

    public function test_brace_params_match_exactly_one_segment(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['allow' => [
            'POST /portal/tenant/{tenantId}/account/delete',
            'POST /portal/tenant/{tenantId}/account/cancel-deletion',
            '/portal/tenant/{tenantId}/files/authorize',
            'POST /devices/pairing/redeem',
        ]]);
        $ok = [
            ['POST', '/portal/tenant/abc-123/account/delete'],
            ['POST', '/api/portal/tenant/abc-123/account/cancel-deletion'],
            ['POST', '/portal/tenant/abc/files/authorize'],
            ['DELETE', '/portal/tenant/abc/files/authorize/x'],   // unscoped: component prefix
            ['POST', '/devices/pairing/redeem'],
        ];
        foreach ($ok as [$m, $p]) {
            $this->assertSame('passed', $this->call($mw, $m, $p)->message, "$m $p");
        }
        $no = [
            ['POST', '/portal/tenant//account/delete'],            // empty segment
            ['POST', '/portal/tenant/a/b/account/delete'],         // would span '/'
            ['POST', '/portal/tenant/account/delete'],             // missing segment
            ['PUT', '/portal/tenant/abc/account/delete'],          // wrong method
            ['POST', '/portal/tenant/abc/account/delete/more'],    // scoped = exact
            ['POST', '/portal/tenant/abc/account/deletex'],
            ['POST', '/portal/tenant/abc/bills'],
            ['POST', '/devices/pairing/redeem/x'],
        ];
        foreach ($no as [$m, $p]) {
            $this->assertSame(423, $this->call($mw, $m, $p)->httpStatusCode, "$m $p");
        }
    }

    public function test_brace_entries_with_trailing_slash_are_prefixes(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['allow' => ['POST /t/{id}/sync/']]);
        $this->assertSame('passed', $this->call($mw, 'POST', '/t/9/sync/a/b')->message);
        $this->assertSame(423, $this->call($mw, 'POST', '/t//sync/a')->httpStatusCode);
    }

    public function test_prefix_entries_behave_as_before(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['allow' => ['/reports', '/tools/']]);
        foreach (['/reports', '/reports/daily', '/api/reports/daily', '/tools/x', '/tools'] as $p) {
            $this->assertSame('passed', $this->call($mw, 'POST', $p)->message, $p);
        }
        $this->assertSame(423, $this->call($mw, 'POST', '/reportsx')->httpStatusCode);
        $this->assertSame(423, $this->call($mw, 'POST', '/toolsx')->httpStatusCode);
    }

    public function test_allow_list_is_path_component_aware(): void
    {
        $mw = $this->mw(self::expiredTrial());
        $this->assertSame(423, $this->call($mw, 'POST', '/accounting/entries')->httpStatusCode);
        $this->assertSame(423, $this->call($mw, 'POST', '/subscriptions-export')->httpStatusCode);
        $this->assertSame(423, $this->call($mw, 'POST', '/authors')->httpStatusCode);
    }

    public function test_configured_extra_allow_entries_and_method_scoped_entries(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['allow' => ['/devices', 'POST /files/authorize']]);
        $this->assertSame('passed', $this->call($mw, 'POST', '/api/devices/pair')->message);
        $this->assertSame('passed', $this->call($mw, 'POST', '/files/authorize')->message);
        $this->assertSame(423, $this->call($mw, 'DELETE', '/files/authorize')->httpStatusCode);
        $this->assertSame(423, $this->call($mw, 'POST', '/bills')->httpStatusCode, 'extras do not widen anything else');
    }

    // ---- warning / ok ---------------------------------------------------

    public function test_trial_inside_window_sets_trial_ending_header_and_passes_writes(): void
    {
        $row = ['status' => 'trial', 'is_trial' => true, 'is_active' => true, 'expires_at' => '2026-10-04T00:00:00Z'];
        $mw = $this->mw($row);
        $this->assertSame('passed', $this->call($mw, 'POST', '/bills')->message);
        $this->assertSame('trial_ending; ends_at=2026-10-04T00:00:00Z; days=3', $mw->headers['X-Subscription-State']);
    }

    public function test_window_boundary_is_inclusive_at_seven_days(): void
    {
        $in  = ['status' => 'trial', 'is_trial' => true, 'is_active' => true, 'expires_at' => '2026-10-08T00:00:00Z'];
        $out = ['status' => 'trial', 'is_trial' => true, 'is_active' => true, 'expires_at' => '2026-10-08T00:00:01Z'];
        $mwIn = $this->mw($in);
        $this->call($mwIn, 'GET', '/x');
        $this->assertStringStartsWith('trial_ending; ', $mwIn->headers['X-Subscription-State']);
        $mwOut = $this->mw($out);
        $this->call($mwOut, 'GET', '/x');
        $this->assertSame('ok', $mwOut->headers['X-Subscription-State'] ?? '');
    }

    public function test_paid_plan_ending_and_ok(): void
    {
        $ending = ['status' => 'active', 'is_trial' => false, 'is_active' => true, 'expires_at' => '2026-10-03T12:00:00Z'];
        $mw = $this->mw($ending);
        $this->call($mw, 'GET', '/x');
        $this->assertSame('plan_ending; ends_at=2026-10-03T12:00:00Z; days=3', $mw->headers['X-Subscription-State']);

        $ok = ['status' => 'active', 'is_trial' => false, 'is_active' => true, 'expires_at' => '2027-10-01T00:00:00Z'];
        $mw2 = $this->mw($ok);
        $this->assertSame('passed', $this->call($mw2, 'POST', '/x')->message);
        $this->assertSame('ok', $mw2->headers['X-Subscription-State']);
    }

    // ---- failure policy / scoping --------------------------------------

    public function test_lookup_error_fails_open_without_header(): void
    {
        $mw = $this->mw(null, ['provider' => function () { throw new \RuntimeException('gateway down'); }]);
        $this->assertSame('passed', $this->call($mw, 'POST', '/bills')->message);
        $this->assertArrayNotHasKey('X-Subscription-State', $mw->headers);
    }

    public function test_unauthenticated_and_tenantless_requests_pass_untouched(): void
    {
        $mw = $this->mw(self::expiredTrial());
        $this->assertSame('passed', $this->call($mw, 'POST', '/bills', false)->message);
        AuthContext::setUser(new AuthenticatedUser(user_id: 'u1'));
        $_SERVER['REQUEST_URI'] = '/bills';
        $this->assertSame('passed', $mw->handle(['method' => 'POST'], fn() => new ApiResponse('ok', 'passed'))->message);
        $this->assertSame([], $mw->headers);
    }

    public function test_health_is_exempt(): void
    {
        $this->assertSame('passed', $this->call($this->mw(self::expiredTrial()), 'POST', '/health')->message);
    }

    // ---- block (legacy) mode -------------------------------------------

    public function test_block_mode_returns_402_for_reads_and_writes(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['mode' => 'block']);
        foreach (['GET', 'POST'] as $m) {
            $res = $this->call($mw, $m, '/bills');
            $this->assertSame(402, $res->httpStatusCode, $m);
            $this->assertSame('SUBSCRIPTION_EXPIRED', $res->data['error_code']);
        }
    }

    public function test_block_mode_keeps_legacy_exempt_paths(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['mode' => 'block']);
        foreach (['/account/delete', '/subscription/status', '/export', '/auth/login', '/health'] as $p) {
            $this->assertSame('passed', $this->call($mw, 'POST', $p)->message, $p);
        }
        $this->assertSame(402, $this->call($mw, 'GET', '/subscription/plans')->httpStatusCode);
    }

    public function test_block_mode_never_advertises_read_only_header(): void
    {
        $mw = $this->mw(self::expiredTrial(), ['mode' => 'block']);
        $this->call($mw, 'GET', '/bills');
        $this->assertSame('blocked; ended_at=2026-09-10T00:00:00Z; reason=trial_expired', $mw->headers['X-Subscription-State']);
        $this->assertStringNotContainsString('read_only', $mw->headers['X-Subscription-State']);
    }

    public function test_block_mode_no_row_is_402(): void
    {
        $this->assertSame(402, $this->call($this->mw(null, ['mode' => 'block']), 'GET', '/bills')->httpStatusCode);
    }

    public function test_active_tenant_is_never_blocked_in_block_mode(): void
    {
        $row = ['status' => 'active', 'is_trial' => false, 'is_active' => true, 'expires_at' => '2027-10-01T00:00:00Z'];
        $this->assertSame('passed', $this->call($this->mw($row, ['mode' => 'block']), 'POST', '/bills')->message);
    }

    // ---- validation / config -------------------------------------------

    public function test_invalid_mode_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SubscriptionMiddleware(expiredMode: 'lockdown');
    }

    public function test_invalid_missing_option_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SubscriptionMiddleware(missingSubscription: 'maybe');
    }

    public function test_config_defaults_to_read_only_and_mode_specific_exempt_paths(): void
    {
        foreach (['DB_MODE' => 'gateway', 'DB_GATEWAY_URL' => 'http://gateway.invalid:9000', 'DB_GATEWAY_PLATFORM' => 'testplatform',
                  'DB_GATEWAY_SCHEMA_NAME' => 'main', 'DB_GATEWAY_TENANT_SCHEMA_NAME' => 'tenant'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
        $env = new \ReflectionProperty(\StoneScriptPHP\Env::class, '_instance');
        $env->setAccessible(true);
        $env->setValue(null, null);
        $c = new SubscriptionConfig([]);
        $this->assertSame('read_only', $c->expiredMode);
        $this->assertSame(7, $c->warningDays);
        $this->assertSame('read_only', $c->missingSubscription);
        $this->assertSame(['/health', '/auth/'], $c->exemptPaths);

        $b = new SubscriptionConfig(['expired_mode' => 'block', 'write_allow_list' => ['/x'], 'warning_days' => 3]);
        $this->assertSame('block', $b->expiredMode);
        $this->assertContains('/account/', $b->exemptPaths);
        $this->assertSame(['/x'], $b->writeAllowList);
        $this->assertSame(3, $b->warningDays);

        $this->assertInstanceOf(SubscriptionMiddleware::class, SubscriptionMiddleware::fromConfig($c));

        foreach (['DB_MODE', 'DB_GATEWAY_URL', 'DB_GATEWAY_PLATFORM', 'DB_GATEWAY_SCHEMA_NAME', 'DB_GATEWAY_TENANT_SCHEMA_NAME'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        $env->setValue(null, null);
    }

    // ---- state value object --------------------------------------------

    public function test_state_without_dates_falls_back_to_is_active(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $this->assertSame('ok', SubscriptionState::fromRow(['is_active' => true], $now, 7)->state);
        $this->assertSame('read_only', SubscriptionState::fromRow(['is_active' => false], $now, 7)->state);
    }

    // ---- path source (W5) + route-level --------------------------------

    public function test_path_comes_from_request_array_not_stale_request_uri(): void
    {
        $mw = $this->mw(self::expiredTrial());
        AuthContext::setUser(new AuthenticatedUser(user_id: 'u1', tenant_id: 't1'));
        $_SERVER['REQUEST_URI'] = '/health'; // stale / different from the dispatched path
        $res = $mw->handle(['method' => 'POST', 'path' => '/bills'], fn() => new ApiResponse('ok', 'passed'));
        $this->assertSame(423, $res->httpStatusCode);
        $_SERVER['REQUEST_URI'] = '/bills';
        $res = $mw->handle(['method' => 'POST', 'path' => '/account/delete'], fn() => new ApiResponse('ok', 'passed'));
        $this->assertSame('passed', $res->message);
    }

    public function test_route_level_dispatch_through_router_pipeline(): void
    {
        $handler = new class implements \StoneScriptPHP\IRouteHandler {
            public function validation_rules(): array { return []; }
            public function process(): ApiResponse { return new ApiResponse('ok', 'handled'); }
        };
        $setUser = new class implements \StoneScriptPHP\Routing\MiddlewareInterface {
            public function handle(array $request, callable $next): ?ApiResponse
            {
                AuthContext::setUser(new AuthenticatedUser(user_id: 'u1', tenant_id: 't1'));
                return $next($request);
            }
        };
        $build = function (array $row, array $allow = []) use ($handler, $setUser) {
            $router = new \StoneScriptPHP\Routing\Router();
            $router->use($setUser);
            $router->use($this->mw($row, ['allow' => $allow]));
            foreach (['/portal/tenant/{tenantId}/bills', '/portal/tenant/{tenantId}/account/delete'] as $p) {
                $router->post($p, $handler, isPublic: true);
            }
            $router->addRoute('DELETE', '/account', $handler, isPublic: true);
            $router->get('/portal/tenant/{tenantId}/bills', $handler, isPublic: true);
            return $router;
        };
        $d = fn($router, $m, $p) => $router->dispatch(new \StoneScriptPHP\Routing\IncomingRequest($m, $p));

        $router = $build(self::expiredTrial(), ['POST /portal/tenant/{tenantId}/account/delete']);
        $this->assertSame('handled', $d($router, 'GET', '/portal/tenant/t1/bills')->message);
        $this->assertSame(423, $d($router, 'POST', '/portal/tenant/t1/bills')->httpStatusCode);
        $this->assertSame('handled', $d($router, 'POST', '/portal/tenant/t1/account/delete')->message);
        $this->assertSame('handled', $d($router, 'DELETE', '/account')->message, 'default deletion route');

        // Without the allow-list entry the tenant-scoped deletion route is refused.
        $router2 = $build(self::expiredTrial());
        $this->assertSame(423, $d($router2, 'POST', '/portal/tenant/t1/account/delete')->httpStatusCode);

        $active = ['status' => 'active', 'is_trial' => false, 'expires_at' => '2027-10-01T00:00:00Z'];
        $this->assertSame('handled', $d($build($active), 'POST', '/portal/tenant/t1/bills')->message);
    }

    // ---- fail-open log rate limit (W6) ---------------------------------

    public function test_fail_open_log_is_rate_limited(): void
    {
        $f = sys_get_temp_dir() . '/ssp-failopen-' . uniqid() . '.log';
        $prev = ini_set('error_log', $f);
        $ref = new \ReflectionProperty(SubscriptionMiddleware::class, 'lastFailOpenLog');
        $ref->setAccessible(true);
        $ref->setValue(null, 0);
        try {
            $mw = $this->mw(null, ['provider' => function () { throw new \RuntimeException('gateway down'); }]);
            for ($i = 0; $i < 5; $i++) {
                $this->assertSame('passed', $this->call($mw, 'POST', '/bills')->message);
            }
            $lines = array_filter(explode("\n", (string) @file_get_contents($f)), fn($l) => str_contains($l, 'lookup failed'));
            $this->assertCount(1, $lines);
        } finally {
            ini_set('error_log', (string) $prev);
            @unlink($f);
            $ref->setValue(null, 0);
        }
    }

    public function test_sql_functions_agree_cancelled_is_active_until_expiry(): void
    {
        $dir = __DIR__ . '/../../src/Subscriptions/Schema/functions/';
        foreach (['sub_get_status.pgsql', 'sub_activate.pgsql'] as $f) {
            $this->assertStringNotContainsString("NOT IN ('cancelled')", (string) file_get_contents($dir . $f), $f);
        }
        $this->assertStringContainsString('v_is_active := v_sub.expires_at > NOW();', (string) file_get_contents($dir . 'sub_get_status.pgsql'));
    }

    // ---- CORS ----------------------------------------------------------

    public function test_cors_exposes_subscription_header_by_default(): void
    {
        $ref = new \ReflectionProperty(CorsMiddleware::class, 'exposedHeaders');
        $ref->setAccessible(true);
        $this->assertSame(['X-Subscription-State'], $ref->getValue(new CorsMiddleware(['https://a.example'])));
    }
}

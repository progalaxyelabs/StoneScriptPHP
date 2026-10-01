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

    public function test_cancelled_subscription_is_read_only(): void
    {
        $row = ['status' => 'cancelled', 'is_trial' => false, 'is_active' => false, 'expires_at' => '2027-01-01T00:00:00Z'];
        $this->assertSame(423, $this->call($this->mw($row), 'POST', '/bills')->httpStatusCode);
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
        foreach (['/account', '/account/delete', '/api/account/cancel', '/subscription/checkout', '/api/subscription/admin/activate', '/export/csv', '/internal/sync'] as $p) {
            $this->assertSame('passed', $this->call($mw, 'POST', $p)->message, $p);
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

    public function test_allow_list_is_path_component_aware(): void
    {
        $mw = $this->mw(self::expiredTrial());
        $this->assertSame(423, $this->call($mw, 'POST', '/accounting/entries')->httpStatusCode);
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

    // ---- CORS ----------------------------------------------------------

    public function test_cors_exposes_subscription_header_by_default(): void
    {
        $ref = new \ReflectionProperty(CorsMiddleware::class, 'exposedHeaders');
        $ref->setAccessible(true);
        $this->assertSame(['X-Subscription-State'], $ref->getValue(new CorsMiddleware(['https://a.example'])));
    }
}

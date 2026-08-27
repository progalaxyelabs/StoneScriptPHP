<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Audit\AuditRecorder;
use StoneScriptPHP\Audit\HasAuditBag;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Env;

final class AuditRecorderTestPlainHandler
{
}

final class AuditRecorderTestEnrichedHandler
{
    use HasAuditBag;

    public function fillBag(): void
    {
        $this->auditRecord(
            entityType: 'invoice',
            entityId: '42',
            oldValues: ['status' => 'draft'],
            newValues: ['status' => 'sent'],
            summary: 'Invoice #42 marked sent',
            action: 'invoice.send',
        );
    }
}

/**
 * The base-record-floor + optional-enrichment contract, and the "log loudly,
 * never throw" failure rule. buildParams() is exercised directly (pure, no
 * gateway) for the decision logic; record()'s dispatch is exercised via
 * fakeCallFunction() (no real HTTP call, no live gateway needed).
 */
final class AuditRecorderTest extends TestCase
{
    /** @var string[] */
    private array $putenvKeys = [];

    protected function tearDown(): void
    {
        AuthContext::clear();
        AuditRecorder::fakeCallFunction(null);
        foreach ($this->putenvKeys as $key) {
            putenv($key);
        }
        $this->putenvKeys = [];
        $this->resetEnvSingleton();
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->putenvKeys[] = $key;
        $this->resetEnvSingleton();
    }

    private function resetEnvSingleton(): void
    {
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * The minimum Env requires to boot at all under DB_MODE=gateway
     * (the default) — Env::__construct() fails loud if these are missing,
     * regardless of anything audit-related. Every test needs this, even the
     * ones proving the audit feature stays OFF.
     */
    private function setRequiredGatewayEnv(): void
    {
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.test:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->setEnv('DB_GATEWAY_SCHEMA_NAME', 'main');
        $this->setEnv('PLATFORM_CODE', 'testplatform');
    }

    private function enableAuditTrail(): void
    {
        $this->setRequiredGatewayEnv();
        $this->setEnv('AUDIT_TRAIL_ENABLED', 'true');
    }

    public function test_get_request_is_never_audited_even_when_enabled(): void
    {
        $this->enableAuditTrail();

        $params = AuditRecorder::buildParams(
            ['method' => 'GET', 'path' => '/things'],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertNull($params, 'GET must never produce an audit record — only POST/PUT/PATCH/DELETE are mutating');
    }

    public function test_mutating_request_is_not_audited_when_feature_disabled(): void
    {
        // AUDIT_TRAIL_ENABLED defaults to false — deliberately not set here,
        // but Env still needs its other required-at-boot vars set regardless
        // of anything audit-related.
        $this->setRequiredGatewayEnv();

        $params = AuditRecorder::buildParams(
            ['method' => 'POST', 'path' => '/things'],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertNull($params, 'AUDIT_TRAIL_ENABLED=false (the default) must mean zero audit calls, ever');
    }

    public function test_mutating_request_without_bag_still_writes_the_base_record_floor(): void
    {
        $this->enableAuditTrail();

        $params = AuditRecorder::buildParams(
            ['method' => 'POST', 'path' => '/things', 'route' => ['pattern' => '/things']],
            new AuditRecorderTestPlainHandler(), // no HasAuditBag at all
            new ApiResponse('ok', '', [], 201)
        );

        $this->assertNotNull($params, 'the base record floor must be written even when the handler never enriches');
        // Positional order must match stonescriptdb-gateway's audit_append() signature exactly.
        [$tenantId, $actorId, $platformCode, $route, $method, $action, $status, $entityType, $entityId, $old, $new, $summary] = $params;
        $this->assertNull($tenantId);
        $this->assertNull($actorId);
        $this->assertSame('testplatform', $platformCode);
        $this->assertSame('/things', $route);
        $this->assertSame('POST', $method);
        $this->assertNull($action);
        $this->assertSame(201, $status);
        $this->assertNull($entityType);
        $this->assertNull($entityId);
        $this->assertNull($old);
        $this->assertNull($new);
        $this->assertNull($summary);
    }

    public function test_platform_code_falls_back_to_db_gateway_platform_when_unset(): void
    {
        // Real gap, live-caught: PLATFORM_CODE is optional and a real
        // deployment had it unset entirely, silently writing every audit
        // record with an empty platform_code even though the platform was
        // perfectly well identified via DB_GATEWAY_PLATFORM (the one Env
        // actually requires non-empty under DB_MODE=gateway).
        // Deliberately does NOT reuse setRequiredGatewayEnv()/enableAuditTrail()
        // here — both also set PLATFORM_CODE, which is exactly the variable
        // this test needs to prove works correctly when left UNSET.
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway.test:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->setEnv('DB_GATEWAY_SCHEMA_NAME', 'main');
        $this->setEnv('AUDIT_TRAIL_ENABLED', 'true');
        // Confirm it's genuinely empty (Env's own default) before asserting
        // the fallback kicked in.
        $this->assertSame('', Env::get_instance()->PLATFORM_CODE);

        $params = AuditRecorder::buildParams(
            ['method' => 'POST', 'path' => '/things'],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertSame('testplatform', $params[2], 'platform_code must fall back to DB_GATEWAY_PLATFORM when PLATFORM_CODE is unset');
    }

    public function test_mutating_request_with_bag_is_fully_enriched(): void
    {
        $this->enableAuditTrail();
        AuthContext::setUser(new AuthenticatedUser(user_id: 'user-1', tenant_id: 'tenant-9'));

        $handler = new AuditRecorderTestEnrichedHandler();
        $handler->fillBag();

        $params = AuditRecorder::buildParams(
            ['method' => 'PUT', 'path' => '/invoices/42', 'route' => ['pattern' => '/invoices/{id}']],
            $handler,
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertNotNull($params);
        [$tenantId, $actorId, $platformCode, $route, $method, $action, $status, $entityType, $entityId, $old, $new, $summary] = $params;
        $this->assertSame('tenant-9', $tenantId);
        $this->assertSame('user-1', $actorId);
        $this->assertSame('/invoices/{id}', $route);
        $this->assertSame('PUT', $method);
        $this->assertSame('invoice.send', $action);
        $this->assertSame(200, $status);
        $this->assertSame('invoice', $entityType);
        $this->assertSame('42', $entityId);
        $this->assertSame('{"status":"draft"}', $old);
        $this->assertSame('{"status":"sent"}', $new);
        $this->assertSame('Invoice #42 marked sent', $summary);
    }

    public function test_record_dispatches_built_params_to_the_fake_closure(): void
    {
        $this->enableAuditTrail();
        $captured = null;
        AuditRecorder::fakeCallFunction(function (array $params) use (&$captured) {
            $captured = $params;
        });

        AuditRecorder::record(
            ['method' => 'DELETE', 'path' => '/things/1', 'route' => ['pattern' => '/things/{id}']],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertNotNull($captured, 'record() must dispatch to the fake when one is registered');
        $this->assertSame('/things/{id}', $captured[3]);
        $this->assertSame('DELETE', $captured[4]);
    }

    public function test_record_never_throws_when_the_write_fails_no_fake_success(): void
    {
        $this->enableAuditTrail();
        AuditRecorder::fakeCallFunction(function (array $params) {
            throw new \RuntimeException('gateway unreachable');
        });

        // The whole point of the design's failure rule: a broken audit write
        // must never surface as an exception to the real request. If this
        // call throws, the test fails — that IS the assertion.
        AuditRecorder::record(
            ['method' => 'POST', 'path' => '/things'],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertTrue(true, 'record() must swallow (and only log) a failing audit write, never throw');
    }

    public function test_record_never_throws_even_when_env_reconstruction_itself_throws(): void
    {
        // Real regression: buildParams() calls Env::get_instance(), which
        // throws if required gateway config is missing on a FRESH
        // reconstruction (e.g. after another test resets the singleton).
        // record() must swallow that too, not just a failing gateway call —
        // a broken/misconfigured audit setup must never be able to turn an
        // otherwise-successful request into a 500.
        foreach (['DB_GATEWAY_URL', 'DB_GATEWAY_PLATFORM', 'DB_GATEWAY_SCHEMA_NAME'] as $key) {
            putenv($key); // ensure genuinely unset, not just "not set by this test"
            $this->putenvKeys[] = $key;
        }
        $this->resetEnvSingleton();

        AuditRecorder::record(
            ['method' => 'POST', 'path' => '/things'],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertTrue(true, 'record() must swallow an Env reconstruction failure, not just a gateway-call failure');
    }

    public function test_record_is_a_complete_noop_for_get_requests_even_with_a_fake_registered(): void
    {
        $this->enableAuditTrail();
        $called = false;
        AuditRecorder::fakeCallFunction(function (array $params) use (&$called) {
            $called = true;
        });

        AuditRecorder::record(
            ['method' => 'GET', 'path' => '/things'],
            new AuditRecorderTestPlainHandler(),
            new ApiResponse('ok', '', [], 200)
        );

        $this->assertFalse($called, 'GET requests must never reach the dispatch step at all');
    }
}

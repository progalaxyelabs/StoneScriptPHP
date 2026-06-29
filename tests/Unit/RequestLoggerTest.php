<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\RequestLogging\RequestContext;
use StoneScriptPHP\RequestLogging\RequestLogger;

/**
 * Unit tests for RequestLogger and RequestContext.
 *
 * Covers §10 of the request-logging spec:
 *   - Logs on success (row assembled and passed to write adapter).
 *   - Logs on uncaught exception (error_class / error_message populated).
 *   - Logs on simulated fatal (error_get_last path).
 *   - NULL identity (unauthenticated request) → identity_id / tenant_id null.
 *   - Fail-open when DB / gateway unavailable (exception in write adapter → no throw).
 *   - Fail-open when table missing (exception in write adapter → no throw).
 *   - client_ip with trust_proxy ON  (X-Real-Ip used).
 *   - client_ip with trust_proxy OFF (REMOTE_ADDR used).
 *   - request_id generated when X-Request-Id header absent.
 *   - request_id captured when X-Request-Id header present.
 */
class RequestLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        RequestLogger::reset();
        RequestContext::reset();
        AuthContext::clear();

        // Provide sensible server superglobals for every test
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/api/items?page=1';
        $_SERVER['HTTP_HOST']      = 'api.example.com';
        $_SERVER['REMOTE_ADDR']    = '10.0.0.1';
        unset(
            $_SERVER['HTTP_X_REQUEST_ID'],
            $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_REFERER']
        );
    }

    protected function tearDown(): void
    {
        RequestLogger::reset();
        RequestContext::reset();
        AuthContext::clear();

        // Restore superglobals
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_REQUEST_ID'],
            $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_REFERER']
        );
    }

    // -------------------------------------------------------------------------
    // §10 — arm() / config reading
    // -------------------------------------------------------------------------

    public function test_arm_enables_logger_by_default(): void
    {
        RequestLogger::arm([], microtime(true));
        $this->assertTrue(RequestLogger::isArmed());
        $this->assertTrue(RequestLogger::isEnabled());
    }

    public function test_arm_respects_enabled_false(): void
    {
        RequestLogger::arm(['request_logging' => ['enabled' => false]], microtime(true));
        $this->assertFalse(RequestLogger::isArmed());
        $this->assertFalse(RequestLogger::isEnabled());
    }

    public function test_arm_reads_platform_code_from_config(): void
    {
        RequestLogger::arm(
            ['auth' => ['platform' => ['code' => 'exampleapp']]],
            microtime(true)
        );
        $this->assertSame('exampleapp', RequestLogger::getPlatformCode());
    }

    public function test_arm_reads_platform_code_from_env(): void
    {
        $_ENV['PLATFORM_CODE'] = 'sampleapp';
        RequestLogger::arm([], microtime(true));
        $this->assertSame('sampleapp', RequestLogger::getPlatformCode());
        unset($_ENV['PLATFORM_CODE']);
    }

    public function test_arm_trust_proxy_explicit_true(): void
    {
        RequestLogger::arm(['request_logging' => ['trust_proxy' => true]], microtime(true));
        $this->assertTrue(RequestLogger::isTrustProxy());
    }

    public function test_arm_trust_proxy_explicit_false(): void
    {
        RequestLogger::arm(['request_logging' => ['trust_proxy' => false]], microtime(true));
        $this->assertFalse(RequestLogger::isTrustProxy());
    }

    public function test_arm_trust_proxy_from_env(): void
    {
        $_ENV['TRUST_PROXY'] = 'true';
        RequestLogger::arm([], microtime(true));
        $this->assertTrue(RequestLogger::isTrustProxy());
        unset($_ENV['TRUST_PROXY']);
    }

    public function test_arm_trust_proxy_defaults_to_false_when_env_not_set(): void
    {
        unset($_ENV['TRUST_PROXY'], $_SERVER['TRUST_PROXY']);
        RequestLogger::arm([], microtime(true));
        $this->assertFalse(RequestLogger::isTrustProxy());
    }

    // -------------------------------------------------------------------------
    // §10 — success path: row assembled and written
    // -------------------------------------------------------------------------

    public function test_success_row_written_on_persist(): void
    {
        $capturedRow = null;

        RequestLogger::arm(
            ['request_logging' => ['enabled' => true]],
            microtime(true)
        );
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertNotNull($capturedRow, 'Write adapter should have been called');
        $this->assertSame('GET',              $capturedRow['method']);
        $this->assertSame('/api/items',       $capturedRow['path']);
        $this->assertSame('api.example.com',  $capturedRow['host']);
        $this->assertIsInt($capturedRow['status']);
        $this->assertIsFloat($capturedRow['duration_ms']);
        $this->assertNull($capturedRow['error_class'],   'Success: no error_class');
        $this->assertNull($capturedRow['error_message'], 'Success: no error_message');
    }

    public function test_success_row_contains_request_id(): void
    {
        $capturedRow = null;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertArrayHasKey('request_id', $capturedRow);
        $this->assertNotEmpty($capturedRow['request_id']);
    }

    public function test_success_row_contains_occurred_at(): void
    {
        $capturedRow = null;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertArrayHasKey('occurred_at', $capturedRow);
        $this->assertStringContainsString('+00:00', $capturedRow['occurred_at']);
    }

    // -------------------------------------------------------------------------
    // §10 — uncaught exception path
    // -------------------------------------------------------------------------

    public function test_uncaught_exception_stamped_into_row(): void
    {
        $capturedRow = null;

        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        // Simulate ExceptionHandler stamping the context
        RequestContext::captureException(new \RuntimeException('Disk full'));

        RequestLogger::persistRequestLog();

        $this->assertSame(\RuntimeException::class, $capturedRow['error_class']);
        $this->assertSame('Disk full',              $capturedRow['error_message']);
    }

    public function test_exception_message_truncated_to_1000_chars(): void
    {
        $longMessage = str_repeat('x', 2000);
        RequestContext::captureException(new \RuntimeException($longMessage));
        $this->assertSame(1000, mb_strlen(RequestContext::getErrorMessage() ?? ''));
    }

    // -------------------------------------------------------------------------
    // §10 — simulated fatal (error_get_last path)
    // -------------------------------------------------------------------------

    public function test_fatal_error_captured_via_error_get_last(): void
    {
        $capturedRow = null;

        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        // Simulate what persistRequestLog sees when a fatal occurred
        // (ExceptionHandler stamped it, or we inject it directly for this test)
        RequestContext::captureFatalError([
            'type'    => E_ERROR,
            'message' => 'Allowed memory size exhausted',
            'file'    => '/app/src/Foo.php',
            'line'    => 42,
        ]);

        RequestLogger::persistRequestLog();

        $this->assertSame('FatalError',                    $capturedRow['error_class']);
        $this->assertSame('Allowed memory size exhausted', $capturedRow['error_message']);
    }

    // -------------------------------------------------------------------------
    // §10 — null identity (unauthenticated)
    // -------------------------------------------------------------------------

    public function test_null_identity_for_unauthenticated_request(): void
    {
        $capturedRow = null;

        // AuthContext is already cleared in setUp()
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertNull($capturedRow['identity_id'], 'Unauthenticated: identity_id must be null');
        $this->assertNull($capturedRow['tenant_id'],   'Unauthenticated: tenant_id must be null');
        $this->assertNull($capturedRow['role'],        'Unauthenticated: role must be null');
    }

    public function test_identity_populated_for_authenticated_request(): void
    {
        $capturedRow = null;

        AuthContext::setUser(new AuthenticatedUser(
            user_id:   'aaaaaaaa-0000-4000-8000-000000000001',
            tenant_id: 'bbbbbbbb-0000-4000-8000-000000000002',
            role_id:   'owner',
        ));

        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $capturedRow['identity_id']);
        $this->assertSame('bbbbbbbb-0000-4000-8000-000000000002', $capturedRow['tenant_id']);
        $this->assertSame('owner', $capturedRow['role']);
    }

    // -------------------------------------------------------------------------
    // §10 — fail-open when DB / gateway unavailable
    // -------------------------------------------------------------------------

    public function test_fail_open_when_gateway_throws(): void
    {
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) {
            throw new \RuntimeException('Connection refused — gateway down');
        });

        // Must not propagate the exception
        RequestLogger::persistRequestLog();

        // No assertion needed beyond "no exception was thrown"
        $this->assertTrue(true, 'Logger must be fail-open when gateway throws');
    }

    // -------------------------------------------------------------------------
    // §10 — fail-open when table missing (same mechanism: gateway throws)
    // -------------------------------------------------------------------------

    public function test_fail_open_when_table_missing(): void
    {
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) {
            throw new \RuntimeException('relation "request_logs" does not exist');
        });

        RequestLogger::persistRequestLog();

        $this->assertTrue(true, 'Logger must be fail-open when table is missing');
    }

    // -------------------------------------------------------------------------
    // §10 — no write when disabled
    // -------------------------------------------------------------------------

    public function test_no_write_when_disabled(): void
    {
        $called = false;

        RequestLogger::arm(['request_logging' => ['enabled' => false]], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$called) {
            $called = true;
        });

        RequestLogger::persistRequestLog();

        $this->assertFalse($called, 'Write adapter must not be called when logging is disabled');
    }

    // -------------------------------------------------------------------------
    // §10 — client_ip with trust_proxy ON (X-Real-Ip)
    // -------------------------------------------------------------------------

    public function test_client_ip_uses_x_real_ip_when_trust_proxy_on(): void
    {
        $_SERVER['HTTP_X_REAL_IP']    = '203.0.113.42';
        $_SERVER['REMOTE_ADDR']       = '10.0.0.99'; // should be ignored

        $ip = RequestLogger::resolveClientIp(true);

        $this->assertSame('203.0.113.42', $ip);
    }

    public function test_client_ip_falls_back_to_xff_rightmost_when_no_real_ip(): void
    {
        unset($_SERVER['HTTP_X_REAL_IP']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1, 10.0.0.2, 10.0.0.3';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.99';

        $ip = RequestLogger::resolveClientIp(true);

        $this->assertSame('10.0.0.3', $ip, 'Rightmost XFF entry expected');
    }

    public function test_client_ip_falls_back_to_remote_addr_when_trust_proxy_on_but_no_headers(): void
    {
        unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.55';

        $ip = RequestLogger::resolveClientIp(true);

        $this->assertSame('10.0.0.55', $ip);
    }

    // -------------------------------------------------------------------------
    // §10 — client_ip with trust_proxy OFF (REMOTE_ADDR)
    // -------------------------------------------------------------------------

    public function test_client_ip_uses_remote_addr_when_trust_proxy_off(): void
    {
        $_SERVER['HTTP_X_REAL_IP']       = '203.0.113.42'; // must be ignored
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';      // must be ignored
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';

        $ip = RequestLogger::resolveClientIp(false);

        $this->assertSame('10.0.0.1', $ip);
    }

    public function test_client_ip_xff_cannot_be_spoofed_when_trust_proxy_off(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.3.3.7'; // attacker-supplied, must be ignored
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';

        $ip = RequestLogger::resolveClientIp(false);

        $this->assertSame('10.0.0.1', $ip, 'XFF must be ignored when trust_proxy is false');
    }

    // -------------------------------------------------------------------------
    // §10 — request_id: generated when header absent
    // -------------------------------------------------------------------------

    public function test_request_id_generated_when_header_absent(): void
    {
        unset($_SERVER['HTTP_X_REQUEST_ID']);

        $capturedRow = null;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertNotEmpty($capturedRow['request_id']);
        // Must match UUIDv4 pattern: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $capturedRow['request_id'],
            'Generated request_id must be a valid UUIDv4'
        );
    }

    // -------------------------------------------------------------------------
    // §10 — request_id: captured when header present
    // -------------------------------------------------------------------------

    public function test_request_id_captured_from_header(): void
    {
        $edgeId = 'traefik-injected-id-abc123';
        $_SERVER['HTTP_X_REQUEST_ID'] = $edgeId;

        $capturedRow = null;
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $row) use (&$capturedRow) {
            $capturedRow = $row;
        });

        RequestLogger::persistRequestLog();

        $this->assertSame($edgeId, $capturedRow['request_id']);
    }

    // -------------------------------------------------------------------------
    // generateUuid() — basic sanity
    // -------------------------------------------------------------------------

    public function test_generate_uuid_returns_valid_v4(): void
    {
        $uuid = RequestLogger::generateUuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    public function test_generate_uuid_returns_unique_values(): void
    {
        $uuids = [];
        for ($i = 0; $i < 10; $i++) {
            $uuids[] = RequestLogger::generateUuid();
        }
        $this->assertCount(10, array_unique($uuids), 'UUIDs must be unique');
    }

    // -------------------------------------------------------------------------
    // RequestContext — standalone tests
    // -------------------------------------------------------------------------

    public function test_request_context_starts_null(): void
    {
        $this->assertNull(RequestContext::getErrorClass());
        $this->assertNull(RequestContext::getErrorMessage());
    }

    public function test_request_context_captures_exception(): void
    {
        RequestContext::captureException(new \InvalidArgumentException('bad input'));
        $this->assertSame(\InvalidArgumentException::class, RequestContext::getErrorClass());
        $this->assertSame('bad input', RequestContext::getErrorMessage());
    }

    public function test_request_context_captures_fatal_error(): void
    {
        RequestContext::captureFatalError([
            'type'    => E_ERROR,
            'message' => 'Maximum execution time exceeded',
            'file'    => '/app/foo.php',
            'line'    => 7,
        ]);
        $this->assertSame('FatalError', RequestContext::getErrorClass());
        $this->assertSame('Maximum execution time exceeded', RequestContext::getErrorMessage());
    }

    public function test_request_context_reset_clears_state(): void
    {
        RequestContext::captureException(new \RuntimeException('boom'));
        RequestContext::reset();
        $this->assertNull(RequestContext::getErrorClass());
        $this->assertNull(RequestContext::getErrorMessage());
    }

    // -------------------------------------------------------------------------
    // Schema file presence (quick sanity — does not require a DB)
    // -------------------------------------------------------------------------

    public function test_schema_table_file_exists(): void
    {
        $file = __DIR__ . '/../../src/RequestLogging/Schema/tables/req_001_request_logs.pgsql';
        $this->assertFileExists($file, 'req_001_request_logs.pgsql must be committed with the framework');
    }

    public function test_schema_function_file_exists(): void
    {
        $file = __DIR__ . '/../../src/RequestLogging/Schema/functions/rl_insert_request_log.pgsql';
        $this->assertFileExists($file, 'rl_insert_request_log.pgsql must be committed with the framework');
    }

    public function test_schema_table_contains_required_columns(): void
    {
        $sql = file_get_contents(
            __DIR__ . '/../../src/RequestLogging/Schema/tables/req_001_request_logs.pgsql'
        );
        $requiredColumns = [
            'request_id', 'occurred_at', 'platform_code', 'host', 'method',
            'path', 'status', 'duration_ms', 'client_ip', 'identity_id',
            'tenant_id', 'role', 'error_class', 'error_message',
            'user_agent', 'referer', 'logged_at',
        ];
        foreach ($requiredColumns as $col) {
            $this->assertStringContainsString(
                $col,
                $sql,
                "Column '$col' must be present in req_001_request_logs.pgsql"
            );
        }
    }
}
